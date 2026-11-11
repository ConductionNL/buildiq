<?php

/**
 * Buildiq Dashboard Widget Registrar
 *
 * Walks every published Application's production manifest, collects each
 * `widgets[]` placement carrying an `ncDashboard` object, registers one
 * container service per placement under a SYNTHETIC service name, and hands
 * that name to `IManager::lazyRegisterWidget()`.
 *
 * No class is generated and no class is faked.
 * `OCA\Buildiq\Dashboard\Virtual\<id>` is a SERVICE name, not a class name;
 * nothing ever autoloads it. Nextcloud resolves it through the server
 * container, which routes any `OCA\…` name to that app's
 * `DIContainer::queryNoFallback()`, which checks `offsetExists($name)` FIRST
 * and returns the registered service before it tries to instantiate anything.
 * That behaviour is documented by implementation rather than by contract,
 * which is why tests/Unit/Dashboard/SyntheticServiceResolutionPinTest.php
 * pins it against the live container. If that pin ever fails, the fallback is
 * design.md R1: generate one real PHP class per promoted widget.
 *
 * Per ADR-031 §Exceptions this is imperative because widget registration
 * requires a closure factory evaluated per request, container service
 * registration under a name computed at runtime and
 * `IManager::lazyRegisterWidget()`. None of those are OpenRegister
 * calculation vocabulary. It is the same carve-out AppNavigationService
 * documents for `INavigationManager::add()`. WHICH widgets are promoted stays
 * declarative and lives in the app manifest.
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
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Dashboard\VirtualAppWidget;
use OCA\Buildiq\Dashboard\VirtualAppWidgetContext;
use OCA\Buildiq\Dashboard\WidgetApplication;
use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCP\Dashboard\IManager;
use OCP\IContainer;
use Psr\Log\LoggerInterface;

/**
 * Registers one Nextcloud Dashboard widget per promoted manifest placement.
 */
class DashboardWidgetRegistrar {
	/**
	 * Prefix of every synthetic container service name. A SERVICE name, not a
	 * class name: nothing autoloads it.
	 */
	public const SERVICE_PREFIX = 'OCA\\Buildiq\\Dashboard\\Virtual\\';

	/**
	 * The app id widgets are registered under.
	 */
	public const APP_ID = 'buildiq';

	/**
	 * The manifest key that promotes a placement.
	 */
	public const PROMOTION_KEY = 'ncDashboard';

	/**
	 * Keys an `ncDashboard` object may carry.
	 *
	 * @var array<int,string>
	 */
	public const PANEL_KEYS = ['title', 'icon', 'order', 'link'];

	/**
	 * Constructor.
	 *
	 * @param PublishedApplicationProvider $applications Shared per-request published-app read.
	 * @param AppVisibilityResolver $visibility Shared visibility check order.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PublishedApplicationProvider $applications,
		private readonly AppVisibilityResolver $visibility,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Register one dashboard widget per promoted placement.
	 *
	 * Never throws. An instance without OpenRegister, or one whose query
	 * fails, logs a warning, registers nothing, and leaves every other
	 * dashboard widget on the instance working.
	 *
	 * @param IContainer $container The app container the synthetic services are registered on.
	 * @param IManager $dashboardManager The Nextcloud dashboard manager.
	 * @param VirtualAppWidgetContext $context Shared collaborators for the widgets.
	 *
	 * @return int The number of widgets registered.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
	 */
	public function registerWidgets(
		IContainer $container,
		IManager $dashboardManager,
		VirtualAppWidgetContext $context,
	): int {
		try {
			$applications = $this->applications->getPublishedApplications();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DashboardWidgetRegistrar: failed to query published applications: ' . $e->getMessage()
			);
			return 0;
		}

		$registered = 0;
		foreach ($this->collectDescriptors(applications: $applications) as $descriptor) {
			$context->addDescriptor(descriptor: $descriptor);

			$serviceName = self::serviceNameFor(widgetId: $descriptor->id);
			$container->registerService(
				$serviceName,
				static function () use ($descriptor, $context): VirtualAppWidget {
					return new VirtualAppWidget(descriptor: $descriptor, context: $context);
				}
			);
			$dashboardManager->lazyRegisterWidget($serviceName, self::APP_ID);
			$registered++;
		}

