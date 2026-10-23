<?php

/**
 * Buildiq App Health Service
 *
 * Stores the health samples running built apps post and adds a window of
 * them up for the app detail page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes and reads app-health-sample objects in the buildiq register.
 *
 * RBAC is off on both paths: the schema is admin-only, and the controller has
 * already applied the version's own rule (open it to post, see its insights to read).
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md
 */
class AppHealthService {
	/**
	 * The register the samples live in.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * The sample schema.
	 */
	public const SCHEMA_SLUG = 'app-health-sample';

	/**
	 * The most samples one window reads (50 pages, every five minutes, 90 days would be far more;
	 * the newest are kept).
	 */
	public const READ_LIMIT = 20000;

	/**
	 * Window lengths in days.
	 */
	public const WINDOW_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister objects.
	 * @param AppHealthSamples $samples Sanitising and aggregation.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AppHealthSamples $samples,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store one sample per posted page for an app version the caller may open.
	 *
	 * @param string $applicationUuid The application, derived by the server.
	 * @param string $versionUuid The version, derived by the server.
	 * @param mixed $pages The posted pages.
	 *
	 * @return int How many samples were stored.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
	 */
	public function record(string $applicationUuid, string $versionUuid, mixed $pages): int {
		$windowStart = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
		$stored = 0;
		foreach ($this->samples->sanitise(pages: $pages) as $sample) {
			try {
				$this->objectService->saveObject(
					['applicationUuid' => $applicationUuid, 'versionUuid' => $versionUuid, 'windowStart' => $windowStart] + $sample,
					register: self::REGISTER_SLUG,
					schema: self::SCHEMA_SLUG,
					_rbac: false,
					_multitenancy: false
				);
				$stored++;
			} catch (Throwable $e) {
				$this->logger->warning('Buildiq: an app health sample could not be stored', ['exception' => $e->getMessage()]);
			}
		}

		return $stored;
	}//end record()

	/**
	 * The health of an app version over a window. The caller has already been authorised.
	 *
	 * @param string $applicationUuid The application.
	 * @param string $versionUuid The version.
	 * @param string $window 7d, 30d or 90d.
	 * @param DateTimeImmutable|null $now The moment; now when null.
	 *
	 * @return array<string, mixed>|null The aggregate, null for an unknown window.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
	 */
	public function computeHealth(string $applicationUuid, string $versionUuid, string $window, ?DateTimeImmutable $now = null): ?array {
		if (isset(self::WINDOW_DAYS[$window]) === false) {
			return null;
		}

		$now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$since = $now->modify('-' . self::WINDOW_DAYS[$window] . ' days')->format(DATE_ATOM);

		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER_SLUG,
						'schema' => self::SCHEMA_SLUG,
						'applicationUuid' => $applicationUuid,
						'versionUuid' => $versionUuid,
					],
					'limit' => self::READ_LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: app health samples could not be read', ['exception' => $e->getMessage()]);
			$rows = [];
		}

		$inWindow = [];
		foreach ($rows as $row) {
			$data = $this->normalise(object: $row);
			if ((string)($data['windowStart'] ?? '') >= $since) {
				$inWindow[] = $data;
			}
		}

		return ['window' => $window] + $this->samples->aggregate(samples: $inWindow);
	}//end computeHealth()

	/**
	 * An object as its data array.
	 *
	 * @param mixed $object An entity or array.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
	 */
	public function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$data = $object->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end normalise()
}//end class
