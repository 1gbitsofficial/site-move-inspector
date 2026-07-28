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

use FilesystemIterator;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\FileScanner;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\PathGuard;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

\defined('_JEXEC') || \define('_JEXEC', 1);

$serviceRoot = dirname(__DIR__, 3)
	. '/component/administrator/components/com_sitemoveinspector/src/Service/';
require_once $serviceRoot . 'PathGuard.php';
require_once $serviceRoot . 'FileScanner.php';

final class FileScannerTest extends TestCase
{
	public function testPathGuardRejectsASimilarSibling(): void
	{
		$root = PathGuard::normalize(dirname(__DIR__, 2) . '/fixtures/site');

		self::assertTrue(PathGuard::isWithin($root . '/images', $root));
		self::assertTrue(PathGuard::isWithin($root, $root));
		self::assertFalse(PathGuard::isWithin($root . '-backup/images', $root));
		self::assertSame('images', PathGuard::relative($root . '/images', $root));
	}

	public function testScannerCollectsOnlyBoundedMetadataAndAnonymousLargestFiles(): void
	{
		$root = dirname(__DIR__, 2) . '/fixtures/site';
		$scanner = new FileScanner();
		$state = $scanner->start(
			$root,
			[
				'images' => $root . '/images',
				'media' => $root . '/media',
				'plugins' => $root . '/plugins',
				'templates' => $root . '/templates',
				'components_admin' => $root . '/administrator/components',
			]
		);
		$steps = 0;

		while (empty($state['completed']) && $steps < 20) {
			$state = $scanner->step($state);
			++$steps;
		}

		$summary = $scanner->summarize($state);
		$categories = array_column($summary['categories'], null, 'id');

		self::assertTrue($summary['completed']);
		self::assertFalse($summary['partial']);
		self::assertSame(6, $summary['file_count']);
		self::assertSame(1, $categories['images']['file_count']);
		self::assertSame(1, $categories['media']['file_count']);
		self::assertSame(1, $categories['components']['file_count']);
		self::assertSame(1, $categories['plugins']['file_count']);
		self::assertSame(1, $categories['templates']['file_count']);
		self::assertSame(1, $categories['other']['file_count']);
		self::assertNotEmpty($summary['top_files']);
		self::assertArrayNotHasKey('path', $summary['top_files'][0]);
		self::assertArrayNotHasKey('root', $summary);
		self::assertArrayNotHasKey('queue', $summary);
	}

	public function testScannerResumesADirectoryLargerThanOneBatch(): void
	{
		$root = sys_get_temp_dir() . '/smi-scanner-' . bin2hex(random_bytes(8));
		self::assertTrue(mkdir($root, 0700));

		try {
			for ($index = 0; $index < 260; ++$index) {
				self::assertNotFalse(
					file_put_contents($root . '/fixture-' . $index . '.dat', 'x')
				);
			}

			$scanner = new FileScanner();
			$state = $scanner->start($root);
			$state = $scanner->step($state);
			self::assertFalse($state['completed']);
			self::assertSame(250, $state['processed_entries']);
			$state = $scanner->step($state);
			$summary = $scanner->summarize($state);

			self::assertTrue($summary['completed']);
			self::assertFalse($summary['partial']);
			self::assertSame(260, $summary['file_count']);
		} finally {
			$this->removeTree($root);
		}
	}

	private function removeTree(string $root): void
	{
		if (!is_dir($root)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir() && !$item->isLink()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($root);
	}
}
