<?php
/**
 * WordPress migration-readiness checks.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects read-only environment metadata and builds check results.
 */
final class OGSMI_Inspector {

	const NETWORK_SITE_LIMIT = 250;

	/**
	 * Start a report with all non-filesystem checks.
	 *
	 * @param array $destination Sanitized destination profile.
	 * @param bool  $run_self_test Whether to request this site's own URLs.
	 * @return array
	 */
	public function inspect_initial( array $destination, $run_self_test ) {
		$report = OGSMI_Report_Builder::create( $destination );

		$steps = array(
			array(
				'id'      => 'software_inventory',
				'section' => 'environment',
				'title'   => __( 'Environment', '1gbits-site-move-inspector' ),
				'label'   => __( 'Software inventory', '1gbits-site-move-inspector' ),
				'run'     => function () use ( &$report ) {
					$this->inspect_software( $report );
				},
			),
			array(
				'id'      => 'environment_checks',
				'section' => 'environment',
				'title'   => __( 'Environment', '1gbits-site-move-inspector' ),
				'label'   => __( 'Environment checks', '1gbits-site-move-inspector' ),
				'run'     => function () use ( &$report ) {
					$this->inspect_environment( $report );
				},
			),
			array(
				'id'      => 'configuration_checks',
				'section' => 'configuration',
				'title'   => __( 'Site configuration', '1gbits-site-move-inspector' ),
				'label'   => __( 'Configuration checks', '1gbits-site-move-inspector' ),
				'run'     => function () use ( &$report ) {
					$this->inspect_configuration( $report );
				},
			),
			array(
				'id'      => 'database_checks',
				'section' => 'database',
				'title'   => __( 'Database', '1gbits-site-move-inspector' ),
				'label'   => __( 'Database checks', '1gbits-site-move-inspector' ),
				'run'     => function () use ( &$report ) {
					$this->inspect_database( $report );
				},
			),
			array(
				'id'      => 'destination_checks',
				'section' => 'destination',
				'title'   => __( 'Destination comparison', '1gbits-site-move-inspector' ),
				'label'   => __( 'Destination checks', '1gbits-site-move-inspector' ),
				'run'     => function () use ( &$report ) {
					$this->inspect_destination_software( $report );
				},
			),
			array(
				'id'      => 'self_connection_check',
				'section' => 'reliability',
				'title'   => __( 'Background jobs', '1gbits-site-move-inspector' ),
				'label'   => __( 'Self-connection check', '1gbits-site-move-inspector' ),
				'run'     => function () use ( &$report, $run_self_test ) {
					$this->inspect_self_connection( $report, (bool) $run_self_test );
				},
			),
		);

		foreach ( $steps as $step ) {
			try {
				$step['run']();
			} catch ( Throwable $throwable ) {
				OGSMI_Report_Builder::mark_partial(
					$report,
					__( 'One or more metadata checks could not be completed.', '1gbits-site-move-inspector' )
				);
				OGSMI_Report_Builder::add_check(
					$report,
					$step['section'],
					$step['title'],
					array(
						'id'             => $step['id'],
						'status'         => OGSMI_Report_Builder::STATUS_UNKNOWN,
						'label'          => $step['label'],
						'value'          => __( 'Unavailable', '1gbits-site-move-inspector' ),
						'message'        => __( 'This check failed without exposing technical details.', '1gbits-site-move-inspector' ),
						'recommendation' => __( 'Review the server PHP log or ask the current host for this information.', '1gbits-site-move-inspector' ),
					)
				);
			}
		}

		return $report;
	}

