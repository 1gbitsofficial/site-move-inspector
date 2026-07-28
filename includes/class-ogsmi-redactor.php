<?php
/**
 * Privacy-safe report export.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds reports from an export allowlist instead of scrubbing raw data.
 */
final class OGSMI_Redactor {

	/**
	 * Explicit export placeholders. These are deliberately human-readable so
	 * support recipients can distinguish omitted private data from missing data.
	 */
	private const REDACTED_URL             = '[redacted-url]';
	private const REDACTED_DOMAIN          = '[redacted-domain]';
	private const REDACTED_EMAIL           = '[redacted-email]';
	private const REDACTED_IP              = '[redacted-ip]';
	private const REDACTED_PATH            = '[redacted-path]';
	private const REDACTED_SITE_IDENTIFIER = '[redacted-site-identifier]';
	private const REDACTED_PLUGIN_NAME     = '[redacted-plugin-name]';
	private const REDACTED_THEME_NAME      = '[redacted-theme-name]';

	/**
	 * Return the privacy-safe export schema.
	 *
	 * @param array $report Internal administrator report.
	 * @return array
	 */
	public static function for_export( array $report ) {
		$destination = isset( $report['destination'] ) && is_array( $report['destination'] )
			? $report['destination']
			: array();
		$summary     = isset( $report['summary'] ) && is_array( $report['summary'] )
			? $report['summary']
			: array();
		$sections    = isset( $report['sections'] ) && is_array( $report['sections'] )
			? $report['sections']
			: array();
		$inventory   = isset( $report['inventory'] ) && is_array( $report['inventory'] )
			? $report['inventory']
			: array();
		$files       = isset( $inventory['files'] ) && is_array( $inventory['files'] )
			? $inventory['files']
			: array();
		$database    = isset( $inventory['database'] ) && is_array( $inventory['database'] )
			? $inventory['database']
			: array();
		$software    = isset( $inventory['software'] ) && is_array( $inventory['software'] )
			? $inventory['software']
			: array();

		$site_identifiers = self::site_identifiers( $software );
		$schema_version   = OGSMI_Utils::sanitize_version( $report['schema_version'] ?? '1.0' );
		if ( '' === $schema_version ) {
			$schema_version = '1.0';
		}

		$export = array(
			'schema_version'  => $schema_version,
			'plugin_version'  => OGSMI_Utils::sanitize_version( $report['plugin_version'] ?? '' ),
			'generated_at'    => self::timestamp( $report['generated_at'] ?? '' ),
			'scope'           => in_array( $report['scope'] ?? '', array( 'site', 'network' ), true )
				? $report['scope']
				: 'site',
			'partial'         => ! empty( $report['partial'] ),
			'partial_reasons' => self::string_list( $report['partial_reasons'] ?? array(), 20, $site_identifiers ),
			'destination'     => self::destination( $destination ),
			'summary'         => self::summary( $summary ),
			'sections'        => self::sections( $sections, $site_identifiers ),
			'inventory'       => array(
				'files'    => self::files( $files, $site_identifiers ),
				'database' => self::database( $database, $site_identifiers ),
				'software' => self::software( $software, $site_identifiers ),
			),
		);

		return $export;
	}

	/**
	 * Whitelist destination fields.
	 *
	 * @param array $destination Destination profile.
	 * @return array
	 */
	private static function destination( array $destination ) {
		$engine    = in_array( $destination['database_engine'] ?? '', array( 'mysql', 'mariadb' ), true )
			? $destination['database_engine']
			: '';
		$multisite = in_array( $destination['multisite_support'] ?? '', array( 'yes', 'no', 'unknown' ), true )
			? $destination['multisite_support']
			: 'unknown';

		return array(
			'provided'          => ! empty( $destination['provided'] ),
			'php_version'       => OGSMI_Utils::sanitize_version( $destination['php_version'] ?? '' ),
			'database_engine'   => $engine,
			'database_version'  => OGSMI_Utils::sanitize_version( $destination['database_version'] ?? '' ),
			'disk_bytes'        => max( 0, (int) ( $destination['disk_bytes'] ?? 0 ) ),
			'multisite_support' => $multisite,
		);
	}

