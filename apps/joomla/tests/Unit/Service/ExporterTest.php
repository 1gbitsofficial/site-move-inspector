<?php
/**
 * @package     OneGbits.Component.SiteMoveInspector
 * @subpackage  Tests.Unit.Service
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace OneGbits\Component\SiteMoveInspector\Tests\Unit\Service;

use OneGbits\Component\SiteMoveInspector\Administrator\Service\Exporter;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\ReportBuilder;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

$serviceRoot = dirname(__DIR__, 3)
    . '/component/administrator/components/com_sitemoveinspector/src/Service/';
require_once $serviceRoot . 'DestinationProfile.php';
require_once $serviceRoot . 'ReportBuilder.php';
require_once $serviceRoot . 'Redactor.php';
require_once $serviceRoot . 'Exporter.php';

final class ExporterTest extends TestCase
{
    public function testJsonAndTextAreInMemoryPrivacySafeExports(): void
    {
        $report = ReportBuilder::create();
        $report['extension_version'] = '1.0.0';
        $report['inventory']['software'] = [
            'joomla_version'   => '5.3.1',
            'php_version'      => '8.3.2',
            'database_engine'  => 'mysql',
            'database_version' => '8.4.0',
            'extensions'       => [[
                'name'    => 'Private Customer Extension',
                'type'    => 'component',
                'version' => '2.0.0',
                'enabled' => true,
            ]],
            'templates'        => [[
                'name'    => 'Private Customer Template',
                'type'    => 'site',
                'version' => '1.0.0',
                'active'  => true,
            ]],
        ];
        $report['inventory']['files'] = [
            'file_count'  => 4,
            'total_bytes' => 1024,
        ];
        $report['inventory']['database'] = [
            'available'   => true,
            'table_count' => 8,
            'total_bytes' => 2048,
        ];
        ReportBuilder::addCheck(
            $report,
            'environment',
            'Environment',
            [
                'id'      => 'server',
                'status'  => ReportBuilder::STATUS_CRITICAL,
                'label'   => 'Server',
                'value'   => 'admin@example.com',
                'message' => 'Token=super-secret',
            ]
        );
        ReportBuilder::finalize($report);

        $json = Exporter::toJson($report);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $text = Exporter::toText($report);

        self::assertSame('high_risk', $decoded['summary']['overall']);
        self::assertStringContainsString('SAFE INVENTORY', $text);
        self::assertStringContainsString('Extensions: 1', $text);

        foreach (
            [
                'admin@example.com',
                'super-secret',
                'Private Customer Extension',
                'Private Customer Template',
            ] as $privateValue
        ) {
            self::assertStringNotContainsString($privateValue, $json);
            self::assertStringNotContainsString($privateValue, $text);
        }
    }
}