	/**
	 * Add filesystem and disk-capacity results, then finalize the report.
	 *
	 * @param array $report Initial report.
	 * @param array $files Filesystem summary.
	 * @return array
	 */
	public function finalize( array $report, array $files ) {
		$report['inventory']['files'] = $files;

		if ( ! empty( $files['partial'] ) ) {
			foreach ( $files['partial_reasons'] as $reason ) {
				OGSMI_Report_Builder::mark_partial( $report, $reason );
			}
		}

		$paths       = $report['inventory']['software']['paths'] ?? array();
		$path_labels = array(
			'content'    => __( 'content', '1gbits-site-move-inspector' ),
			'plugins'    => __( 'plugins', '1gbits-site-move-inspector' ),
			'mu_plugins' => __( 'must-use plugins', '1gbits-site-move-inspector' ),
			'themes'     => __( 'themes', '1gbits-site-move-inspector' ),
			'uploads'    => __( 'uploads', '1gbits-site-move-inspector' ),
		);
		foreach ( $path_labels as $path_id => $path_label ) {
			$key = $path_id . '_within_root';
			if ( isset( $paths[ $key ] ) && ! $paths[ $key ] ) {
				OGSMI_Report_Builder::mark_partial(
					$report,
					sprintf(
						/* translators: %s: type of WordPress directory, such as plugins or themes. */
						__( 'The %s directory is outside the WordPress root or could not be resolved, so it was not scanned.', '1gbits-site-move-inspector' ),
						$path_label
					)
				);
			}
		}

		$scan_status = empty( $files['partial'] )
			? OGSMI_Report_Builder::STATUS_PASS
			: OGSMI_Report_Builder::STATUS_WARNING;

		OGSMI_Report_Builder::add_check(
			$report,
			'storage',
			__( 'Files and storage', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'filesystem_scan',
				'status'         => $scan_status,
				'label'          => __( 'Filesystem scan', '1gbits-site-move-inspector' ),
				'value'          => sprintf(
					/* translators: 1: file count, 2: directory count. */
					__( 'Files: %1$s; directories: %2$s', '1gbits-site-move-inspector' ),
					number_format_i18n( absint( $files['file_count'] ?? 0 ) ),
					number_format_i18n( absint( $files['directory_count'] ?? 0 ) )
				),
				'message'        => empty( $files['partial'] )
					? __( 'The bounded metadata scan completed.', '1gbits-site-move-inspector' )
					: __( 'The scan stopped at a safety limit, so totals are incomplete.', '1gbits-site-move-inspector' ),
				'recommendation' => empty( $files['partial'] )
					? ''
					: __( 'Use server-level tools to obtain exact totals before planning the move.', '1gbits-site-move-inspector' ),
			)
		);

		$total_bytes = max( 0, (int) ( $files['total_bytes'] ?? 0 ) );
		OGSMI_Report_Builder::add_check(
			$report,
			'storage',
			__( 'Files and storage', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'site_file_size',
				'status'         => $total_bytes > 0
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_UNKNOWN,
				'label'          => __( 'Scanned file size', '1gbits-site-move-inspector' ),
				'value'          => OGSMI_Utils::format_bytes( $total_bytes ),
				'message'        => empty( $files['partial'] )
					? __( 'This is the total size of regular files found within the WordPress root.', '1gbits-site-move-inspector' )
					: __( 'This is a lower bound because the scan is incomplete.', '1gbits-site-move-inspector' ),
				'recommendation' => '',
			)
		);

		$symlink_count = absint( $files['symlink_count'] ?? 0 );
		OGSMI_Report_Builder::add_check(
			$report,
			'storage',
			__( 'Files and storage', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'symlinks',
				'status'         => $symlink_count > 0
					? OGSMI_Report_Builder::STATUS_WARNING
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Symbolic links', '1gbits-site-move-inspector' ),
				'value'          => number_format_i18n( $symlink_count ),
				'message'        => $symlink_count > 0
					? __( 'Symbolic links were detected but never followed.', '1gbits-site-move-inspector' )
					: __( 'No symbolic links were detected.', '1gbits-site-move-inspector' ),
				'recommendation' => $symlink_count > 0
					? __( 'Confirm that the destination can reproduce every required link target.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$unreadable_count   = absint( $files['unreadable_count'] ?? 0 );
		$outside_root_count = absint( $files['outside_root_count'] ?? 0 );
		$skipped_count      = absint( $files['skipped_directory_count'] ?? 0 );
		$access_issue_count = $unreadable_count + $outside_root_count + $skipped_count;

		if ( $access_issue_count > 0 ) {
			OGSMI_Report_Builder::mark_partial(
				$report,
				__( 'Some filesystem entries could not be included safely.', '1gbits-site-move-inspector' )
			);
		}

		OGSMI_Report_Builder::add_check(
			$report,
			'storage',
			__( 'Files and storage', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'filesystem_access',
				'status'         => $access_issue_count > 0
					? OGSMI_Report_Builder::STATUS_WARNING
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Filesystem access', '1gbits-site-move-inspector' ),
				'value'          => number_format_i18n( $access_issue_count ),
				'message'        => $access_issue_count > 0
					? __( 'One or more entries were unreadable, outside the scan root, or skipped at a safety limit.', '1gbits-site-move-inspector' )
					: __( 'All discovered entries stayed inside the scan root and were readable.', '1gbits-site-move-inspector' ),
				'recommendation' => $access_issue_count > 0
					? __( 'Ask the current host for a server-level file inventory before migration.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$this->inspect_disk_space( $report, $total_bytes );
		$this->inspect_destination_disk( $report, $total_bytes );

		OGSMI_Report_Builder::finalize( $report );

		return $report;
	}

	/**
	 * Inspect installed software and calculate the strictest PHP requirement.
	 *
	 * @param array $report Report, by reference.
	 */
	private function inspect_software( array &$report ) {
		global $required_php_version, $required_mysql_version, $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$core_php = OGSMI_Utils::sanitize_version( $required_php_version ?? '7.4' );
		if ( '' === $core_php ) {
			$core_php = '7.4';
		}

		$required_php       = $core_php;
		$requirement_source = __( 'WordPress core', '1gbits-site-move-inspector' );
		$active_plugins     = array();
		$all_plugins        = get_plugins();
		$mu_plugins         = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
		$site_ids           = $this->site_ids_for_inventory( $report );
		$active_files       = array();
		$network_files      = array();

		if ( is_multisite() ) {
			$network_plugins = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_plugins ) ) {
				foreach ( array_keys( $network_plugins ) as $plugin_file ) {
					$active_files[ $plugin_file ]  = true;
					$network_files[ $plugin_file ] = true;
				}
			}

			foreach ( $site_ids as $site_id ) {
				$site_plugins = get_blog_option( $site_id, 'active_plugins', array() );
				if ( ! is_array( $site_plugins ) ) {
					continue;
				}
				foreach ( $site_plugins as $plugin_file ) {
					$active_files[ (string) $plugin_file ] = true;
				}
			}
		} else {
			$site_plugins = get_option( 'active_plugins', array() );
			if ( is_array( $site_plugins ) ) {
				foreach ( $site_plugins as $plugin_file ) {
					$active_files[ (string) $plugin_file ] = true;
				}
			}
		}

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( ! isset( $active_files[ $plugin_file ] ) ) {
				continue;
			}

			$plugin_php = OGSMI_Utils::sanitize_version( $plugin_data['RequiresPHP'] ?? '' );
			if ( '' !== $plugin_php && version_compare( $plugin_php, $required_php, '>' ) ) {
				$required_php       = $plugin_php;
				$requirement_source = sanitize_text_field( $plugin_data['Name'] ?? $plugin_file );
			}

			$active_plugins[] = array(
				'name'         => sanitize_text_field( $plugin_data['Name'] ?? $plugin_file ),
				'version'      => OGSMI_Utils::sanitize_version( $plugin_data['Version'] ?? '' ),
				'requires_php' => $plugin_php,
				'requires_wp'  => OGSMI_Utils::sanitize_version( $plugin_data['RequiresWP'] ?? '' ),
				'network'      => isset( $network_files[ $plugin_file ] ),
				'must_use'     => false,
			);
		}

		foreach ( $mu_plugins as $plugin_file => $plugin_data ) {
			$plugin_php  = OGSMI_Utils::sanitize_version( $plugin_data['RequiresPHP'] ?? '' );
			$plugin_name = sanitize_text_field( $plugin_data['Name'] ?? $plugin_file );

			if ( '' !== $plugin_php && version_compare( $plugin_php, $required_php, '>' ) ) {
				$required_php       = $plugin_php;
				$requirement_source = $plugin_name;
			}

			$active_plugins[] = array(
				'name'         => $plugin_name,
				'version'      => OGSMI_Utils::sanitize_version( $plugin_data['Version'] ?? '' ),
				'requires_php' => $plugin_php,
				'requires_wp'  => OGSMI_Utils::sanitize_version( $plugin_data['RequiresWP'] ?? '' ),
				'network'      => is_multisite(),
				'must_use'     => true,
			);
		}

		usort(
			$active_plugins,
			static function ( $left, $right ) {
				return strcasecmp( $left['name'], $right['name'] );
			}
		);

		$theme_slugs = array();
		foreach ( $site_ids as $site_id ) {
			$stylesheet = is_multisite()
				? get_blog_option( $site_id, 'stylesheet', '' )
				: get_option( 'stylesheet', '' );
			$template   = is_multisite()
				? get_blog_option( $site_id, 'template', '' )
				: get_option( 'template', '' );

			if ( '' !== (string) $stylesheet ) {
				$theme_slugs[ (string) $stylesheet ] = true;
			}
			if ( '' !== (string) $template ) {
				$theme_slugs[ (string) $template ] = true;
			}
		}

		$active_themes = array();
		foreach ( array_keys( $theme_slugs ) as $theme_slug ) {
			$network_theme = wp_get_theme( $theme_slug );
			if ( ! $network_theme->exists() ) {
				OGSMI_Report_Builder::mark_partial(
					$report,
					__( 'At least one configured theme could not be inspected.', '1gbits-site-move-inspector' )
				);
				continue;
			}

			$network_theme_php  = OGSMI_Utils::sanitize_version( $network_theme->get( 'RequiresPHP' ) );
			$network_theme_name = sanitize_text_field( $network_theme->get( 'Name' ) );
			$network_theme_name = '' === $network_theme_name ? $network_theme->get_stylesheet() : $network_theme_name;

			if ( '' !== $network_theme_php && version_compare( $network_theme_php, $required_php, '>' ) ) {
				$required_php       = $network_theme_php;
				$requirement_source = $network_theme_name;
			}

			$active_themes[] = array(
				'name'         => $network_theme_name,
				'version'      => OGSMI_Utils::sanitize_version( $network_theme->get( 'Version' ) ),
				'requires_php' => $network_theme_php,
				'requires_wp'  => OGSMI_Utils::sanitize_version( $network_theme->get( 'RequiresWP' ) ),
			);
		}

		$theme      = wp_get_theme();
		$theme_php  = OGSMI_Utils::sanitize_version( $theme->get( 'RequiresPHP' ) );
		$theme_name = sanitize_text_field( $theme->get( 'Name' ) );
		$theme_name = '' === $theme_name ? $theme->get_stylesheet() : $theme_name;

		$report['inventory']['software'] = array(
			'wordpress_version'     => OGSMI_Utils::sanitize_version( $wp_version ),
			'php_version'           => OGSMI_Utils::sanitize_version( PHP_VERSION ),
			'core_required_php'     => $core_php,
			'core_required_db'      => OGSMI_Utils::sanitize_version( $required_mysql_version ?? '' ),
			'strictest_php'         => $required_php,
			'php_requirement_from'  => $requirement_source,
			'active_plugins'        => $active_plugins,
			'inactive_plugin_count' => max( 0, count( $all_plugins ) - count( array_intersect_key( $all_plugins, $active_files ) ) ),
			'active_theme'          => array(
				'name'         => $theme_name,
				'version'      => OGSMI_Utils::sanitize_version( $theme->get( 'Version' ) ),
				'requires_php' => $theme_php,
				'requires_wp'  => OGSMI_Utils::sanitize_version( $theme->get( 'RequiresWP' ) ),
			),
			'active_themes'         => $active_themes,
			'inspected_site_count'  => count( $site_ids ),
			'paths'                 => $this->inspect_path_layout(),
		);
	}

	/**
	 * Return a bounded list of site IDs whose active software should be inspected.
	 *
	 * @param array $report Report, by reference.
	 * @return int[]
	 */
	private function site_ids_for_inventory( array &$report ) {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() );
		}

		$site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => self::NETWORK_SITE_LIMIT + 1,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		$site_ids = array_map( 'absint', is_array( $site_ids ) ? $site_ids : array() );

		if ( count( $site_ids ) > self::NETWORK_SITE_LIMIT ) {
			OGSMI_Report_Builder::mark_partial(
				$report,
				sprintf(
					/* translators: %s: maximum number of multisite sites inspected. */
					__( 'The network software inventory was limited to the first %s sites.', '1gbits-site-move-inspector' ),
					number_format_i18n( self::NETWORK_SITE_LIMIT )
				)
			);
			$site_ids = array_slice( $site_ids, 0, self::NETWORK_SITE_LIMIT );
		}

		if ( empty( $site_ids ) ) {
			OGSMI_Report_Builder::mark_partial(
				$report,
				__( 'The network site list could not be inspected.', '1gbits-site-move-inspector' )
			);
			$site_ids[] = get_main_site_id();
		}

		return array_values( array_unique( $site_ids ) );
	}

	/**
	 * Add environment checks.
	 *
	 * @param array $report Report, by reference.
	 */
	private function inspect_environment( array &$report ) {
		global $wpdb;

		$software    = $report['inventory']['software'];
		$current_php = $software['php_version'];
		$required    = $software['strictest_php'];
		$php_ok      = '' !== $current_php && '' !== $required && version_compare( $current_php, $required, '>=' );

		OGSMI_Report_Builder::add_check(
			$report,
			'environment',
			__( 'Environment', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'wordpress_version',
				'status'         => OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'WordPress version', '1gbits-site-move-inspector' ),
				'value'          => $software['wordpress_version'],
				'message'        => __( 'The installed WordPress version was recorded for destination planning.', '1gbits-site-move-inspector' ),
				'recommendation' => '',
			)
		);

		OGSMI_Report_Builder::add_check(
			$report,
			'environment',
			__( 'Environment', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'php_version',
				'status'         => $php_ok
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_CRITICAL,
				'label'          => __( 'Current PHP version', '1gbits-site-move-inspector' ),
				'value'          => $current_php,
				'message'        => sprintf(
					/* translators: 1: required PHP version, 2: component requiring it. */
					__( 'The strictest declared requirement is PHP %1$s from %2$s.', '1gbits-site-move-inspector' ),
					$required,
					$software['php_requirement_from']
				),
				'recommendation' => $php_ok
					? ''
					: __( 'Resolve the PHP compatibility issue before moving the site.', '1gbits-site-move-inspector' ),
			)
		);

		$db_version = '';
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) {
			$db_version = OGSMI_Utils::sanitize_version( $wpdb->db_version() );
		}
		$db_engine = $this->database_engine();

