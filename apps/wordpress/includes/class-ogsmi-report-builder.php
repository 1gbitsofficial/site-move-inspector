<?php
/**
 * Report construction helpers.
 *
 * @package OneGbits_Site_Move_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the stable, white-listed scan report schema.
 */
final class OGSMI_Report_Builder {

	const STATUS_PASS           = 'pass';
	const STATUS_WARNING        = 'warning';
	const STATUS_CRITICAL       = 'critical';
	const STATUS_UNKNOWN        = 'unknown';
	const STATUS_NOT_APPLICABLE = 'not_applicable';

	/**
	 * Create an empty report.
	 *
	 * @param array $destination Sanitized destination profile.
	 * @return array
	 */
	public static function create( array $destination ) {
		return array(
			'schema_version'  => '1.0',
			'plugin_version'  => OGSMI_VERSION,
			'generated_at'    => '',
			'scope'           => is_multisite() ? 'network' : 'site',
			'partial'         => false,
			'partial_reasons' => array(),
			'destination'     => $destination,
			'summary'         => array(),
			'sections'        => array(),
			'inventory'       => array(
				'files'    => array(),
				'database' => array(),
				'software' => array(),
			),
		);
	}

	/**
	 * Add a result to a named section.
	 *
	 * @param array  $report Report passed by reference.
	 * @param string $section_id Stable section ID.
	 * @param string $section_title Translatable title.
	 * @param array  $check Check payload.
	 */
	public static function add_check( array &$report, $section_id, $section_title, array $check ) {
		$allowed = array(
			self::STATUS_PASS,
			self::STATUS_WARNING,
			self::STATUS_CRITICAL,
			self::STATUS_UNKNOWN,
			self::STATUS_NOT_APPLICABLE,
		);

		$status = in_array( $check['status'] ?? '', $allowed, true )
			? $check['status']
			: self::STATUS_UNKNOWN;

		$normalized = array(
			'id'             => sanitize_key( $check['id'] ?? '' ),
			'status'         => $status,
			'label'          => (string) ( $check['label'] ?? '' ),
			'value'          => (string) ( $check['value'] ?? '' ),
			'message'        => (string) ( $check['message'] ?? '' ),
			'recommendation' => (string) ( $check['recommendation'] ?? '' ),
		);

		if ( ! isset( $report['sections'][ $section_id ] ) ) {
			$report['sections'][ $section_id ] = array(
				'id'     => sanitize_key( $section_id ),
				'title'  => (string) $section_title,
				'checks' => array(),
			);
		}

		$report['sections'][ $section_id ]['checks'][] = $normalized;
	}

	/**
	 * Mark a report as incomplete.
	 *
	 * @param array  $report Report passed by reference.
	 * @param string $reason Human-readable reason.
	 */
	public static function mark_partial( array &$report, $reason ) {
		$report['partial'] = true;
		if ( '' !== (string) $reason && ! in_array( $reason, $report['partial_reasons'], true ) ) {
			$report['partial_reasons'][] = (string) $reason;
		}
	}

	/**
	 * Calculate counts and the overall result.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	public static function summarize( array $report ) {
		$counts = array(
			self::STATUS_PASS           => 0,
			self::STATUS_WARNING        => 0,
			self::STATUS_CRITICAL       => 0,
			self::STATUS_UNKNOWN        => 0,
			self::STATUS_NOT_APPLICABLE => 0,
		);

		foreach ( $report['sections'] as $section ) {
			foreach ( $section['checks'] as $check ) {
				$status = $check['status'] ?? self::STATUS_UNKNOWN;
				if ( isset( $counts[ $status ] ) ) {
					++$counts[ $status ];
				}
			}
		}

		if ( $counts[ self::STATUS_CRITICAL ] > 0 ) {
			$overall = 'high_risk';
		} elseif (
			! empty( $report['partial'] )
			|| $counts[ self::STATUS_WARNING ] > 0
			|| $counts[ self::STATUS_UNKNOWN ] > 0
		) {
			$overall = 'review_recommended';
		} else {
			$overall = 'no_blockers';
		}

		return array(
			'overall' => $overall,
			'counts'  => $counts,
		);
	}

	/**
	 * Finalize timestamps and the summary.
	 *
	 * @param array $report Report passed by reference.
	 */
	public static function finalize( array &$report ) {
		$report['generated_at'] = gmdate( 'c' );
		$report['summary']      = self::summarize( $report );
	}
}
