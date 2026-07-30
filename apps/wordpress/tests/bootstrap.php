<?php
/**
 * Lightweight test bootstrap for side-effect-free classes.
 *
 * @package OneGbits_Site_Move_Inspector
 */

$ogsmi_fixture_root = __DIR__ . '/fixtures/site/';

define( 'ABSPATH', $ogsmi_fixture_root );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'OGSMI_VERSION', '1.0.1' );
define( 'OGSMI_PLUGIN_FILE', WP_PLUGIN_DIR . '/1gbits-site-move-inspector/1gbits-site-move-inspector.php' );
define( 'OGSMI_PLUGIN_DIR', dirname( OGSMI_PLUGIN_FILE ) . '/' );
define( 'OGSMI_PLUGIN_URL', 'https://example.test/wp-content/plugins/1gbits-site-move-inspector/' );

$GLOBALS['ogsmi_test_home_path']  = ABSPATH;
$GLOBALS['ogsmi_test_home_url']   = 'https://example.test/';
$GLOBALS['ogsmi_test_site_url']   = 'https://example.test/';
$GLOBALS['ogsmi_test_theme_root'] = WP_CONTENT_DIR . '/themes';
$GLOBALS['ogsmi_test_theme_roots'] = array( WP_CONTENT_DIR . '/themes' );
$GLOBALS['ogsmi_test_upload_dir'] = WP_CONTENT_DIR . '/uploads';
$GLOBALS['ogsmi_test_dropins']    = array();
$_SERVER['SCRIPT_FILENAME']       = addslashes( ABSPATH . 'index.php' );

final class OGSMI_Test_Theme {

	private $root;

	public function __construct( $root ) {
		$this->root = $root;
	}

	public function get_theme_root() {
		return $this->root;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $text ) ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return rtrim( $GLOBALS['ogsmi_test_home_url'], '/' )
			. ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return rtrim( $GLOBALS['ogsmi_test_site_url'], '/' )
			. ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		$file        = wp_normalize_path( $file );
		$plugin_root = rtrim( wp_normalize_path( WP_PLUGIN_DIR ), '/' );

		return ltrim( substr( $file, strlen( $plugin_root ) ), '/' );
	}
}

if ( ! function_exists( 'wp_convert_hr_to_bytes' ) ) {
	function wp_convert_hr_to_bytes( $value ) {
		$value = trim( (string) $value );
		$unit  = strtolower( substr( $value, -1 ) );
		$bytes = (float) $value;
		if ( 'g' === $unit ) {
			$bytes *= 1024;
			$unit   = 'm';
		}
		if ( 'm' === $unit ) {
			$bytes *= 1024;
			$unit   = 'k';
		}
		if ( 'k' === $unit ) {
			$bytes *= 1024;
		}
		return (int) $bytes;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$units = array( 'B', 'KB', 'MB', 'GB' );
		$index = 0;
		$value = (float) $bytes;
		while ( $value >= 1024 && $index < count( $units ) - 1 ) {
			$value /= 1024;
			++$index;
		}
		return number_format( $value, $decimals ) . ' ' . $units[ $index ];
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length ) {
		return str_repeat( 'a', (int) $length );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return false;
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root( $stylesheet_or_template = '' ) {
		return $GLOBALS['ogsmi_test_theme_root'];
	}
}

if ( ! function_exists( 'wp_get_themes' ) ) {
	function wp_get_themes( $args = array() ) {
		$themes = array();
		foreach ( $GLOBALS['ogsmi_test_theme_roots'] as $index => $root ) {
			$themes[ 'fixture-' . $index ] = new OGSMI_Test_Theme( $root );
		}
		return $themes;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true ) {
		return array(
			'basedir' => $GLOBALS['ogsmi_test_upload_dir'],
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'get_home_path' ) ) {
	function get_home_path() {
		return $GLOBALS['ogsmi_test_home_path'];
	}
}

if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins() {
		return array();
	}
}

if ( ! function_exists( 'get_dropins' ) ) {
	function get_dropins() {
		return $GLOBALS['ogsmi_test_dropins'];
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ogsmi-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-locations.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-report-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-redactor.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-exporter.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-inspector.php';