	/**
	 * Whitelist summary counts.
	 *
	 * @param array $summary Summary.
	 * @return array
	 */
	private static function summary( array $summary ) {
		$overall = in_array( $summary['overall'] ?? '', array( 'high_risk', 'review_recommended', 'no_blockers' ), true )
			? $summary['overall']
			: 'review_recommended';
		$counts  = array();

		foreach ( array( 'pass', 'warning', 'critical', 'unknown', 'not_applicable' ) as $status ) {
			$counts[ $status ] = absint( $summary['counts'][ $status ] ?? 0 );
		}

		return array(
			'overall' => $overall,
			'counts'  => $counts,
		);
	}

	/**
	 * Whitelist all result sections.
	 *
	 * @param array $sections         Sections.
	 * @param array $site_identifiers Site-specific component names to remove.
	 * @return array
	 */
	private static function sections( array $sections, array $site_identifiers ) {
		$export = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$checks = array();
			foreach ( $section['checks'] ?? array() as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}

				$status = in_array(
					$check['status'] ?? '',
					array( 'pass', 'warning', 'critical', 'unknown', 'not_applicable' ),
					true
				) ? $check['status'] : 'unknown';

				$checks[] = array(
					'id'             => sanitize_key( $check['id'] ?? '' ),
					'status'         => $status,
					'label'          => self::redact_text( $check['label'] ?? '', $site_identifiers ),
					'value'          => self::redact_text( $check['value'] ?? '', $site_identifiers ),
					'message'        => self::redact_text( $check['message'] ?? '', $site_identifiers ),
					'recommendation' => self::redact_text( $check['recommendation'] ?? '', $site_identifiers ),
				);
			}

