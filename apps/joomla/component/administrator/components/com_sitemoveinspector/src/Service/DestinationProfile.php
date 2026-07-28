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
 * Normalizes the optional destination-hosting profile.
 */
final class DestinationProfile
{
    /**
     * Return only supported, privacy-safe destination fields.
     *
     * @param array<string, mixed> $profile Submitted destination values.
     *
     * @return array{
     *     php_version: string,
     *     database_engine: string,
     *     database_version: string,
     *     disk_space_gb: float
     * }
     */
    public static function sanitize(array $profile): array
    {
        $databaseEngine = strtolower(trim((string) ($profile['database_engine'] ?? '')));

        if (!in_array($databaseEngine, ['mysql', 'mariadb', 'postgresql'], true)) {
            $databaseEngine = '';
        }

        $diskSpace = $profile['disk_space_gb'] ?? 0;
        $diskSpace = is_numeric($diskSpace) ? (float) $diskSpace : 0.0;

        if (!is_finite($diskSpace) || $diskSpace < 0 || $diskSpace > 1000000) {
            $diskSpace = 0.0;
        }

        return [
            'php_version'      => self::sanitizeVersion($profile['php_version'] ?? ''),
            'database_engine'  => $databaseEngine,
            'database_version' => self::sanitizeVersion($profile['database_version'] ?? ''),
            'disk_space_gb'    => round($diskSpace, 3),
        ];
    }

    /**
     * Keep a conservative version-number shape.
     */
    private static function sanitizeVersion(mixed $version): string
    {
        $version = trim((string) $version);

        return preg_match('/^\d{1,3}(?:\.\d{1,3}){0,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version) === 1
            ? $version
            : '';
    }
}
