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

/**
 * Side-effect-free filesystem path helpers.
 */
final class PathGuard
{
	/**
	 * Normalize a path for safe comparisons.
	 */
	public static function normalize(string $path): string
	{
		$path = str_replace('\\', '/', $path);
		$path = preg_replace('#/+#', '/', $path) ?: $path;

		return rtrim($path, '/');
	}

	/**
	 * Resolve a directory to its canonical path.
	 */
	public static function canonicalDirectory(string $path): ?string
	{
		$resolved = realpath($path);

		if ($resolved === false || !is_dir($resolved)) {
			return null;
		}

		return self::normalize($resolved);
	}

	/**
	 * Return whether a path is the root or one of its descendants.
	 */
	public static function isWithin(string $path, string $root): bool
	{
		$path = self::comparisonPath($path);
		$root = self::comparisonPath($root);

		if ($path === '' || $root === '') {
			return false;
		}

		return $path === $root || str_starts_with($path, $root . '/');
	}

	/**
	 * Return a root-relative path, or an empty string when it is unsafe.
	 */
	public static function relative(string $path, string $root): string
	{
		$path = self::normalize($path);
		$root = self::normalize($root);

		if (!self::isWithin($path, $root) || strlen($path) === strlen($root)) {
			return '';
		}

		return ltrim(substr($path, strlen($root)), '/');
	}

	/**
	 * Produce a stable visited-set key.
	 */
	public static function key(string $path): string
	{
		return hash('sha256', self::comparisonPath($path));
	}

	/**
	 * Normalize case on case-insensitive filesystems.
	 */
	private static function comparisonPath(string $path): string
	{
		$path = self::normalize($path);

		return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
	}
}
