<?php
/**
 * @package     OneGbits.Component.SiteMoveInspector
 * @subpackage  Administrator.Service
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace OneGbits\Component\SiteMoveInspector\Administrator\Service;

defined('_JEXEC') or die;

/**
 * Builds the stable internal report structure.
 */
final class ReportBuilder
{
    public const STATUS_PASS = 'pass';
    public const STATUS_WARNING = 'warning';
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /**
     * Create an empty Joomla inspection report.
     *
     * @param array<string, mixed> $destination Optional destination profile.
     *
     * @return array<string, mixed>
     */
    public static function create(array $destination = []): array
    {
        return [
            'schema_version'   => '1.0',
            'extension_version' => '',
            'generated_at'     => '',
            'scope'            => 'site',
            'partial'          => false,
            'partial_reasons'  => [],
            'destination'      => DestinationProfile::sanitize($destination),
            'summary'          => self::summarize([]),
            'sections'         => [],
            'inventory'        => [
                'files'    => [],
                'database' => [],
                'software' => [],
            ],
        ];
    }

    /**
     * Add a normalized check to a stable report section.
     *
     * @param array<string, mixed> $report Report passed by reference.
     * @param array<string, mixed> $check  Check payload.
     */
    public static function addCheck(
        array &$report,
        string $sectionId,
        string $sectionTitle,
        array $check
    ): void {
        $sectionId = self::sanitizeKey($sectionId);
        $status = in_array(
            $check['status'] ?? '',
            [
                self::STATUS_PASS,
                self::STATUS_WARNING,
                self::STATUS_CRITICAL,
                self::STATUS_UNKNOWN,
                self::STATUS_NOT_APPLICABLE,
            ],
            true
        ) ? (string) $check['status'] : self::STATUS_UNKNOWN;

        if (!isset($report['sections']) || !is_array($report['sections'])) {
            $report['sections'] = [];
        }

        if (!isset($report['sections'][$sectionId]) || !is_array($report['sections'][$sectionId])) {
            $report['sections'][$sectionId] = [
                'id'     => $sectionId,
                'title'  => $sectionTitle,
                'checks' => [],
            ];
        }

        $report['sections'][$sectionId]['checks'][] = [
            'id'             => self::sanitizeKey((string) ($check['id'] ?? '')),
            'status'         => $status,
            'label'          => (string) ($check['label'] ?? ''),
            'value'          => (string) ($check['value'] ?? ''),
            'message'        => (string) ($check['message'] ?? ''),
            'recommendation' => (string) ($check['recommendation'] ?? ''),
        ];
    }

    /**
     * Mark an inspection as incomplete without duplicating reasons.
     *
     * @param array<string, mixed> $report Report passed by reference.
     */
    public static function markPartial(array &$report, string $reason): void
    {
        $report['partial'] = true;

        if (!isset($report['partial_reasons']) || !is_array($report['partial_reasons'])) {
            $report['partial_reasons'] = [];
        }

        if ($reason !== '' && !in_array($reason, $report['partial_reasons'], true)) {
            $report['partial_reasons'][] = $reason;
        }
    }

    /**
     * Add the UTC timestamp and risk summary.
     *
     * @param array<string, mixed> $report Report passed by reference.
     */
    public static function finalize(array &$report): void
    {
        $report['generated_at'] = gmdate('c');
        $report['summary'] = self::summarize($report);
    }

    /**
     * Calculate status totals and overall risk.
     *
     * @param array<string, mixed> $report Report payload.
     *
     * @return array{
     *     overall: string,
     *     counts: array{
     *         pass: int,
     *         warning: int,
     *         critical: int,
     *         unknown: int,
     *         not_applicable: int
     *     }
     * }
     */
    private static function summarize(array $report): array
    {
        $counts = [
            self::STATUS_PASS           => 0,
            self::STATUS_WARNING        => 0,
            self::STATUS_CRITICAL       => 0,
            self::STATUS_UNKNOWN        => 0,
            self::STATUS_NOT_APPLICABLE => 0,
        ];

        $sections = isset($report['sections']) && is_array($report['sections'])
            ? $report['sections']
            : [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $checks = isset($section['checks']) && is_array($section['checks'])
                ? $section['checks']
                : [];

            foreach ($checks as $check) {
                if (!is_array($check)) {
                    continue;
                }

                $status = (string) ($check['status'] ?? self::STATUS_UNKNOWN);

                $status = array_key_exists($status, $counts)
                    ? $status
                    : self::STATUS_UNKNOWN;
                ++$counts[$status];
            }
        }

        if ($counts[self::STATUS_CRITICAL] > 0) {
            $overall = 'high_risk';
        } elseif (
            !empty($report['partial'])
            || $counts[self::STATUS_WARNING] > 0
            || $counts[self::STATUS_UNKNOWN] > 0
        ) {
            $overall = 'review_recommended';
        } else {
            $overall = 'no_blockers';
        }

        return [
            'overall' => $overall,
            'counts'  => $counts,
        ];
    }

    /**
     * Normalize a machine-readable identifier without Joomla runtime helpers.
     */
    private static function sanitizeKey(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($key));
    }
}
