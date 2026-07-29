<?php
/**
 * Filesystem location resolver tests.
 *
 * @package OneGbits_Site_Move_Inspector
 */

use PHPUnit\Framework\TestCase;

final class OGSMI_Locations_Test extends TestCase {

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

	public function test_snapshot_uses_wordpress_directory_apis() {
		$locations = OGSMI_Locations::filesystem_snapshot();

		$this->assertSame( OGSMI_Utils::normalize_path( ABSPATH ), $locations['root'] );
		$this->assertSame( '', $locations['core'] );
		$this->assertSame( OGSMI_Utils::normalize_path( WP_PLUGIN_DIR ), $locations['plugins'] );
		$this->assertSame( OGSMI_Utils::normalize_path( get_theme_root() ), $locations['themes'] );
		$this->assertSame( array( OGSMI_Utils::normalize_path( get_theme_root() ) ), $locations['theme_roots'] );
		$this->assertSame( OGSMI_Utils::normalize_path( $GLOBALS['ogsmi_test_upload_dir'] ), $locations['uploads'] );
		$this->assertArrayNotHasKey( 'content', $locations );
		$this->assertArrayNotHasKey( 'mu_plugins', $locations );
	}

	public function test_external_uploads_are_not_rewritten_to_a_default_path() {
		$GLOBALS['ogsmi_test_upload_dir'] = dirname( ABSPATH ) . '/external-uploads';

		$locations = OGSMI_Locations::filesystem_snapshot();

		$this->assertSame(
			OGSMI_Utils::normalize_path( $GLOBALS['ogsmi_test_upload_dir'] ),
			$locations['uploads']
		);
		$this->assertFalse(
			OGSMI_Utils::path_is_within( $locations['uploads'], $locations['root'] )
		);
	}

	public function test_plugin_directory_is_based_on_the_main_plugin_file() {
		$this->assertSame(
			OGSMI_Utils::normalize_path( OGSMI_PLUGIN_DIR ),
			OGSMI_Locations::plugin_directory()
		);
	}

	public function test_filesystem_root_from_home_api_falls_back_to_front_controller() {
		$GLOBALS['ogsmi_test_home_path'] = DIRECTORY_SEPARATOR;
		$GLOBALS['ogsmi_test_site_url']  = 'https://example.test/wp/';

		$this->assertSame(
			OGSMI_Utils::normalize_path( ABSPATH ),
			OGSMI_Locations::wordpress_root()
		);
	}

	public function test_unsafe_home_root_is_rejected_without_a_valid_front_controller() {
		$GLOBALS['ogsmi_test_home_path'] = DIRECTORY_SEPARATOR;
		$_SERVER['SCRIPT_FILENAME']      = addslashes( ABSPATH . 'not-index.php' );

		$this->assertSame( '', OGSMI_Locations::wordpress_root() );
	}

	public function test_front_controller_fallback_must_map_to_wordpress_core() {
		$GLOBALS['ogsmi_test_home_path'] = DIRECTORY_SEPARATOR;

		$this->assertSame( '', OGSMI_Locations::wordpress_root() );
	}

	public function test_core_directory_is_mapped_from_site_url_in_subdirectory_install() {
		$GLOBALS['ogsmi_test_site_url'] = 'https://example.test/wp/';

		$this->assertSame(
			OGSMI_Utils::normalize_path( ABSPATH . 'wp' ),
			OGSMI_Locations::wordpress_core_directory()
		);
	}

	public function test_core_mapping_allows_distinct_home_and_site_hosts() {
		$GLOBALS['ogsmi_test_site_url'] = 'https://admin.example.test/wp/';

		$this->assertSame(
			OGSMI_Utils::normalize_path( ABSPATH . 'wp' ),
			OGSMI_Locations::wordpress_core_directory()
		);
	}

	public function test_registered_theme_directories_are_returned_without_duplicates() {
		$GLOBALS['ogsmi_test_theme_roots'] = array(
			WP_CONTENT_DIR . '/themes',
			ABSPATH . 'custom-themes',
			ABSPATH . 'custom-themes',
		);

		$this->assertSame(
			array(
				OGSMI_Utils::normalize_path( WP_CONTENT_DIR . '/themes' ),
				OGSMI_Utils::normalize_path( ABSPATH . 'custom-themes' ),
			),
			OGSMI_Locations::theme_directories()
		);
	}
}
