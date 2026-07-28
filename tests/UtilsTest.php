<?php
/**
 * Utility tests.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

final class OGSMI_Utils_Test extends TestCase {

	public function test_size_conversion() {
		$this->assertSame( 1048576, OGSMI_Utils::size_to_bytes( '1M' ) );
		$this->assertSame( 2147483648, OGSMI_Utils::size_to_bytes( '2G' ) );
		$this->assertSame( 0, OGSMI_Utils::size_to_bytes( '-1' ) );
	}

	public function test_path_boundary_rejects_similar_sibling() {
		$root = wp_normalize_path( ABSPATH );

		$this->assertTrue( OGSMI_Utils::path_is_within( $root . 'wp-content/file.php', $root ) );
		$this->assertFalse( OGSMI_Utils::path_is_within( rtrim( $root, '/' ) . '-copy/file.php', $root ) );
	}

	public function test_relative_path_never_returns_parent_segments() {
		$root = wp_normalize_path( ABSPATH );

		$this->assertSame( 'wp-content/file.php', OGSMI_Utils::relative_path( $root . 'wp-content/file.php', $root ) );
		$this->assertSame( '', OGSMI_Utils::relative_path( dirname( $root ) . '/secret.php', $root ) );
	}

	public function test_version_sanitizer() {
		$this->assertSame( '8.3.1', OGSMI_Utils::sanitize_version( '8.3.1' ) );
		$this->assertSame( '', OGSMI_Utils::sanitize_version( '8.3<script>' ) );
		$this->assertSame( '', OGSMI_Utils::sanitize_version( 'not a version' ) );
	}

	public function test_job_id_validation() {
		$this->assertTrue( OGSMI_Utils::is_valid_job_id( str_repeat( 'a', 32 ) ) );
		$this->assertFalse( OGSMI_Utils::is_valid_job_id( '../' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( OGSMI_Utils::is_valid_job_id( str_repeat( 'z', 32 ) ) );
	}
}
