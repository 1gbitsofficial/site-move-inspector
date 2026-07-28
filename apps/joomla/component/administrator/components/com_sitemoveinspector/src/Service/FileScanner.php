<?php
/**
 * @package     1Gbits.SiteMoveInspector
 * @subpackage  com_sitemoveinspector
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace OneGbits\Component\SiteMoveInspector\Administrator\Service;

\defined('_JEXEC') or die;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Bounded, resumable, metadata-only filesystem scanner.
 */
final class FileScanner
{
	public const MAX_ENTRIES = 100000;
	public const MAX_TOTAL_SECONDS = 60.0;
	public const MAX_BATCH_ENTRIES = 250;
	public const MAX_BATCH_SECONDS = 0.75;
	public const MAX_DIRECTORY_ENTRIES = 10000;
	public const MAX_VISITED_DIRECTORIES = 25000;
	public const TOP_FILE_LIMIT = 20;

	/**
	 * Create a serializable scan cursor.
	 *
	 * Markers are trusted server-generated paths keyed by category.
	 *
	 * @param array<string, string> $markers
	 *
	 * @return array<string, mixed>
	 */
	public function start(string $root, array $markers = []): array
	{
		$canonicalRoot = PathGuard::canonicalDirectory($root);

		if ($canonicalRoot === null) {
			throw new RuntimeException('The Joomla root directory could not be resolved.');
		}

		$resolvedMarkers = [];

		foreach ($markers as $category => $path) {
			$canonical = PathGuard::canonicalDirectory((string) $path);

			if ($canonical !== null && PathGuard::isWithin($canonical, $canonicalRoot)) {
				$resolvedMarkers[(string) $category] = PathGuard::relative($canonical, $canonicalRoot);
			}
		}

		return [
			'root'                    => $canonicalRoot,
			'markers'                 => $resolvedMarkers,
			'queue'                   => [$canonicalRoot],
			'queue_index'             => 0,
			'current'                 => null,
			'visited'                 => [PathGuard::key($canonicalRoot) => true],
			'processed_entries'       => 0,
			'file_count'              => 0,
			'directory_count'         => 1,
			'total_bytes'             => 0,
			'elapsed_seconds'         => 0.0,
			'completed'               => false,
			'partial'                 => false,
			'partial_reasons'         => [],
			'categories'              => $this->emptyCategories(),
			'top_files'               => [],
			'symlink_count'           => 0,
			'symlink_scopes'          => [
				'inside_root' => 0,
				'outside_root' => 0,
				'unresolved' => 0,
			],
			'unreadable_count'        => 0,
			'outside_root_count'      => 0,
			'special_file_count'      => 0,
			'skipped_directory_count' => 0,
			'limits'                  => [
				'max_entries' => self::MAX_ENTRIES,
				'max_seconds' => self::MAX_TOTAL_SECONDS,
			],
		];
	}

	/**
	 * Process one bounded request worth of filesystem metadata.
	 *
	 * @param array<string, mixed> $state
	 *
	 * @return array<string, mixed>
	 */
	public function step(array $state): array
	{
		$this->assertState($state);

		if (!empty($state['completed'])) {
			return $state;
		}

		$batchStarted = microtime(true);
		$batchEntries = 0;

		while (!$this->batchLimitReached($state, $batchStarted, $batchEntries)) {
			if ($state['current'] === null) {
				$queueIndex = max(0, (int) ($state['queue_index'] ?? 0));

				if (!isset($state['queue'][$queueIndex])) {
					$state['completed'] = true;
					break;
				}

				$state['current'] = [
					'path' => $state['queue'][$queueIndex],
					'offset' => 0,
					'mtime' => 0,
					'last_entry_key' => '',
				];
				$state['queue_index'] = $queueIndex + 1;
			}

			$state = $this->scanCurrentDirectory($state, $batchStarted, $batchEntries);

			if ((int) $state['processed_entries'] >= self::MAX_ENTRIES) {
				$this->markPartial($state, 'entry_limit');
				$this->finishEarly($state);
				break;
			}
		}

		$state['elapsed_seconds'] = round(
			(float) $state['elapsed_seconds'] + max(0.0, microtime(true) - $batchStarted),
			4
		);

		if ((float) $state['elapsed_seconds'] >= self::MAX_TOTAL_SECONDS && empty($state['completed'])) {
			$this->markPartial($state, 'time_limit');
			$this->finishEarly($state);
		}

		if (
			$state['current'] === null
			&& !isset($state['queue'][max(0, (int) ($state['queue_index'] ?? 0))])
		) {
			$state['completed'] = true;
		}

		return $state;
	}

