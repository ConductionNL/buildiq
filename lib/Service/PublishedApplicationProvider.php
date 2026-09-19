<?php

/**
 * Buildiq Published Application Provider
 *
 * Owns the single per-request read of every published Application from
 * OpenRegister. Both boot-time consumers — AppNavigationService (top-bar
 * entries) and DashboardWidgetRegistrar (Nextcloud dashboard widgets) — read
 * from this one cache, so registering widgets adds no second query to a
 * request that already reads the same records for the app menu.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Single per-request source of the published Applications.
 *
 * Registered as a SHARED container service (see Application::register), because
 * an autowired resolution builds a fresh instance per call site and two
 * instances mean two caches and two queries — which is the thing this class
 * exists to prevent.
 */
class PublishedApplicationProvider {
	/**
	 * Register slug that hosts Application objects.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * Schema slug for Application objects.
	 */
	public const APPLICATION_SCHEMA = 'built-app';

	/**
	 * Status value that indicates a published Application.
	 */
	public const STATUS_PUBLISHED = 'published';

	/**
	 * Cache of published applications fetched this request (per-request).
	 *
	 * @var array<array<string,mixed>>|null
	 */
	private ?array $cachedApplications = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Fetch (and cache per-request) all published Applications from OpenRegister.
	 *
	 * RBAC + multitenancy are disabled here on purpose: this runs during app
	 * boot, where the user session is often NOT yet resolved (OCS navigation
	 * requests, WebDAV, cron). With the default filters the query silently
	 * returned 0 rows on those requests and no nav entries were ever
	 * registered. Per-user visibility is enforced later, by the caller, when
	 * the user IS known (AppNavigationService's entry closure, and
	 * VirtualAppWidget::isEnabled()).
	 *
	 * `productionVersion` is extended in this same query rather than fetched
	 * per application afterwards. The dashboard registrar needs the production
	 * manifest, and a second read per application would be exactly the N+1
	 * this class exists to prevent.
	 *
	 * @return array<array<string,mixed>> List of normalised Application arrays.
	 *
	 * @throws \Throwable When the OpenRegister query fails.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
	 */
	public function getPublishedApplications(): array {
		if ($this->cachedApplications !== null) {
			return $this->cachedApplications;
		}

		$results = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER_SLUG,
					'schema' => self::APPLICATION_SCHEMA,
					'status' => self::STATUS_PUBLISHED,
				],
				'limit' => 1000,
				'extend' => ['productionVersion'],
			],
			_rbac: false,
			_multitenancy: false
		);

		$applications = [];
		foreach ($results as $item) {
			$applications[] = $this->normaliseObject(object: $item);
		}

		$this->cachedApplications = $applications;
		return $applications;
	}//end getPublishedApplications()

	/**
	 * Coerce an OpenRegister result entry (ObjectEntity or array) to an array.
	 *
	 * @param mixed $object The OpenRegister object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseObject(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseObject()
}//end class
