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

use OneGbits\Component\SiteMoveInspector\Administrator\Service\DestinationProfile;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

require_once dirname(__DIR__, 3)
    . '/component/administrator/components/com_sitemoveinspector/src/Service/DestinationProfile.php';

final class DestinationProfileTest extends TestCase
{
    public function testItReturnsOnlySupportedSanitizedFields(): void
    {
        $profile = DestinationProfile::sanitize([
            'php_version'      => ' 8.3.2 ',
            'database_engine'  => 'MariaDB',
            'database_version' => '11.4.1',
            'disk_space_gb'    => '25.1259',
            'hostname'         => 'private.example.com',
            'password'         => 'secret',
        ]);

        self::assertSame(
            ['php_version', 'database_engine', 'database_version', 'disk_space_gb'],
            array_keys($profile)
        );
        self::assertSame('8.3.2', $profile['php_version']);
        self::assertSame('mariadb', $profile['database_engine']);
        self::assertSame('11.4.1', $profile['database_version']);
        self::assertSame(25.126, $profile['disk_space_gb']);
    }

    public function testItRejectsInvalidVersionsEngineAndCapacity(): void
    {
        $profile = DestinationProfile::sanitize([
            'php_version'      => '8.3<script>',
            'database_engine'  => 'sqlite',
            'database_version' => 'not a version',
            'disk_space_gb'    => -10,
        ]);

        self::assertSame('', $profile['php_version']);
        self::assertSame('', $profile['database_engine']);
        self::assertSame('', $profile['database_version']);
        self::assertSame(0.0, $profile['disk_space_gb']);
    }

    public function testItRejectsUnboundedCapacityValues(): void
    {
        $profile = DestinationProfile::sanitize(['disk_space_gb' => 1000000.1]);

        self::assertSame(0.0, $profile['disk_space_gb']);
    }
}
