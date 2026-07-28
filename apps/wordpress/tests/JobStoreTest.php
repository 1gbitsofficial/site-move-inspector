<?php
/**
 * Job store persistence and locking tests.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

$GLOBALS['ogsmi_job_store_test'] = array();

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration ) {
		if ( ! empty( $GLOBALS['ogsmi_job_store_test']['fail_next_transient_write'] ) ) {
			$GLOBALS['ogsmi_job_store_test']['fail_next_transient_write'] = false;
			return false;
		}

		$GLOBALS['ogsmi_job_store_test']['transients'][ $key ] = array(
			'value'      => $value,
			'expiration' => $expiration,
		);
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['ogsmi_job_store_test']['transients'][ $key ]['value'] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		if ( ! isset( $GLOBALS['ogsmi_job_store_test']['transients'][ $key ] ) ) {
			return false;
		}

		unset( $GLOBALS['ogsmi_job_store_test']['transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		$value = $GLOBALS['ogsmi_job_store_test']['user_meta'][ $user_id ][ $key ] ?? '';

		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		if ( ( $GLOBALS['ogsmi_job_store_test']['fail_meta_key'] ?? '' ) === $key ) {
			return false;
		}

		if ( ( $GLOBALS['ogsmi_job_store_test']['user_meta'][ $user_id ][ $key ] ?? '' ) === $value ) {
			return false;
		}

		$GLOBALS['ogsmi_job_store_test']['user_meta'][ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key, $value = '' ) {
		if ( ! isset( $GLOBALS['ogsmi_job_store_test']['user_meta'][ $user_id ][ $key ] ) ) {
			return false;
		}

		if ( 3 === func_num_args() && $GLOBALS['ogsmi_job_store_test']['user_meta'][ $user_id ][ $key ] !== $value ) {
			return false;
		}

		unset( $GLOBALS['ogsmi_job_store_test']['user_meta'][ $user_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
		if ( isset( $GLOBALS['ogsmi_job_store_test']['options'][ $name ] ) ) {
			return false;
		}

		$GLOBALS['ogsmi_job_store_test']['options'][ $name ] = array(
			'value'    => $value,
			'autoload' => $autoload,
		);
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['ogsmi_job_store_test']['options'][ $name ]['value'] ?? $default;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		if ( ! isset( $GLOBALS['ogsmi_job_store_test']['options'][ $name ] ) ) {
			return false;
		}

		unset( $GLOBALS['ogsmi_job_store_test']['options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete() {
		return true;
	}
}

final class OGSMI_Test_WPDB {

	public $options = 'wp_options';
	public $last_error = '';

	public function query( array $prepared ) {
		$name = $prepared['values'][0];
		$this->last_error = '';

		if ( ! empty( $GLOBALS['ogsmi_job_store_test']['fail_next_lock_insert'] ) ) {
			$GLOBALS['ogsmi_job_store_test']['fail_next_lock_insert'] = false;
			$this->last_error = 'Insert failed.';
			return false;
		}

		if ( isset( $GLOBALS['ogsmi_job_store_test']['insert_before_next_lock_insert'] ) ) {
			$GLOBALS['ogsmi_job_store_test']['options'][ $name ] = array(
				'value'    => $GLOBALS['ogsmi_job_store_test']['insert_before_next_lock_insert'],
				'autoload' => 'no',
			);
			unset( $GLOBALS['ogsmi_job_store_test']['insert_before_next_lock_insert'] );
		}

		if (
			false === strpos( $prepared['query'], 'INSERT IGNORE INTO wp_options' )
			|| isset( $GLOBALS['ogsmi_job_store_test']['options'][ $name ] )
		) {
			return 0;
		}

		$GLOBALS['ogsmi_job_store_test']['options'][ $name ] = array(
			'value'    => $prepared['values'][1],
			'autoload' => $prepared['values'][2],
		);
		return 1;
	}

	public function prepare( $query, ...$values ) {
		return array(
			'query'  => $query,
			'values' => $values,
		);
	}

	public function get_var( array $prepared ) {
		$name = $prepared['values'][0];
		$this->last_error = '';

		if ( ! empty( $GLOBALS['ogsmi_job_store_test']['fail_next_lock_select'] ) ) {
			$GLOBALS['ogsmi_job_store_test']['fail_next_lock_select'] = false;
			$this->last_error = 'Select failed.';
			return null;
		}

		return $GLOBALS['ogsmi_job_store_test']['options'][ $name ]['value'] ?? null;
	}

	public function delete( $table, array $where ) {
		$name = $where['option_name'];
		$this->last_error = '';

		if ( ! empty( $GLOBALS['ogsmi_job_store_test']['fail_next_lock_delete'] ) ) {
			$GLOBALS['ogsmi_job_store_test']['fail_next_lock_delete'] = false;
			$this->last_error = 'Delete failed.';
			return false;
		}

		if ( isset( $GLOBALS['ogsmi_job_store_test']['replace_before_conditional_delete'] ) ) {
			$GLOBALS['ogsmi_job_store_test']['options'][ $name ]['value'] =
				$GLOBALS['ogsmi_job_store_test']['replace_before_conditional_delete'];
			unset( $GLOBALS['ogsmi_job_store_test']['replace_before_conditional_delete'] );
		}

		if (
			'wp_options' !== $table
			|| ! isset( $GLOBALS['ogsmi_job_store_test']['options'][ $name ] )
			|| $GLOBALS['ogsmi_job_store_test']['options'][ $name ]['value'] !== $where['option_value']
		) {
			return 0;
		}

		unset( $GLOBALS['ogsmi_job_store_test']['options'][ $name ] );
		return 1;
	}

	public function update( $table, array $data, array $where ) {
		$name = $where['option_name'];
		$this->last_error = '';

		if ( ! empty( $GLOBALS['ogsmi_job_store_test']['fail_next_lock_update'] ) ) {
			$GLOBALS['ogsmi_job_store_test']['fail_next_lock_update'] = false;
			$this->last_error = 'Update failed.';
			return false;
		}

		if (
			'wp_options' !== $table
			|| ! isset( $GLOBALS['ogsmi_job_store_test']['options'][ $name ] )
			|| $GLOBALS['ogsmi_job_store_test']['options'][ $name ]['value'] !== $where['option_value']
		) {
			return 0;
		}

		$GLOBALS['ogsmi_job_store_test']['options'][ $name ]['value'] = $data['option_value'];
		return 1;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ogsmi-job-store.php';

final class OGSMI_Job_Store_Test extends TestCase {

	/**
	 * Job store instance.
	 *
	 * @var OGSMI_Job_Store
	 */
	private $store;

	protected function setUp(): void {
		$GLOBALS['ogsmi_job_store_test'] = array(
			'fail_meta_key'            => '',
			'fail_next_transient_write' => false,
			'options'                  => array(),
			'transients'               => array(),
			'user_meta'                => array(),
		);
		$GLOBALS['wpdb'] = new OGSMI_Test_WPDB();
		$this->store = new OGSMI_Job_Store();
	}

	public function test_create_transient_failure_preserves_previous_job() {
		$user_id         = 7;
		$previous_job_id = $this->store->create( $user_id, array( 'step' => 1 ) );

		$GLOBALS['ogsmi_job_store_test']['fail_next_transient_write'] = true;
		$result = $this->store->create( $user_id, array( 'step' => 2 ) );

		$this->assertFalse( $result );
		$this->assertSame( $previous_job_id, get_user_meta( $user_id, OGSMI_Job_Store::ACTIVE_META, true ) );
		$this->assertSame( 1, $this->store->get( $previous_job_id, $user_id )['step'] );
	}

	public function test_create_meta_failure_rolls_back_new_transient() {
		$user_id         = 8;
		$previous_job_id = $this->store->create( $user_id, array( 'step' => 1 ) );

		$GLOBALS['ogsmi_job_store_test']['fail_meta_key'] = OGSMI_Job_Store::ACTIVE_META;
		$result = $this->store->create( $user_id, array( 'step' => 2 ) );

		$this->assertFalse( $result );
		$this->assertSame( $previous_job_id, get_user_meta( $user_id, OGSMI_Job_Store::ACTIVE_META, true ) );
		$this->assertCount( 1, $GLOBALS['ogsmi_job_store_test']['transients'] );
	}

	public function test_save_propagates_transient_failure_without_changing_job() {
		$user_id = 9;
		$job_id  = $this->store->create( $user_id, array( 'step' => 1 ) );

		$GLOBALS['ogsmi_job_store_test']['fail_next_transient_write'] = true;
		$result = $this->store->save( $job_id, $user_id, array( 'step' => 2 ) );

		$this->assertFalse( $result );
		$this->assertSame( 1, $this->store->get( $job_id, $user_id )['step'] );
	}

	public function test_complete_transient_failure_preserves_live_job_and_meta() {
		$user_id = 10;
		$job_id  = $this->store->create( $user_id, array( 'step' => 1 ) );
		update_user_meta( $user_id, OGSMI_Job_Store::LAST_REPORT_META, str_repeat( 'a', 32 ) );

		$GLOBALS['ogsmi_job_store_test']['fail_next_transient_write'] = true;
		$result = $this->store->complete( $job_id, $user_id, array( 'done' => true ) );

		$this->assertFalse( $result );
		$this->assertNotNull( $this->store->get( $job_id, $user_id ) );
		$this->assertSame( $job_id, get_user_meta( $user_id, OGSMI_Job_Store::ACTIVE_META, true ) );
		$this->assertSame( str_repeat( 'a', 32 ), get_user_meta( $user_id, OGSMI_Job_Store::LAST_REPORT_META, true ) );
	}

	public function test_complete_meta_failure_restores_previous_report() {
		$user_id         = 11;
		$job_id          = $this->store->create( $user_id, array( 'step' => 1 ) );
		$previous_report = array( 'previous' => true );
		set_transient(
			OGSMI_Job_Store::REPORT_PREFIX . $job_id,
			array(
				'owner_id' => $user_id,
				'report'   => $previous_report,
			),
			OGSMI_Job_Store::REPORT_TTL
		);

		$GLOBALS['ogsmi_job_store_test']['fail_meta_key'] = OGSMI_Job_Store::LAST_REPORT_META;
		$result = $this->store->complete( $job_id, $user_id, array( 'done' => true ) );

		$this->assertFalse( $result );
		$this->assertNotNull( $this->store->get( $job_id, $user_id ) );
		$this->assertSame( $previous_report, $this->store->get_report( $job_id, $user_id ) );
	}

	public function test_complete_commits_report_before_removing_job() {
		$user_id = 12;
		$job_id  = $this->store->create( $user_id, array( 'step' => 1 ) );
		$report  = array( 'done' => true );

		$this->assertTrue( $this->store->complete( $job_id, $user_id, $report ) );
		$this->assertNull( $this->store->get( $job_id, $user_id ) );
		$this->assertSame( $report, $this->store->get_report( $job_id, $user_id ) );
		$this->assertSame( '', get_user_meta( $user_id, OGSMI_Job_Store::ACTIVE_META, true ) );
		$this->assertSame( $job_id, get_user_meta( $user_id, OGSMI_Job_Store::LAST_REPORT_META, true ) );
	}

	public function test_job_lock_is_atomic_non_autoloaded_and_reclaims_stale_lock() {
		$job_id     = str_repeat( 'b', 32 );
		$option_key = OGSMI_Job_Store::LOCK_PREFIX . $job_id;

		$this->assertTrue( $this->store->acquire_lock( $job_id ) );
		$this->assertSame( 'no', $GLOBALS['ogsmi_job_store_test']['options'][ $option_key ]['autoload'] );
		$this->assertFalse( $this->store->acquire_lock( $job_id ) );

		$GLOBALS['ogsmi_job_store_test']['options'][ $option_key ]['value'] =
			( time() - 1 ) . ':' . str_repeat( 'c', 32 );
		$this->assertTrue( $this->store->acquire_lock( $job_id ) );
		$this->assertTrue( $this->store->release_lock( $job_id ) );
		$this->assertFalse( $this->store->acquire_lock( 'not-a-job-id' ) );
	}

	public function test_stale_reclamation_cannot_delete_a_concurrent_replacement() {
		$job_id     = str_repeat( 'd', 32 );
		$option_key = OGSMI_Job_Store::LOCK_PREFIX . $job_id;
		$stale      = ( time() - 1 ) . ':' . str_repeat( 'e', 32 );
		$fresh      = ( time() + OGSMI_Job_Store::LOCK_TTL ) . ':' . str_repeat( 'f', 32 );

		$GLOBALS['ogsmi_job_store_test']['options'][ $option_key ] = array(
			'value'    => $stale,
			'autoload' => false,
		);
		$GLOBALS['ogsmi_job_store_test']['replace_before_conditional_delete'] = $fresh;

		$this->assertFalse( $this->store->acquire_lock( $job_id ) );
		$this->assertSame( $fresh, get_option( $option_key ) );
	}

	public function test_atomic_insert_cannot_overwrite_a_concurrent_lock() {
		$job_id     = str_repeat( '0', 32 );
		$option_key = OGSMI_Job_Store::LOCK_PREFIX . $job_id;
		$fresh      = ( time() + OGSMI_Job_Store::LOCK_TTL ) . ':' . str_repeat( '9', 32 );

		$GLOBALS['ogsmi_job_store_test']['insert_before_next_lock_insert'] = $fresh;

		$this->assertFalse( $this->store->acquire_lock( $job_id ) );
		$this->assertSame( $fresh, get_option( $option_key ) );
		$this->assertFalse( $this->store->lock_storage_failed() );
	}

	public function test_lock_insert_database_failure_is_reported() {
		$job_id = str_repeat( '4', 32 );
		$GLOBALS['ogsmi_job_store_test']['fail_next_lock_insert'] = true;

		$this->assertFalse( $this->store->acquire_lock( $job_id ) );
		$this->assertTrue( $this->store->lock_storage_failed() );
	}

	public function test_lock_select_database_failure_is_reported() {
		$job_id     = str_repeat( '5', 32 );
		$option_key = OGSMI_Job_Store::LOCK_PREFIX . $job_id;
		$GLOBALS['ogsmi_job_store_test']['options'][ $option_key ] = array(
			'value'    => ( time() - 1 ) . ':' . str_repeat( '6', 32 ),
			'autoload' => 'no',
		);
		$GLOBALS['ogsmi_job_store_test']['fail_next_lock_select'] = true;

		$this->assertFalse( $this->store->acquire_lock( $job_id ) );
		$this->assertTrue( $this->store->lock_storage_failed() );
	}

	public function test_lock_refresh_database_failure_is_reported() {
		$job_id = str_repeat( '7', 32 );

		$this->assertTrue( $this->store->acquire_lock( $job_id ) );
		$GLOBALS['ogsmi_job_store_test']['fail_next_lock_update'] = true;

		$this->assertFalse( $this->store->refresh_lock( $job_id ) );
		$this->assertTrue( $this->store->lock_storage_failed() );
	}

	public function test_old_owner_cannot_release_a_replacement_lock() {
		$job_id     = str_repeat( '1', 32 );
		$option_key = OGSMI_Job_Store::LOCK_PREFIX . $job_id;

		$this->assertTrue( $this->store->acquire_lock( $job_id ) );
		$replacement = ( time() + OGSMI_Job_Store::LOCK_TTL ) . ':' . str_repeat( '2', 32 );
		$GLOBALS['ogsmi_job_store_test']['options'][ $option_key ]['value'] = $replacement;

		$this->assertFalse( $this->store->release_lock( $job_id ) );
		$this->assertSame( $replacement, get_option( $option_key ) );
	}

	public function test_expired_owner_cannot_refresh_or_release_a_reclaimed_lock() {
		$job_id     = str_repeat( '3', 32 );
		$option_key = OGSMI_Job_Store::LOCK_PREFIX . $job_id;
		$old_store  = $this->store;
		$new_store  = new OGSMI_Job_Store();

		$this->assertTrue( $old_store->acquire_lock( $job_id ) );
		$old_value = get_option( $option_key );
		$old_parts = explode( ':', $old_value, 2 );
		$GLOBALS['ogsmi_job_store_test']['options'][ $option_key ]['value'] =
			( time() - 1 ) . ':' . $old_parts[1];

		$this->assertTrue( $new_store->acquire_lock( $job_id ) );
		$new_value = get_option( $option_key );
		$this->assertNotSame( $old_value, $new_value );

		$this->assertFalse( $old_store->refresh_lock( $job_id ) );
		$this->assertFalse( $old_store->release_lock( $job_id ) );
		$this->assertSame( $new_value, get_option( $option_key ) );

		$this->assertTrue( $new_store->refresh_lock( $job_id ) );
		$this->assertNotSame( $new_value, get_option( $option_key ) );
		$this->assertSame( 'no', $GLOBALS['ogsmi_job_store_test']['options'][ $option_key ]['autoload'] );
		$this->assertTrue( $new_store->release_lock( $job_id ) );
	}
}
