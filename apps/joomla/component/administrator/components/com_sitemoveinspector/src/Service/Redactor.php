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
 * Rebuilds an export from an explicit privacy-safe allowlist.
 */
final class Redactor
{
    private const REDACTED_URL = '[redacted-url]';
    private const REDACTED_DOMAIN = '[redacted-domain]';
    private const REDACTED_EMAIL = '[redacted-email]';
    private const REDACTED_IP = '[redacted-ip]';
    private const REDACTED_PATH = '[redacted-path]';
    private const REDACTED_SECRET = '[redacted-secret]';
    private const REDACTED_IDENTIFIER = '[redacted-identifier]';

    /**
     * Return the public Joomla report schema.
     *
     * @param array<string, mixed> $report Internal administrator report.
     *
     * @return array<string, mixed>
     */
    public static function forExport(array $report): array
    {
        $inventory = self::arrayValue($report, 'inventory');
        $files = self::arrayValue($inventory, 'files');
        $database = self::arrayValue($inventory, 'database');
        $software = self::arrayValue($inventory, 'software');
        $identifiers = self::siteIdentifiers($inventory);

        $schemaVersion = self::sanitizeVersion($report['schema_version'] ?? '1.0');

        return [
            'schema_version'    => $schemaVersion !== '' ? $schemaVersion : '1.0',
            'extension_version' => self::sanitizeVersion($report['extension_version'] ?? ''),
            'generated_at'      => self::timestamp($report['generated_at'] ?? ''),
            'scope'             => ($report['scope'] ?? '') === 'site' ? 'site' : 'site',
            'partial'           => !empty($report['partial']),
            'partial_reasons'   => self::stringList(
                is_array($report['partial_reasons'] ?? null) ? $report['partial_reasons'] : [],
                20,
                $identifiers
            ),
            'destination'       => DestinationProfile::sanitize(self::arrayValue($report, 'destination')),
            'summary'           => self::summary(self::arrayValue($report, 'summary')),
            'sections'          => self::sections(
                is_array($report['sections'] ?? null) ? $report['sections'] : [],
                $identifiers
            ),
            'inventory'         => [
                'files'    => self::files($files),
                'database' => self::database($database),
                'software' => self::software($software, $identifiers),
            ],
        ];
    }

    /**
     * Normalize report summary values.
     *
     * @param array<string, mixed> $summary
     *
     * @return array<string, mixed>
     */
    private static function summary(array $summary): array
    {
        $overall = in_array(
            $summary['overall'] ?? '',
            ['high_risk', 'review_recommended', 'no_blockers'],
            true
        ) ? (string) $summary['overall'] : 'review_recommended';

        $counts = [];

        foreach (['pass', 'warning', 'critical', 'unknown', 'not_applicable'] as $status) {
            $counts[$status] = self::positiveInt($summary['counts'][$status] ?? 0);
        }

        return [
            'overall' => $overall,
            'counts'  => $counts,
        ];
    }

    /**
     * Keep only normalized result sections.
     *
     * @param array<mixed>  $sections
     * @param array<string> $identifiers
     *
     * @return array<int, array<string, mixed>>
     */
    private static function sections(array $sections, array $identifiers): array
    {
        $output = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $checks = [];
            $rawChecks = is_array($section['checks'] ?? null) ? $section['checks'] : [];

            foreach ($rawChecks as $check) {
                if (!is_array($check)) {
                    continue;
                }

                $status = in_array(
                    $check['status'] ?? '',
                    ['pass', 'warning', 'critical', 'unknown', 'not_applicable'],
                    true
                ) ? (string) $check['status'] : 'unknown';

                $checks[] = [
                    'id'             => self::sanitizeKey($check['id'] ?? ''),
                    'status'         => $status,
                    'label'          => self::redactText($check['label'] ?? '', $identifiers),
                    'value'          => self::redactText($check['value'] ?? '', $identifiers),
                    'message'        => self::redactText($check['message'] ?? '', $identifiers),
                    'recommendation' => self::redactText($check['recommendation'] ?? '', $identifiers),
                ];
            }

            $output[] = [
                'id'     => self::sanitizeKey($section['id'] ?? ''),
                'title'  => self::redactText($section['title'] ?? '', $identifiers),
                'checks' => $checks,
            ];
        }

