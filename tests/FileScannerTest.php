<?php
/**
 * Filesystem scanner tests.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

final class OGSMI_File_Scanner_Test extends TestCase {

	public function test_scanner_stays_inside_fixture_and_categorizes_files() {
		$scanner = new OGSMI_File_Scanner();
		$state   = $scanner->create_state();
		$guard   = 0;

		while ( empty( $state['completed'] ) && $guard < 50 ) {
			$state = $scanner->step( $state );
			++$guard;
		}

		$summary    = $scanner->summarize( $state );
		$categories = array();
		foreach ( $summary['categories'] as $category ) {
			$categories[ $category['id'] ] = $category;
		}

		$this->assertTrue( $state['completed'] );
		$this->assertFalse( $summary['partial'] );
		$this->assertGreaterThanOrEqual( 4, $summary['file_count'] );
		$this->assertGreaterThanOrEqual( 1, $categories['plugins']['file_count'] );
		$this->assertGreaterThanOrEqual( 1, $categories['themes']['file_count'] );
		$this->assertGreaterThanOrEqual( 1, $categories['uploads']['file_count'] );

		foreach ( $summary['top_files'] as $file ) {
			$this->assertStringNotContainsString( '..', $file['path'] );
			$this->assertStringNotContainsString( wp_normalize_path( dirname( ABSPATH ) ), $file['path'] );
		}
	}
}
