<?php

/**
 * Buildiq AppHealthSampleCleanup
 *
 * Deletes app health samples older than the retention window, once a day.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Buildiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\BackgroundJob;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\AppHealthService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily retention for app-health-sample objects.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
 */
class AppHealthSampleCleanup extends TimedJob {
	/**
	 * Run interval: one day.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Default retention in days, and the bounds an administrator may set.
	 */
	public const DEFAULT_DAYS = 30;
	public const MIN_DAYS = 7;
	public const MAX_DAYS = 90;

	/**
	 * The most samples one run deletes.
	 */
	private const BATCH = 5000;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ObjectServiceInterface $objectService OpenRegister objects.
	 * @param AppHealthService $health Normalises stored samples.
	 * @param IAppConfig $appConfig Reads `health_retention_days`.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectServiceInterface $objectService,
		private readonly AppHealthService $health,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Delete the samples whose window started before the retention cutoff.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		unset($argument);
		$this->purge(now: $this->time->getTime());
	}//end run()

	/**
	 * Delete the samples older than the retention window.
	 *
	 * @param int $now Unix time of the run.
	 *
	 * @return int How many samples were deleted.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
	 */
	public function purge(int $now): int {
		$days = $this->appConfig->getValueInt(Application::APP_ID, 'health_retention_days', self::DEFAULT_DAYS);
		$days = max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
		$cutoff = gmdate('Y-m-d\TH:i:s', $now - ($days * 86400));

		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => AppHealthService::REGISTER_SLUG, 'schema' => AppHealthService::SCHEMA_SLUG],
					'limit' => self::BATCH,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: app health cleanup query failed', ['exception' => $e->getMessage()]);
			return 0;
		}

		$deleted = 0;
		foreach ($rows as $row) {
			$data = $this->health->normalise(object: $row);
			$uuid = (string)($data['id'] ?? ($data['uuid'] ?? ''));
			if ($uuid === '' || substr((string)($data['windowStart'] ?? ''), 0, 19) >= $cutoff) {
				continue;
			}

			try {
				$this->objectService->deleteObject(
					uuid: $uuid,
					register: AppHealthService::REGISTER_SLUG,
					schema: AppHealthService::SCHEMA_SLUG,
					_rbac: false,
					_multitenancy: false
				);
				$deleted++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Buildiq: an app health sample could not be deleted',
					['uuid' => $uuid, 'exception' => $e->getMessage()]
				);
			}
		}

		return $deleted;
	}//end purge()
}//end class
