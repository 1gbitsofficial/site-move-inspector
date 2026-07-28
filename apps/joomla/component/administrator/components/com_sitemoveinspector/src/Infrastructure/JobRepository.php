<?php
/**
 * @package     1Gbits.SiteMoveInspector
 * @subpackage  com_sitemoveinspector
 *
 * @copyright   (C) 2026 1Gbits. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace OneGbits\Component\SiteMoveInspector\Administrator\Infrastructure;

\defined('_JEXEC') or die;

use DateTimeImmutable;
use DateTimeZone;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Temporary component-owned scan state with user binding and short TTLs.
 */
final class JobRepository
{
	private const TABLE = '#__sitemoveinspector_jobs';
	private const ACTIVE_TTL = 1800;
	private const REPORT_TTL = 3600;
	private const LOCK_TTL = 20;

	public function __construct(private DatabaseInterface $database)
	{
	}

	/**
	 * Delete expired component-owned state.
	 */
	public function cleanup(): void
	{
		$now = $this->date();
		$query = $this->database->getQuery(true)
			->delete($this->database->quoteName(self::TABLE))
			->where($this->database->quoteName('expires_at') . ' < :now')
			->bind(':now', $now);

		$this->database->setQuery($query)->execute();
	}

	/**
	 * Replace this user's prior job and persist a new active cursor.
	 *
	 * @param array<string, mixed> $state
	 */
	public function create(int $userId, array $state): string
	{
		if ($userId <= 0) {
			throw new RuntimeException('A signed-in administrator is required.');
		}

		$this->deleteForUser($userId);
		$id = bin2hex(random_bytes(16));
		$now = $this->date();
		$row = (object) [
			'id' => $id,
			'user_id' => $userId,
			'status' => 'active',
			'state_json' => $this->encode($state),
			'report_json' => null,
			'lock_token' => '',
			'locked_until' => null,
			'created_at' => $now,
			'updated_at' => $now,
			'expires_at' => $this->date(self::ACTIVE_TTL),
		];

		$this->database->insertObject(self::TABLE, $row);

		return $id;
	}

	/**
	 * Load a job only for its owner.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, int $userId): ?array
	{
		if (!$this->validId($id) || $userId <= 0) {
			return null;
		}

		$now = $this->date();
		$query = $this->database->getQuery(true)
			->select('*')
			->from($this->database->quoteName(self::TABLE))
			->where($this->database->quoteName('id') . ' = :id')
			->where($this->database->quoteName('user_id') . ' = :user_id')
			->where($this->database->quoteName('expires_at') . ' >= :now')
			->bind(':id', $id)
			->bind(':user_id', $userId, ParameterType::INTEGER)
			->bind(':now', $now);

		$this->database->setQuery($query);
		$row = $this->database->loadAssoc();

		if (!is_array($row)) {
			return null;
		}

		$row['user_id'] = (int) $row['user_id'];
		$row['state'] = $this->decode((string) ($row['state_json'] ?? ''));
		$row['report'] = $this->decode((string) ($row['report_json'] ?? ''), true);
		unset($row['state_json'], $row['report_json']);

		return $row;
	}

	/**
	 * Atomically acquire an expired or available job lock.
	 */
	public function acquire(string $id, int $userId): ?string
	{
		if (!$this->validId($id) || $userId <= 0) {
			return null;
		}

		$token = bin2hex(random_bytes(16));
		$now = $this->date();
		$lockedUntil = $this->date(self::LOCK_TTL);
		$query = $this->database->getQuery(true)
			->update($this->database->quoteName(self::TABLE))
			->set($this->database->quoteName('lock_token') . ' = :token')
			->set($this->database->quoteName('locked_until') . ' = :locked_until')
			->where($this->database->quoteName('id') . ' = :id')
			->where($this->database->quoteName('user_id') . ' = :user_id')
			->where($this->database->quoteName('status') . " = 'active'")
			->where($this->database->quoteName('expires_at') . ' >= :expires_now')
			->where(
				'(' . $this->database->quoteName('locked_until') . ' IS NULL'
				. ' OR ' . $this->database->quoteName('locked_until') . ' < :lock_now'
				. ' OR ' . $this->database->quoteName('lock_token') . " = '')"
			)
			->bind(':token', $token)
			->bind(':locked_until', $lockedUntil)
			->bind(':id', $id)
			->bind(':user_id', $userId, ParameterType::INTEGER)
			->bind(':expires_now', $now)
			->bind(':lock_now', $now);

		$this->database->setQuery($query)->execute();

		return $this->database->getAffectedRows() === 1 ? $token : null;
	}