		return $registered;
	}//end registerWidgets()

	/**
	 * The synthetic container service name for one widget id.
	 *
	 * @param string $widgetId The derived widget id.
	 *
	 * @return string A service name that is never autoloaded.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
	 */
	public static function serviceNameFor(string $widgetId): string {
		return self::SERVICE_PREFIX . $widgetId;
	}//end serviceNameFor()

	/**
	 * Collect one descriptor per promoted placement across every application.
	 *
	 * Only published Applications reach this method: the provider's query
	 * filters on `status == published`, so draft and archived are never
	 * walked.
	 *
	 * @param array<int,array<string,mixed>> $applications Published Applications.
	 *
	 * @return array<int,WidgetDescriptor> One descriptor per promoted placement.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
	 */
	public function collectDescriptors(array $applications): array {
		$descriptors = [];
		foreach ($applications as $application) {
			foreach ($this->descriptorsForApplication(application: $application) as $descriptor) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end collectDescriptors()

	/**
	 * Collect the promoted placements of one Application.
	 *
	 * @param array<string,mixed> $application One published Application record.
	 *
	 * @return array<int,WidgetDescriptor> Its promoted placements.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
	 */
	private function descriptorsForApplication(array $application): array {
		$owner = $this->widgetApplication(application: $application);
		if ($owner === null) {
			return [];
		}

		$pages = ($this->productionManifest(application: $application)['pages'] ?? []);
		if (is_array($pages) === false) {
			return [];
		}

		$source = new WidgetSource(application: $owner, principals: $this->appPrincipals(application: $application));
		return $this->descriptorsForPages(pages: $pages, source: $source);
	}//end descriptorsForApplication()

	/**
	 * The uuid, slug and name a widget of this Application is keyed on and shows.
	 *
	 * @param array<string,mixed> $application One published Application record.
	 *
	 * @return WidgetApplication|null Null when the Application has no uuid or no slug.
	 */
	private function widgetApplication(array $application): ?WidgetApplication {
		$uuid = $this->applicationUuid(application: $application);
		$slug = (string)($application['slug'] ?? '');
		if ($uuid === '' || $slug === '') {
			return null;
		}

		return new WidgetApplication(uuid: $uuid, slug: $slug, name: (string)($application['name'] ?? $slug));
	}//end widgetApplication()

	/**
	 * The Application's uuid, or its id when the record carries no uuid.
	 *
	 * @param array<string,mixed> $application One published Application record.
	 *
	 * @return string '' when it carries neither.
	 */
	private function applicationUuid(array $application): string {
		return (string)($application['uuid'] ?? $application['id'] ?? '');
	}//end applicationUuid()

	/**
	 * The Application's flattened permissions; a malformed block names nobody.
	 *
	 * @param array<string,mixed> $application One published Application record.
	 *
	 * @return array<int,mixed>
	 */
	private function appPrincipals(array $application): array {
		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		return $this->visibility->flattenPermissions(permissions: $permissions);
	}//end appPrincipals()

	/**
	 * Collect the promoted placements of every page of one Application.
	 *
	 * @param array<array-key,mixed> $pages  The manifest pages.
	 * @param WidgetSource           $source The owning Application.
	 *
	 * @return array<int,WidgetDescriptor>
	 */
	private function descriptorsForPages(array $pages, WidgetSource $source): array {
		$descriptors = [];
		foreach ($pages as $page) {
			foreach ($this->descriptorsForPage(page: $page, source: $source) as $descriptor) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end descriptorsForPages()

	/**
	 * Collect the promoted placements of one page.
	 *
	 * @param mixed        $page   One manifest page.
	 * @param WidgetSource $source The owning Application.
	 *
	 * @return array<int,WidgetDescriptor> The page's promoted placements.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
	 */
	private function descriptorsForPage(mixed $page, WidgetSource $source): array {
		if (is_array($page) === false) {
			return [];
		}

		$widgets = ($page['widgets'] ?? []);
		if (is_array($widgets) === false) {
			return [];
		}

		return $this->descriptorsOnPage(widgets: $widgets, host: new WidgetHostPage(source: $source, route: (string)($page['route'] ?? '/')));
	}//end descriptorsForPage()

	/**
	 * Collect the promoted placements among a page's widgets.
	 *
	 * @param array<array-key,mixed> $widgets The page's `widgets[]`.
	 * @param WidgetHostPage         $host    The page they sit on.
	 *
	 * @return array<int,WidgetDescriptor>
	 */
	private function descriptorsOnPage(array $widgets, WidgetHostPage $host): array {
		$descriptors = [];
		foreach ($widgets as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$descriptor = $this->descriptorForEntry(entry: $entry, host: $host);
			if ($descriptor !== null) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end descriptorsOnPage()

	/**
	 * Build one descriptor from a widget placement, or null when unpromoted.
	 *
	 * @param array<string,mixed> $entry The `widgets[]` entry.
	 * @param WidgetHostPage      $host  The page the entry sits on.
	 *
	 * @return WidgetDescriptor|null The descriptor, or null when the entry is not promoted.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
	 */
	private function descriptorForEntry(array $entry, WidgetHostPage $host): ?WidgetDescriptor {
		if (is_array($entry[self::PROMOTION_KEY] ?? null) === false) {
			return null;
		}

		$entryId = $this->placementId(entry: $entry, host: $host);
		if ($entryId === '') {
			return null;
		}

		$roles = $this->placementRoles(entry: $entry, host: $host);
		if ($roles === null) {
			return null;
		}

		return $this->describe(placement: new PromotedPlacement(entry: $entry, entryId: $entryId, roles: $roles), host: $host);
	}//end descriptorForEntry()

	/**
	 * A promoted placement's stable id; '' (with a warning) when it carries none.
	 *
	 * The schema requires a non-empty id alongside ncDashboard, because
	 * position in widgets[] is not stable across edits and an id derived from
	 * position would move a user's panel onto a different widget when the page
	 * is reordered. A manifest stored before that rule existed is skipped rather
	 * than given an unstable identity.
	 *
	 * @param array<string,mixed> $entry The `widgets[]` entry.
	 * @param WidgetHostPage      $host  The page the entry sits on.
	 *
	 * @return string
	 */
	private function placementId(array $entry, WidgetHostPage $host): string {
		$entryId = (string)($entry['id'] ?? '');
		if ($entryId === '') {
			$this->logger->warning(
				'DashboardWidgetRegistrar: skipped a promoted widget on app "'
				. $host->source->application->slug . '" because its placement carries no id.'
			);
		}

		return $entryId;
	}//end placementId()

	/**
	 * A placement's own roles; null (with a warning) when they are malformed.
	 *
	 * @param array<string,mixed> $entry The `widgets[]` entry, which carries an id.
	 * @param WidgetHostPage      $host  The page the entry sits on.
	 *
	 * @return array<int,string>|null
	 */
	private function placementRoles(array $entry, WidgetHostPage $host): ?array {
		$roles = $this->visibility->normaliseRoles(roles: ($entry['roles'] ?? null));
		if ($roles === null) {
			$this->logger->warning(
				'DashboardWidgetRegistrar: skipped promoted widget "' . (string)($entry['id'] ?? '') . '" on app "'
				. $host->source->application->slug . '" because its roles are neither a string nor a list.'
			);
		}

		return $roles;
	}//end placementRoles()

	/**
	 * The descriptor of an accepted placement.
	 *
	 * @param PromotedPlacement $placement The accepted placement.
	 * @param WidgetHostPage    $host      The page it sits on.
	 *
	 * @return WidgetDescriptor
	 */
	private function describe(PromotedPlacement $placement, WidgetHostPage $host): WidgetDescriptor {
		$entry = $placement->entry;
		return new WidgetDescriptor(
			application: $host->source->application,
			principals: $host->source->principals,
			entryId: $placement->entryId,
			widgetKey: (string)($entry['widgetKey'] ?? ''),
			panel: $this->panelFrom(panel: $entry[self::PROMOTION_KEY], entryId: $placement->entryId),
			placement: $this->placementFields(placement: $placement, host: $host)
		);
	}//end describe()

	/**
	 * Where an accepted placement sits and what it shows: page route, props,
	 * data source and its own roles.
	 *
	 * @param PromotedPlacement $placement The accepted placement.
	 * @param WidgetHostPage    $host      The page it sits on.
	 *
	 * @return array{pageRoute:string,props:array<mixed>,dataSource:array<mixed>,roles:array<int,string>}
	 */
	private function placementFields(PromotedPlacement $placement, WidgetHostPage $host): array {
		return [
			'pageRoute' => $host->route,
			'props' => (array)($placement->entry['props'] ?? []),
			'dataSource' => (array)($placement->entry['dataSource'] ?? []),
			'roles' => $placement->roles,
		];
	}//end placementFields()

	/**
	 * Keep only the four keys `ncDashboard` may carry.
	 *
	 * @param array<string,mixed> $panel The raw ncDashboard object.
	 * @param string $entryId Fallback title when none is declared.
	 *
	 * @return array<string,mixed> The panel declaration.
	 */
	private function panelFrom(array $panel, string $entryId): array {
		$kept = ['title' => $entryId];
		foreach (self::PANEL_KEYS as $key) {
			if (array_key_exists($key, $panel) === true) {
				$kept[$key] = $panel[$key];
			}
		}

		return $kept;
	}//end panelFrom()

	/**
	 * The production manifest of one Application.
	 *
	 * `productionVersion` is extended by the shared provider query, so an
	 * embedded version object carries its manifest here with no second read.
	 * An Application whose production pointer is still a bare UUID falls back
	 * to its own `manifest`, which is the same order ManifestResolverService
	 * applies.
	 *
	 * @param array<string,mixed> $application One published Application record.
	 *
	 * @return array<string,mixed> The manifest, possibly empty.
	 */
	private function productionManifest(array $application): array {
		$productionVersion = ($application['productionVersion'] ?? null);
		if (is_array($productionVersion) === true) {
			$manifest = ($productionVersion['manifest'] ?? null);
			if (is_array($manifest) === true) {
				return $manifest;
			}
		}

		$manifest = ($application['manifest'] ?? null);
		if (is_array($manifest) === true) {
			return $manifest;
		}

		return [];
	}//end productionManifest()
}//end class
