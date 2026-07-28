<?php
/**
 * Report serialization.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes only the redacted export schema.
 */
final class OGSMI_Exporter {

	/**
	 * Export JSON.
	 *
	 * @param array $report Internal report.
	 * @return string
	 */
	public static function to_json( array $report ) {
		return (string) wp_json_encode(
			OGSMI_Redactor::for_export( $report ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Export a concise human-readable report.
	 *
	 * @param array $report Internal report.
	 * @return string
	 */
	public static function to_text( array $report ) {
		$report         = OGSMI_Redactor::for_export( $report );
		$title          = __( '1Gbits Site Move Inspector', '1gbits-site-move-inspector' );
		$scope_labels   = array(
			'site'    => __( 'Site', '1gbits-site-move-inspector' ),
			'network' => __( 'Network', '1gbits-site-move-inspector' ),
		);
		$overall_labels = array(
			'high_risk'          => __( 'High risk', '1gbits-site-move-inspector' ),
			'review_recommended' => __( 'Review recommended', '1gbits-site-move-inspector' ),
			'no_blockers'        => __( 'No blockers detected', '1gbits-site-move-inspector' ),
		);
		$status_labels  = array(
			'pass'           => __( 'Pass', '1gbits-site-move-inspector' ),
			'warning'        => __( 'Warning', '1gbits-site-move-inspector' ),
			'critical'       => __( 'Critical', '1gbits-site-move-inspector' ),
			'unknown'        => __( 'Unknown', '1gbits-site-move-inspector' ),
			'not_applicable' => __( 'Not applicable', '1gbits-site-move-inspector' ),
		);
		$scope          = $scope_labels[ $report['scope'] ] ?? __( 'Unknown', '1gbits-site-move-inspector' );
		$overall        = $overall_labels[ $report['summary']['overall'] ] ?? __( 'Unknown', '1gbits-site-move-inspector' );
		$lines          = array(
			$title,
			str_repeat( '=', max( 3, strlen( $title ) ) ),
			sprintf(
				/* translators: %s: export schema version. */
				__( 'Schema: %s', '1gbits-site-move-inspector' ),
				$report['schema_version']
			),
			sprintf(
				/* translators: %s: plugin version. */
				__( 'Plugin: %s', '1gbits-site-move-inspector' ),
				$report['plugin_version']
			),
			sprintf(
				/* translators: %s: UTC report timestamp. */
				__( 'Generated (UTC): %s', '1gbits-site-move-inspector' ),
				$report['generated_at']
			),
			sprintf(
				/* translators: %s: site or network scan scope. */
				__( 'Scope: %s', '1gbits-site-move-inspector' ),
				$scope
			),
			sprintf(
				/* translators: %s: overall migration-readiness result. */
				__( 'Overall: %s', '1gbits-site-move-inspector' ),
				$overall
			),
			sprintf(
				/* translators: %s: yes or no. */
				__( 'Partial scan: %s', '1gbits-site-move-inspector' ),
				$report['partial']
					? __( 'yes', '1gbits-site-move-inspector' )
					: __( 'no', '1gbits-site-move-inspector' )
			),
			'',
		);

		if ( ! empty( $report['partial_reasons'] ) ) {
			$lines[] = __( 'Partial scan reasons:', '1gbits-site-move-inspector' );
			foreach ( $report['partial_reasons'] as $reason ) {
				$lines[] = '- ' . $reason;
			}
			$lines[] = '';
		}

		foreach ( $report['sections'] as $section ) {
			$lines[] = strtoupper( $section['title'] );
			$lines[] = str_repeat( '-', max( 3, strlen( $section['title'] ) ) );
			foreach ( $section['checks'] as $check ) {
				$lines[] = sprintf(
					/* translators: 1: check status, 2: check label, 3: check value. */
					__( '[%1$s] %2$s: %3$s', '1gbits-site-move-inspector' ),
					strtoupper( $status_labels[ $check['status'] ] ?? __( 'Unknown', '1gbits-site-move-inspector' ) ),
					$check['label'],
					$check['value']
				);
				if ( '' !== $check['message'] ) {
					$lines[] = '  ' . $check['message'];
				}
				if ( '' !== $check['recommendation'] ) {
					$lines[] = sprintf(
						/* translators: %s: recommended migration action. */
						__( '  Action: %s', '1gbits-site-move-inspector' ),
						$check['recommendation']
					);
				}
			}
			$lines[] = '';
		}

		$files    = $report['inventory']['files'];
		$database = $report['inventory']['database'];
		$software = $report['inventory']['software'];

		$inventory_title = __( 'SAFE INVENTORY', '1gbits-site-move-inspector' );
		$lines[]         = $inventory_title;
		$lines[]         = str_repeat( '-', max( 3, strlen( $inventory_title ) ) );
		/* translators: %s: WordPress version. */
		$lines[] = sprintf( __( 'WordPress: %s', '1gbits-site-move-inspector' ), $software['wordpress_version'] );
		/* translators: %s: PHP version. */
		$lines[] = sprintf( __( 'PHP: %s', '1gbits-site-move-inspector' ), $software['php_version'] );
		/* translators: %s: database engine and version. */
		$lines[] = sprintf( __( 'Database: %s', '1gbits-site-move-inspector' ), trim( ucfirst( $software['database_engine'] ) . ' ' . $software['database_version'] ) );
		/* translators: %s: web server family. */
		$lines[] = sprintf( __( 'Web server family: %s', '1gbits-site-move-inspector' ), $software['web_server'] );
		/* translators: %s: active plugin count. */
		$lines[] = sprintf( __( 'Active plugins: %s', '1gbits-site-move-inspector' ), $software['active_plugin_count'] );
		/* translators: %s: inactive plugin count. */
		$lines[] = sprintf( __( 'Inactive plugins: %s', '1gbits-site-move-inspector' ), $software['inactive_plugin_count'] );
		/* translators: %s: scanned file count. */
		$lines[] = sprintf( __( 'Scanned files: %s', '1gbits-site-move-inspector' ), $files['file_count'] );
		/* translators: %s: scanned file bytes. */
		$lines[] = sprintf( __( 'Scanned file bytes: %s', '1gbits-site-move-inspector' ), $files['total_bytes'] );
		/* translators: %s: database table count. */
		$lines[] = sprintf( __( 'Database tables: %s', '1gbits-site-move-inspector' ), $database['table_count'] );
		/* translators: %s: database size in bytes. */
		$lines[] = sprintf( __( 'Database bytes: %s', '1gbits-site-move-inspector' ), $database['total_bytes'] );
		$lines[] = '';
		$lines[] = __( 'This report intentionally excludes site domains, absolute paths, upload filenames, IP addresses, emails, credentials, content, option values, cookies, request headers, logs, and stack traces.', '1gbits-site-move-inspector' );

		return implode( "\r\n", $lines ) . "\r\n";
	}
}
