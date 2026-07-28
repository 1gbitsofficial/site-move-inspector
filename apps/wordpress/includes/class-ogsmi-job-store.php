<?php
/**
 * Ephemeral scan job storage.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps scan cursors and completed reports in short-lived transients.
 */
final class OGSMI_Job_Store {

	const ACTIVE_META      = '_ogsmi_active_job';
	const LAST_REPORT_META = '_ogsmi_last_report';
	const JOB_TTL          = 1800;
	const REPORT_TTL       = 3600;
	const LOCK_TTL         = 30;
	const WRITE_LOCK_TTL   = 120;
	const TRANSIENT_PREFIX = 'ogsmi_job_';
	const REPORT_PREFIX    = 'ogsmi_report_';
	const LOCK_PREFIX      = 'ogsmi_job_lock_';

	/**
	 * Exact option values owned by this request, keyed by job ID.
	 *
	 * @var string[]
	 */
	private $lock_values = array();

	/**
	 * Whether the most recent public lock operation encountered a database error.
	 *
	 * @var bool
	 */
	private $lock_storage_error = false;

	/**
	 * Start a job for a user, replacing their previous active job.
	 *
	 * @param int   $user_id User ID.
	 * @param array $state   Initial job state.
	 * @return string|false Job ID on success, false when the job could not be stored.
	 */
	public function create( $user_id, array $state ) {
		$user_id         = absint( $user_id );
		$previous_job_id = (string) get_user_meta( $user_id, self::ACTIVE_META, true );

		$job_id              = OGSMI_Utils::random_job_id();
		$state['job_id']     = $job_id;
		$state['owner_id']   = $user_id;
		$state['created_at'] = time();
		$state['updated_at'] = time();

		if ( ! set_transient( self::TRANSIENT_PREFIX . $job_id, $state, self::JOB_TTL ) ) {
			return false;
		}

		if ( ! $this->update_user_meta( $user_id, self::ACTIVE_META, $job_id ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $job_id );
			return false;
		}

		if ( $previous_job_id !== $job_id && OGSMI_Utils::is_valid_job_id( $previous_job_id ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $previous_job_id );
		}

		return $job_id;
	}

