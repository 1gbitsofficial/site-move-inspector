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

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Collects read-only Joomla and hosting metadata.
 */
final class Inspector
{
	public const VERSION = '1.0.0';
	private const EXTENSION_LIMIT = 1000;

	public function __construct(
		private DatabaseInterface $database,
		private CMSApplicationInterface $application
	) {
	}

	/**
	 * Run all lightweight checks and return a report ready for filesystem data.
	 *
	 * @param array<string, mixed> $destination
	 *
	 * @return array<string, mixed>
	 */
	public function inspectInitial(array $destination): array
	{
		$report = ReportBuilder::create($destination);
		$report['extension_version'] = self::VERSION;

		$steps = [
			'software_inventory' => [
				'section' => 'environment',
				'title' => Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_SOFTWARE_INVENTORY'),
				'callback' => function () use (&$report): void {
					$this->inspectSoftware($report);
				},
			],
			'environment_checks' => [
				'section' => 'environment',
				'title' => Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_ENVIRONMENT'),
				'callback' => function () use (&$report): void {
					$this->inspectEnvironment($report);
				},
			],
			'configuration_checks' => [
				'section' => 'configuration',
				'title' => Text::_('COM_SITEMOVEINSPECTOR_SECTION_CONFIGURATION'),
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_CONFIGURATION'),
				'callback' => function () use (&$report): void {
					$this->inspectConfiguration($report);
				},
			],
			'database_checks' => [
				'section' => 'database',
				'title' => Text::_('COM_SITEMOVEINSPECTOR_SECTION_DATABASE'),
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DATABASE'),
				'callback' => function () use (&$report): void {
					$this->inspectDatabase($report);
				},
			],
			'scheduled_tasks' => [
				'section' => 'reliability',
				'title' => Text::_('COM_SITEMOVEINSPECTOR_SECTION_RELIABILITY'),
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_SCHEDULED_TASKS'),
				'callback' => function () use (&$report): void {
					$this->inspectScheduledTasks($report);
				},
			],
			'destination_checks' => [
				'section' => 'destination',
				'title' => Text::_('COM_SITEMOVEINSPECTOR_SECTION_DESTINATION'),
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DESTINATION'),
				'callback' => function () use (&$report): void {
					$this->inspectDestinationSoftware($report);
				},
			],
		];

		foreach ($steps as $id => $step) {
			try {
				$step['callback']();
			} catch (Throwable $exception) {
				ReportBuilder::markPartial(
					$report,
					Text::_('COM_SITEMOVEINSPECTOR_PARTIAL_METADATA')
				);
				ReportBuilder::addCheck(
					$report,
					$step['section'],
					$step['title'],
					[
						'id' => $id,
						'status' => ReportBuilder::STATUS_UNKNOWN,
						'label' => $step['label'],
						'value' => Text::_('COM_SITEMOVEINSPECTOR_VALUE_UNAVAILABLE'),
						'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_CHECK_FAILED'),
						'recommendation' => Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_SERVER_LOG'),
					]
				);
			}
		}