		$report['inventory']['software']['database_version'] = $db_version;
		$report['inventory']['software']['database_engine']  = $db_engine;
		$report['inventory']['software']['web_server']       = $this->web_server_family();

		OGSMI_Report_Builder::add_check(
			$report,
			'environment',
			__( 'Environment', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'database_version',
				'status'         => '' === $db_version
					? OGSMI_Report_Builder::STATUS_UNKNOWN
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Current database server', '1gbits-site-move-inspector' ),
				'value'          => trim( ucfirst( $db_engine ) . ' ' . $db_version ),
				'message'        => '' === $db_version
					? __( 'The database version could not be determined.', '1gbits-site-move-inspector' )
					: __( 'The current database engine and version were recorded.', '1gbits-site-move-inspector' ),
				'recommendation' => '',
			)
		);

		$memory_limit    = OGSMI_Utils::size_to_bytes( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ) );
		$upload_limit    = function_exists( 'wp_max_upload_size' )
			? (int) wp_max_upload_size()
			: min(
				OGSMI_Utils::size_to_bytes( ini_get( 'upload_max_filesize' ) ),
				OGSMI_Utils::size_to_bytes( ini_get( 'post_max_size' ) )
			);
		$execution_limit = max( 0, (int) ini_get( 'max_execution_time' ) );

		$report['inventory']['software']['limits'] = array(
			'memory_bytes'      => $memory_limit,
			'upload_bytes'      => $upload_limit,
			'execution_seconds' => $execution_limit,
		);

		OGSMI_Report_Builder::add_check(
			$report,
			'environment',
			__( 'Environment', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'runtime_limits',
				'status'         => OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'PHP runtime limits', '1gbits-site-move-inspector' ),
				'value'          => sprintf(
					/* translators: 1: memory limit, 2: upload limit, 3: execution time in seconds. */
					__( 'Memory %1$s; upload %2$s; execution %3$s', '1gbits-site-move-inspector' ),
					0 === $memory_limit ? __( 'unlimited or unknown', '1gbits-site-move-inspector' ) : OGSMI_Utils::format_bytes( $memory_limit ),
					0 === $upload_limit ? __( 'unknown', '1gbits-site-move-inspector' ) : OGSMI_Utils::format_bytes( $upload_limit ),
					0 === $execution_limit
						? __( 'unlimited', '1gbits-site-move-inspector' )
						: sprintf(
							/* translators: %d: seconds. */
							__( '%d seconds', '1gbits-site-move-inspector' ),
							$execution_limit
						)
				),
				'message'        => __( 'These values help the destination team reproduce or improve the runtime configuration.', '1gbits-site-move-inspector' ),
				'recommendation' => '',
			)
		);

		$this->inspect_extensions( $report );
	}

	/**
	 * Add PHP extension checks.
	 *
	 * @param array $report Report, by reference.
	 */
	private function inspect_extensions( array &$report ) {
		$required_groups    = array(
			'json'     => array( 'json' ),
			'openssl'  => array( 'openssl' ),
			'database' => array( 'mysqli', 'mysqlnd' ),
		);
		$recommended_groups = array(
			'curl'      => array( 'curl' ),
			'DOM'       => array( 'dom' ),
			'Fileinfo'  => array( 'fileinfo' ),
			'Multibyte' => array( 'mbstring' ),
			'ZIP'       => array( 'zip' ),
			'Images'    => array( 'imagick', 'gd' ),
		);

		$missing_required    = $this->missing_extension_groups( $required_groups );
		$missing_recommended = $this->missing_extension_groups( $recommended_groups );

		$report['inventory']['software']['extensions'] = array(
			'missing_required'    => $missing_required,
			'missing_recommended' => $missing_recommended,
		);

		OGSMI_Report_Builder::add_check(
			$report,
			'environment',
			__( 'Environment', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'required_php_extensions',
				'status'         => empty( $missing_required )
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_WARNING,
				'label'          => __( 'Core PHP extensions', '1gbits-site-move-inspector' ),
				'value'          => empty( $missing_required )
					? __( 'Available', '1gbits-site-move-inspector' )
					: implode( ', ', $missing_required ),
				'message'        => empty( $missing_required )
					? __( 'The expected core extension groups are available.', '1gbits-site-move-inspector' )
					: __( 'One or more expected extension groups could not be detected.', '1gbits-site-move-inspector' ),
				'recommendation' => empty( $missing_required )
					? ''
					: __( 'Confirm the destination provides the missing extension groups.', '1gbits-site-move-inspector' ),
			)
		);

		OGSMI_Report_Builder::add_check(
			$report,
			'environment',
			__( 'Environment', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'recommended_php_extensions',
				'status'         => empty( $missing_recommended )
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_WARNING,
				'label'          => __( 'Migration-supporting PHP extensions', '1gbits-site-move-inspector' ),
				'value'          => empty( $missing_recommended )
					? __( 'Available', '1gbits-site-move-inspector' )
					: implode( ', ', $missing_recommended ),
				'message'        => empty( $missing_recommended )
					? __( 'Common HTTP, archive, text, and image extension groups are available.', '1gbits-site-move-inspector' )
					: __( 'Some commonly useful extension groups are unavailable.', '1gbits-site-move-inspector' ),
				'recommendation' => empty( $missing_recommended )
					? ''
					: __( 'Ask the destination host whether these extension groups can be enabled.', '1gbits-site-move-inspector' ),
			)
		);
	}

	/**
	 * Inspect URL layout, special paths, cron, and drop-ins.
	 *
	 * @param array $report Report, by reference.
	 */
	private function inspect_configuration( array &$report ) {
		$home_url    = home_url( '/' );
		$site_url    = site_url( '/' );
		$home_host   = OGSMI_Utils::url_host( $home_url );
		$site_host   = OGSMI_Utils::url_host( $site_url );
		$home_scheme = strtolower( (string) wp_parse_url( $home_url, PHP_URL_SCHEME ) );
		$site_scheme = strtolower( (string) wp_parse_url( $site_url, PHP_URL_SCHEME ) );

		OGSMI_Report_Builder::add_check(
			$report,
			'configuration',
			__( 'Site configuration', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'https',
				'status'         => 'https' === $home_scheme
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_WARNING,
				'label'          => __( 'Public HTTPS URL', '1gbits-site-move-inspector' ),
				'value'          => 'https' === $home_scheme ? __( 'HTTPS', '1gbits-site-move-inspector' ) : __( 'Not HTTPS', '1gbits-site-move-inspector' ),
				'message'        => 'https' === $home_scheme
					? __( 'The public site URL uses HTTPS.', '1gbits-site-move-inspector' )
					: __( 'The public site URL does not use HTTPS.', '1gbits-site-move-inspector' ),
				'recommendation' => 'https' === $home_scheme
					? ''
					: __( 'Provision and test TLS on the destination before changing DNS.', '1gbits-site-move-inspector' ),
			)
		);

		$url_mismatch = '' === $home_host
			|| '' === $site_host
			|| $home_host !== $site_host
			|| $home_scheme !== $site_scheme;

		OGSMI_Report_Builder::add_check(
			$report,
			'configuration',
			__( 'Site configuration', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'url_alignment',
				'status'         => $url_mismatch
					? OGSMI_Report_Builder::STATUS_WARNING
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Home and WordPress URLs', '1gbits-site-move-inspector' ),
				'value'          => $url_mismatch
					? __( 'Different host or scheme', '1gbits-site-move-inspector' )
					: __( 'Aligned', '1gbits-site-move-inspector' ),
				'message'        => $url_mismatch
					? __( 'The configured public and WordPress URLs use different hosts or schemes.', '1gbits-site-move-inspector' )
					: __( 'The configured URLs use the same host and scheme. Different subdirectory paths are supported.', '1gbits-site-move-inspector' ),
				'recommendation' => $url_mismatch
					? __( 'Document the intended URL layout before migration and test redirects afterward.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$paths       = $report['inventory']['software']['paths'];
		$custom_path = ! $paths['content_default'] || ! $paths['uploads_default'];
		$outside     = ! $paths['content_within_root'] || ! $paths['uploads_within_root'];

		OGSMI_Report_Builder::add_check(
			$report,
			'configuration',
			__( 'Site configuration', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'custom_paths',
				'status'         => $custom_path
					? OGSMI_Report_Builder::STATUS_WARNING
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Content and uploads paths', '1gbits-site-move-inspector' ),
				'value'          => $custom_path
					? __( 'Customized', '1gbits-site-move-inspector' )
					: __( 'Default layout', '1gbits-site-move-inspector' ),
				'message'        => $outside
					? __( 'At least one content path is outside the WordPress root and is excluded from the file scan.', '1gbits-site-move-inspector' )
					: ( $custom_path
						? __( 'A non-default path layout was detected inside the WordPress root.', '1gbits-site-move-inspector' )
						: __( 'The standard WordPress path layout is in use.', '1gbits-site-move-inspector' ) ),
				'recommendation' => $custom_path
					? __( 'Make sure the destination preserves custom path constants and directory mappings.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$dropins                                    = $this->detect_dropins();
		$report['inventory']['software']['dropins'] = $dropins;

		OGSMI_Report_Builder::add_check(
			$report,
			'configuration',
			__( 'Site configuration', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'dropins',
				'status'         => empty( $dropins )
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_WARNING,
				'label'          => __( 'WordPress drop-ins', '1gbits-site-move-inspector' ),
				'value'          => empty( $dropins ) ? __( 'None detected', '1gbits-site-move-inspector' ) : implode( ', ', $dropins ),
				'message'        => empty( $dropins )
					? __( 'No migration-sensitive WordPress drop-ins were detected.', '1gbits-site-move-inspector' )
					: __( 'One or more drop-ins may depend on server-side cache or database services.', '1gbits-site-move-inspector' ),
				'recommendation' => empty( $dropins )
					? ''
					: __( 'Confirm each drop-in is supported or replaced on the destination.', '1gbits-site-move-inspector' ),
			)
		);

		$is_multisite = is_multisite();
		OGSMI_Report_Builder::add_check(
			$report,
			'configuration',
			__( 'Site configuration', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'multisite',
				'status'         => $is_multisite
					? OGSMI_Report_Builder::STATUS_WARNING
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Multisite', '1gbits-site-move-inspector' ),
				'value'          => $is_multisite ? __( 'Enabled', '1gbits-site-move-inspector' ) : __( 'Not enabled', '1gbits-site-move-inspector' ),
				'message'        => $is_multisite
					? __( 'This installation is a WordPress multisite network.', '1gbits-site-move-inspector' )
					: __( 'This is a single-site installation.', '1gbits-site-move-inspector' ),
				'recommendation' => $is_multisite
					? __( 'Use a migration process that explicitly supports multisite networks.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$this->inspect_cron( $report );
	}

	/**
	 * Add cron configuration and backlog checks.
	 *
	 * @param array $report Report, by reference.
	 */
	private function inspect_cron( array &$report ) {
		$disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alternate = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;

		if ( $disabled ) {
			$cron_status = OGSMI_Report_Builder::STATUS_WARNING;
			$cron_value  = __( 'WP-Cron disabled', '1gbits-site-move-inspector' );
			$cron_msg    = __( 'Traffic-triggered WP-Cron is disabled; a real scheduler may be configured.', '1gbits-site-move-inspector' );
		} elseif ( $alternate ) {
			$cron_status = OGSMI_Report_Builder::STATUS_WARNING;
			$cron_value  = __( 'Alternate mode', '1gbits-site-move-inspector' );
			$cron_msg    = __( 'Alternate WP-Cron mode is enabled.', '1gbits-site-move-inspector' );
		} else {
			$cron_status = OGSMI_Report_Builder::STATUS_PASS;
			$cron_value  = __( 'Standard mode', '1gbits-site-move-inspector' );
			$cron_msg    = __( 'Standard traffic-triggered WP-Cron is enabled.', '1gbits-site-move-inspector' );
		}

		OGSMI_Report_Builder::add_check(
			$report,
			'reliability',
			__( 'Background jobs', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'cron_mode',
				'status'         => $cron_status,
				'label'          => __( 'WP-Cron mode', '1gbits-site-move-inspector' ),
				'value'          => $cron_value,
				'message'        => $cron_msg,
				'recommendation' => $disabled || $alternate
					? __( 'Document and recreate the destination scheduler before switching traffic.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$cron_array    = _get_cron_array();
		$overdue_count = 0;
		$now           = time();

		if ( is_array( $cron_array ) ) {
			foreach ( $cron_array as $timestamp => $hooks ) {
				if ( (int) $timestamp >= $now - 600 || ! is_array( $hooks ) ) {
					continue;
				}

				foreach ( $hooks as $events ) {
					if ( is_array( $events ) ) {
						$overdue_count += count( $events );
					}
				}
			}
		}

		OGSMI_Report_Builder::add_check(
			$report,
			'reliability',
			__( 'Background jobs', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'overdue_cron',
				'status'         => $overdue_count > 0
					? OGSMI_Report_Builder::STATUS_WARNING
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Overdue cron events', '1gbits-site-move-inspector' ),
				'value'          => number_format_i18n( $overdue_count ),
				'message'        => $overdue_count > 0
					? __( 'Events scheduled more than ten minutes ago are still present.', '1gbits-site-move-inspector' )
					: __( 'No cron events more than ten minutes overdue were found.', '1gbits-site-move-inspector' ),
				'recommendation' => $overdue_count > 0
					? __( 'Resolve the cron backlog and verify scheduled jobs after migration.', '1gbits-site-move-inspector' )
					: '',
			)
		);
	}

	/**
	 * Inspect table metadata without reading table content.
	 *
	 * @param array $report Report, by reference.
	 */
	private function inspect_database( array &$report ) {
		global $wpdb;

		$prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$sql    = $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like );

		$previous_suppression = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Read-only metadata query, prepared immediately above, run only on manual scans.
		$tables = $wpdb->get_results( $sql, ARRAY_A );
		$wpdb->suppress_errors( $previous_suppression );

		if ( ! is_array( $tables ) || empty( $tables ) ) {
			$report['inventory']['database'] = array(
				'available'   => false,
				'table_count' => 0,
				'total_bytes' => 0,
				'top_tables'  => array(),
			);

			OGSMI_Report_Builder::add_check(
				$report,
				'database',
				__( 'Database', '1gbits-site-move-inspector' ),
				array(
					'id'             => 'database_inventory',
					'status'         => OGSMI_Report_Builder::STATUS_UNKNOWN,
					'label'          => __( 'Database inventory', '1gbits-site-move-inspector' ),
					'value'          => __( 'Unavailable', '1gbits-site-move-inspector' ),
					'message'        => __( 'Table metadata was not available with the current database permissions.', '1gbits-site-move-inspector' ),
					'recommendation' => __( 'Ask the current host for the database size and table count.', '1gbits-site-move-inspector' ),
				)
			);
			return;
		}

		$inventory        = array();
		$total_bytes      = 0;
		$non_innodb_count = 0;

		foreach ( $tables as $table ) {
			$name      = sanitize_text_field( $table['Name'] ?? '' );
			$data      = max( 0, (int) ( $table['Data_length'] ?? 0 ) );
			$index     = max( 0, (int) ( $table['Index_length'] ?? 0 ) );
			$bytes     = $data + $index;
			$engine    = sanitize_key( $table['Engine'] ?? '' );
			$collation = sanitize_text_field( $table['Collation'] ?? '' );

			if ( '' !== $engine && 'innodb' !== strtolower( $engine ) ) {
				++$non_innodb_count;
			}

			$total_bytes += $bytes;
			$inventory[]  = array(
				'name'      => $name,
				'bytes'     => $bytes,
				'rows'      => max( 0, (int) ( $table['Rows'] ?? 0 ) ),
				'engine'    => $engine,
				'collation' => $collation,
			);
		}

		usort(
			$inventory,
			static function ( $left, $right ) {
				return (int) $right['bytes'] <=> (int) $left['bytes'];
			}
		);

		$report['inventory']['database'] = array(
			'available'        => true,
			'table_count'      => count( $inventory ),
			'total_bytes'      => $total_bytes,
			'top_tables'       => array_slice( $inventory, 0, 10 ),
			'non_innodb_count' => $non_innodb_count,
		);

		OGSMI_Report_Builder::add_check(
			$report,
			'database',
			__( 'Database', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'database_inventory',
				'status'         => OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Database inventory', '1gbits-site-move-inspector' ),
				'value'          => sprintf(
					/* translators: 1: database size, 2: number of tables. */
					__( 'Size: %1$s; tables: %2$s', '1gbits-site-move-inspector' ),
					OGSMI_Utils::format_bytes( $total_bytes ),
					number_format_i18n( count( $inventory ) )
				),
				'message'        => __( 'Sizes are estimates reported by the database server.', '1gbits-site-move-inspector' ),
				'recommendation' => '',
			)
		);

		OGSMI_Report_Builder::add_check(
			$report,
			'database',
			__( 'Database', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'database_engines',
				'status'         => $non_innodb_count > 0
					? OGSMI_Report_Builder::STATUS_WARNING
					: OGSMI_Report_Builder::STATUS_PASS,
				'label'          => __( 'Database table engines', '1gbits-site-move-inspector' ),
				'value'          => $non_innodb_count > 0
					? sprintf(
						/* translators: %s: number of non-InnoDB tables. */
						_n(
							'%s non-InnoDB table',
							'%s non-InnoDB tables',
							$non_innodb_count,
							'1gbits-site-move-inspector'
						),
						number_format_i18n( $non_innodb_count )
					)
					: __( 'All reported tables use InnoDB', '1gbits-site-move-inspector' ),
				'message'        => $non_innodb_count > 0
					? __( 'Some tables may require additional care during a live migration.', '1gbits-site-move-inspector' )
					: __( 'The reported tables use the common transactional engine.', '1gbits-site-move-inspector' ),
				'recommendation' => $non_innodb_count > 0
					? __( 'Confirm the destination supports every reported table engine.', '1gbits-site-move-inspector' )
					: '',
			)
		);
	}

	/**
	 * Compare destination PHP, database, and multisite capabilities.
	 *
	 * @param array $report Report, by reference.
	 */
	private function inspect_destination_software( array &$report ) {
		$destination = $report['destination'];
		if ( empty( $destination['provided'] ) ) {
			OGSMI_Report_Builder::add_check(
				$report,
				'destination',
				__( 'Destination comparison', '1gbits-site-move-inspector' ),
				array(
					'id'             => 'destination_profile',
					'status'         => OGSMI_Report_Builder::STATUS_NOT_APPLICABLE,
					'label'          => __( 'Destination profile', '1gbits-site-move-inspector' ),
					'value'          => __( 'Not provided', '1gbits-site-move-inspector' ),
					'message'        => __( 'This report evaluates the source site only.', '1gbits-site-move-inspector' ),
					'recommendation' => __( 'Enter destination details to compare declared compatibility and capacity.', '1gbits-site-move-inspector' ),
				)
			);
			return;
		}

		$required_php = $report['inventory']['software']['strictest_php'];
		$target_php   = $destination['php_version'];

		if ( '' === $target_php ) {
			$php_status = OGSMI_Report_Builder::STATUS_UNKNOWN;
			$php_msg    = __( 'The destination PHP version was not supplied.', '1gbits-site-move-inspector' );
		} elseif ( version_compare( $target_php, $required_php, '<' ) ) {
			$php_status = OGSMI_Report_Builder::STATUS_CRITICAL;
			$php_msg    = sprintf(
				/* translators: 1: target PHP version, 2: required PHP version. */
				__( 'Target PHP %1$s is below the declared requirement of %2$s.', '1gbits-site-move-inspector' ),
				$target_php,
				$required_php
			);
		} else {
			$php_status = OGSMI_Report_Builder::STATUS_PASS;
			$php_msg    = sprintf(
				/* translators: 1: target PHP version, 2: required PHP version. */
				__( 'Target PHP %1$s meets the declared requirement of %2$s.', '1gbits-site-move-inspector' ),
				$target_php,
				$required_php
			);
		}

		OGSMI_Report_Builder::add_check(
			$report,
			'destination',
			__( 'Destination comparison', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'destination_php',
				'status'         => $php_status,
				'label'          => __( 'Destination PHP', '1gbits-site-move-inspector' ),
				'value'          => '' === $target_php ? __( 'Unknown', '1gbits-site-move-inspector' ) : $target_php,
				'message'        => $php_msg,
				'recommendation' => OGSMI_Report_Builder::STATUS_CRITICAL === $php_status
					? __( 'Select a supported PHP version before migration.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$target_db     = $destination['database_version'];
		$target_engine = $destination['database_engine'];
		$core_required = $report['inventory']['software']['core_required_db'];

		if ( '' === $target_db || '' === $target_engine ) {
			$db_status = OGSMI_Report_Builder::STATUS_UNKNOWN;
			$db_msg    = __( 'The destination database engine or version was not supplied.', '1gbits-site-move-inspector' );
		} elseif ( '' !== $core_required && version_compare( $target_db, $core_required, '<' ) ) {
			$db_status = OGSMI_Report_Builder::STATUS_CRITICAL;
			$db_msg    = sprintf(
				/* translators: 1: target database version, 2: core-required database version. */
				__( 'Target database %1$s is below WordPress core requirement %2$s.', '1gbits-site-move-inspector' ),
				$target_db,
				$core_required
			);
		} else {
			$db_status = OGSMI_Report_Builder::STATUS_PASS;
			$db_msg    = __( 'The declared destination database version meets the core minimum.', '1gbits-site-move-inspector' );
		}

		OGSMI_Report_Builder::add_check(
			$report,
			'destination',
			__( 'Destination comparison', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'destination_database',
				'status'         => $db_status,
				'label'          => __( 'Destination database', '1gbits-site-move-inspector' ),
				'value'          => trim( ucfirst( $target_engine ) . ' ' . $target_db ),
				'message'        => $db_msg,
				'recommendation' => OGSMI_Report_Builder::STATUS_CRITICAL === $db_status
					? __( 'Choose a database version supported by the installed WordPress release.', '1gbits-site-move-inspector' )
					: '',
			)
		);

		$multisite_support = $destination['multisite_support'];
		$source_multisite  = is_multisite();
		if ( ! $source_multisite ) {
			$multi_status = OGSMI_Report_Builder::STATUS_NOT_APPLICABLE;
			$multi_msg    = __( 'The source is not a multisite network.', '1gbits-site-move-inspector' );
		} elseif ( 'yes' === $multisite_support ) {
			$multi_status = OGSMI_Report_Builder::STATUS_PASS;
			$multi_msg    = __( 'The destination was declared to support multisite.', '1gbits-site-move-inspector' );
		} elseif ( 'no' === $multisite_support ) {
			$multi_status = OGSMI_Report_Builder::STATUS_CRITICAL;
			$multi_msg    = __( 'The destination was declared not to support multisite.', '1gbits-site-move-inspector' );
		} else {
			$multi_status = OGSMI_Report_Builder::STATUS_UNKNOWN;
			$multi_msg    = __( 'Destination multisite support is unknown.', '1gbits-site-move-inspector' );
		}

		OGSMI_Report_Builder::add_check(
			$report,
			'destination',
			__( 'Destination comparison', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'destination_multisite',
				'status'         => $multi_status,
				'label'          => __( 'Destination multisite support', '1gbits-site-move-inspector' ),
				'value'          => ucfirst( $multisite_support ),
				'message'        => $multi_msg,
				'recommendation' => OGSMI_Report_Builder::STATUS_CRITICAL === $multi_status
					? __( 'Choose a destination and migration process that support WordPress multisite.', '1gbits-site-move-inspector' )
					: '',
			)
		);
	}

	/**
	 * Optionally test this site's own public and REST URLs.
	 *
	 * @param array $report Report, by reference.
	 * @param bool  $run_self_test Whether to run requests.
	 */
	private function inspect_self_connection( array &$report, $run_self_test ) {
		if ( ! $run_self_test ) {
			OGSMI_Report_Builder::add_check(
				$report,
				'reliability',
				__( 'Background jobs', '1gbits-site-move-inspector' ),
				array(
					'id'             => 'self_connection',
					'status'         => OGSMI_Report_Builder::STATUS_NOT_APPLICABLE,
					'label'          => __( 'Self-connection test', '1gbits-site-move-inspector' ),
					'value'          => __( 'Not requested', '1gbits-site-move-inspector' ),
					'message'        => __( 'No HTTP request was made.', '1gbits-site-move-inspector' ),
					'recommendation' => '',
				)
			);
			return;
		}

		$home_result = $this->request_own_url( home_url( '/' ) );
		$rest_result = $this->request_own_url( rest_url() );
		$failures    = array();

		if ( ! $home_result['pass'] ) {
			$failures[] = __( 'public URL', '1gbits-site-move-inspector' );
		}
		if ( ! $rest_result['pass'] ) {
			$failures[] = __( 'REST URL', '1gbits-site-move-inspector' );
		}

		$report['inventory']['software']['self_connection'] = array(
			'home_code' => $home_result['code'],
			'rest_code' => $rest_result['code'],
		);

		OGSMI_Report_Builder::add_check(
			$report,
			'reliability',
			__( 'Background jobs', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'self_connection',
				'status'         => empty( $failures )
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_WARNING,
				'label'          => __( 'Self-connection test', '1gbits-site-move-inspector' ),
				'value'          => sprintf(
					/* translators: 1: public URL response code, 2: REST URL response code. */
					__( 'Public %1$s; REST %2$s', '1gbits-site-move-inspector' ),
					$home_result['code'],
					$rest_result['code']
				),
				'message'        => empty( $failures )
					? __( 'The site responded to manual requests for its own public and REST URLs.', '1gbits-site-move-inspector' )
					: sprintf(
						/* translators: %s: comma-separated failed endpoint labels. */
						__( 'The following self-requests need review: %s.', '1gbits-site-move-inspector' ),
						implode( ', ', $failures )
					),
				'recommendation' => empty( $failures )
					? ''
					: __( 'Check DNS, TLS, firewall, maintenance mode, and loopback restrictions.', '1gbits-site-move-inspector' ),
			)
		);
	}

	/**
	 * Add source disk-space information.
	 *
	 * @param array $report Report, by reference.
	 * @param int   $site_bytes Scanned file bytes.
	 */
	private function inspect_disk_space( array &$report, $site_bytes ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Some hosts warn instead of returning false.
		$free = @disk_free_space( ABSPATH );
		$free = false === $free ? 0 : max( 0, (int) $free );

		$report['inventory']['files']['source_free_bytes'] = $free;

		OGSMI_Report_Builder::add_check(
			$report,
			'storage',
			__( 'Files and storage', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'source_disk_free',
				'status'         => $free > 0
					? OGSMI_Report_Builder::STATUS_PASS
					: OGSMI_Report_Builder::STATUS_UNKNOWN,
				'label'          => __( 'Source free disk space', '1gbits-site-move-inspector' ),
				'value'          => $free > 0 ? OGSMI_Utils::format_bytes( $free ) : __( 'Unknown', '1gbits-site-move-inspector' ),
				'message'        => $free > 0
					? __( 'The filesystem reported its currently available capacity.', '1gbits-site-move-inspector' )
					: __( 'Free disk capacity could not be determined.', '1gbits-site-move-inspector' ),
				'recommendation' => $free > 0 || 0 === $site_bytes
					? ''
					: __( 'Ask the current host to confirm free capacity before creating migration archives.', '1gbits-site-move-inspector' ),
			)
		);
	}

	/**
	 * Compare target disk capacity with scanned source size plus 20 percent.
	 *
	 * @param array $report Report, by reference.
	 * @param int   $site_bytes Scanned file bytes.
	 */
	private function inspect_destination_disk( array &$report, $site_bytes ) {
		$destination = $report['destination'];
		if ( empty( $destination['provided'] ) ) {
			return;
		}

		$target_bytes = max( 0, (int) $destination['disk_bytes'] );
		$db_available = ! empty( $report['inventory']['database']['available'] );
		$db_bytes     = max( 0, (int) ( $report['inventory']['database']['total_bytes'] ?? 0 ) );
		$required     = (int) ceil( ( $site_bytes + $db_bytes ) * 1.2 );

		if ( ! $db_available ) {
			$status  = OGSMI_Report_Builder::STATUS_UNKNOWN;
			$message = __( 'Database size is unavailable, so destination capacity cannot be confirmed.', '1gbits-site-move-inspector' );
		} elseif ( $target_bytes <= 0 || $required <= 0 ) {
			$status  = OGSMI_Report_Builder::STATUS_UNKNOWN;
			$message = __( 'Destination capacity or complete source size is unavailable.', '1gbits-site-move-inspector' );
		} elseif ( $target_bytes < $required ) {
			$status  = OGSMI_Report_Builder::STATUS_CRITICAL;
			$message = sprintf(
				/* translators: 1: destination capacity, 2: estimated required capacity. */
				__( 'Destination capacity %1$s is below the estimated %2$s requirement.', '1gbits-site-move-inspector' ),
				OGSMI_Utils::format_bytes( $target_bytes ),
				OGSMI_Utils::format_bytes( $required )
			);
		} else {
			$status  = OGSMI_Report_Builder::STATUS_PASS;
			$message = sprintf(
				/* translators: 1: destination capacity, 2: estimated required capacity. */
				__( 'Destination capacity %1$s meets the estimated %2$s requirement.', '1gbits-site-move-inspector' ),
				OGSMI_Utils::format_bytes( $target_bytes ),
				OGSMI_Utils::format_bytes( $required )
			);
		}

		if ( ! empty( $report['partial'] ) && OGSMI_Report_Builder::STATUS_PASS === $status ) {
			$status  = OGSMI_Report_Builder::STATUS_UNKNOWN;
			$message = __( 'A partial source scan cannot confirm destination capacity.', '1gbits-site-move-inspector' );
		}

		OGSMI_Report_Builder::add_check(
			$report,
			'destination',
			__( 'Destination comparison', '1gbits-site-move-inspector' ),
			array(
				'id'             => 'destination_disk',
				'status'         => $status,
				'label'          => __( 'Destination disk capacity', '1gbits-site-move-inspector' ),
				'value'          => $target_bytes > 0 ? OGSMI_Utils::format_bytes( $target_bytes ) : __( 'Unknown', '1gbits-site-move-inspector' ),
				'message'        => $message,
				'recommendation' => OGSMI_Report_Builder::STATUS_CRITICAL === $status
					? __( 'Increase destination capacity or reduce the migration footprint before moving.', '1gbits-site-move-inspector' )
					: (
						OGSMI_Report_Builder::STATUS_UNKNOWN === $status
							? __( 'Confirm the database size and available destination capacity before moving.', '1gbits-site-move-inspector' )
							: ''
					),
			)
		);
	}

	/**
	 * Return path-layout booleans without exposing absolute paths.
	 *
	 * @return array
	 */
	private function inspect_path_layout() {
		$root = realpath( ABSPATH );
		$root = false === $root ? OGSMI_Utils::normalize_path( ABSPATH ) : OGSMI_Utils::normalize_path( $root );

		$content = realpath( WP_CONTENT_DIR );
		$content = false === $content ? OGSMI_Utils::normalize_path( WP_CONTENT_DIR ) : OGSMI_Utils::normalize_path( $content );

		$plugins = realpath( WP_PLUGIN_DIR );
		$plugins = false === $plugins ? OGSMI_Utils::normalize_path( WP_PLUGIN_DIR ) : OGSMI_Utils::normalize_path( $plugins );

		$mu_plugin_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$mu_plugins    = realpath( $mu_plugin_dir );
		$mu_plugins    = false === $mu_plugins ? OGSMI_Utils::normalize_path( $mu_plugin_dir ) : OGSMI_Utils::normalize_path( $mu_plugins );

		$theme_dir = get_theme_root();
		$themes    = realpath( $theme_dir );
		$themes    = false === $themes ? OGSMI_Utils::normalize_path( $theme_dir ) : OGSMI_Utils::normalize_path( $themes );

		$uploads      = wp_get_upload_dir();
		$uploads_dir  = empty( $uploads['basedir'] ) ? '' : $uploads['basedir'];
		$uploads_real = '' === $uploads_dir ? false : realpath( $uploads_dir );
		$uploads_path = false === $uploads_real ? OGSMI_Utils::normalize_path( $uploads_dir ) : OGSMI_Utils::normalize_path( $uploads_real );

		$default_content = OGSMI_Utils::normalize_path( $root . '/wp-content' );
		$default_uploads = OGSMI_Utils::normalize_path( $default_content . '/uploads' );

		return array(
			'content_default'        => $content === $default_content,
			'uploads_default'        => '' !== $uploads_path && $uploads_path === $default_uploads,
			'content_within_root'    => OGSMI_Utils::path_is_within( $content, $root ),
			'plugins_within_root'    => OGSMI_Utils::path_is_within( $plugins, $root ),
			'mu_plugins_within_root' => OGSMI_Utils::path_is_within( $mu_plugins, $root ),
			'themes_within_root'     => OGSMI_Utils::path_is_within( $themes, $root ),
			'uploads_within_root'    => '' !== $uploads_path && OGSMI_Utils::path_is_within( $uploads_path, $root ),
		);
	}

	/**
	 * Detect migration-sensitive core drop-in files.
	 *
	 * @return string[]
	 */
	private function detect_dropins() {
		$dropins = array(
			'advanced-cache.php',
			'db.php',
			'object-cache.php',
			'sunrise.php',
		);

		$found = array();
		foreach ( $dropins as $dropin ) {
			if ( is_file( WP_CONTENT_DIR . '/' . $dropin ) ) {
				$found[] = $dropin;
			}
		}

		return $found;
	}

	/**
	 * Find extension groups for which no alternative is loaded.
	 *
	 * @param array $groups Label => extension alternatives.
	 * @return string[]
	 */
	private function missing_extension_groups( array $groups ) {
		$missing = array();
		foreach ( $groups as $label => $alternatives ) {
			$found = false;
			foreach ( $alternatives as $extension ) {
				if ( extension_loaded( $extension ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$missing[] = (string) $label;
			}
		}

		return $missing;
	}

	/**
	 * Reduce server software to a non-sensitive family name.
	 *
	 * @return string
	 */
	private function web_server_family() {
		$software = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) );

		if ( false !== strpos( $software, 'openlitespeed' ) ) {
			return 'OpenLiteSpeed';
		}
		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'LiteSpeed';
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'Nginx';
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return 'Apache';
		}
		if ( false !== strpos( $software, 'microsoft-iis' ) ) {
			return 'IIS';
		}

		return __( 'Other or unknown', '1gbits-site-move-inspector' );
	}

	/**
	 * Detect MySQL versus MariaDB without retaining the raw server string.
	 *
	 * @return string
	 */
	private function database_engine() {
		global $wpdb;

		$server_info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';

		return false !== stripos( $server_info, 'mariadb' ) ? 'mariadb' : 'mysql';
	}

	/**
	 * Request one server-generated URL without following redirects.
	 *
	 * @param string $url This site's URL.
	 * @return array
	 */
	private function request_own_url( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 2048,
				'sslverify'           => true,
				'user-agent'          => '1Gbits-Site-Move-Inspector/' . OGSMI_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'pass' => false,
				'code' => sanitize_key( $response->get_error_code() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'pass' => $code >= 200 && $code < 300,
			'code' => $code > 0 ? (string) $code : 'unknown',
		);
	}
}