	/**
	 * Return a privacy-safe scanner summary.
	 *
	 * @param array<string, mixed> $state
	 *
	 * @return array<string, mixed>
	 */
	public function summarize(array $state): array
	{
		$this->assertState($state);

		return [
			'completed'               => (bool) $state['completed'],
			'partial'                 => (bool) $state['partial'],
			'partial_reasons'         => array_values(array_unique((array) $state['partial_reasons'])),
			'processed_entries'       => max(0, (int) $state['processed_entries']),
			'file_count'              => max(0, (int) $state['file_count']),
			'directory_count'         => max(0, (int) $state['directory_count']),
			'total_bytes'             => max(0, (int) $state['total_bytes']),
			'elapsed_seconds'         => round(max(0.0, (float) $state['elapsed_seconds']), 2),
			'categories'              => array_values((array) $state['categories']),
			'top_files'               => array_values((array) $state['top_files']),
			'symlink_count'           => max(0, (int) $state['symlink_count']),
			'symlink_scopes'          => (array) $state['symlink_scopes'],
			'unreadable_count'        => max(0, (int) $state['unreadable_count']),
			'outside_root_count'      => max(0, (int) $state['outside_root_count']),
			'special_file_count'      => max(0, (int) $state['special_file_count']),
			'skipped_directory_count' => max(0, (int) $state['skipped_directory_count']),
			'limits'                  => (array) $state['limits'],
		];
	}

	/**
	 * Scan part of the selected directory.
	 *
	 * @param array<string, mixed> $state
	 */
	private function scanCurrentDirectory(array $state, float $batchStarted, int &$batchEntries): array
	{
		$current = (array) $state['current'];
		$path = (string) ($current['path'] ?? '');
		$offset = max(0, (int) ($current['offset'] ?? 0));
		$canonicalPath = PathGuard::canonicalDirectory($path);

		if (
			$canonicalPath === null
			|| PathGuard::key($canonicalPath) !== PathGuard::key($path)
			|| !PathGuard::isWithin($canonicalPath, (string) $state['root'])
		) {
			++$state['outside_root_count'];
			$this->markPartial($state, 'outside_root');
			$state['current'] = null;

			return $state;
		}

		$mtime = $this->directoryMtime($path);

		if ($offset > 0 && (int) ($current['mtime'] ?? 0) > 0 && (int) $current['mtime'] !== $mtime) {
			$this->markPartial($state, 'directory_changed');
		}

		$state['current']['mtime'] = $mtime;

		try {
			$iterator = new FilesystemIterator(
				$canonicalPath,
				FilesystemIterator::SKIP_DOTS
					| FilesystemIterator::CURRENT_AS_FILEINFO
					| FilesystemIterator::KEY_AS_PATHNAME
			);
		} catch (UnexpectedValueException $exception) {
			++$state['unreadable_count'];
			$this->markPartial($state, 'unreadable_entries');
			$state['current'] = null;

			return $state;
		}

		$postOpenCanonicalPath = PathGuard::canonicalDirectory($path);

		if (
			$postOpenCanonicalPath === null
			|| PathGuard::key($postOpenCanonicalPath) !== PathGuard::key($canonicalPath)
			|| !PathGuard::isWithin($postOpenCanonicalPath, (string) $state['root'])
		) {
			++$state['outside_root_count'];
			$this->markPartial($state, 'outside_root');
			$state['current'] = null;

			return $state;
		}

		$index = 0;
		$paused = false;
		$skipDirectory = false;

		try {
			foreach ($iterator as $fileInfo) {
				if ($index < $offset) {
					if (
						$index === $offset - 1
						&& (string) ($current['last_entry_key'] ?? '') !== ''
						&& !hash_equals(
							(string) $current['last_entry_key'],
							PathGuard::key($fileInfo->getPathname())
						)
					) {
						++$state['skipped_directory_count'];
						$this->markPartial($state, 'directory_changed');
						$state['current'] = null;

						return $state;
					}

					++$index;

					if (microtime(true) - $batchStarted >= self::MAX_BATCH_SECONDS) {
						++$state['skipped_directory_count'];
						$this->markPartial($state, 'directory_resume_limit');
						$state['current'] = null;

						return $state;
					}

					continue;
				}

				++$index;

				if ($offset >= self::MAX_DIRECTORY_ENTRIES) {
					$skipDirectory = true;
					++$state['skipped_directory_count'];
					$this->markPartial($state, 'directory_entry_limit');
					break;
				}

				$this->processEntry($state, $fileInfo);
				++$offset;
				++$batchEntries;
				++$state['processed_entries'];
				$state['current']['offset'] = $offset;
				$state['current']['last_entry_key'] = PathGuard::key($fileInfo->getPathname());

				if (
					(int) $state['processed_entries'] >= self::MAX_ENTRIES
					|| $batchEntries >= self::MAX_BATCH_ENTRIES
					|| microtime(true) - $batchStarted >= self::MAX_BATCH_SECONDS
				) {
					$paused = true;
					break;
				}
			}
		} catch (RuntimeException $exception) {
			++$state['unreadable_count'];
			$this->markPartial($state, 'unreadable_entries');
			$skipDirectory = true;
		}

		if (!$paused || $skipDirectory) {
			$state['current'] = null;
		}

		return $state;
	}

