<?php
/**
 * WordPress filesystem location discovery.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves scan locations through WordPress directory APIs.
 */
final class OGSMI_Locations {

	/**
	 * Return the filesystem root represented by the site's home URL.
	 *
	 * @return string
	 */
	public static function wordpress_root() {
		if ( ! function_exists( 'get_home_path' ) ) {
			/*
			 * get_home_path() is an admin-only API. WordPress documents this
			 * core include as the way to make it available in REST requests.
			 */
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$root = self::canonical_directory( get_home_path() );
		if ( self::is_safe_scan_root( $root ) ) {
			return $root;
		}

		/*
		 * get_home_path() can resolve to the filesystem root when WordPress
		 * lives in a subdirectory but REST is routed through the home index.
		 * In that case, use the actual front-controller directory only when
		 * the Home/Site URL relationship maps it back to WordPress core.
		 */
		$root = self::request_front_controller_directory();

		return self::is_safe_fallback_root( $root ) ? $root : '';
	}

	/**
	 * Return this plugin's directory.
	 *
	 * @return string
	 */
	public static function plugin_directory() {
		return self::canonical_directory( plugin_dir_path( OGSMI_PLUGIN_FILE ) );
	}

	/**
	 * Return the directory that contains installed standard plugins.
	 *
	 * The submitted plugin is distributed in its own directory. Using its
	 * main file keeps this lookup compatible with renamed plugin directories.
	 *
	 * @return string
	 */
	public static function plugins_directory() {
		$plugin_directory = self::installed_plugin_directory();
		$plugin_basename  = OGSMI_Utils::normalize_path( plugin_basename( OGSMI_PLUGIN_FILE ) );

		if ( false === strpos( $plugin_basename, '/' ) ) {
			return $plugin_directory;
		}

		return self::canonical_directory( dirname( $plugin_directory ) );
	}

	/**
	 * Return the WordPress core directory relative to the resolved home root.
	 *
	 * @return string
	 */
	public static function wordpress_core_directory() {
		return self::resolve_wordpress_core_directory( self::wordpress_root() );
	}

	/**
	 * Return the registered themes directory.
	 *
	 * @return string
	 */
	public static function themes_directory() {
		return self::canonical_directory( get_theme_root() );
	}

	/**
	 * Return every filesystem root represented by WordPress' theme inventory.
	 *
	 * WordPress supports additional roots registered with
	 * register_theme_directory(). WP_Theme exposes the resolved root for each
	 * installed theme without relying on content-directory constants.
	 *
	 * @return string[]
	 */
	public static function theme_directories() {
		$directories = array( self::themes_directory() );
		$themes      = wp_get_themes(
			array(
				'allowed' => null,
				'errors'  => null,
			)
		);

		foreach ( $themes as $theme ) {
			if ( ! is_object( $theme ) || ! method_exists( $theme, 'get_theme_root' ) ) {
				continue;
			}

			$directories[] = self::canonical_directory( $theme->get_theme_root() );
		}

		$directories = array_filter( array_unique( $directories ) );

		return array_values( $directories );
	}

	/**
	 * Return the configured base uploads directory without creating it.
	 *
	 * @return string
	 */
	public static function uploads_directory() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return self::canonical_directory( $uploads['basedir'] );
	}

	/**
	 * Return the locations used by read-only filesystem checks.
	 *
	 * @return array
	 */
	public static function filesystem_snapshot() {
		$root = self::wordpress_root();

		return array(
			'root'        => $root,
			'core'        => self::resolve_wordpress_core_directory( $root ),
			'plugins'     => self::plugins_directory(),
			'themes'      => self::themes_directory(),
			'theme_roots' => self::theme_directories(),
			'uploads'     => self::uploads_directory(),
		);
	}

