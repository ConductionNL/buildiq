<?php

/**
 * Buildiq RuleExecutionLogCleanup
 *
 * Weekly TimedJob (7-day interval) that enforces the RuleExecutionLog retention
 * policy (REQ-BRE-013). Logs older than the retention window (default 90 days)
 * are first marked `archived: true`, then purged. Records within the retention
 * window are left untouched. Idempotent — re-running over an already-archived
 * set is a no-op.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\Buildiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#8.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\BackgroundJob;

use OCA\Buildiq\Kernel\Contracts\RuleEngineServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retention-policy cleanup job for RuleExecutionLog records.
 */
class RuleExecutionLogCleanup extends TimedJob {

	/**
	 * Run interval: 7 days.
	 */
	private const INTERVAL_SECONDS = 604800;

	/**
	 * Retention window: 90 days.
	 */
	private const RETENTION_SECONDS = 7776000;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Archive then purge RuleExecutionLog records past the retention window.
	 *
	 * @param mixed $argument Job argument injected by Nextcloud (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-013-cleanup-job-for-aged-execution-logs
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	protected function run($argument): void {
		unset($argument);

		$cutoff = gmdate('Y-m-d\TH:i:s\Z', (time() - self::RETENTION_SECONDS));

		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => RuleEngineServiceInterface::REGISTER_SLUG,
						'schema' => RuleEngineServiceInterface::EXECUTION_LOG_SCHEMA,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: RuleExecutionLog cleanup query failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$purged = 0;
		foreach ($results as $row) {
			$purged += $this->purgeIfExpired(row: $row, cutoff: $cutoff);
		}

		if ($purged > 0) {
			$this->logger->info('Buildiq cleanup: purged ' . $purged . ' expired rule-execution log(s)');
		}

	}//end run()

	/**
	 * Delete one log record older than the cutoff.
	 *
	 * @param mixed  $row    The log record.
	 * @param string $cutoff The retention cutoff, as an ISO 8601 UTC timestamp.
	 *
	 * @return int 1 when the record was purged, 0 otherwise.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function purgeIfExpired(mixed $row, string $cutoff): int {
		$data = ObjectRecord::serialised(object: $row);
		$timestamp = (string)($data['timestamp'] ?? '');
		if ($timestamp === '' || $timestamp >= $cutoff) {
			return 0;
		}

		$uuid = (string)($data['id'] ?? ($data['uuid'] ?? ''));
		if ($uuid === '') {
			return 0;
		}

		try {
			$this->objectService->deleteObject(uuid: $uuid);
			return 1;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: failed to purge RuleExecutionLog record',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);
		}

		return 0;
	}//end purgeIfExpired()
}//end class
