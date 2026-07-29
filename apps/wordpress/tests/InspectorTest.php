<?php
/**
 * Inspector edge-case tests.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

final class OGSMI_Inspector_Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ogsmi_test_home_path']  = ABSPATH;
		$GLOBALS['ogsmi_test_home_url']   = 'https://example.test/';
		$GLOBALS['ogsmi_test_site_url']   = 'https://example.test/';
		$GLOBALS['ogsmi_test_theme_root'] = WP_CONTENT_DIR . '/themes';
		$GLOBALS['ogsmi_test_theme_roots'] = array( WP_CONTENT_DIR . '/themes' );
		$GLOBALS['ogsmi_test_upload_dir'] = WP_CONTENT_DIR . '/uploads';
		$GLOBALS['ogsmi_test_dropins']    = array();
		$_SERVER['SCRIPT_FILENAME']       = addslashes( ABSPATH . 'index.php' );
	}

	public function test_destination_disk_is_unknown_when_database_size_is_unavailable() {
		$report = OGSMI_Report_Builder::create(
			array(
				'provided'          => true,
				'php_version'       => '',
				'database_engine'   => '',
				'database_version'  => '',
				'disk_bytes'        => 10 * 1024 * 1024 * 1024,
				'multisite_support' => 'unknown',
			)
		);
		$report['inventory']['database'] = array(
			'available'   => false,
			'total_bytes' => 0,
		);

		$inspector = new OGSMI_Inspector();
		$method    = new ReflectionMethod( OGSMI_Inspector::class, 'inspect_destination_disk' );
		$method->setAccessible( true );
		$arguments = array( &$report, 100 * 1024 * 1024 );
		$method->invokeArgs( $inspector, $arguments );

		$checks = $report['sections']['destination']['checks'];
		$this->assertCount( 1, $checks );
		$this->assertSame( OGSMI_Report_Builder::STATUS_UNKNOWN, $checks[0]['status'] );
		$this->assertStringContainsString( 'Database size is unavailable', $checks[0]['message'] );
	}

	public function test_destination_disk_can_pass_with_complete_database_metadata() {
		$report = OGSMI_Report_Builder::create(
			array(
				'provided'          => true,
				'php_version'       => '',
				'database_engine'   => '',
				'database_version'  => '',
				'disk_bytes'        => 10 * 1024 * 1024 * 1024,
				'multisite_support' => 'unknown',
			)
		);
		$report['inventory']['database'] = array(
			'available'   => true,
			'total_bytes' => 50 * 1024 * 1024,
		);

		$inspector = new OGSMI_Inspector();
		$method    = new ReflectionMethod( OGSMI_Inspector::class, 'inspect_destination_disk' );
		$method->setAccessible( true );
		$arguments = array( &$report, 100 * 1024 * 1024 );
		$method->invokeArgs( $inspector, $arguments );

		$checks = $report['sections']['destination']['checks'];
		$this->assertSame( OGSMI_Report_Builder::STATUS_PASS, $checks[0]['status'] );
	}

	public function test_path_layout_reports_api_resolved_external_uploads() {
		$GLOBALS['ogsmi_test_upload_dir'] = dirname( ABSPATH ) . '/external-uploads';

		$inspector = new OGSMI_Inspector();
		$method    = new ReflectionMethod( OGSMI_Inspector::class, 'inspect_path_layout' );
		$method->setAccessible( true );
		$paths = $method->invoke( $inspector );

		$this->assertTrue( $paths['plugins_within_root'] );
		$this->assertTrue( $paths['themes_within_root'] );
		$this->assertFalse( $paths['uploads_within_root'] );
	}

	public function test_path_layout_reports_registered_external_theme_roots() {
		$GLOBALS['ogsmi_test_theme_roots'] = array(
			WP_CONTENT_DIR . '/themes',
			dirname( ABSPATH ) . '/external-themes',
		);

		$inspector = new OGSMI_Inspector();
		$method    = new ReflectionMethod( OGSMI_Inspector::class, 'inspect_path_layout' );
		$method->setAccessible( true );
		$paths = $method->invoke( $inspector );

		$this->assertFalse( $paths['themes_within_root'] );
	}

	public function test_dropins_are_discovered_through_wordpress_inventory_api() {
		$GLOBALS['ogsmi_test_dropins'] = array(
			'advanced-cache.php' => array( 'Name' => 'Advanced cache' ),
			'maintenance.php'    => array( 'Name' => 'Maintenance' ),
			'object-cache.php'   => array( 'Name' => 'Object cache' ),
		);

		$inspector = new OGSMI_Inspector();
		$method    = new ReflectionMethod( OGSMI_Inspector::class, 'detect_dropins' );
		$method->setAccessible( true );

		$this->assertSame(
			array( 'advanced-cache.php', 'object-cache.php' ),
			$method->invoke( $inspector )
		);
	}
}
