<?php
/**
 * Authenticated REST scan workflow.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides one resumable scan job per authorized administrator.
 */
final class OGSMI_REST_Controller {

	const REST_NAMESPACE = '1gbits-site-move-inspector/v1';

	/**
	 * Job storage.
	 *
	 * @var OGSMI_Job_Store
	 */
	private $job_store;

	/**
	 * Constructor.
	 *
	 * @param OGSMI_Job_Store $job_store Job storage.
	 */
	public function __construct( OGSMI_Job_Store $job_store ) {
		$this->job_store = $job_store;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start_scan' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/scan/(?P<job_id>[a-f0-9]{32})/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'step_scan' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'job_id' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_job_id' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/scan/(?P<job_id>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'cancel_scan' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'job_id' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_job_id' ),
					),
				),
			)
		);
	}

	/**
	 * Require both a REST nonce and the appropriate administrative capability.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'ogsmi_invalid_nonce',
				__( 'The scan request could not be verified.', '1gbits-site-move-inspector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( is_multisite() ) {
			$allowed = is_super_admin() && current_user_can( 'manage_network_options' );
		} else {
			$allowed = current_user_can( 'manage_options' );
		}

		if ( ! $allowed ) {
			return new WP_Error(
				'ogsmi_forbidden',
				__( 'You are not allowed to run this inspection.', '1gbits-site-move-inspector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Validate a route job ID.
	 *
	 * @param mixed $value Job ID.
	 * @return bool
	 */
	public function validate_job_id( $value ) {
		return OGSMI_Utils::is_valid_job_id( $value );
	}

	/**
	 * Create the initial report and filesystem cursor.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan( WP_REST_Request $request ) {
		$user_id       = get_current_user_id();
		$destination   = $this->sanitize_destination( $request );
		$self_test     = rest_sanitize_boolean( $request->get_param( 'self_test' ) );
		$inspector     = new OGSMI_Inspector();
		$scanner       = new OGSMI_File_Scanner();
		$lock_id       = md5( 'start:' . $user_id );
		$active_lock   = '';
		$active_locked = false;

		if ( ! $this->job_store->acquire_lock( $lock_id ) ) {
			return $this->lock_failure_error(
				__( 'Another inspection request is already in progress. Try again in a moment.', '1gbits-site-move-inspector' )
			);
		}

		try {
			try {
				$report = $inspector->inspect_initial( $destination, $self_test );
			} catch ( Throwable $throwable ) {
				$report = OGSMI_Report_Builder::create( $destination );
				OGSMI_Report_Builder::mark_partial(
					$report,
					__( 'One or more initial checks could not be completed.', '1gbits-site-move-inspector' )
				);
				OGSMI_Report_Builder::add_check(
					$report,
					'environment',
					__( 'Environment', '1gbits-site-move-inspector' ),
					array(
						'id'             => 'initial_scan_error',
						'status'         => OGSMI_Report_Builder::STATUS_UNKNOWN,
						'label'          => __( 'Initial checks', '1gbits-site-move-inspector' ),
						'value'          => __( 'Incomplete', '1gbits-site-move-inspector' ),
						'message'        => __( 'An internal check failed without exposing technical details.', '1gbits-site-move-inspector' ),
						'recommendation' => __( 'Review the server PHP log or ask the host to inspect the environment.', '1gbits-site-move-inspector' ),
					)
				);
			}

			$state = array(
				'report' => $report,
				'files'  => $scanner->create_state(),
			);

			if ( ! $this->job_store->refresh_lock( $lock_id ) ) {
				return $this->lock_lost_error();
			}

			$active_lock = $this->job_store->get_active_id( $user_id );
			if ( '' !== $active_lock ) {
				$active_locked = $this->job_store->acquire_lock( $active_lock );
				if ( ! $active_locked ) {
					return $this->lock_failure_error(
						__( 'The current inspection is still processing. Try again in a moment.', '1gbits-site-move-inspector' )
					);
				}
			}

			if (
				! $this->job_store->refresh_lock( $lock_id )
				|| ( $active_locked && ! $this->job_store->refresh_lock( $active_lock ) )
			) {
				return $this->lock_lost_error();
			}

			$job_id = $this->job_store->create( $user_id, $state );

			if ( false === $job_id ) {
				return new WP_Error(
					'ogsmi_job_storage_failed',
					__( 'The inspection could not be stored temporarily. No site data was changed.', '1gbits-site-move-inspector' ),
					array( 'status' => 500 )
				);
			}

			return $this->response(
				array(
					'job_id'   => $job_id,
					'complete' => false,
					'progress' => $this->progress( $state['files'] ),
				),
				201
			);
		} catch ( Throwable $throwable ) {
			return new WP_Error(
				'ogsmi_scan_start_failed',
				__( 'The inspection could not start safely. No site data was changed.', '1gbits-site-move-inspector' ),
				array( 'status' => 500 )
			);
		} finally {
			if ( $active_locked ) {
				$this->job_store->release_lock( $active_lock );
			}
			$this->job_store->release_lock( $lock_id );
		}
	}

	/**
	 * Process one filesystem batch and complete the report when done.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function step_scan( WP_REST_Request $request ) {
		$job_id  = (string) $request['job_id'];
		$user_id = get_current_user_id();
		$state   = $this->job_store->get( $job_id, $user_id );

		if ( null === $state ) {
			return new WP_Error(
				'ogsmi_job_not_found',
				__( 'The scan expired or does not belong to this user.', '1gbits-site-move-inspector' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->job_store->acquire_lock( $job_id ) ) {
			return $this->lock_failure_error(
				__( 'This inspection is already processing another step. Try again in a moment.', '1gbits-site-move-inspector' )
			);
		}

		try {
			$state = $this->job_store->get( $job_id, $user_id );
			if ( null === $state ) {
				return new WP_Error(
					'ogsmi_job_not_found',
					__( 'The scan expired or does not belong to this user.', '1gbits-site-move-inspector' ),
					array( 'status' => 404 )
				);
			}

			$scanner = new OGSMI_File_Scanner();
			try {
				$state['files'] = $scanner->step( $state['files'] );
			} catch ( Throwable $throwable ) {
				$state['files']['partial']           = true;
				$state['files']['completed']         = true;
				$state['files']['queue']             = array();
				$state['files']['current']           = null;
				$state['files']['partial_reasons'][] = __( 'A filesystem operation failed and the scan stopped safely.', '1gbits-site-move-inspector' );
			}

			if ( ! empty( $state['files']['completed'] ) ) {
				$inspector = new OGSMI_Inspector();
				try {
					$report = $inspector->finalize(
						$state['report'],
						$scanner->summarize( $state['files'] )
					);
				} catch ( Throwable $throwable ) {
					$report = $state['report'];
					OGSMI_Report_Builder::mark_partial(
						$report,
						__( 'The final storage checks could not be completed.', '1gbits-site-move-inspector' )
					);
					OGSMI_Report_Builder::add_check(
						$report,
						'storage',
						__( 'Files and storage', '1gbits-site-move-inspector' ),
						array(
							'id'             => 'filesystem_scan',
							'status'         => OGSMI_Report_Builder::STATUS_UNKNOWN,
							'label'          => __( 'Filesystem scan', '1gbits-site-move-inspector' ),
							'value'          => __( 'Unavailable', '1gbits-site-move-inspector' ),
							'message'        => __( 'The scan stopped safely while preparing the storage summary.', '1gbits-site-move-inspector' ),
							'recommendation' => __( 'Use server-level tools to confirm file totals before migration.', '1gbits-site-move-inspector' ),
						)
					);
					OGSMI_Report_Builder::finalize( $report );
				}

				if ( ! $this->job_store->refresh_lock( $job_id ) ) {
					return $this->lock_lost_error();
				}

				if ( ! $this->job_store->complete( $job_id, $user_id, $report ) ) {
					return new WP_Error(
						'ogsmi_report_storage_failed',
						__( 'The completed report could not be stored temporarily.', '1gbits-site-move-inspector' ),
						array( 'status' => 500 )
					);
				}

				return $this->response(
					array(
						'job_id'   => $job_id,
						'complete' => true,
						'progress' => $this->progress( $state['files'] ),
					)
				);
			}

			if ( ! $this->job_store->refresh_lock( $job_id ) ) {
				return $this->lock_lost_error();
			}

			if ( ! $this->job_store->save( $job_id, $user_id, $state ) ) {
				return new WP_Error(
					'ogsmi_job_storage_failed',
					__( 'The scan state could not be stored. No site data was changed.', '1gbits-site-move-inspector' ),
					array( 'status' => 500 )
				);
			}

			return $this->response(
				array(
					'job_id'   => $job_id,
					'complete' => false,
					'progress' => $this->progress( $state['files'] ),
				)
			);
		} finally {
			$this->job_store->release_lock( $job_id );
		}
	}

	/**
	 * Cancel an owned scan.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_scan( WP_REST_Request $request ) {
		$job_id  = (string) $request['job_id'];
		$user_id = get_current_user_id();

		if ( null === $this->job_store->get( $job_id, $user_id ) ) {
			return new WP_Error(
				'ogsmi_job_not_found',
				__( 'The scan expired or does not belong to this user.', '1gbits-site-move-inspector' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->job_store->acquire_lock( $job_id ) ) {
			return $this->lock_failure_error(
				__( 'This inspection is finishing a step. Try canceling again in a moment.', '1gbits-site-move-inspector' )
			);
		}

		try {
			if ( ! $this->job_store->refresh_lock( $job_id ) ) {
				return $this->lock_lost_error();
			}

			if ( ! $this->job_store->cancel( $job_id, $user_id ) ) {
				return new WP_Error(
					'ogsmi_job_not_found',
					__( 'The scan expired or does not belong to this user.', '1gbits-site-move-inspector' ),
					array( 'status' => 404 )
				);
			}

			return $this->response(
				array(
					'job_id'   => $job_id,
					'canceled' => true,
				)
			);
		} finally {
			$this->job_store->release_lock( $job_id );
		}
	}

	/**
	 * Sanitize optional destination fields without retaining raw input.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array
	 */
	private function sanitize_destination( WP_REST_Request $request ) {
		$php_version      = OGSMI_Utils::sanitize_version( $request->get_param( 'target_php' ) );
		$database_version = OGSMI_Utils::sanitize_version( $request->get_param( 'target_database_version' ) );
		$database_engine  = sanitize_key( (string) $request->get_param( 'target_database_engine' ) );
		$database_engine  = in_array( $database_engine, array( 'mysql', 'mariadb' ), true ) ? $database_engine : '';
		$multisite        = sanitize_key( (string) $request->get_param( 'target_multisite' ) );
		$multisite        = in_array( $multisite, array( 'yes', 'no', 'unknown' ), true ) ? $multisite : 'unknown';

		$disk_raw         = trim( sanitize_text_field( (string) $request->get_param( 'target_disk_gb' ) ) );
		$disk_gb          = preg_match( '/^\d{1,7}(?:\.\d{1,2})?$/', $disk_raw ) ? (float) $disk_raw : 0.0;
		$disk_bytes_float = $disk_gb * 1024 * 1024 * 1024;
		$disk_bytes       = $disk_bytes_float > PHP_INT_MAX ? PHP_INT_MAX : (int) round( $disk_bytes_float );

		$provided = '' !== $php_version
			|| '' !== $database_version
			|| '' !== $database_engine
			|| $disk_bytes > 0
			|| 'unknown' !== $multisite;

		return array(
			'provided'          => $provided,
			'php_version'       => $php_version,
			'database_engine'   => $database_engine,
			'database_version'  => $database_version,
			'disk_bytes'        => max( 0, $disk_bytes ),
			'multisite_support' => $multisite,
		);
	}

	/**
	 * Return non-sensitive progress counters.
	 *
	 * @param array $files File scanner state.
	 * @return array
	 */
	private function progress( array $files ) {
		return array(
			'files'       => absint( $files['file_count'] ?? 0 ),
			'directories' => absint( $files['directory_count'] ?? 0 ),
			'entries'     => absint( $files['processed_entries'] ?? 0 ),
			'bytes'       => max( 0, (int) ( $files['total_bytes'] ?? 0 ) ),
			'partial'     => ! empty( $files['partial'] ),
		);
	}

	/**
	 * Return a conflict when this request lost ownership of its scan lock.
	 *
	 * @return WP_Error
	 */
	private function lock_lost_error() {
		return $this->lock_failure_error(
			__( 'A newer inspection request took over. Try again in a moment.', '1gbits-site-move-inspector' )
		);
	}

	/**
	 * Distinguish normal lock contention from a database storage failure.
	 *
	 * @param string $busy_message Message for an owned-lock conflict.
	 * @return WP_Error
	 */
	private function lock_failure_error( $busy_message ) {
		if ( $this->job_store->lock_storage_failed() ) {
			return new WP_Error(
				'ogsmi_lock_storage_failed',
				__( 'The inspection lock could not be stored. Check the database connection and try again.', '1gbits-site-move-inspector' ),
				array( 'status' => 500 )
			);
		}

		return new WP_Error(
			'ogsmi_job_busy',
			$busy_message,
			array( 'status' => 409 )
		);
	}

	/**
	 * Create a no-store REST response.
	 *
	 * @param array $data Response body.
	 * @param int   $status HTTP status.
	 * @return WP_REST_Response
	 */
	private function response( array $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, absint( $status ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );

		return $response;
	}
}
