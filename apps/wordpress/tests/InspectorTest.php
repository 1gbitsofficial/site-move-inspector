<?php
/**
 * Inspector edge-case tests.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

final class OGSMI_Inspector_Test extends TestCase {

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
}
