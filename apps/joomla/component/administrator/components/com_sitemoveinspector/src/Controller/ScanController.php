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
use Joomla\CMS\Response\JsonResponse;
use OneGbits\Component\SiteMoveInspector\Administrator\Model\ScanModel;
use RuntimeException;
use Throwable;

/**
 * Authenticated, CSRF-protected scan endpoints.
 */
final class ScanController extends BaseController
{
	/**
	 * Start a new scan.
	 */
	public function start(): void
	{
		$this->respond(
			function (ScanModel $model, int $userId): array {
				$destination = $this->input->post->get('destination', [], 'array');

				return $model->start(
					$userId,
					is_array($destination) ? $destination : []
				);
			}
		);
	}

	/**
	 * Process one filesystem batch.
	 */
	public function step(): void
	{
		$this->respond(
			fn (ScanModel $model, int $userId): array => $model->step(
				$this->input->post->getString('job_id'),
				$userId
			)
		);
	}

	/**
	 * Cancel and remove a scan.
	 */
	public function cancel(): void
	{
		$this->respond(
			function (ScanModel $model, int $userId): array {
				$model->cancel($this->input->post->getString('job_id'), $userId);

				return ['status' => 'cancelled'];
			}
		);
	}

	/**
	 * Run a secured action and close with a JSON response.
	 *
	 * @param callable(ScanModel, int): array<string, mixed> $callback
	 */
	private function respond(callable $callback): void
	{
		$application = $this->app;
		$error = false;
		$message = '';
		$data = null;
		$status = 200;

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

			$data = $callback($model, (int) $identity->id);
		} catch (Throwable $exception) {
			$error = true;
			$status = in_array((int) $exception->getCode(), [400, 403, 404, 409], true)
				? (int) $exception->getCode()
				: 400;
			$message = $status === 403
				? Text::_('JERROR_ALERTNOAUTHOR')
				: Text::_('COM_SITEMOVEINSPECTOR_ERROR_REQUEST');
		}

		$application->setHeader(
			'status',
			$status . ' ' . (
				[
					200 => 'OK',
					400 => 'Bad Request',
					403 => 'Forbidden',
					404 => 'Not Found',
					409 => 'Conflict',
				][$status] ?? 'Error'
			),
			true
		);
		$application->setHeader('Content-Type', 'application/json; charset=utf-8', true);
		$application->setHeader('X-Content-Type-Options', 'nosniff', true);
		$application->setHeader('Cache-Control', 'no-store, max-age=0', true);
		$application->sendHeaders();
		echo new JsonResponse($data, $message, $error, true);
		$application->close();
	}
}
