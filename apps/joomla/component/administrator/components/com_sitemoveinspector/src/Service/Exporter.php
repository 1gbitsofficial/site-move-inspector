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
 * Serializes privacy-safe reports without filesystem side effects.
 */
final class Exporter
{
    /**
     * Export a report as formatted JSON.
     *
     * @param array<string, mixed> $report
     *
     * @throws \JsonException When encoding unexpectedly fails.
     */
    public static function toJson(array $report): string
    {
        return json_encode(
            Redactor::forExport($report),
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Export a concise, plain-text report.
     *
     * @param array<string, mixed> $report
     */
    public static function toText(array $report): string
    {
        $report = Redactor::forExport($report);
        $summary = $report['summary'];
        $lines = [
            'Site Move Inspector for Joomla',
            '=======================================',
            'Schema: ' . $report['schema_version'],
            'Extension: ' . $report['extension_version'],
            'Generated (UTC): ' . $report['generated_at'],
            'Overall: ' . $summary['overall'],
            'Partial scan: ' . ($report['partial'] ? 'yes' : 'no'),
            '',
        ];

        if ($report['partial_reasons'] !== []) {
            $lines[] = 'Partial scan reasons:';

            foreach ($report['partial_reasons'] as $reason) {
                $lines[] = '- ' . $reason;
            }

            $lines[] = '';
        }

        foreach ($report['sections'] as $section) {
            $lines[] = strtoupper($section['title']);
            $lines[] = str_repeat('-', max(3, strlen($section['title'])));

            foreach ($section['checks'] as $check) {
                $lines[] = sprintf(
                    '[%s] %s: %s',
                    strtoupper($check['status']),
                    $check['label'],
                    $check['value']
                );

                if ($check['message'] !== '') {
                    $lines[] = '  ' . $check['message'];
                }

                if ($check['recommendation'] !== '') {
                    $lines[] = '  Action: ' . $check['recommendation'];
                }
            }

            $lines[] = '';
        }

        $files = $report['inventory']['files'];
        $database = $report['inventory']['database'];
        $software = $report['inventory']['software'];
        $lines[] = 'SAFE INVENTORY';
        $lines[] = '--------------';
        $lines[] = 'Joomla: ' . $software['joomla_version'];
        $lines[] = 'PHP: ' . $software['php_version'];
        $lines[] = 'Database: ' . trim(ucfirst($software['database_engine']) . ' ' . $software['database_version']);
        $lines[] = 'Web server family: ' . $software['web_server'];
        $lines[] = 'Extensions: ' . $software['extension_count'];
        $lines[] = 'Enabled extensions: ' . $software['enabled_extension_count'];
        $lines[] = 'Templates: ' . $software['template_count'];
        $lines[] = 'Active templates: ' . $software['active_template_count'];
        $lines[] = 'Scanned files: ' . $files['file_count'];
        $lines[] = 'Scanned file bytes: ' . $files['total_bytes'];
        $lines[] = 'Database tables: ' . $database['table_count'];
        $lines[] = 'Database bytes: ' . $database['total_bytes'];
        $lines[] = '';
        $lines[] = 'Extension/template names, filenames, domains, addresses, absolute paths, credentials, and content are intentionally excluded.';

        return implode("\r\n", $lines) . "\r\n";
    }
}
