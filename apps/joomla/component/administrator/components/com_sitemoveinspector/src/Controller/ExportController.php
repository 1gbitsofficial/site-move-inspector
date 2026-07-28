<?php
/**
 * @package     1Gbits.SiteMoveInspector
 * @subpackage  com_sitemoveinspector
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace OneGbits\Component\SiteMoveInspector\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use OneGbits\Component\SiteMoveInspector\Administrator\Model\ScanModel;
use RuntimeException;
use Throwable;

/**
 * Generates privacy-safe report downloads in memory.
 */
final class ExportController extends BaseController
{
	/**
	 * Stream a completed TXT or JSON report.
	 */
	public function download(): void
	{
		$application = $this->app;

		try {
			if (!$this->checkToken('post', false)) {
				throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
			}

			$identity = $application->getIdentity();

			if (!$identity->authorise('core.manage', 'com_sitemoveinspector')) {
				throw new RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
			}

			$model = $this->getModel('Scan');

			if (!$model instanceof ScanModel) {
				throw new RuntimeException(Text::_('COM_SITEMOVEINSPECTOR_ERROR_MODEL'));
			}

			$download = $model->export(
				$this->input->post->getString('job_id'),
				(int) $identity->id,
				$this->input->post->getCmd('report_format', 'txt')
			);
			$application->setHeader('Content-Type', $download['mime'], true);
			$application->setHeader(
				'Content-Disposition',
				'attachment; filename="' . $download['filename'] . '"',
				true
			);
			$application->setHeader('X-Content-Type-Options', 'nosniff', true);
			$application->setHeader('Cache-Control', 'no-store, max-age=0', true);
			$application->sendHeaders();
			echo $download['content'];
			$application->close();
		} catch (Throwable $exception) {
			$status = (int) $exception->getCode() === 403 ? 403 : 400;
			$application->setHeader(
				'status',
				$status . ' ' . ($status === 403 ? 'Forbidden' : 'Bad Request'),
				true
			);
			$application->enqueueMessage(
				$status === 403
					? Text::_('JERROR_ALERTNOAUTHOR')
					: Text::_('COM_SITEMOVEINSPECTOR_ERROR_EXPORT'),
				'error'
			);
			$this->setRedirect(
				Route::_('index.php?option=com_sitemoveinspector&view=inspector', false)
			);
		}
	}
}
