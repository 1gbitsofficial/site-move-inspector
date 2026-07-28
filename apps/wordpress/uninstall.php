<?php
/**
 * Remove short-lived scan references created by the plugin.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ogsmi_user_ids = get_users(
	array(
		'fields'     => 'ids',
		'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs only during explicit uninstall.
			'relation' => 'OR',
			array(
				'key'     => '_ogsmi_active_job',
				'compare' => 'EXISTS',
			),
			array(
				'key'     => '_ogsmi_last_report',
				'compare' => 'EXISTS',
			),
		),
	)
);

foreach ( $ogsmi_user_ids as $ogsmi_user_id ) {
	$ogsmi_active_job = (string) get_user_meta( $ogsmi_user_id, '_ogsmi_active_job', true );
	$ogsmi_last_job   = (string) get_user_meta( $ogsmi_user_id, '_ogsmi_last_report', true );

	if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $ogsmi_active_job ) ) {
		delete_transient( 'ogsmi_job_' . $ogsmi_active_job );
		delete_option( 'ogsmi_job_lock_' . $ogsmi_active_job );
	}
	if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $ogsmi_last_job ) ) {
		delete_transient( 'ogsmi_report_' . $ogsmi_last_job );
	}

	delete_user_meta( $ogsmi_user_id, '_ogsmi_active_job' );
	delete_user_meta( $ogsmi_user_id, '_ogsmi_last_report' );
	delete_option( 'ogsmi_job_lock_' . md5( 'start:' . absint( $ogsmi_user_id ) ) );
}

unset( $ogsmi_user_ids, $ogsmi_user_id, $ogsmi_active_job, $ogsmi_last_job );
