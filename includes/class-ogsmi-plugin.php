<?php
/**
 * Plugin bootstrap.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the plugin's admin and REST components.
 */
final class OGSMI_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var OGSMI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Job storage.
	 *
	 * @var OGSMI_Job_Store
	 */
	private $job_store;

	/**
	 * Return the singleton.
	 *
	 * @return OGSMI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register plugin components.
	 */
	private function __construct() {
		$this->job_store = new OGSMI_Job_Store();

		$rest  = new OGSMI_REST_Controller( $this->job_store );
		$admin = new OGSMI_Admin_Page( $this->job_store );

		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		add_action( 'admin_menu', array( $admin, 'register_site_menu' ) );
		add_action( 'network_admin_menu', array( $admin, 'register_network_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		add_action( 'admin_post_ogsmi_export_report', array( $admin, 'export_report' ) );
	}
}
