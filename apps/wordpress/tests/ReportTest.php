<?php
/**
 * Report and redaction tests.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

final class OGSMI_Report_Test extends TestCase {

	public function test_critical_result_has_precedence() {
		$report = OGSMI_Report_Builder::create( array() );
		OGSMI_Report_Builder::add_check(
			$report,
			'test',
			'Test',
			array(
				'id'     => 'warning',
				'status' => 'warning',
			)
		);
		OGSMI_Report_Builder::add_check(
			$report,
			'test',
			'Test',
			array(
				'id'     => 'critical',
				'status' => 'critical',
			)
		);

		$summary = OGSMI_Report_Builder::summarize( $report );
		$this->assertSame( 'high_risk', $summary['overall'] );
		$this->assertSame( 1, $summary['counts']['critical'] );
	}

	public function test_partial_report_cannot_be_green() {
		$report = OGSMI_Report_Builder::create( array() );
		OGSMI_Report_Builder::add_check(
			$report,
			'test',
			'Test',
			array(
				'id'     => 'pass',
				'status' => 'pass',
			)
		);
		OGSMI_Report_Builder::mark_partial( $report, 'Safety limit reached.' );

		$this->assertSame( 'review_recommended', OGSMI_Report_Builder::summarize( $report )['overall'] );
	}

	public function test_redactor_masks_paths_and_custom_tables() {
		$report = OGSMI_Report_Builder::create(
			array(
				'provided'          => false,
				'php_version'       => '',
				'database_engine'   => '',
				'database_version'  => '',
				'disk_bytes'        => 0,
				'multisite_support' => 'unknown',
			)
		);
		$report['generated_at'] = '2026-07-27T00:00:00+00:00';
		$report['summary']      = OGSMI_Report_Builder::summarize( $report );
		$report['inventory']['files'] = array(
			'top_files' => array(
				array(
					'path'      => 'wp-content/uploads/customer@example.com.jpg',
					'bytes'     => 1234,
					'extension' => 'jpg',
					'category'  => 'uploads',
				),
			),
		);
		$report['inventory']['database'] = array(
			'available'   => true,
			'table_count' => 1,
			'total_bytes' => 999,
			'top_tables'  => array(
				array(
					'name'      => 'wp_customer_private_records',
					'bytes'     => 999,
					'rows'      => 5,
					'engine'    => 'innodb',
					'collation' => 'utf8mb4_unicode_ci',
				),
			),
		);

		$json = OGSMI_Exporter::to_json( $report );

		$this->assertStringNotContainsString( 'customer@example.com', $json );
		$this->assertStringNotContainsString( 'wp_customer_private_records', $json );
		$this->assertStringContainsString( 'file_01', $json );
		$this->assertStringContainsString( 'custom_table_01', $json );
	}
}