		return $report;
	}

	/**
	 * Merge filesystem metadata, add capacity checks, and finalize.
	 *
	 * @param array<string, mixed> $report
	 * @param array<string, mixed> $files
	 *
	 * @return array<string, mixed>
	 */
	public function finalize(array $report, array $files): array
	{
		$files['complete'] = !empty($files['completed']) && empty($files['partial']);
		$files['source_free_bytes'] = $this->sourceFreeBytes();
		$report['inventory']['files'] = $files;

		foreach ((array) ($files['partial_reasons'] ?? []) as $reason) {
			ReportBuilder::markPartial($report, $this->partialReason((string) $reason));
		}

		$partial = !empty($files['partial']);
		ReportBuilder::addCheck(
			$report,
			'storage',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_STORAGE'),
			[
				'id' => 'filesystem_scan',
				'status' => $partial ? ReportBuilder::STATUS_WARNING : ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_FILESYSTEM'),
				'value' => Text::sprintf(
					'COM_SITEMOVEINSPECTOR_VALUE_FILES_DIRECTORIES',
					$this->number((int) ($files['file_count'] ?? 0)),
					$this->number((int) ($files['directory_count'] ?? 0))
				),
				'message' => $partial
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SCAN_PARTIAL')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SCAN_COMPLETE'),
				'recommendation' => $partial
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_SERVER_INVENTORY')
					: '',
			]
		);

		$totalBytes = max(0, (int) ($files['total_bytes'] ?? 0));
		ReportBuilder::addCheck(
			$report,
			'storage',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_STORAGE'),
			[
				'id' => 'site_file_size',
				'status' => $totalBytes > 0
					? ($partial ? ReportBuilder::STATUS_UNKNOWN : ReportBuilder::STATUS_PASS)
					: ReportBuilder::STATUS_UNKNOWN,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_FILE_SIZE'),
				'value' => $this->formatBytes($totalBytes),
				'message' => $partial
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SIZE_LOWER_BOUND')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SIZE_TOTAL'),
				'recommendation' => '',
			]
		);

		$symlinks = max(0, (int) ($files['symlink_count'] ?? 0));
		ReportBuilder::addCheck(
			$report,
			'storage',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_STORAGE'),
			[
				'id' => 'symlinks',
				'status' => $symlinks > 0
					? ReportBuilder::STATUS_WARNING
					: ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_SYMLINKS'),
				'value' => $this->number($symlinks),
				'message' => $symlinks > 0
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SYMLINKS_FOUND')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_NO_SYMLINKS'),
				'recommendation' => $symlinks > 0
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_SYMLINKS')
					: '',
			]
		);

		$accessIssues = max(0, (int) ($files['unreadable_count'] ?? 0))
			+ max(0, (int) ($files['outside_root_count'] ?? 0))
			+ max(0, (int) ($files['skipped_directory_count'] ?? 0));

		if ($accessIssues > 0) {
			ReportBuilder::markPartial(
				$report,
				Text::_('COM_SITEMOVEINSPECTOR_PARTIAL_FILESYSTEM_ACCESS')
			);
		}

		ReportBuilder::addCheck(
			$report,
			'storage',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_STORAGE'),
			[
				'id' => 'filesystem_access',
				'status' => $accessIssues > 0
					? ReportBuilder::STATUS_WARNING
					: ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_FILESYSTEM_ACCESS'),
				'value' => $this->number($accessIssues),
				'message' => $accessIssues > 0
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_ACCESS_ISSUES')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_ACCESS_OK'),
				'recommendation' => $accessIssues > 0
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_SERVER_INVENTORY')
					: '',
			]
		);

		$this->inspectSourceDisk($report, $totalBytes);
		$this->inspectDestinationDisk($report, $totalBytes);
		ReportBuilder::finalize($report);

		return $report;
	}

	/**
	 * Return trusted, server-side category markers.
	 *
	 * @return array<string, string>
	 */
	public function filesystemMarkers(): array
	{
		$markers = [
			'images' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/images' : '',
			'media' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/media' : '',
			'components' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/components' : '',
			'components_admin' => \defined('JPATH_ADMINISTRATOR') ? JPATH_ADMINISTRATOR . '/components' : '',
			'plugins' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/plugins' : '',
			'modules' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/modules' : '',
			'modules_admin' => \defined('JPATH_ADMINISTRATOR') ? JPATH_ADMINISTRATOR . '/modules' : '',
			'templates' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/templates' : '',
			'templates_admin' => \defined('JPATH_ADMINISTRATOR') ? JPATH_ADMINISTRATOR . '/templates' : '',
			'languages' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/language' : '',
			'languages_admin' => \defined('JPATH_ADMINISTRATOR') ? JPATH_ADMINISTRATOR . '/language' : '',
			'cache' => \defined('JPATH_ROOT') ? JPATH_ROOT . '/cache' : '',
			'cache_admin' => \defined('JPATH_ADMINISTRATOR') ? JPATH_ADMINISTRATOR . '/cache' : '',
			'logs' => (string) $this->application->get('log_path', ''),
			'tmp' => (string) $this->application->get('tmp_path', ''),
		];

		return array_filter($markers, static fn (string $path): bool => $path !== '');
	}

	/**
	 * Collect software inventory without reading extension parameters.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectSoftware(array &$report): void
	{
		$query = $this->database->getQuery(true)
			->select(
				[
					$this->database->quoteName('type'),
					$this->database->quoteName('element'),
					$this->database->quoteName('enabled'),
					$this->database->quoteName('protected'),
					$this->database->quoteName('manifest_cache'),
				]
			)
			->from($this->database->quoteName('#__extensions'))
			->order($this->database->quoteName('extension_id') . ' ASC');

		$this->database->setQuery($query, 0, self::EXTENSION_LIMIT + 1);
		$rows = $this->database->loadAssocList();
		$rows = is_array($rows) ? $rows : [];

		if (count($rows) > self::EXTENSION_LIMIT) {
			ReportBuilder::markPartial(
				$report,
				Text::sprintf('COM_SITEMOVEINSPECTOR_PARTIAL_EXTENSION_LIMIT', self::EXTENSION_LIMIT)
			);
			$rows = array_slice($rows, 0, self::EXTENSION_LIMIT);
		}

		$extensions = [];
		$templates = [];
		$enabledExtensionCount = 0;
		$thirdPartyCount = 0;
		$activeTemplates = $this->activeTemplateElements();

		foreach ($rows as $row) {
			$type = $this->sanitizeKey((string) ($row['type'] ?? 'extension'));
			$element = $this->sanitizeKey((string) ($row['element'] ?? ''));
			$enabled = (int) ($row['enabled'] ?? 0) === 1;
			$manifest = json_decode((string) ($row['manifest_cache'] ?? ''), true);
			$manifest = is_array($manifest) ? $manifest : [];
			$version = $this->sanitizeVersion($manifest['version'] ?? '');
			$item = [
				'type' => $type !== '' ? $type : 'extension',
				'version' => $version,
				'enabled' => $enabled,
			];

			if ($type === 'template') {
				$item['active'] = isset($activeTemplates[$element]);
				$templates[] = $item;
			} else {
				$extensions[] = $item;

				if ($enabled) {
					++$enabledExtensionCount;
				}

			}

			if ($this->isThirdPartyExtension($row, $manifest, $element)) {
				++$thirdPartyCount;
			}
		}

		$engine = $this->databaseEngine();
		$report['inventory']['software'] = [
			'joomla_version' => $this->joomlaVersion(),
			'php_version' => $this->sanitizeVersion(PHP_VERSION),
			'database_engine' => $engine,
			'database_version' => $this->databaseVersion(),
			'web_server' => $this->webServerFamily(),
			'extensions' => $extensions,
			'templates' => $templates,
			'extension_count' => count($extensions),
			'enabled_extension_count' => $enabledExtensionCount,
			'template_count' => count($templates),
			'active_template_count' => count($activeTemplates),
			'third_party_count' => $thirdPartyCount,
		];

		ReportBuilder::addCheck(
			$report,
			'environment',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
			[
				'id' => 'joomla_version',
				'status' => ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_JOOMLA_VERSION'),
				'value' => $report['inventory']['software']['joomla_version'],
				'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_JOOMLA_RECORDED'),
				'recommendation' => '',
			]
		);

		ReportBuilder::addCheck(
			$report,
			'extensions',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_EXTENSIONS'),
			[
				'id' => 'extension_inventory',
				'status' => ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_EXTENSIONS'),
				'value' => Text::sprintf(
					'COM_SITEMOVEINSPECTOR_VALUE_EXTENSIONS_ENABLED',
					$this->number(count($extensions)),
					$this->number($enabledExtensionCount)
				),
				'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_EXTENSIONS_METADATA'),
				'recommendation' => '',
			]
		);

		ReportBuilder::addCheck(
			$report,
			'extensions',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_EXTENSIONS'),
			[
				'id' => 'template_inventory',
				'status' => ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_TEMPLATES'),
				'value' => Text::sprintf(
					'COM_SITEMOVEINSPECTOR_VALUE_TEMPLATES_ACTIVE',
					$this->number(count($templates)),
					$this->number((int) $report['inventory']['software']['active_template_count'])
				),
				'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_TEMPLATES_METADATA'),
				'recommendation' => '',
			]
		);

		ReportBuilder::addCheck(
			$report,
			'extensions',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_EXTENSIONS'),
			[
				'id' => 'third_party_extensions',
				'status' => $thirdPartyCount > 0
					? ReportBuilder::STATUS_WARNING
					: ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_THIRD_PARTY'),
				'value' => $this->number($thirdPartyCount),
				'message' => $thirdPartyCount > 0
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_THIRD_PARTY')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_NO_THIRD_PARTY'),
				'recommendation' => $thirdPartyCount > 0
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_THIRD_PARTY')
					: '',
			]
		);
	}

	/**
	 * Add PHP and server checks.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectEnvironment(array &$report): void
	{
		$software = (array) ($report['inventory']['software'] ?? []);
		$currentPhp = (string) ($software['php_version'] ?? '');
		$minimumPhp = $this->minimumPhp();
		$phpOk = $currentPhp !== '' && version_compare($currentPhp, $minimumPhp, '>=');

		ReportBuilder::addCheck(
			$report,
			'environment',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
			[
				'id' => 'php_version',
				'status' => $phpOk
					? ReportBuilder::STATUS_PASS
					: ReportBuilder::STATUS_CRITICAL,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_PHP_VERSION'),
				'value' => $currentPhp,
				'message' => Text::sprintf(
					'COM_SITEMOVEINSPECTOR_MESSAGE_PHP_MINIMUM',
					$minimumPhp
				),
				'recommendation' => $phpOk
					? ''
					: Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_PHP'),
			]
		);

		$requiredExtensions = ['json', 'dom', 'simplexml', 'zlib', 'session'];
		$missing = array_values(
			array_filter(
				$requiredExtensions,
				static fn (string $extension): bool => !extension_loaded($extension)
			)
		);
		ReportBuilder::addCheck(
			$report,
			'environment',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
			[
				'id' => 'php_extensions',
				'status' => $missing === []
					? ReportBuilder::STATUS_PASS
					: ReportBuilder::STATUS_CRITICAL,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_PHP_EXTENSIONS'),
				'value' => $missing === []
					? Text::_('COM_SITEMOVEINSPECTOR_VALUE_AVAILABLE')
					: Text::sprintf('COM_SITEMOVEINSPECTOR_VALUE_MISSING_COUNT', count($missing)),
				'message' => $missing === []
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_EXTENSIONS_AVAILABLE')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_EXTENSIONS_MISSING'),
				'recommendation' => $missing === []
					? ''
					: Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_PHP_EXTENSIONS'),
			]
		);

		$memoryBytes = $this->sizeToBytes((string) ini_get('memory_limit'));
		$memoryUnlimited = trim((string) ini_get('memory_limit')) === '-1';
		$memoryOk = $memoryUnlimited || $memoryBytes >= 128 * 1024 * 1024;
		ReportBuilder::addCheck(
			$report,
			'environment',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
			[
				'id' => 'memory_limit',
				'status' => $memoryOk
					? ReportBuilder::STATUS_PASS
					: ReportBuilder::STATUS_WARNING,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_MEMORY_LIMIT'),
				'value' => $memoryUnlimited
					? Text::_('COM_SITEMOVEINSPECTOR_VALUE_UNLIMITED')
					: $this->formatBytes($memoryBytes),
				'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_MEMORY_LIMIT'),
				'recommendation' => $memoryOk
					? ''
					: Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_MEMORY'),
			]
		);

		$uploadBytes = $this->sizeToBytes((string) ini_get('upload_max_filesize'));
		$postBytes = $this->sizeToBytes((string) ini_get('post_max_size'));
		ReportBuilder::addCheck(
			$report,
			'environment',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
			[
				'id' => 'request_limits',
				'status' => ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_REQUEST_LIMITS'),
				'value' => Text::sprintf(
					'COM_SITEMOVEINSPECTOR_VALUE_UPLOAD_POST',
					$this->formatBytes($uploadBytes),
					$this->formatBytes($postBytes)
				),
				'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_REQUEST_LIMITS'),
				'recommendation' => '',
			]
		);

		ReportBuilder::addCheck(
			$report,
			'environment',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_ENVIRONMENT'),
			[
				'id' => 'web_server',
				'status' => ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_WEB_SERVER'),
				'value' => (string) ($software['web_server'] ?? Text::_('COM_SITEMOVEINSPECTOR_VALUE_UNKNOWN')),
				'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SERVER_RECORDED'),
				'recommendation' => '',
			]
		);
	}

	/**
	 * Add HTTPS and path-layout checks.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectConfiguration(array &$report): void
	{
		$https = $this->isHttps();
		ReportBuilder::addCheck(
			$report,
			'configuration',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_CONFIGURATION'),
			[
				'id' => 'https',
				'status' => $https
					? ReportBuilder::STATUS_PASS
					: ReportBuilder::STATUS_WARNING,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_HTTPS'),
				'value' => $https
					? Text::_('JYES')
					: Text::_('JNO'),
				'message' => $https
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_HTTPS')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_NO_HTTPS'),
				'recommendation' => $https
					? ''
					: Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_HTTPS'),
			]
		);

		$root = \defined('JPATH_ROOT') ? PathGuard::canonicalDirectory(JPATH_ROOT) : null;
		$outside = 0;
		$unresolved = 0;

		foreach (
			[
				(string) $this->application->get('tmp_path', ''),
				(string) $this->application->get('log_path', ''),
			] as $configuredPath
		) {
			$canonical = PathGuard::canonicalDirectory($configuredPath);

			if ($canonical === null) {
				++$unresolved;
			} elseif ($root === null || !PathGuard::isWithin($canonical, $root)) {
				++$outside;
			}
		}

		$pathIssues = $outside + $unresolved;
		ReportBuilder::addCheck(
			$report,
			'configuration',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_CONFIGURATION'),
			[
				'id' => 'custom_paths',
				'status' => $pathIssues > 0
					? ReportBuilder::STATUS_WARNING
					: ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_CUSTOM_PATHS'),
				'value' => $this->number($pathIssues),
				'message' => $pathIssues > 0
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_CUSTOM_PATHS')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_STANDARD_PATHS'),
				'recommendation' => $pathIssues > 0
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_CUSTOM_PATHS')
					: '',
			]
		);

		$debug = (bool) $this->application->get('debug', false);
		ReportBuilder::addCheck(
			$report,
			'configuration',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_CONFIGURATION'),
			[
				'id' => 'debug_mode',
				'status' => $debug
					? ReportBuilder::STATUS_WARNING
					: ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DEBUG_MODE'),
				'value' => $debug ? Text::_('JON') : Text::_('JOFF'),
				'message' => $debug
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DEBUG_ON')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DEBUG_OFF'),
				'recommendation' => $debug
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DEBUG')
					: '',
			]
		);
	}

	/**
	 * Collect database aggregate metadata.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectDatabase(array &$report): void
	{
		$tableNames = $this->database->getTableList();
		$tableNames = is_array($tableNames) ? $tableNames : [];
		$prefix = $this->database->getPrefix();
		$tableCount = count(
			array_filter(
				$tableNames,
				static fn (string $name): bool => str_starts_with($name, $prefix)
			)
		);
		$size = $this->databaseSize($tableCount);
		$tableCount = max(0, (int) ($size['table_count'] ?? $tableCount));
		$report['inventory']['database'] = [
			'available' => $size['available'],
			'table_count' => $tableCount,
			'total_bytes' => $size['total_bytes'],
			'non_innodb_count' => $size['non_innodb_count'],
			'engines' => $size['engines'],
		];

		ReportBuilder::addCheck(
			$report,
			'database',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_DATABASE'),
			[
				'id' => 'database_inventory',
				'status' => $tableCount > 0
					? ReportBuilder::STATUS_PASS
					: ReportBuilder::STATUS_UNKNOWN,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DATABASE_TABLES'),
				'value' => $this->number($tableCount),
				'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DATABASE_TABLES'),
				'recommendation' => '',
			]
		);

		ReportBuilder::addCheck(
			$report,
			'database',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_DATABASE'),
			[
				'id' => 'database_size',
				'status' => $size['available']
					? ReportBuilder::STATUS_PASS
					: ReportBuilder::STATUS_UNKNOWN,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DATABASE_SIZE'),
				'value' => $size['available']
					? $this->formatBytes($size['total_bytes'])
					: Text::_('COM_SITEMOVEINSPECTOR_VALUE_UNAVAILABLE'),
				'message' => $size['available']
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DATABASE_SIZE')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DATABASE_SIZE_UNAVAILABLE'),
				'recommendation' => $size['available']
					? ''
					: Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DATABASE_SIZE'),
			]
		);

		if ($this->databaseEngine() === 'mysql' || $this->databaseEngine() === 'mariadb') {
			ReportBuilder::addCheck(
				$report,
				'database',
				Text::_('COM_SITEMOVEINSPECTOR_SECTION_DATABASE'),
				[
					'id' => 'database_engines',
					'status' => $size['available'] && $size['non_innodb_count'] === 0
						? ReportBuilder::STATUS_PASS
						: ($size['available'] ? ReportBuilder::STATUS_WARNING : ReportBuilder::STATUS_UNKNOWN),
					'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DATABASE_ENGINES'),
					'value' => $size['available']
						? $this->number($size['non_innodb_count'])
						: Text::_('COM_SITEMOVEINSPECTOR_VALUE_UNAVAILABLE'),
					'message' => $size['non_innodb_count'] > 0
						? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_NON_INNODB')
						: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_INNODB'),
					'recommendation' => $size['non_innodb_count'] > 0
						? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DATABASE_ENGINE')
						: '',
				]
			);
		}
	}

	/**
	 * Inspect scheduler aggregate counts without reading task options.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectScheduledTasks(array &$report): void
	{
		$table = $this->database->getPrefix() . 'scheduler_tasks';
		$tables = $this->database->getTableList();

		if (!is_array($tables) || !in_array($table, $tables, true)) {
			ReportBuilder::addCheck(
				$report,
				'reliability',
				Text::_('COM_SITEMOVEINSPECTOR_SECTION_RELIABILITY'),
				[
					'id' => 'scheduled_tasks',
					'status' => ReportBuilder::STATUS_NOT_APPLICABLE,
					'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_SCHEDULED_TASKS'),
					'value' => Text::_('COM_SITEMOVEINSPECTOR_VALUE_NOT_APPLICABLE'),
					'message' => Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_NO_SCHEDULER'),
					'recommendation' => '',
				]
			);

			return;
		}

		$query = $this->database->getQuery(true)
			->select('COUNT(*) AS ' . $this->database->quoteName('total'))
			->select(
				'SUM(CASE WHEN ' . $this->database->quoteName('state')
				. ' = 1 THEN 1 ELSE 0 END) AS ' . $this->database->quoteName('enabled')
			)
			->from($this->database->quoteName('#__scheduler_tasks'));
		$this->database->setQuery($query);
		$row = $this->database->loadAssoc();
		$total = max(0, (int) ($row['total'] ?? 0));
		$enabled = max(0, (int) ($row['enabled'] ?? 0));

		ReportBuilder::addCheck(
			$report,
			'reliability',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_RELIABILITY'),
			[
				'id' => 'scheduled_tasks',
				'status' => $enabled > 0
					? ReportBuilder::STATUS_WARNING
					: ReportBuilder::STATUS_PASS,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_SCHEDULED_TASKS'),
				'value' => Text::sprintf(
					'COM_SITEMOVEINSPECTOR_VALUE_TASKS_ENABLED',
					$this->number($total),
					$this->number($enabled)
				),
				'message' => $enabled > 0
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_TASKS_ENABLED')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_TASKS_DISABLED'),
				'recommendation' => $enabled > 0
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_TASKS')
					: '',
			]
		);
	}

	/**
	 * Compare destination PHP and database metadata.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectDestinationSoftware(array &$report): void
	{
		$destination = (array) ($report['destination'] ?? []);
		$destinationPhp = (string) ($destination['php_version'] ?? '');
		$currentPhp = (string) ($report['inventory']['software']['php_version'] ?? '');
		$minimumPhp = $this->minimumPhp();

		if ($destinationPhp === '') {
			$phpStatus = ReportBuilder::STATUS_NOT_APPLICABLE;
			$phpValue = Text::_('COM_SITEMOVEINSPECTOR_VALUE_NOT_PROVIDED');
			$phpMessage = Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_OPTIONAL');
			$phpRecommendation = '';
		} elseif (version_compare($destinationPhp, $minimumPhp, '<')) {
			$phpStatus = ReportBuilder::STATUS_CRITICAL;
			$phpValue = $destinationPhp;
			$phpMessage = Text::sprintf(
				'COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_PHP_TOO_LOW',
				$minimumPhp
			);
			$phpRecommendation = Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DESTINATION_PHP');
		} elseif ($currentPhp !== '' && version_compare($destinationPhp, $currentPhp, '<')) {
			$phpStatus = ReportBuilder::STATUS_WARNING;
			$phpValue = $destinationPhp;
			$phpMessage = Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_PHP_LOWER');
			$phpRecommendation = Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_EXTENSION_COMPATIBILITY');
		} else {
			$phpStatus = ReportBuilder::STATUS_PASS;
			$phpValue = $destinationPhp;
			$phpMessage = Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_PHP_OK');
			$phpRecommendation = '';
		}

		ReportBuilder::addCheck(
			$report,
			'destination',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_DESTINATION'),
			[
				'id' => 'destination_php',
				'status' => $phpStatus,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DESTINATION_PHP'),
				'value' => $phpValue,
				'message' => $phpMessage,
				'recommendation' => $phpRecommendation,
			]
		);

		$destinationEngine = (string) ($destination['database_engine'] ?? '');
		$currentEngine = (string) ($report['inventory']['software']['database_engine'] ?? '');
		$destinationVersion = (string) ($destination['database_version'] ?? '');

		if ($destinationEngine === '') {
			$dbStatus = ReportBuilder::STATUS_NOT_APPLICABLE;
			$dbValue = Text::_('COM_SITEMOVEINSPECTOR_VALUE_NOT_PROVIDED');
			$dbMessage = Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_OPTIONAL');
			$dbRecommendation = '';
		} else {
			$minimum = $this->minimumDatabaseVersion($destinationEngine);
			$engineChanged = $currentEngine !== '' && $destinationEngine !== $currentEngine;
			$tooOld = $destinationVersion !== '' && $minimum !== ''
				&& version_compare($destinationVersion, $minimum, '<');

			if ($tooOld) {
				$dbStatus = ReportBuilder::STATUS_CRITICAL;
				$dbMessage = Text::sprintf(
					'COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_DB_TOO_LOW',
					$minimum
				);
				$dbRecommendation = Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DESTINATION_DB');
			} elseif ($engineChanged || $destinationVersion === '') {
				$dbStatus = ReportBuilder::STATUS_WARNING;
				$dbMessage = $engineChanged
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_DB_CHANGED')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_DB_VERSION_MISSING');
				$dbRecommendation = Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DATABASE_COMPATIBILITY');
			} else {
				$dbStatus = ReportBuilder::STATUS_PASS;
				$dbMessage = Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_DB_OK');
				$dbRecommendation = '';
			}

			$dbValue = trim(ucfirst($destinationEngine) . ' ' . $destinationVersion);
		}

		ReportBuilder::addCheck(
			$report,
			'destination',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_DESTINATION'),
			[
				'id' => 'destination_database',
				'status' => $dbStatus,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DESTINATION_DATABASE'),
				'value' => $dbValue,
				'message' => $dbMessage,
				'recommendation' => $dbRecommendation,
			]
		);
	}

	/**
	 * Record source free disk space.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectSourceDisk(array &$report, int $totalBytes): void
	{
		$freeBytes = (int) ($report['inventory']['files']['source_free_bytes'] ?? 0);
		$known = $freeBytes > 0;
		$low = $known && $totalBytes > 0 && $freeBytes < (int) ceil($totalBytes * 0.2);

		ReportBuilder::addCheck(
			$report,
			'storage',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_STORAGE'),
			[
				'id' => 'source_disk_space',
				'status' => !$known
					? ReportBuilder::STATUS_UNKNOWN
					: ($low ? ReportBuilder::STATUS_WARNING : ReportBuilder::STATUS_PASS),
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_SOURCE_DISK'),
				'value' => $known
					? $this->formatBytes($freeBytes)
					: Text::_('COM_SITEMOVEINSPECTOR_VALUE_UNAVAILABLE'),
				'message' => $low
					? Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SOURCE_DISK_LOW')
					: Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_SOURCE_DISK'),
				'recommendation' => $low
					? Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_SOURCE_DISK')
					: '',
			]
		);
	}

	/**
	 * Compare destination capacity with files plus database and 20% headroom.
	 *
	 * @param array<string, mixed> $report
	 */
	private function inspectDestinationDisk(array &$report, int $fileBytes): void
	{
		$destinationGb = (float) ($report['destination']['disk_space_gb'] ?? 0);
		$database = (array) ($report['inventory']['database'] ?? []);
		$databaseAvailable = !empty($database['available']);
		$databaseBytes = max(0, (int) ($database['total_bytes'] ?? 0));
		$requiredBytes = (int) ceil(($fileBytes + $databaseBytes) * 1.2);

		if ($destinationGb <= 0) {
			$status = ReportBuilder::STATUS_NOT_APPLICABLE;
			$value = Text::_('COM_SITEMOVEINSPECTOR_VALUE_NOT_PROVIDED');
			$message = Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_OPTIONAL');
			$recommendation = '';
		} elseif (
			!empty($report['inventory']['files']['partial'])
			|| !$databaseAvailable
			|| $requiredBytes <= 0
		) {
			$status = ReportBuilder::STATUS_UNKNOWN;
			$value = $this->formatBytes((int) round($destinationGb * 1024 * 1024 * 1024));
			$message = Text::_('COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_DISK_UNKNOWN');
			$recommendation = Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DESTINATION_DISK_REVIEW');
		} else {
			$destinationBytes = (int) round($destinationGb * 1024 * 1024 * 1024);
			$enough = $destinationBytes >= $requiredBytes;
			$status = $enough
				? ReportBuilder::STATUS_PASS
				: ReportBuilder::STATUS_CRITICAL;
			$value = $this->formatBytes($destinationBytes);
			$message = Text::sprintf(
				'COM_SITEMOVEINSPECTOR_MESSAGE_DESTINATION_DISK_REQUIRED',
				$this->formatBytes($requiredBytes)
			);
			$recommendation = $enough
				? ''
				: Text::_('COM_SITEMOVEINSPECTOR_RECOMMEND_DESTINATION_DISK');
		}

		ReportBuilder::addCheck(
			$report,
			'destination',
			Text::_('COM_SITEMOVEINSPECTOR_SECTION_DESTINATION'),
			[
				'id' => 'destination_disk',
				'status' => $status,
				'label' => Text::_('COM_SITEMOVEINSPECTOR_CHECK_DESTINATION_DISK'),
				'value' => $value,
				'message' => $message,
				'recommendation' => $recommendation,
			]
		);
	}

	/**
	 * Obtain aggregate DB size and engine counts.
	 *
	 * @return array{
	 *     available: bool,
	 *     table_count: int,
	 *     total_bytes: int,
	 *     non_innodb_count: int,
	 *     engines: array<string, int>
	 * }
	 */
	private function databaseSize(int $tableCount): array
	{
		$result = [
			'available' => false,
			'table_count' => max(0, $tableCount),
			'total_bytes' => 0,
			'non_innodb_count' => 0,
			'engines' => [],
		];
		$engine = $this->databaseEngine();
		$pattern = $this->escapeLike($this->database->getPrefix()) . '%';

		try {
			if ($engine === 'mysql' || $engine === 'mariadb') {
				$query = $this->database->getQuery(true)
					->select(
						[
							'COALESCE(' . $this->database->quoteName('ENGINE') . ", 'unknown') AS "
								. $this->database->quoteName('engine'),
							'COUNT(*) AS ' . $this->database->quoteName('table_count'),
							'COALESCE(SUM(' . $this->database->quoteName('DATA_LENGTH')
								. ' + ' . $this->database->quoteName('INDEX_LENGTH') . '), 0) AS '
								. $this->database->quoteName('total_bytes'),
						]
					)
					->from($this->database->quoteName('information_schema.TABLES'))
					->where($this->database->quoteName('TABLE_SCHEMA') . ' = DATABASE()')
					->where($this->database->quoteName('TABLE_NAME') . ' LIKE :prefix')
					->group($this->database->quoteName('ENGINE'))
					->bind(':prefix', $pattern);
				$this->database->setQuery($query);
				$rows = $this->database->loadAssocList();
				$scopedTableCount = 0;

				foreach (is_array($rows) ? $rows : [] as $row) {
					$name = strtolower((string) ($row['engine'] ?? 'unknown'));
					$count = max(0, (int) ($row['table_count'] ?? 0));
					$scopedTableCount += $count;
					$result['engines'][$name] = $count;
					$result['total_bytes'] += max(0, (int) ($row['total_bytes'] ?? 0));

					if ($name !== 'innodb') {
						$result['non_innodb_count'] += $count;
					}
				}

				if (is_array($rows) && $rows !== []) {
					$result['table_count'] = $scopedTableCount;
				}

				$result['available'] = $tableCount === 0 || (is_array($rows) && $rows !== []);
			} elseif ($engine === 'postgresql') {
				$sql = 'SELECT COUNT(*) AS table_count,'
					. ' COALESCE(SUM(pg_total_relation_size(quote_ident(schemaname)'
					. " || '.' || quote_ident(tablename))), 0) AS total_bytes"
					. ' FROM pg_tables'
					. ' WHERE schemaname = current_schema() AND tablename LIKE '
					. $this->database->quote($pattern);
				$this->database->setQuery($sql);
				$row = $this->database->loadAssoc();

				if (is_array($row)) {
					$postgresTableCount = max(0, (int) ($row['table_count'] ?? 0));
					$result['table_count'] = $postgresTableCount;
					$result['total_bytes'] = max(0, (int) ($row['total_bytes'] ?? 0));
					$result['engines'] = $postgresTableCount > 0
						? ['postgresql' => $postgresTableCount]
						: [];
					$result['available'] = true;
				}
			}
		} catch (Throwable $exception) {
			// Insufficient information_schema permissions must remain non-fatal.
		}

		return $result;
	}

	/**
	 * Return a privacy-safe database family.
	 */
	private function databaseEngine(): string
	{
		$type = method_exists($this->database, 'getServerType')
			? strtolower((string) $this->database->getServerType())
			: '';
		$version = method_exists($this->database, 'getVersion')
			? strtolower((string) $this->database->getVersion())
			: '';

		if (str_contains($version, 'mariadb')) {
			return 'mariadb';
		}

		if (str_contains($type, 'pgsql') || str_contains($type, 'postgres')) {
			return 'postgresql';
		}

		return str_contains($type, 'mysql') || str_contains($type, 'mysqli') ? 'mysql' : $this->sanitizeKey($type);
	}

	/**
	 * Return the database version without vendor text.
	 */
	private function databaseVersion(): string
	{
		$value = method_exists($this->database, 'getVersion')
			? (string) $this->database->getVersion()
			: '';

		if (preg_match('/\d+(?:\.\d+){0,3}/', $value, $matches) === 1) {
			return $this->sanitizeVersion($matches[0]);
		}

		return '';
	}

	/**
	 * Return a coarse web-server family only.
	 */
	private function webServerFamily(): string
	{
		$value = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
		$families = [
			'openlitespeed' => 'OpenLiteSpeed',
			'litespeed' => 'LiteSpeed',
			'nginx' => 'Nginx',
			'apache' => 'Apache',
			'microsoft-iis' => 'IIS',
			'caddy' => 'Caddy',
		];

		foreach ($families as $needle => $label) {
			if (str_contains($value, $needle)) {
				return $label;
			}
		}

		return $value === '' ? Text::_('COM_SITEMOVEINSPECTOR_VALUE_UNKNOWN') : Text::_('COM_SITEMOVEINSPECTOR_VALUE_OTHER');
	}

	/**
	 * Determine current Joomla's minimum PHP requirement.
	 */
	private function minimumPhp(): string
	{
		$major = (int) explode('.', $this->joomlaVersion())[0];

		return $major >= 6 ? '8.3.0' : '8.1.0';
	}

	/**
	 * Return the current Joomla version.
	 */
	private function joomlaVersion(): string
	{
		return $this->sanitizeVersion(\defined('JVERSION') ? (string) JVERSION : '');
	}

	/**
	 * Identify default template styles for request-local aggregation.
	 *
	 * Template identifiers are used only to set aggregate active flags and are
	 * never retained in the report.
	 *
	 * @return array<string, bool>
	 */
	private function activeTemplateElements(): array
	{
		try {
			$query = $this->database->getQuery(true)
				->select('DISTINCT ' . $this->database->quoteName('template'))
				->from($this->database->quoteName('#__template_styles'))
				->where($this->database->quoteName('home') . " <> '0'");
			$this->database->setQuery($query);
			$elements = $this->database->loadColumn();
			$active = [];

			foreach (is_array($elements) ? $elements : [] as $element) {
				$key = $this->sanitizeKey((string) $element);

				if ($key !== '') {
					$active[$key] = true;
				}
			}

			return $active;
		} catch (Throwable $exception) {
			return [];
		}
	}

	/**
	 * Determine whether an installed extension is outside Joomla's bundled set.
	 *
	 * The protected flag alone covers only Joomla's locked extensions. Bundled
	 * editor integrations can be authored upstream while still using Joomla's
	 * namespace, so both the manifest author and namespace are considered.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $manifest
	 */
	private function isThirdPartyExtension(
		array $row,
		array $manifest,
		string $element
	): bool {
		if (
			$element === 'com_sitemoveinspector'
			|| (int) ($row['protected'] ?? 0) === 1
		) {
			return false;
		}

		$author = trim((string) ($manifest['author'] ?? ''));

		if (strcasecmp($author, 'Joomla! Project') === 0) {
			return false;
		}

		$namespace = ltrim(trim((string) ($manifest['namespace'] ?? '')), '\\');

		return $namespace !== 'Joomla'
			&& !str_starts_with($namespace, 'Joomla\\');
	}

	/**
	 * Return the minimum supported DB version for the selected engine.
	 */
	private function minimumDatabaseVersion(string $engine): string
	{
		return [
			'mysql' => '8.0.13',
			'mariadb' => '10.4.0',
			'postgresql' => '12.0',
		][$engine] ?? '';
	}

	/**
	 * Return whether the current administrator request is HTTPS.
	 */
	private function isHttps(): bool
	{
		return $this->application instanceof CMSWebApplicationInterface
			&& $this->application->isSslConnection();
	}

	/**
	 * Query available disk space without exposing the path.
	 */
	private function sourceFreeBytes(): int
	{
		if (!\defined('JPATH_ROOT')) {
			return 0;
		}

		set_error_handler(
			static function (int $severity, string $message): bool {
				throw new \ErrorException($message, 0, $severity);
			}
		);

		try {
			$bytes = disk_free_space(JPATH_ROOT);

			return $bytes === false ? 0 : max(0, (int) $bytes);
		} catch (Throwable $exception) {
			return 0;
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Convert a PHP ini size to bytes.
	 */
	private function sizeToBytes(string $value): int
	{
		$value = trim($value);

		if ($value === '' || $value === '-1') {
			return 0;
		}

		$unit = strtolower(substr($value, -1));
		$number = (float) $value;

		if ($unit === 'g') {
			$number *= 1024;
			$unit = 'm';
		}

		if ($unit === 'm') {
			$number *= 1024;
			$unit = 'k';
		}

		if ($unit === 'k') {
			$number *= 1024;
		}

		return max(0, (int) round($number));
	}

	/**
	 * Format a byte count without retaining a server path.
	 */
	private function formatBytes(int $bytes): string
	{
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$value = (float) max(0, $bytes);
		$index = 0;

		while ($value >= 1024 && $index < count($units) - 1) {
			$value /= 1024;
			++$index;
		}

		return number_format($value, 1, '.', '') . ' ' . $units[$index];
	}

	/**
	 * Format an integer consistently for reports.
	 */
	private function number(int $value): string
	{
		return number_format(max(0, $value), 0, '.', ',');
	}

	/**
	 * Convert scanner reason codes to localized safe text.
	 */
	private function partialReason(string $reason): string
	{
		$key = [
			'entry_limit' => 'COM_SITEMOVEINSPECTOR_PARTIAL_ENTRY_LIMIT',
			'time_limit' => 'COM_SITEMOVEINSPECTOR_PARTIAL_TIME_LIMIT',
			'directory_entry_limit' => 'COM_SITEMOVEINSPECTOR_PARTIAL_DIRECTORY_ENTRY_LIMIT',
			'directory_limit' => 'COM_SITEMOVEINSPECTOR_PARTIAL_DIRECTORY_LIMIT',
			'directory_changed' => 'COM_SITEMOVEINSPECTOR_PARTIAL_DIRECTORY_CHANGED',
			'directory_resume_limit' => 'COM_SITEMOVEINSPECTOR_PARTIAL_DIRECTORY_RESUME',
			'symlinks_not_followed' => 'COM_SITEMOVEINSPECTOR_PARTIAL_SYMLINKS',
			'unreadable_entries' => 'COM_SITEMOVEINSPECTOR_PARTIAL_UNREADABLE',
			'outside_root' => 'COM_SITEMOVEINSPECTOR_PARTIAL_OUTSIDE_ROOT',
		][$reason] ?? 'COM_SITEMOVEINSPECTOR_PARTIAL_FILESYSTEM';

		return Text::_($key);
	}

	/**
	 * Sanitize a machine key.
	 */
	private function sanitizeKey(string $value): string
	{
		return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($value));
	}

	/**
	 * Keep a conservative version format.
	 */
	private function sanitizeVersion(mixed $value): string
	{
		$value = trim((string) $value);

		return preg_match('/^\d{1,3}(?:\.\d{1,3}){0,3}(?:[-+][A-Za-z0-9.-]+)?$/', $value) === 1
			? $value
			: '';
	}

	/**
	 * Escape SQL LIKE metacharacters in a trusted database prefix.
	 */
	private function escapeLike(string $value): string
	{
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}
}
