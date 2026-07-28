<?php
/**
 * @package     1Gbits.SiteMoveInspector
 * @subpackage  com_sitemoveinspector
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace OneGbits\Component\SiteMoveInspector\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use OneGbits\Component\SiteMoveInspector\Administrator\Infrastructure\JobRepository;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\DestinationProfile;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\Exporter;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\FileScanner;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\Inspector;
use OneGbits\Component\SiteMoveInspector\Administrator\Service\Redactor;
use RuntimeException;
use Throwable;

/**
 * Orchestrates resumable scans and component-owned temporary state.
 */
final class ScanModel extends BaseDatabaseModel
{
	/**
	 * Begin a new user-bound scan.
	 *
	 * @param array<string, mixed> $submittedDestination
	 *
	 * @return array<string, mixed>
	 */
	public function start(int $userId, array $submittedDestination): array
	{
		$services = $this->services();
		$destination = DestinationProfile::sanitize($submittedDestination);
		$report = $services['inspector']->inspectInitial($destination);

		if (!\defined('JPATH_ROOT')) {
			throw new RuntimeException('The Joomla root directory is unavailable.');
		}

		$scannerState = $services['scanner']->start(
			JPATH_ROOT,
			$services['inspector']->filesystemMarkers()
		);
		$services['jobs']->cleanup();
		$jobId = $services['jobs']->create(
			$userId,
			[
				'report' => $report,
				'scanner' => $scannerState,
			]
		);

		return [
			'job_id' => $jobId,
			'status' => 'active',
			'progress' => $this->progress($scannerState),
		];
	}

	/**
	 * Run one bounded filesystem batch.
	 *
	 * @return array<string, mixed>
	 */
	public function step(string $jobId, int $userId): array
	{
		$services = $this->services();
		$services['jobs']->cleanup();
		$existing = $services['jobs']->find($jobId, $userId);

		if ($existing === null) {
			throw new RuntimeException('The scan job was not found or has expired.');
		}

		if (($existing['status'] ?? '') === 'completed' && is_array($existing['report'] ?? null)) {
			$state = is_array($existing['state'] ?? null) ? $existing['state'] : [];
			$summary = is_array($state['scanner'] ?? null) ? $state['scanner'] : [];

			return [
				'job_id' => $jobId,
				'status' => 'completed',
				'progress' => array_merge(
					$this->progress($summary),
					['percent' => 100]
				),
				'report' => Redactor::forExport($existing['report']),
			];
		}

		$lockToken = $services['jobs']->acquire($jobId, $userId);

		if ($lockToken === null) {
			throw new RuntimeException('This scan is already being processed. Please retry.');
		}

		try {
			$job = $services['jobs']->find($jobId, $userId);

			if ($job === null) {
				throw new RuntimeException('The scan job expired while waiting for its lock.');
			}

			$state = is_array($job['state'] ?? null) ? $job['state'] : [];
			$scannerState = is_array($state['scanner'] ?? null) ? $state['scanner'] : [];
			$report = is_array($state['report'] ?? null) ? $state['report'] : [];
			$scannerState = $services['scanner']->step($scannerState);
			$completed = !empty($scannerState['completed']);

			if ($completed) {
				$summary = $services['scanner']->summarize($scannerState);
				$report = $services['inspector']->finalize($report, $summary);
				$services['jobs']->save(
					$jobId,
					$userId,
					$lockToken,
					['scanner' => $summary],
					$report,
					true
				);

				return [
					'job_id' => $jobId,
					'status' => 'completed',
					'progress' => array_merge(
						$this->progress($summary),
						['percent' => 100]
					),
					'report' => Redactor::forExport($report),
				];
			}

			$services['jobs']->save(
				$jobId,
				$userId,
				$lockToken,
				[
					'report' => $report,
					'scanner' => $scannerState,
				],
				null,
				false
			);

			return [
				'job_id' => $jobId,
				'status' => 'active',
				'progress' => $this->progress($scannerState),
			];
		} catch (Throwable $exception) {
			$services['jobs']->release($jobId, $userId, $lockToken);

			throw $exception;
		}
	}

	/**
	 * Delete a user-owned scan.
	 */
	public function cancel(string $jobId, int $userId): void
	{
		$this->services()['jobs']->delete($jobId, $userId);
	}

	/**
	 * Build a privacy-safe download entirely in memory.
	 *
	 * @return array{content: string, mime: string, filename: string}
	 */
	public function export(string $jobId, int $userId, string $format): array
	{
		$job = $this->services()['jobs']->find($jobId, $userId);

		if (
			$job === null
			|| ($job['status'] ?? '') !== 'completed'
			|| !is_array($job['report'] ?? null)
		) {
			throw new RuntimeException('The completed report was not found or has expired.');
		}

		$format = strtolower($format) === 'json' ? 'json' : 'txt';
		$content = $format === 'json'
			? Exporter::toJson($job['report'])
			: Exporter::toText($job['report']);

		return [
			'content' => $content,
			'mime' => $format === 'json'
				? 'application/json; charset=utf-8'
				: 'text/plain; charset=utf-8',
			'filename' => '1gbits-site-move-inspector-' . gmdate('Ymd-His') . '.' . $format,
		];
	}

	/**
	 * Construct request-local services from Joomla's application container.
	 *
	 * @return array{
	 *     jobs: JobRepository,
	 *     scanner: FileScanner,
	 *     inspector: Inspector
	 * }
	 */
	private function services(): array
	{
		$application = Factory::getApplication();
		$database = Factory::getContainer()->get(DatabaseInterface::class);

		return [
			'jobs' => new JobRepository($database),
			'scanner' => new FileScanner(),
			'inspector' => new Inspector($database, $application),
		];
	}

	/**
	 * Return bounded progress metadata without exposing filesystem paths.
	 *
	 * @param array<string, mixed> $state
	 *
	 * @return array<string, int|float>
	 */
	private function progress(array $state): array
	{
		$processed = max(0, (int) ($state['processed_entries'] ?? 0));
		$percent = min(
			95,
			max(1, (int) floor(($processed / FileScanner::MAX_ENTRIES) * 100))
		);

		return [
			'percent' => $percent,
			'processed_entries' => $processed,
			'file_count' => max(0, (int) ($state['file_count'] ?? 0)),
			'directory_count' => max(0, (int) ($state['directory_count'] ?? 0)),
			'elapsed_seconds' => round(max(0.0, (float) ($state['elapsed_seconds'] ?? 0)), 2),
		];
	}
}