	/**
	 * Retrieve an owned active job.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @return array|null
	 */
	public function get( $job_id, $user_id ) {
		if ( ! OGSMI_Utils::is_valid_job_id( $job_id ) ) {
			return null;
		}

		$state = get_transient( self::TRANSIENT_PREFIX . $job_id );
		if ( ! is_array( $state ) || absint( $state['owner_id'] ?? 0 ) !== absint( $user_id ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * Persist an active job and refresh its short expiry.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @param array  $state Job state.
	 * @return bool
	 */
	public function save( $job_id, $user_id, array $state ) {
		$existing = $this->get( $job_id, $user_id );
		if ( null === $existing ) {
			return false;
		}

		$state['job_id']     = $job_id;
		$state['owner_id']   = absint( $user_id );
		$state['created_at'] = absint( $existing['created_at'] ?? time() );
		$state['updated_at'] = time();

		return set_transient( self::TRANSIENT_PREFIX . $job_id, $state, self::JOB_TTL );
	}

	/**
	 * Complete a job and retain its report for one hour.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @param array  $report Completed report.
	 * @return bool
	 */
	public function complete( $job_id, $user_id, array $report ) {
		if ( null === $this->get( $job_id, $user_id ) ) {
			return false;
		}

		$report_key       = self::REPORT_PREFIX . $job_id;
		$previous_payload = get_transient( $report_key );
		$payload          = array(
			'owner_id' => absint( $user_id ),
			'report'   => $report,
		);

		if ( ! set_transient( $report_key, $payload, self::REPORT_TTL ) ) {
			return false;
		}

		if ( ! $this->update_user_meta( $user_id, self::LAST_REPORT_META, $job_id ) ) {
			$this->restore_transient( $report_key, $previous_payload, self::REPORT_TTL );
			return false;
		}

		delete_transient( self::TRANSIENT_PREFIX . $job_id );
		if ( get_user_meta( $user_id, self::ACTIVE_META, true ) === $job_id ) {
			delete_user_meta( $user_id, self::ACTIVE_META, $job_id );
		}

		return true;
	}

	/**
	 * Acquire a short-lived, atomic lock for one job.
	 *
	 * A stale lock may be reclaimed after LOCK_TTL seconds. Callers that
	 * acquire the lock must release it in a finally block.
	 *
	 * @param string $job_id Job ID.
	 * @return bool True when the lock was acquired.
	 */
	public function acquire_lock( $job_id ) {
		$this->lock_storage_error = false;

		if ( ! OGSMI_Utils::is_valid_job_id( $job_id ) ) {
			return false;
		}

		$option_name = self::LOCK_PREFIX . $job_id;
		$lock_value  = $this->create_lock_value();

		if ( $this->insert_lock_value( $option_name, $lock_value ) ) {
			$this->lock_values[ $job_id ] = $lock_value;
			return true;
		}

		$current_value = $this->read_lock_value( $option_name );
		if ( null === $current_value ) {
			if ( $this->lock_storage_error ) {
				return false;
			}

			$lock_value = $this->create_lock_value();
			if ( ! $this->insert_lock_value( $option_name, $lock_value ) ) {
				return false;
			}

			$this->lock_values[ $job_id ] = $lock_value;
			return true;
		}

		$current_parts = explode( ':', $current_value, 2 );
		if ( absint( $current_parts[0] ?? 0 ) > time() ) {
			return false;
		}

		if ( ! $this->delete_lock_value( $option_name, $current_value ) ) {
			return false;
		}

		$lock_value = $this->create_lock_value();
		if ( ! $this->insert_lock_value( $option_name, $lock_value ) ) {
			return false;
		}

		$this->lock_values[ $job_id ] = $lock_value;
		return true;
	}

	/**
	 * Renew a lock only while this request still owns its exact value.
	 *
	 * Callers should refresh immediately before persisting state. If another
	 * request reclaimed an expired lock, the compare-and-swap fails and the
	 * older request must not write.
	 *
	 * @param string $job_id Job ID.
	 * @return bool True when ownership was atomically renewed.
	 */
	public function refresh_lock( $job_id ) {
		global $wpdb;

		$this->lock_storage_error = false;

		if ( ! OGSMI_Utils::is_valid_job_id( $job_id ) || ! isset( $this->lock_values[ $job_id ] ) ) {
			return false;
		}

		$option_name = self::LOCK_PREFIX . $job_id;
		$current     = $this->lock_values[ $job_id ];
		$replacement = $this->create_lock_value( $current, self::WRITE_LOCK_TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap lock renewal requires matching the exact owned value.
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $replacement ),
			array(
				'option_name'  => $option_name,
				'option_value' => $current,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);

		if ( false === $updated ) {
			$this->lock_storage_error = true;
			return false;
		}

		if ( 1 !== $updated ) {
			return false;
		}

		wp_cache_delete( $option_name, 'options' );
		$this->lock_values[ $job_id ] = $replacement;
		return true;
	}

	/**
	 * Release a job lock previously acquired by the caller.
	 *
	 * @param string $job_id Job ID.
	 * @return bool True when the lock option was removed.
	 */
	public function release_lock( $job_id ) {
		$this->lock_storage_error = false;

		if ( ! OGSMI_Utils::is_valid_job_id( $job_id ) || ! isset( $this->lock_values[ $job_id ] ) ) {
			return false;
		}

		$lock_value = $this->lock_values[ $job_id ];
		unset( $this->lock_values[ $job_id ] );

		return $this->delete_lock_value( self::LOCK_PREFIX . $job_id, $lock_value );
	}

	/**
	 * Report whether the latest lock operation failed at the database layer.
	 *
	 * @return bool
	 */
	public function lock_storage_failed() {
		return $this->lock_storage_error;
	}

	/**
	 * Retrieve an owned completed report.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @return array|null
	 */
	public function get_report( $job_id, $user_id ) {
		if ( ! OGSMI_Utils::is_valid_job_id( $job_id ) ) {
			return null;
		}

		$payload = get_transient( self::REPORT_PREFIX . $job_id );
		if ( ! is_array( $payload ) || absint( $payload['owner_id'] ?? 0 ) !== absint( $user_id ) ) {
			return null;
		}

		return is_array( $payload['report'] ?? null ) ? $payload['report'] : null;
	}

	/**
	 * Return the user's latest report.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public function get_latest_report( $user_id ) {
		$job_id = (string) get_user_meta( $user_id, self::LAST_REPORT_META, true );
		$report = $this->get_report( $job_id, $user_id );

		if ( null === $report && '' !== $job_id ) {
			delete_user_meta( $user_id, self::LAST_REPORT_META );
		}

		return $report;
	}

	/**
	 * Return the user's active job ID if its transient still exists.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function get_active_id( $user_id ) {
		$job_id = (string) get_user_meta( $user_id, self::ACTIVE_META, true );
		if ( null === $this->get( $job_id, $user_id ) ) {
			delete_user_meta( $user_id, self::ACTIVE_META );
			return '';
		}

		return $job_id;
	}

	/**
	 * Cancel one owned job.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	public function cancel( $job_id, $user_id ) {
		if ( null === $this->get( $job_id, $user_id ) ) {
			return false;
		}

		delete_transient( self::TRANSIENT_PREFIX . $job_id );

		if ( get_user_meta( $user_id, self::ACTIVE_META, true ) === $job_id ) {
			delete_user_meta( $user_id, self::ACTIVE_META );
		}

		return true;
	}

	/**
	 * Cancel the user's active job, if present.
	 *
	 * @param int $user_id User ID.
	 */
	public function cancel_active( $user_id ) {
		$job_id = (string) get_user_meta( $user_id, self::ACTIVE_META, true );
		if ( OGSMI_Utils::is_valid_job_id( $job_id ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $job_id );
		}
		delete_user_meta( $user_id, self::ACTIVE_META );
	}

	/**
	 * Update user metadata and distinguish an unchanged value from a failure.
	 *
	 * @param int    $user_id User ID.
	 * @param string $meta_key Meta key.
	 * @param string $value Meta value.
	 * @return bool
	 */
	private function update_user_meta( $user_id, $meta_key, $value ) {
		$result = update_user_meta( $user_id, $meta_key, $value );

		return false !== $result || get_user_meta( $user_id, $meta_key, true ) === $value;
	}

	/**
	 * Restore a transient value after a later persistence operation fails.
	 *
	 * @param string $key Transient key.
	 * @param mixed  $previous_value Previous transient value, or false if absent.
	 * @param int    $expiration Expiration in seconds.
	 */
	private function restore_transient( $key, $previous_value, $expiration ) {
		if ( false === $previous_value ) {
			delete_transient( $key );
			return;
		}

		set_transient( $key, $previous_value, $expiration );
	}

	/**
	 * Build a fresh lock value containing its expiry and owner token.
	 *
	 * @param string $different_from Optional value that the result must differ from.
	 * @param int    $ttl            Lease duration in seconds.
	 * @return string
	 */
	private function create_lock_value( $different_from = '', $ttl = self::LOCK_TTL ) {
		$expires_at = time() + absint( $ttl );
		$lock_value = $expires_at . ':' . OGSMI_Utils::random_job_id();

		if ( $lock_value === $different_from ) {
			$lock_value = ( $expires_at + 1 ) . ':' . OGSMI_Utils::random_job_id();
		}

		return $lock_value;
	}

	/**
	 * Atomically insert a lock without overwriting an existing option row.
	 *
	 * Core's add_option() may update a duplicate row after its initial
	 * existence check, so it cannot provide compare-and-insert semantics.
	 *
	 * @param string $option_name Exact option name.
	 * @param string $lock_value  New lock value.
	 * @return bool
	 */
	private function insert_lock_value( $option_name, $lock_value ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE provides atomic contention without emitting a duplicate-key database error.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )",
				$option_name,
				$lock_value,
				'no'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted ) {
			$this->lock_storage_error = true;
			return false;
		}

		if ( 1 !== $inserted ) {
			return false;
		}

		wp_cache_delete( $option_name, 'options' );
		return true;
	}

	/**
	 * Read a lock from the database instead of the potentially stale cache.
	 *
	 * @param string $option_name Exact option name.
	 * @return string|null Lock value, or null when no row exists.
	 */
	private function read_lock_value( $option_name ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock ownership must be read from the authoritative database row.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' !== (string) $wpdb->last_error ) {
			$this->lock_storage_error = true;
			return null;
		}

		return null === $value ? null : (string) $value;
	}

	/**
	 * Delete a lock only if the database still contains the value we observed.
	 *
	 * The value comparison makes stale-lock reclamation and release atomic: an
	 * older request cannot remove a replacement lock owned by another request.
	 *
	 * @param string $option_name Exact option name.
	 * @param string $lock_value  Exact observed or owned option value.
	 * @return bool
	 */
	private function delete_lock_value( $option_name, $lock_value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A conditional delete is required for compare-and-delete lock ownership.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => $lock_value,
			),
			array( '%s', '%s' )
		);

		if ( false === $deleted ) {
			$this->lock_storage_error = true;
			return false;
		}

		if ( 1 !== $deleted ) {
			return false;
		}

		wp_cache_delete( $option_name, 'options' );
		return true;
	}
}
