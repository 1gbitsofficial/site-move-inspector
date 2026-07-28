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

use OneGbits\Component\SiteMoveInspector\Administrator\Service\ReportBuilder;
use PHPUnit\Framework\TestCase;

defined('_JEXEC') || define('_JEXEC', 1);

$serviceRoot = dirname(__DIR__, 3)
    . '/component/administrator/components/com_sitemoveinspector/src/Service/';
require_once $serviceRoot . 'DestinationProfile.php';
require_once $serviceRoot . 'ReportBuilder.php';

final class ReportBuilderTest extends TestCase
{
    public function testCriticalStatusHasPrecedence(): void
    {
        $report = ReportBuilder::create();
        ReportBuilder::addCheck(
            $report,
            'environment',
            'Environment',
            ['id' => 'runtime', 'status' => ReportBuilder::STATUS_WARNING]
        );
        ReportBuilder::addCheck(
            $report,
            'environment',
            'Environment',
            ['id' => 'database', 'status' => ReportBuilder::STATUS_CRITICAL]
        );
        ReportBuilder::finalize($report);

        self::assertSame('high_risk', $report['summary']['overall']);
        self::assertSame(1, $report['summary']['counts']['warning']);
        self::assertSame(1, $report['summary']['counts']['critical']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $report['generated_at']);
    }

    public function testPartialAndUnknownReportsRequireReview(): void
    {
        $report = ReportBuilder::create(['database_engine' => 'mysql']);
        ReportBuilder::addCheck(
            $report,
            'Files & Storage',
            'Files',
            ['id' => 'Unsafe ID!', 'status' => 'invalid']
        );
        ReportBuilder::markPartial($report, 'Safety limit reached.');
        ReportBuilder::markPartial($report, 'Safety limit reached.');
        ReportBuilder::finalize($report);

        self::assertSame('review_recommended', $report['summary']['overall']);
        self::assertSame(['Safety limit reached.'], $report['partial_reasons']);
        self::assertSame(
            ReportBuilder::STATUS_UNKNOWN,
            $report['sections']['filesstorage']['checks'][0]['status']
        );
        self::assertSame('unsafeid', $report['sections']['filesstorage']['checks'][0]['id']);
    }

    public function testPassAndNotApplicableChecksHaveNoBlockers(): void
    {
        $report = ReportBuilder::create();
        ReportBuilder::addCheck(
            $report,
            'environment',
            'Environment',
            ['id' => 'php', 'status' => ReportBuilder::STATUS_PASS]
        );
        ReportBuilder::addCheck(
            $report,
            'destination',
            'Destination',
            ['id' => 'profile', 'status' => ReportBuilder::STATUS_NOT_APPLICABLE]
        );
        ReportBuilder::finalize($report);

        self::assertSame('no_blockers', $report['summary']['overall']);
    }

    public function testCorruptStatusCannotProduceAClearSummary(): void
    {
        $report = ReportBuilder::create();
        $report['sections']['corrupt'] = [
            'id' => 'corrupt',
            'title' => 'Corrupt',
            'checks' => [['status' => 'unexpected']],
        ];
        ReportBuilder::finalize($report);

        self::assertSame('review_recommended', $report['summary']['overall']);
        self::assertSame(1, $report['summary']['counts']['unknown']);
    }
}