        return $output;
    }

    /**
     * Export aggregate filesystem metadata without paths or filenames.
     *
     * @param array<string, mixed> $files
     *
     * @return array<string, mixed>
     */
    private static function files(array $files): array
    {
        $types = [];
        $categories = is_array($files['categories'] ?? null) ? $files['categories'] : [];

        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $type = self::sanitizeKey($category['id'] ?? $category['type'] ?? '');

            if ($type === '') {
                continue;
            }

            $types[] = [
                'type'       => $type,
                'file_count' => self::positiveInt($category['file_count'] ?? $category['count'] ?? 0),
                'bytes'      => self::positiveInt($category['bytes'] ?? 0),
            ];
        }

        return [
            'complete'          => !empty($files['complete']),
            'partial'           => !empty($files['partial']),
            'processed_entries' => self::positiveInt($files['processed_entries'] ?? 0),
            'file_count'        => self::positiveInt($files['file_count'] ?? 0),
            'directory_count'   => self::positiveInt($files['directory_count'] ?? 0),
            'total_bytes'       => self::positiveInt($files['total_bytes'] ?? 0),
            'source_free_bytes' => self::positiveInt($files['source_free_bytes'] ?? 0),
            'types'             => array_slice($types, 0, 50),
        ];
    }

    /**
     * Export database totals without table names.
     *
     * @param array<string, mixed> $database
     *
     * @return array<string, mixed>
     */
    private static function database(array $database): array
    {
        $engines = [];
        $rawEngines = is_array($database['engines'] ?? null) ? $database['engines'] : [];

        foreach ($rawEngines as $key => $engine) {
            if (is_array($engine)) {
                $type = self::sanitizeKey($engine['type'] ?? $engine['engine'] ?? $key);
                $count = self::positiveInt($engine['count'] ?? 0);
            } else {
                $type = self::sanitizeKey($key);
                $count = self::positiveInt($engine);
            }

            if ($type !== '') {
                $engines[] = ['type' => $type, 'count' => $count];
            }
        }

        return [
            'available'        => !empty($database['available']),
            'table_count'      => self::positiveInt($database['table_count'] ?? 0),
            'total_bytes'      => self::positiveInt($database['total_bytes'] ?? 0),
            'non_innodb_count' => self::positiveInt($database['non_innodb_count'] ?? 0),
            'engines'          => array_slice($engines, 0, 20),
        ];
    }

    /**
     * Export software versions and anonymous extension/template aggregates.
     *
     * @param array<string, mixed> $software
     * @param array<string>        $identifiers
     *
     * @return array<string, mixed>
     */
    private static function software(array $software, array $identifiers): array
    {
        $hasExtensionItems = is_array($software['extensions'] ?? null)
            || is_array($software['extension_types'] ?? null);
        $hasTemplateItems = is_array($software['templates'] ?? null)
            || is_array($software['template_types'] ?? null);
        $extensionItems = is_array($software['extensions'] ?? null)
            ? $software['extensions']
            : (is_array($software['extension_types'] ?? null) ? $software['extension_types'] : []);
        $templateItems = is_array($software['templates'] ?? null)
            ? $software['templates']
            : (is_array($software['template_types'] ?? null) ? $software['template_types'] : []);

        $extensionGroups = self::aggregateTypes($extensionItems, false);
        $templateGroups = self::aggregateTypes($templateItems, true);

        $extensionCount = $hasExtensionItems
            ? $extensionGroups['count']
            : self::positiveInt($software['extension_count'] ?? 0);
        $enabledExtensionCount = $hasExtensionItems
            ? $extensionGroups['active_count']
            : self::positiveInt($software['enabled_extension_count'] ?? 0);
        $templateCount = $hasTemplateItems
            ? $templateGroups['count']
            : self::positiveInt($software['template_count'] ?? 0);
        $activeTemplateCount = $hasTemplateItems && $templateGroups['active_count'] > 0
            ? $templateGroups['active_count']
            : self::positiveInt($software['active_template_count'] ?? 0);

        return [
            'joomla_version'          => self::sanitizeVersion($software['joomla_version'] ?? ''),
            'php_version'             => self::sanitizeVersion($software['php_version'] ?? ''),
            'database_engine'         => self::sanitizeKey($software['database_engine'] ?? ''),
            'database_version'        => self::sanitizeVersion($software['database_version'] ?? ''),
            'web_server'              => self::redactText($software['web_server'] ?? '', $identifiers),
            'extension_count'         => $extensionCount,
            'enabled_extension_count' => min($extensionCount, $enabledExtensionCount),
            'extension_types'         => $extensionGroups['groups'],
            'template_count'          => $templateCount,
            'active_template_count'   => min($templateCount, $activeTemplateCount),
            'template_types'          => $templateGroups['groups'],
        ];
    }

    /**
     * Group extension metadata by type and version, never by display name.
     *
     * @param array<mixed> $items
     *
     * @return array{count: int, active_count: int, groups: array<int, array<string, mixed>>}
     */
    private static function aggregateTypes(array $items, bool $templates): array
    {
        $groups = [];
        $total = 0;
        $activeTotal = 0;

        foreach (array_slice($items, 0, 1000, true) as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = self::sanitizeKey($item['type'] ?? (is_string($key) ? $key : ''));
            $version = self::sanitizeVersion($item['version'] ?? '');

            if ($type === '') {
                $type = $templates ? 'template' : 'extension';
            }

            $count = max(1, self::positiveInt($item['count'] ?? 1));
            $activeKey = $templates ? 'active_count' : 'enabled_count';
            $activeFlag = $templates ? 'active' : 'enabled';
            $activeCount = array_key_exists($activeKey, $item)
                ? self::positiveInt($item[$activeKey])
                : (!empty($item[$activeFlag]) ? $count : 0);
            $activeCount = min($count, $activeCount);
            $groupKey = $type . "\0" . $version;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'type'    => $type,
                    'version' => $version,
                    'count'   => 0,
                    $activeKey => 0,
                ];
            }

            $groups[$groupKey]['count'] += $count;
            $groups[$groupKey][$activeKey] += $activeCount;
            $total += $count;
            $activeTotal += $activeCount;
        }

        $groups = array_values($groups);

        usort(
            $groups,
            static fn(array $left, array $right): int =>
                [$left['type'], $left['version']] <=> [$right['type'], $right['version']]
        );

        return [
            'count'        => $total,
            'active_count' => $activeTotal,
            'groups'       => array_slice($groups, 0, 100),
        ];
    }

    /**
     * Find private extension, template, table and file identifiers.
     *
     * @param array<string, mixed> $inventory
     *
     * @return array<string>
     */
    private static function siteIdentifiers(array $inventory): array
    {
        $identifiers = [];
        $software = self::arrayValue($inventory, 'software');

        foreach (['extensions', 'templates'] as $collection) {
            $items = is_array($software[$collection] ?? null) ? $software[$collection] : [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach (['name', 'element', 'filename', 'path'] as $key) {
                    self::addIdentifier($identifiers, $item[$key] ?? '');
                }
            }
        }

        $files = self::arrayValue($inventory, 'files');

        foreach (['top_files', 'unreadable_samples', 'outside_root_samples'] as $collection) {
            $items = is_array($files[$collection] ?? null) ? $files[$collection] : [];

            foreach ($items as $item) {
                self::addIdentifier(
                    $identifiers,
                    is_array($item) ? ($item['path'] ?? $item['filename'] ?? '') : $item
                );
            }
        }

        $database = self::arrayValue($inventory, 'database');
        $tables = is_array($database['top_tables'] ?? null) ? $database['top_tables'] : [];

        foreach ($tables as $table) {
            if (is_array($table)) {
                self::addIdentifier($identifiers, $table['name'] ?? '');
            }
        }

        usort(
            $identifiers,
            static fn(string $left, string $right): int => strlen($right) <=> strlen($left)
        );

        return $identifiers;
    }

    /**
     * Append a useful private identifier and its non-generic words.
     *
     * @param array<string> $identifiers
     */
    private static function addIdentifier(array &$identifiers, mixed $value): void
    {
        $value = self::plainText($value);

        if (strlen($value) >= 4 && !in_array($value, $identifiers, true)) {
            $identifiers[] = $value;
        }

        $generic = [
            'administrator', 'component', 'extension', 'file', 'joomla', 'module',
            'package', 'plugin', 'site', 'template',
        ];
        $words = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        foreach (is_array($words) ? $words : [] as $word) {
            if (
                strlen($word) >= 4
                && !in_array(strtolower($word), $generic, true)
                && !in_array($word, $identifiers, true)
            ) {
                $identifiers[] = $word;
            }
        }
    }

    /**
     * Redact private substrings while preserving generic guidance.
     *
     * @param array<string> $identifiers
     */
    private static function redactText(mixed $value, array $identifiers = []): string
    {
        $text = self::plainText($value);

        if ($text === '') {
            return '';
        }

        foreach ($identifiers as $identifier) {
            $text = self::replace(
                '~(?<![\p{L}\p{N}])' . preg_quote($identifier, '~') . '(?![\p{L}\p{N}])~iu',
                self::REDACTED_IDENTIFIER,
                $text
            );
        }

        $text = self::replace(
            '~\b(?:password|passwd|pwd|token|api[_ -]?key|client[_ -]?secret|authorization)\s*[:=]\s*[^\s,;]+~iu',
            self::REDACTED_SECRET,
            $text
        );
        $text = self::replace(
            '~\bBearer\s+[A-Za-z0-9._\~+/\-=]+~i',
            self::REDACTED_SECRET,
            $text
        );
        $text = self::replace(
            '~\b[A-Z][A-Z0-9+.-]*://[^\s<>"\']+~iu',
            self::REDACTED_URL,
            $text
        );
        $text = self::replace(
            '~(?<![A-Z0-9])[A-Z]:[/\\\\][^\r\n<>:"|?*,;(){}\[\]]*~iu',
            self::REDACTED_PATH,
            $text
        );
        $text = self::replace(
            '~(?<![A-Z0-9])\\\\\\\\[^\r\n<>:"|?*,;(){}\[\]]+~iu',
            self::REDACTED_PATH,
            $text
        );
        $text = self::replace(
            '~(?<![\p{L}\p{N}])/(?:[^/\s<>"\']+/)+[^/\s<>"\']*~u',
            self::REDACTED_PATH,
            $text
        );
        $text = self::replace(
            '~(?<![\p{L}\p{N}:/.])/(?!/)[\p{L}\p{N}._\~+@%=-]+(?=$|[\s,;!?)}\]])~u',
            self::REDACTED_PATH,
            $text
        );
        $text = self::replace(
            '~(?<![A-Z0-9.!#$%&\'*+/=?^_`{|}\~-])[A-Z0-9.!#$%&\'*+/=?^_`{|}\~-]+@(?:[A-Z0-9-]+\.)+[A-Z]{2,63}(?![A-Z0-9._-])~iu',
            self::REDACTED_EMAIL,
            $text
        );
        $text = self::redactIpv6($text);
        $text = self::replace(
            '~(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)(?::\d{1,5})?(?![\d.])~',
            self::REDACTED_IP,
            $text
        );
        $text = self::replace(
            '~(?<![\p{L}\p{N}@._-])(?:[\p{L}\p{N}-]+\.)+(?:[\p{L}]{2,63}|xn--[A-Z0-9-]{2,59})(?::\d{1,5})?(?![\p{L}\p{N}._-])~iu',
            self::REDACTED_DOMAIN,
            $text
        );

        return trim($text);
    }

    private static function redactIpv6(string $text): string
    {
        $result = preg_replace_callback(
            '~(?<![0-9A-Z:])(?P<address>\[?(?:[0-9A-F]{0,4}:){2,}[0-9A-F:.]*(?:%[0-9A-Z._\~-]+)?\]?)(?::\d{1,5})?(?![0-9A-Z:])~i',
            static function (array $matches): string {
                $address = trim((string) $matches['address'], '[]');
                $address = (string) preg_replace('/%.*$/', '', $address);

                return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? self::REDACTED_IP
                    : (string) $matches[0];
            },
            $text
        );

        return is_string($result) ? $result : $text;
    }

    private static function replace(string $pattern, string $replacement, string $text): string
    {
        $result = preg_replace($pattern, $replacement, $text);

        return is_string($result) ? $result : $text;
    }

    private static function plainText(mixed $value): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);

        return trim(is_string($text) ? $text : '');
    }

    /**
     * @param array<mixed>  $values
     * @param array<string> $identifiers
     *
     * @return array<string>
     */
    private static function stringList(array $values, int $limit, array $identifiers = []): array
    {
        $output = [];

        foreach (array_slice($values, 0, max(0, $limit)) as $value) {
            $output[] = self::redactText($value, $identifiers);
        }

        return $output;
    }

    private static function timestamp(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match(
            '/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/',
            $value
        ) === 1 ? $value : '';
    }

    private static function sanitizeVersion(mixed $version): string
    {
        $version = trim((string) $version);

        return preg_match('/^\d{1,3}(?:\.\d{1,3}){0,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version) === 1
            ? $version
            : '';
    }

    private static function sanitizeKey(mixed $key): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) $key)));
    }

    private static function positiveInt(mixed $value): int
    {
        return max(0, (int) $value);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function arrayValue(array $values, string $key): array
    {
        return isset($values[$key]) && is_array($values[$key]) ? $values[$key] : [];
    }
}
