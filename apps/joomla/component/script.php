<?php
/**
 * @package     1Gbits.SiteMoveInspector
 * @subpackage  com_sitemoveinspector
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Version;

return new class () implements InstallerScriptInterface {
	/**
	 * Run installation checks.
	 */
	public function preflight(string $type, InstallerAdapter $parent): bool
	{
		if (version_compare(PHP_VERSION, '8.1.0', '<')) {
			return false;
		}

		if (
			!in_array(
				[Version::MAJOR_VERSION, Version::MINOR_VERSION],
				[[5, 4], [6, 1]],
				true
			)
		) {
			return false;
		}

		return true;
	}

	/**
	 * Complete an installation.
	 */
	public function install(InstallerAdapter $parent): bool
	{
		return true;
	}

	/**
	 * Complete an update.
	 */
	public function update(InstallerAdapter $parent): bool
	{
		return true;
	}

	/**
	 * Complete an uninstall.
	 */
	public function uninstall(InstallerAdapter $parent): bool
	{
		return true;
	}

	/**
	 * Complete post-installation processing.
	 */
	public function postflight(string $type, InstallerAdapter $parent): bool
	{
		return true;
	}
};