			$export[] = array(
				'id'     => sanitize_key( $section['id'] ?? '' ),
				'title'  => self::redact_text( $section['title'] ?? '', $site_identifiers ),
				'checks' => $checks,
			);
		}

		return $export;
	}

	/**
	 * Whitelist file counts and anonymize individual paths.
	 *
	 * @param array $files            Filesystem summary.
	 * @param array $site_identifiers Site-specific component names to remove.
	 * @return array
	 */
	private static function files( array $files, array $site_identifiers ) {
		$categories = array();
		foreach ( $files['categories'] ?? array() as $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$categories[] = array(
				'id'         => sanitize_key( $category['id'] ?? '' ),
				'label'      => self::redact_text( $category['label'] ?? '', $site_identifiers ),
				'file_count' => absint( $category['file_count'] ?? 0 ),
				'bytes'      => max( 0, (int) ( $category['bytes'] ?? 0 ) ),
			);
		}

		$top_files = array();
		$index     = 0;
		foreach ( $files['top_files'] ?? array() as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			++$index;
			$top_files[] = array(
				'id'        => sprintf( 'file_%02d', $index ),
				'bytes'     => max( 0, (int) ( $file['bytes'] ?? 0 ) ),
				'extension' => sanitize_key( $file['extension'] ?? '' ),
				'category'  => sanitize_key( $file['category'] ?? '' ),
			);
		}

		$symlink_scopes = array(
			'inside_root'  => 0,
			'outside_root' => 0,
			'unresolved'   => 0,
		);
		foreach ( $files['symlink_samples'] ?? array() as $sample ) {
			$scope = $sample['target_scope'] ?? '';
			if ( isset( $symlink_scopes[ $scope ] ) ) {
				++$symlink_scopes[ $scope ];
			}
		}

		return array(
			'complete'                => ! empty( $files['complete'] ),
			'partial'                 => ! empty( $files['partial'] ),
			'processed_entries'       => absint( $files['processed_entries'] ?? 0 ),
			'file_count'              => absint( $files['file_count'] ?? 0 ),
			'directory_count'         => absint( $files['directory_count'] ?? 0 ),
			'total_bytes'             => max( 0, (int) ( $files['total_bytes'] ?? 0 ) ),
			'source_free_bytes'       => max( 0, (int) ( $files['source_free_bytes'] ?? 0 ) ),
			'elapsed_seconds'         => round( (float) ( $files['elapsed_seconds'] ?? 0 ), 2 ),
			'categories'              => $categories,
			'top_files'               => $top_files,
			'symlink_count'           => absint( $files['symlink_count'] ?? 0 ),
			'symlink_sample_scopes'   => $symlink_scopes,
			'unreadable_count'        => absint( $files['unreadable_count'] ?? 0 ),
			'outside_root_count'      => absint( $files['outside_root_count'] ?? 0 ),
			'special_file_count'      => absint( $files['special_file_count'] ?? 0 ),
			'skipped_directory_count' => absint( $files['skipped_directory_count'] ?? 0 ),
		);
	}

	/**
	 * Whitelist aggregate database metadata and alias table names.
	 *
	 * @param array $database         Database inventory.
	 * @param array $site_identifiers Site-specific component names to remove.
	 * @return array
	 */
	private static function database( array $database, array $site_identifiers ) {
		$tables = array();
		$index  = 0;

		foreach ( $database['top_tables'] ?? array() as $table ) {
			if ( ! is_array( $table ) ) {
				continue;
			}

			++$index;
			$tables[] = array(
				'id'        => self::table_alias( (string) ( $table['name'] ?? '' ), $index ),
				'bytes'     => max( 0, (int) ( $table['bytes'] ?? 0 ) ),
				'rows'      => max( 0, (int) ( $table['rows'] ?? 0 ) ),
				'engine'    => sanitize_key( $table['engine'] ?? '' ),
				'collation' => self::redact_text( $table['collation'] ?? '', $site_identifiers ),
			);
		}

		return array(
			'available'        => ! empty( $database['available'] ),
			'table_count'      => absint( $database['table_count'] ?? 0 ),
			'total_bytes'      => max( 0, (int) ( $database['total_bytes'] ?? 0 ) ),
			'non_innodb_count' => absint( $database['non_innodb_count'] ?? 0 ),
			'top_tables'       => $tables,
		);
	}

	/**
	 * Whitelist software metadata.
	 *
	 * @param array $software         Software inventory.
	 * @param array $site_identifiers Site-specific component names to remove.
	 * @return array
	 */
	private static function software( array $software, array $site_identifiers ) {
		$plugins = array();
		foreach ( $software['active_plugins'] ?? array() as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}

			$plugins[] = array(
				'name'         => '' === sanitize_text_field( $plugin['name'] ?? '' )
					? ''
					: self::REDACTED_PLUGIN_NAME,
				'version'      => OGSMI_Utils::sanitize_version( $plugin['version'] ?? '' ),
				'requires_php' => OGSMI_Utils::sanitize_version( $plugin['requires_php'] ?? '' ),
				'requires_wp'  => OGSMI_Utils::sanitize_version( $plugin['requires_wp'] ?? '' ),
				'network'      => ! empty( $plugin['network'] ),
			);
		}

		$theme           = $software['active_theme'] ?? array();
		$paths           = $software['paths'] ?? array();
		$limits          = $software['limits'] ?? array();
		$extensions      = $software['extensions'] ?? array();
		$self_connection = $software['self_connection'] ?? array();

		return array(
			'wordpress_version'     => OGSMI_Utils::sanitize_version( $software['wordpress_version'] ?? '' ),
			'php_version'           => OGSMI_Utils::sanitize_version( $software['php_version'] ?? '' ),
			'database_engine'       => in_array( $software['database_engine'] ?? '', array( 'mysql', 'mariadb' ), true )
				? $software['database_engine']
				: '',
			'database_version'      => OGSMI_Utils::sanitize_version( $software['database_version'] ?? '' ),
			'web_server'            => self::redact_text( $software['web_server'] ?? '', $site_identifiers ),
			'core_required_php'     => OGSMI_Utils::sanitize_version( $software['core_required_php'] ?? '' ),
			'core_required_db'      => OGSMI_Utils::sanitize_version( $software['core_required_db'] ?? '' ),
			'strictest_php'         => OGSMI_Utils::sanitize_version( $software['strictest_php'] ?? '' ),
			'php_requirement_from'  => self::redact_text( $software['php_requirement_from'] ?? '', $site_identifiers ),
			'active_plugin_count'   => count( $plugins ),
			'inactive_plugin_count' => absint( $software['inactive_plugin_count'] ?? 0 ),
			'active_plugins'        => $plugins,
			'active_theme'          => array(
				'name'         => '' === sanitize_text_field( $theme['name'] ?? '' )
					? ''
					: self::REDACTED_THEME_NAME,
				'version'      => OGSMI_Utils::sanitize_version( $theme['version'] ?? '' ),
				'requires_php' => OGSMI_Utils::sanitize_version( $theme['requires_php'] ?? '' ),
				'requires_wp'  => OGSMI_Utils::sanitize_version( $theme['requires_wp'] ?? '' ),
			),
			'limits'                => array(
				'memory_bytes'      => max( 0, (int) ( $limits['memory_bytes'] ?? 0 ) ),
				'upload_bytes'      => max( 0, (int) ( $limits['upload_bytes'] ?? 0 ) ),
				'execution_seconds' => absint( $limits['execution_seconds'] ?? 0 ),
			),
			'extensions'            => array(
				'missing_required'    => self::string_list(
					$extensions['missing_required'] ?? array(),
					20,
					$site_identifiers
				),
				'missing_recommended' => self::string_list(
					$extensions['missing_recommended'] ?? array(),
					20,
					$site_identifiers
				),
			),
			'paths'                 => array(
				'content_default'     => ! empty( $paths['content_default'] ),
				'uploads_default'     => ! empty( $paths['uploads_default'] ),
				'content_within_root' => ! empty( $paths['content_within_root'] ),
				'uploads_within_root' => ! empty( $paths['uploads_within_root'] ),
			),
			'dropins'               => self::string_list( $software['dropins'] ?? array(), 10, $site_identifiers ),
			'self_connection'       => array(
				'home_code' => sanitize_key( (string) ( $self_connection['home_code'] ?? '' ) ),
				'rest_code' => sanitize_key( (string) ( $self_connection['rest_code'] ?? '' ) ),
			),
		);
	}

	/**
	 * Preserve familiar core table roles while masking prefixes and custom names.
	 *
	 * @param string $name Raw table name.
	 * @param int    $index Result index.
	 * @return string
	 */
	private static function table_alias( $name, $index ) {
		$core_suffixes = array(
			'commentmeta',
			'comments',
			'links',
			'options',
			'postmeta',
			'posts',
			'termmeta',
			'terms',
			'term_relationships',
			'term_taxonomy',
			'usermeta',
			'users',
			'blogs',
			'blogmeta',
			'site',
			'sitemeta',
			'signups',
		);

		$name = strtolower( sanitize_key( $name ) );
		foreach ( $core_suffixes as $suffix ) {
			if ( $name === $suffix || substr( $name, -strlen( '_' . $suffix ) ) === '_' . $suffix ) {
				return 'core_' . $suffix;
			}
		}

		return sprintf( 'custom_table_%02d', $index );
	}

	/**
	 * Sanitize a bounded list of strings.
	 *
	 * @param array $values           Values.
	 * @param int   $limit            Maximum values.
	 * @param array $site_identifiers Site-specific component names to remove.
	 * @return array
	 */
	private static function string_list( array $values, $limit, array $site_identifiers = array() ) {
		$output = array();
		foreach ( array_slice( $values, 0, absint( $limit ) ) as $value ) {
			$output[] = self::redact_text( $value, $site_identifiers );
		}

		return $output;
	}

	/**
	 * Collect opaque component display names which may be copied into checks.
	 *
	 * Plugin and theme headers are free-form and commonly include customer or
	 * project names. They are never exported verbatim, even when they do not look
	 * like another sensitive data type.
	 *
	 * @param array $software Software inventory.
	 * @return array
	 */
	private static function site_identifiers( array $software ) {
		$identifiers = array();

		foreach ( $software['active_plugins'] ?? array() as $plugin ) {
			if ( is_array( $plugin ) ) {
				self::add_site_identifier( $identifiers, $plugin['name'] ?? '' );
			}
		}

		$theme = isset( $software['active_theme'] ) && is_array( $software['active_theme'] )
			? $software['active_theme']
			: array();
		self::add_site_identifier( $identifiers, $theme['name'] ?? '' );

		$requirement_source = sanitize_text_field( $software['php_requirement_from'] ?? '' );
		if ( 'WordPress core' !== $requirement_source ) {
			self::add_site_identifier( $identifiers, $requirement_source );
		}

		usort(
			$identifiers,
			static function ( $left, $right ) {
				return strlen( $right ) - strlen( $left );
			}
		);

		return $identifiers;
	}

	/**
	 * Append one unique, non-empty site identifier.
	 *
	 * @param array $identifiers Identifiers, by reference.
	 * @param mixed $value       Candidate value.
	 * @return void
	 */
	private static function add_site_identifier( array &$identifiers, $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' !== $value && ! in_array( $value, $identifiers, true ) ) {
			$identifiers[] = $value;
		}

		$generic_words = array(
			'client',
			'connector',
			'core',
			'custom',
			'integration',
			'plugin',
			'plugins',
			'private',
			'site',
			'sync',
			'theme',
			'themes',
			'wordpress',
		);
		$words         = preg_split( '/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( is_array( $words ) ? $words : array() as $word ) {
			if (
				strlen( $word ) >= 4
				&& ! in_array( strtolower( $word ), $generic_words, true )
				&& ! in_array( $word, $identifiers, true )
			) {
				$identifiers[] = $word;
			}
		}
	}

	/**
	 * Redact sensitive substrings while preserving surrounding generic guidance.
	 *
	 * Paths and URLs are replaced before their component domains, IPs, or email
	 * addresses so a single private value produces one clear placeholder.
	 *
	 * @param mixed $value            Free-form report value.
	 * @param array $site_identifiers Site-specific component names to remove.
	 * @return string
	 */
	private static function redact_text( $value, array $site_identifiers = array() ) {
		$text = sanitize_text_field( (string) $value );
		if ( '' === $text ) {
			return '';
		}

		foreach ( $site_identifiers as $identifier ) {
			$text = self::replace_pattern(
				'~(?<![\p{L}\p{N}])' . preg_quote( $identifier, '~' ) . '(?![\p{L}\p{N}])~iu',
				self::REDACTED_SITE_IDENTIFIER,
				$text
			);
		}

		$text = self::replace_pattern(
			'~\b[A-Z][A-Z0-9+.-]*://[^\s<>"\']+~iu',
			self::REDACTED_URL,
			$text
		);
		$text = self::replace_pattern(
			'~(?<![:/])//(?:[\p{L}\p{N}-]+\.)+[\p{L}]{2,63}(?::\d{1,5})?(?:/[^\s<>"\']*)?~iu',
			self::REDACTED_URL,
			$text
		);
		$text = self::replace_pattern(
			'~(["\'])(?:[A-Z]:[/\\\\]|\\\\\\\\|/)[^"\']+\1~iu',
			self::REDACTED_PATH,
			$text
		);
		$text = self::replace_pattern(
			'~(?<![A-Z0-9])\\\\\\\\[?.]\\\\(?:[A-Z]:[/\\\\])?[^\r\n<>:"|?*,;(){}\[\]]*~iu',
			self::REDACTED_PATH,
			$text
		);
		$text = self::replace_pattern(
			'~(?<![A-Z0-9])[A-Z]:[/\\\\][^\r\n<>:"|?*,;(){}\[\]]*~iu',
			self::REDACTED_PATH,
			$text
		);
		$text = self::replace_pattern(
			'~(?<![A-Z0-9])\\\\\\\\(?:\?\\\\)?[^\r\n<>:"|?*,;(){}\[\]]+~iu',
			self::REDACTED_PATH,
			$text
		);
		$text = self::replace_pattern(
			'~(?<![\p{L}\p{N}._-])/{1,2}(?!/|\s)[^\r\n<>"\'|?*,;:(){}\[\]]+~u',
			self::REDACTED_PATH,
			$text
		);
		$text = self::replace_pattern(
			'~(?<![A-Z0-9.!#$%&\'*+/=?^_`{|}\~-])[A-Z0-9.!#$%&\'*+/=?^_`{|}\~-]+@(?:\[[^\]\s]+\]|[A-Z0-9](?:[A-Z0-9.-]{0,253}[A-Z0-9])?)(?![A-Z0-9._-])~iu',
			self::REDACTED_EMAIL,
			$text
		);
		$text = self::redact_ipv6( $text );
		$text = self::replace_pattern(
			'~(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)(?::\d{1,5})?(?![\d.])~',
			self::REDACTED_IP,
			$text
		);
		$text = self::replace_pattern(
			'~(?<![\p{L}\p{N}@._-])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+(?!(?:7z|avi|avif|bak|css|csv|docx?|eot|gif|gz|ico|jpe?g|js|json|log|map|md|mov|mp3|mp4|odt|ogg|otf|pdf|php|png|pptx?|rar|sql|svg|tar|tiff?|ttf|txt|wav|webm|webp|woff2?|xlsx?|xml|zip)(?=$|[\s,;:!?)}\]]))(?:[\p{L}]{2,63}|xn--[A-Z0-9-]{2,59})(?::\d{1,5})?(?:/[^\s<>"\']*)?(?![\p{L}\p{N}._-])~iu',
			self::REDACTED_DOMAIN,
			$text
		);

		return trim( $text );
	}

	/**
	 * Replace validated IPv6 candidates, including compressed and mapped forms.
	 *
	 * @param string $text Sanitized text.
	 * @return string
	 */
	private static function redact_ipv6( $text ) {
		$pattern = '~(?<![0-9A-Z:])(?P<address>\[?(?:[0-9A-F]{0,4}:){2,}[0-9A-F:.]*(?:%[0-9A-Z._\~-]+)?\]?)(?::\d{1,5})?(?![0-9A-Z:])~i';
		$result  = preg_replace_callback(
			$pattern,
			static function ( $matches ) {
				$address = trim( $matches['address'], '[]' );
				$address = (string) preg_replace( '/%.*$/', '', $address );

				return false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 )
					? self::REDACTED_IP
					: $matches[0];
			},
			$text
		);

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Apply one redaction expression without ever dropping the source text if
	 * the regular expression engine rejects a pattern.
	 *
	 * @param string $pattern     Regular expression.
	 * @param string $replacement Explicit redaction placeholder.
	 * @param string $text        Current text.
	 * @return string
	 */
	private static function replace_pattern( $pattern, $replacement, $text ) {
		$result = preg_replace( $pattern, $replacement, $text );

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * Keep only the timestamp shape generated by the report builder.
	 *
	 * @param mixed $value Candidate timestamp.
	 * @return string
	 */
	private static function timestamp( $value ) {
		$value = trim( (string) $value );

		return 1 === preg_match(
			'/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/',
			$value
		) ? $value : '';
	}
}