	/**
	 * Record one filesystem entry without opening file contents.
	 *
	 * @param array<string, mixed> $state
	 */
	private function processEntry(array &$state, SplFileInfo $fileInfo): void
	{
		$path = PathGuard::normalize($fileInfo->getPathname());
		$root = (string) $state['root'];
		$relative = PathGuard::relative($path, $root);

		if ($fileInfo->isLink() || is_link($path)) {
			++$state['symlink_count'];
			$target = realpath($path);
			$scope = $target === false
				? 'unresolved'
				: (PathGuard::isWithin((string) $target, $root) ? 'inside_root' : 'outside_root');
			++$state['symlink_scopes'][$scope];
			$this->markPartial($state, 'symlinks_not_followed');

			return;
		}

		if ($fileInfo->isDir()) {
			$canonical = PathGuard::canonicalDirectory($path);

			if ($canonical === null) {
				++$state['unreadable_count'];
				$this->markPartial($state, 'unreadable_entries');

				return;
			}

			if (!PathGuard::isWithin($canonical, $root)) {
				++$state['outside_root_count'];
				$this->markPartial($state, 'outside_root');

				return;
			}

			$key = PathGuard::key($canonical);

			if (isset($state['visited'][$key])) {
				return;
			}

			if (count($state['visited']) >= self::MAX_VISITED_DIRECTORIES) {
				++$state['skipped_directory_count'];
				$this->markPartial($state, 'directory_limit');

				return;
			}

			$state['visited'][$key] = true;
			$state['queue'][] = $canonical;
			++$state['directory_count'];

			return;
		}

		if (!$fileInfo->isFile()) {
			++$state['special_file_count'];

			return;
		}

		$canonicalFile = realpath($path);

		if ($canonicalFile === false) {
			++$state['unreadable_count'];
			$this->markPartial($state, 'unreadable_entries');

			return;
		}

		$canonicalFile = PathGuard::normalize($canonicalFile);

		if (
			PathGuard::key($canonicalFile) !== PathGuard::key($path)
			|| !PathGuard::isWithin($canonicalFile, $root)
		) {
			++$state['outside_root_count'];
			$this->markPartial($state, 'outside_root');

			return;
		}

		try {
			$bytes = max(0, (int) $fileInfo->getSize());
		} catch (RuntimeException $exception) {
			++$state['unreadable_count'];
			$this->markPartial($state, 'unreadable_entries');

			return;
		}

		$category = $this->categoryFor($relative, (array) $state['markers']);
		++$state['file_count'];
		$state['total_bytes'] += $bytes;
		++$state['categories'][$category]['file_count'];
		$state['categories'][$category]['bytes'] += $bytes;
		$this->addTopFile($state, $relative, $bytes, $category);
	}

