<?php
/**
 * @package     1Gbits.SiteMoveInspector
 * @subpackage  com_sitemoveinspector
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace OneGbits\Component\SiteMoveInspector\Administrator\View\Inspector;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use RuntimeException;

/**
 * Administrator migration-preflight screen.
 */
final class HtmlView extends BaseHtmlView
{
	/**
	 * Render the inspector and register its local assets.
	 */
	public function display($tpl = null): void
	{
		$application = Factory::getApplication();
		$identity = $application->getIdentity();

		if (!$identity->authorise('core.manage', 'com_sitemoveinspector')) {
			throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		ToolbarHelper::title(Text::_('COM_SITEMOVEINSPECTOR_TITLE'), 'server');

		if ($identity->authorise('core.options', 'com_sitemoveinspector')) {
			ToolbarHelper::preferences('com_sitemoveinspector');
		}

		$document = $this->getDocument();
		$assets = $document->getWebAssetManager();
		$assets->useStyle('com_sitemoveinspector.admin');
		$assets->useScript('com_sitemoveinspector.admin');
		$document->addScriptOptions(
			'com_sitemoveinspector',
			[
				'startUrl' => Route::_(
					'index.php?option=com_sitemoveinspector&task=scan.start&format=json',
					false
				),
				'stepUrl' => Route::_(
					'index.php?option=com_sitemoveinspector&task=scan.step&format=json',
					false
				),
				'cancelUrl' => Route::_(
					'index.php?option=com_sitemoveinspector&task=scan.cancel&format=json',
					false
				),
				'token' => Session::getFormToken(),
			]
		);

		foreach (
			[
				'COM_SITEMOVEINSPECTOR_JS_STARTING',
				'COM_SITEMOVEINSPECTOR_JS_SCANNING',
				'COM_SITEMOVEINSPECTOR_JS_COMPLETE',
				'COM_SITEMOVEINSPECTOR_JS_CANCELLED',
				'COM_SITEMOVEINSPECTOR_JS_ERROR',
				'COM_SITEMOVEINSPECTOR_JS_PROCESSED',
				'COM_SITEMOVEINSPECTOR_JS_ACTION',
				'COM_SITEMOVEINSPECTOR_OVERALL_HIGH_RISK',
				'COM_SITEMOVEINSPECTOR_OVERALL_REVIEW',
				'COM_SITEMOVEINSPECTOR_OVERALL_CLEAR',
				'COM_SITEMOVEINSPECTOR_STATUS_PASS',
				'COM_SITEMOVEINSPECTOR_STATUS_WARNING',
				'COM_SITEMOVEINSPECTOR_STATUS_CRITICAL',
				'COM_SITEMOVEINSPECTOR_STATUS_UNKNOWN',
				'COM_SITEMOVEINSPECTOR_STATUS_NOT_APPLICABLE',
			] as $key
		) {
			Text::script($key);
		}

		parent::display($tpl);
	}
}
