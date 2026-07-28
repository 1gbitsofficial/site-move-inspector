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
define( 'OGSMI_VERSION', '1.0.0' );

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
	function get_theme_root() {
		return WP_CONTENT_DIR . '/themes';
	}
}

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	function wp_get_upload_dir() {
		return array(
			'basedir' => WP_CONTENT_DIR . '/uploads',
			'error'   => false,
		);
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ogsmi-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-report-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-redactor.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-exporter.php';
require_once dirname( __DIR__ ) . '/includes/class-ogsmi-inspector.php';
