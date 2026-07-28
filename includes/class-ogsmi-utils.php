<?php
/**
 * Shared utility methods.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small, side-effect-free helpers used by scanners and renderers.
 */
final class OGSMI_Utils {

	/**
	 * Convert a PHP size value to bytes.
	 *
	 * WordPress' wp_convert_hr_to_bytes() handles the values returned by
	 * php.ini, including -1 for unlimited. This wrapper normalizes failures.
	 *
	 * @param mixed $value Human-readable size.
	 * @return int
	 */
	public static function size_to_bytes( $value ) {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return max( 0, (int) wp_convert_hr_to_bytes( $value ) );
		}

		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}

		return max( 0, (int) round( $number ) );
	}

	/**
	 * Format bytes without leaking warnings for invalid input.
	 *
	 * @param mixed $bytes Byte count.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );

		if ( function_exists( 'size_format' ) ) {
			return size_format( $bytes, 1 );
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$index = 0;
		$value = (float) $bytes;
		$count = count( $units );

		while ( $value >= 1024 && $index < $count - 1 ) {
			$value /= 1024;
			++$index;
		}

		return number_format( $value, 1 ) . ' ' . $units[ $index ];
	}

	/**
	 * Normalize a filesystem path for comparisons.
	 *
	 * @param string $path Filesystem path.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		$path = function_exists( 'wp_normalize_path' )
			? wp_normalize_path( (string) $path )
			: str_replace( '\\', '/', (string) $path );

		return rtrim( $path, '/' );
	}

	/**
	 * Check whether a path is the root or one of its descendants.
	 *
	 * @param string $path Candidate path.
	 * @param string $root Canonical root.
	 * @return bool
	 */
	public static function path_is_within( $path, $root ) {
		$path = self::normalize_path( $path );
		$root = self::normalize_path( $root );

		if ( '' === $path || '' === $root ) {
			return false;
		}

		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}

		return $path === $root || 0 === strpos( $path, $root . '/' );
	}

	/**
	 * Return a root-relative path, or an empty string for the root itself.
	 *
	 * @param string $path Canonical path.
	 * @param string $root Canonical root.
	 * @return string
	 */
	public static function relative_path( $path, $root ) {
		$path = self::normalize_path( $path );
		$root = self::normalize_path( $root );

		if ( ! self::path_is_within( $path, $root ) ) {
			return '';
		}

		if ( strlen( $path ) === strlen( $root ) ) {
			return '';
		}

		return ltrim( substr( $path, strlen( $root ) ), '/' );
	}

	/**
	 * Sanitize a version-like value.
	 *
	 * @param mixed $version Submitted version.
	 * @return string
	 */
	public static function sanitize_version( $version ) {
		$version = trim( (string) $version );

		if ( '' === $version || ! preg_match( '/^\d{1,3}(?:\.\d{1,3}){0,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) {
			return '';
		}

		return $version;
	}

	/**
	 * Create a random, URL-safe job identifier.
	 *
	 * @return string
	 */
	public static function random_job_id() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $throwable ) {
			$seed = wp_generate_password( 64, true, true ) . uniqid( '', true );

			return substr( hash( 'sha256', $seed ), 0, 32 );
		}
	}

	/**
	 * Validate a job identifier.
	 *
	 * @param mixed $job_id Candidate job identifier.
	 * @return bool
	 */
	public static function is_valid_job_id( $job_id ) {
		return is_string( $job_id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $job_id );
	}

	/**
	 * Get the host portion of a URL without exposing the full URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}
}
