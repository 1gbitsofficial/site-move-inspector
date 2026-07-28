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

use OneGbits\Component\SiteMoveInspector\Administrator\Service\Redactor;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

$serviceRoot = dirname(__DIR__, 3)
    . '/component/administrator/components/com_sitemoveinspector/src/Service/';
require_once $serviceRoot . 'DestinationProfile.php';
require_once $serviceRoot . 'Redactor.php';

final class RedactorTest extends TestCase
{
    public function testExportUsesAnAllowlistAndAnonymousAggregates(): void
    {
        $report = [
            'schema_version'    => '1.0',
            'extension_version' => '1.2.0',
            'generated_at'      => '2026-07-28T10:00:00+00:00',
            'scope'             => 'site',
            'partial'           => true,
            'partial_reasons'   => [
                'Inspect /srv/customers/acme/site and admin@private.example.com',
            ],
            'destination'       => [
                'php_version'      => '8.3',
                'database_engine'  => 'mysql',
                'database_version' => '8.4',
                'disk_space_gb'    => 50,
                'hostname'         => 'destination.example.com',
            ],
            'summary'           => [
                'overall' => 'review_recommended',
                'counts'  => ['warning' => 1],
            ],
            'sections'          => [
                'environment' => [
                    'id'     => 'environment',
                    'title'  => 'Environment',
                    'checks' => [[
                        'id'             => 'private',
                        'status'         => 'warning',
                        'label'          => 'Customer Connector',
                        'value'          => 'https://private.example.com 192.0.2.20',
                        'message'        => 'password=hunter2',
                        'recommendation' => 'Review Customer Connector',
                    ]],
                ],
            ],
            'inventory'         => [
                'files'    => [
                    'file_count'  => 3,
                    'total_bytes' => 500,
                    'top_files'   => [[
                        'path'  => 'images/customer-contract.pdf',
                        'bytes' => 400,
                    ]],
                    'categories'  => [[
                        'id'         => 'media',
                        'label'      => 'Private uploads',
                        'file_count' => 3,
                        'bytes'      => 500,
                    ]],
                ],
                'database' => [
                    'available'   => true,
                    'table_count' => 2,
                    'total_bytes' => 900,
                    'top_tables'  => [[
                        'name'  => 'jos_customer_contracts',
                        'bytes' => 900,
                    ]],
                ],
                'software' => [
                    'joomla_version'   => '5.3.1',
                    'php_version'      => '8.3.2',
                    'database_engine'  => 'mysql',
                    'database_version' => '8.4.0',
                    'web_server'       => 'Nginx',
                    'extensions'       => [
                        [
                            'name'     => 'Customer Connector',
                            'element'  => 'com_customer',
                            'filename' => 'customer.php',
                            'type'     => 'component',
                            'version'  => '2.4.0',
                            'enabled'  => true,
                        ],
                        [
                            'name'    => 'Another private extension',
                            'type'    => 'component',
                            'version' => '2.4.0',
                            'enabled' => false,
                        ],
                    ],
                    'templates'        => [[
                        'name'     => 'Acme Customer Portal',
                        'filename' => 'index.php',
                        'type'     => 'site',
                        'version'  => '3.0.0',
                        'active'   => true,
                    ]],
                ],
            ],
            'private_token'     => 'must never export',
        ];

        $export = Redactor::forExport($report);
        $serialized = json_encode($export, JSON_THROW_ON_ERROR);

        foreach (
            [
                '/srv/customers/acme/site',
                'admin@private.example.com',
                'private.example.com',
                '192.0.2.20',
                'hunter2',
                'Customer Connector',
                'customer.php',
                'Acme Customer Portal',
                'customer-contract.pdf',
                'jos_customer_contracts',
                'must never export',
            ] as $privateValue
        ) {
            self::assertStringNotContainsString($privateValue, $serialized);
        }

        self::assertSame(
            [
                'schema_version',
                'extension_version',
                'generated_at',
                'scope',
                'partial',
                'partial_reasons',
                'destination',
                'summary',
                'sections',
                'inventory',
            ],
            array_keys($export)
        );
        self::assertArrayNotHasKey('top_files', $export['inventory']['files']);
        self::assertArrayNotHasKey('top_tables', $export['inventory']['database']);
        self::assertSame(2, $export['inventory']['software']['extension_count']);
        self::assertSame(1, $export['inventory']['software']['enabled_extension_count']);
        self::assertSame(
            [
                'type'          => 'component',
                'version'       => '2.4.0',
                'count'         => 2,
                'enabled_count' => 1,
            ],
            $export['inventory']['software']['extension_types'][0]
        );
        self::assertSame(1, $export['inventory']['software']['template_count']);
        self::assertSame(1, $export['inventory']['software']['active_template_count']);
    }

    public function testDisabledAggregateDoesNotFallBackToAnUnrelatedTopLevelCount(): void
    {
        $export = Redactor::forExport([
            'inventory' => [
                'software' => [
                    'extensions' => [[
                        'type' => 'component',
                        'version' => '1.0.0',
                        'enabled' => false,
                    ]],
                    'enabled_extension_count' => 9,
                ],
            ],
        ]);

        self::assertSame(1, $export['inventory']['software']['extension_count']);
        self::assertSame(0, $export['inventory']['software']['enabled_extension_count']);
    }

    public function testShortUnixPathsAreRedactedWithoutMatchingUrlsOrOrdinarySlashes(): void
    {
        $export = Redactor::forExport([
            'sections' => [[
                'id'     => 'privacy',
                'title'  => 'Privacy',
                'checks' => [[
                    'id'             => 'paths',
                    'status'         => 'warning',
                    'label'          => 'Path review',
                    'value'          => 'Inspect /tmp and /root',
                    'message'        => 'Open https://docs.example.com/help for PHP/Joomla notes dated 2026/07/28.',
                    'recommendation' => 'Keep 8/10 checks and/or notes.',
                ]],
            ]],
        ]);

        $check = $export['sections'][0]['checks'][0];

        self::assertSame(
            'Inspect [redacted-path] and [redacted-path]',
            $check['value']
        );
        self::assertSame(
            'Open [redacted-url] for PHP/Joomla notes dated 2026/07/28.',
            $check['message']
        );
        self::assertSame('Keep 8/10 checks and/or notes.', $check['recommendation']);
    }
}