	/**
	 * Keep aggregate metadata for only the largest files.
	 *
	 * @param array<string, mixed> $state
	 */
	private function addTopFile(array &$state, string $relative, int $bytes, string $category): void
	{
		$extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
		$extension = preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1 ? $extension : '';

		$state['top_files'][] = [
			'bytes' => $bytes,
			'extension' => $extension,
			'category' => $category,
		];

		usort(
			$state['top_files'],
			static fn (array $left, array $right): int => (int) $right['bytes'] <=> (int) $left['bytes']
		);

		if (count($state['top_files']) > self::TOP_FILE_LIMIT) {
			$state['top_files'] = array_slice($state['top_files'], 0, self::TOP_FILE_LIMIT);
		}
	}

	/**
	 * Return the privacy-safe category for a root-relative path.
	 *
	 * @param array<string, string> $markers
	 */
	private function categoryFor(string $relative, array $markers): string
	{
		$relative = trim(PathGuard::normalize($relative), '/');
		$aliases = [
			'components_admin' => 'components',
			'modules_admin' => 'modules',
			'templates_admin' => 'templates',
			'languages_admin' => 'languages',
			'cache' => 'runtime',
			'cache_admin' => 'runtime',
			'logs' => 'runtime',
			'tmp' => 'runtime',
		];
		$categories = $this->emptyCategories();

		foreach ($markers as $category => $marker) {
			$marker = trim(PathGuard::normalize((string) $marker), '/');

			if ($marker !== '' && ($relative === $marker || str_starts_with($relative, $marker . '/'))) {
				$category = $aliases[$category] ?? $category;

				return isset($categories[$category]) ? $category : 'other';
			}
		}

		return 'other';
	}

	/**
	 * Build categories in display order.
	 *
	 * @return array<string, array<string, int|string>>
	 */
	private function emptyCategories(): array
	{
		$labels = [
			'images' => 'Images',
			'media' => 'Media',
			'components' => 'Components',
			'plugins' => 'Plugins',
			'modules' => 'Modules',
			'templates' => 'Templates',
			'languages' => 'Languages',
			'runtime' => 'Cache, logs, and temporary files',
			'other' => 'Other Joomla files',
		];
		$categories = [];

		foreach ($labels as $id => $label) {
			$categories[$id] = [
				'id' => $id,
				'label' => $label,
				'file_count' => 0,
				'bytes' => 0,
			];
		}

		return $categories;
	}

	/**
	 * Return whether the current request should yield.
	 *
	 * @param array<string, mixed> $state
	 */
	private function batchLimitReached(array $state, float $batchStarted, int $batchEntries): bool
	{
		return (int) $state['processed_entries'] >= self::MAX_ENTRIES
			|| (float) $state['elapsed_seconds'] >= self::MAX_TOTAL_SECONDS
			|| $batchEntries >= self::MAX_BATCH_ENTRIES
			|| microtime(true) - $batchStarted >= self::MAX_BATCH_SECONDS;
	}

	/**
	 * Add a machine-readable partial reason once.
	 *
	 * @param array<string, mixed> $state
	 */
	private function markPartial(array &$state, string $reason): void
	{
		$state['partial'] = true;

		if (!in_array($reason, $state['partial_reasons'], true)) {
			$state['partial_reasons'][] = $reason;
		}
	}

	/**
	 * Stop traversal while preserving the collected summary.
	 *
	 * @param array<string, mixed> $state
	 */
	private function finishEarly(array &$state): void
	{
		$state['queue'] = [];
		$state['queue_index'] = 0;
		$state['current'] = null;
		$state['completed'] = true;
	}

	/**
	 * Read directory mtime without emitting filesystem warnings.
	 */
	private function directoryMtime(string $path): int
	{
		try {
			return max(0, (int) (new SplFileInfo($path))->getMTime());
		} catch (RuntimeException $exception) {
			return 0;
		}
	}

	/**
	 * Reject client-created or corrupt cursors.
	 *
	 * @param array<string, mixed> $state
	 */
	private function assertState(array $state): void
	{
		$root = (string) ($state['root'] ?? '');

		if (
			$root === ''
			|| PathGuard::canonicalDirectory($root) !== PathGuard::normalize($root)
			|| !isset($state['queue'], $state['visited'], $state['categories'])
			|| !is_array($state['queue'])
			|| !is_array($state['visited'])
			|| !is_array($state['categories'])
		) {
			throw new RuntimeException('The filesystem scan cursor is invalid.');
		}
	}
}