	/**
	 * Load WordPress' plugin inventory functions when called over REST.
	 */
	public static function load_plugin_functions() {
		if ( function_exists( 'get_plugins' ) && function_exists( 'get_dropins' ) ) {
			return;
		}

		/*
		 * These inventory APIs live in an admin-only core file and are not
		 * loaded automatically during a REST request.
		 */
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * Normalize a directory, preferring its canonical existing location.
	 *
	 * @param string $directory Candidate directory.
	 * @return string
	 */
	private static function canonical_directory( $directory ) {
		$directory = (string) $directory;
		if ( '' === $directory ) {
			return '';
		}

		$canonical = realpath( $directory );

		return OGSMI_Utils::normalize_path( false === $canonical ? $directory : $canonical );
	}

	/**
	 * Resolve the installed plugin directory from its WordPress-generated URL.
	 *
	 * This preserves the configured plugins parent when this plugin directory
	 * is a symbolic link whose target is elsewhere on the filesystem.
	 *
	 * @return string
	 */
	private static function installed_plugin_directory() {
		$mapped = self::map_url_directory_to_root(
			self::wordpress_root(),
			home_url( '/' ),
			OGSMI_PLUGIN_URL
		);

		if ( '' !== $mapped && ( is_dir( $mapped ) || is_link( $mapped ) ) ) {
			return $mapped;
		}

		return self::plugin_directory();
	}

	/**
	 * Resolve the core directory from the public home and WordPress URLs.
	 *
	 * @param string $root Safe home filesystem root.
	 * @return string
	 */
	private static function resolve_wordpress_core_directory( $root ) {
		if ( '' === $root ) {
			return '';
		}

		$core = self::map_url_directory_to_root(
			$root,
			home_url( '/' ),
			site_url( '/' ),
			false
		);
		if ( self::is_wordpress_core_directory( $core ) ) {
			return self::canonical_directory( $core );
		}

		return self::is_wordpress_core_directory( $root ) ? $root : '';
	}

	/**
	 * Map a URL path beneath the Home URL onto the safe Home root.
	 *
	 * @param string $root Safe home filesystem root.
	 * @param string $base_url Home URL.
	 * @param string $target_url Directory URL to map.
	 * @param bool   $require_same_host Whether both URLs must use the same host.
	 * @return string
	 */
	private static function map_url_directory_to_root( $root, $base_url, $target_url, $require_same_host = true ) {
		if ( '' === $root ) {
			return '';
		}

		$base_host   = strtolower( (string) wp_parse_url( $base_url, PHP_URL_HOST ) );
		$target_host = strtolower( (string) wp_parse_url( $target_url, PHP_URL_HOST ) );
		if (
			$require_same_host
			&& ( '' === $base_host || $base_host !== $target_host )
		) {
			return '';
		}

		$base_path   = '/' . trim( rawurldecode( (string) wp_parse_url( $base_url, PHP_URL_PATH ) ), '/' );
		$target_path = '/' . trim( rawurldecode( (string) wp_parse_url( $target_url, PHP_URL_PATH ) ), '/' );
		$base_path   = '/' === $base_path ? '' : $base_path;

		if (
			'' !== $base_path
			&& $target_path !== $base_path
			&& 0 !== strpos( $target_path, $base_path . '/' )
		) {
			return '';
		}

		$relative = '' === $base_path
			? ltrim( $target_path, '/' )
			: ltrim( substr( $target_path, strlen( $base_path ) ), '/' );

		return OGSMI_Utils::normalize_path(
			rtrim( $root, '/' ) . ( '' === $relative ? '' : '/' . $relative )
		);
	}

	/**
	 * Return whether a directory contains the expected WordPress core folders.
	 *
	 * @param string $directory Candidate core directory.
	 * @return bool
	 */
	private static function is_wordpress_core_directory( $directory ) {
		return '' !== $directory
			&& is_dir( $directory . '/wp-admin' )
			&& is_dir( $directory . '/wp-includes' )
			&& is_file( $directory . '/wp-load.php' );
	}

	/**
	 * Return whether a directory is a bounded site root safe to scan.
	 *
	 * @param string $directory Candidate home directory.
	 * @return bool
	 */
	private static function is_safe_scan_root( $directory ) {
		return '' !== $directory
			&& ! self::is_filesystem_root( $directory )
			&& is_dir( $directory )
			&& is_file( rtrim( $directory, '/' ) . '/index.php' );
	}

	/**
	 * Return whether a fallback root maps to the loaded site's core layout.
	 *
	 * @param string $directory Candidate front-controller directory.
	 * @return bool
	 */
	private static function is_safe_fallback_root( $directory ) {
		return self::is_safe_scan_root( $directory )
			&& '' !== self::resolve_wordpress_core_directory( $directory );
	}

	/**
	 * Return whether a path is a local volume or network-share root.
	 *
	 * @param string $directory Normalized directory.
	 * @return bool
	 */
	private static function is_filesystem_root( $directory ) {
		$directory = OGSMI_Utils::normalize_path( $directory );

		return '/' === $directory
			|| 1 === preg_match( '/^[A-Za-z]:\/?$/', $directory )
			|| 1 === preg_match( '#^//[^/]+/[^/]+$#', $directory );
	}

	/**
	 * Resolve the web request's WordPress front-controller directory.
	 *
	 * @return string
	 */
	private static function request_front_controller_directory() {
		if ( empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
			return '';
		}

		$script = realpath( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) );
		if ( false === $script || ! is_file( $script ) || 'index.php' !== strtolower( basename( $script ) ) ) {
			return '';
		}

		return self::canonical_directory( dirname( $script ) );
	}
}