	/**
	 * Save a cursor/report and release its lock.
	 *
	 * @param array<string, mixed>      $state
	 * @param array<string, mixed>|null $report
	 */
	public function save(
		string $id,
		int $userId,
		string $lockToken,
		array $state,
		?array $report,
		bool $completed
	): void {
		$status = $completed ? 'completed' : 'active';
		$stateJson = $this->encode($state);
		$reportJson = $report === null ? '' : $this->encode($report);
		$updatedAt = $this->date();
		$expiresAt = $this->date($completed ? self::REPORT_TTL : self::ACTIVE_TTL);
		$query = $this->database->getQuery(true)
			->update($this->database->quoteName(self::TABLE))
			->set($this->database->quoteName('status') . ' = :status')
			->set($this->database->quoteName('state_json') . ' = :state')
			->set($this->database->quoteName('report_json') . ' = :report')
			->set($this->database->quoteName('updated_at') . ' = :updated_at')
			->set($this->database->quoteName('expires_at') . ' = :expires_at')
			->set($this->database->quoteName('lock_token') . " = ''")
			->set($this->database->quoteName('locked_until') . ' = NULL')
			->where($this->database->quoteName('id') . ' = :id')
			->where($this->database->quoteName('user_id') . ' = :user_id')
			->where($this->database->quoteName('lock_token') . ' = :lock_token')
			->bind(':status', $status)
			->bind(':state', $stateJson)
			->bind(':report', $reportJson)
			->bind(':updated_at', $updatedAt)
			->bind(':expires_at', $expiresAt)
			->bind(':id', $id)
			->bind(':user_id', $userId, ParameterType::INTEGER)
			->bind(':lock_token', $lockToken);

		$this->database->setQuery($query)->execute();

		if ($this->database->getAffectedRows() !== 1) {
			throw new RuntimeException('The scan job changed before it could be saved.');
		}
	}

	/**
	 * Release a lock after an error without altering the job payload.
	 */
	public function release(string $id, int $userId, string $lockToken): void
	{
		try {
			$query = $this->database->getQuery(true)
				->update($this->database->quoteName(self::TABLE))
				->set($this->database->quoteName('lock_token') . " = ''")
				->set($this->database->quoteName('locked_until') . ' = NULL')
				->where($this->database->quoteName('id') . ' = :id')
				->where($this->database->quoteName('user_id') . ' = :user_id')
				->where($this->database->quoteName('lock_token') . ' = :lock_token')
				->bind(':id', $id)
				->bind(':user_id', $userId, ParameterType::INTEGER)
				->bind(':lock_token', $lockToken);

			$this->database->setQuery($query)->execute();
		} catch (Throwable $exception) {
			// The short lock TTL is the final recovery path.
		}
	}

	/**
	 * Delete one job owned by the current user.
	 */
	public function delete(string $id, int $userId): void
	{
		if (!$this->validId($id) || $userId <= 0) {
			return;
		}

		$query = $this->database->getQuery(true)
			->delete($this->database->quoteName(self::TABLE))
			->where($this->database->quoteName('id') . ' = :id')
			->where($this->database->quoteName('user_id') . ' = :user_id')
			->bind(':id', $id)
			->bind(':user_id', $userId, ParameterType::INTEGER);

		$this->database->setQuery($query)->execute();
	}

	/**
	 * Remove all prior state for one user.
	 */
	private function deleteForUser(int $userId): void
	{
		$query = $this->database->getQuery(true)
			->delete($this->database->quoteName(self::TABLE))
			->where($this->database->quoteName('user_id') . ' = :user_id')
			->bind(':user_id', $userId, ParameterType::INTEGER);

		$this->database->setQuery($query)->execute();
	}

	/**
	 * Encode serializable metadata without partial output.
	 *
	 * @param array<string, mixed> $value
	 */
	private function encode(array $value): string
	{
		$json = json_encode(
			$value,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		);

		if (!is_string($json)) {
			throw new RuntimeException('The scan job could not be encoded.');
		}

		return $json;
	}

	/**
	 * Decode a job payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decode(string $value, bool $nullable = false): ?array
	{
		if ($value === '') {
			return $nullable ? null : [];
		}

		$decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

		if (!is_array($decoded)) {
			throw new RuntimeException('The scan job payload is invalid.');
		}

		return $decoded;
	}

	/**
	 * Return a UTC database timestamp.
	 */
	private function date(int $offsetSeconds = 0): string
	{
		$time = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		if ($offsetSeconds !== 0) {
			$time = $time->modify(sprintf('+%d seconds', $offsetSeconds));
		}

		return $time->format('Y-m-d H:i:s');
	}

	/**
	 * Validate a browser-facing opaque job identifier.
	 */
	private function validId(string $id): bool
	{
		return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
	}
}
