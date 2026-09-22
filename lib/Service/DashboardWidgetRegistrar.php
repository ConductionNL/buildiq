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
		$uuid = (string)($application['uuid'] ?? $application['id'] ?? '');
		$slug = (string)($application['slug'] ?? '');
		if ($uuid === '' || $slug === '') {
			return [];
		}

		$manifest = $this->productionManifest(application: $application);
		$pages = ($manifest['pages'] ?? []);
		if (is_array($pages) === false) {
			return [];
		}

		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		$appPrincipals = $this->visibility->flattenPermissions(permissions: $permissions);
		$name = (string)($application['name'] ?? $slug);

		$descriptors = [];
		foreach ($pages as $page) {
			$found = $this->descriptorsForPage(
				page: $page,
				application: ['uuid' => $uuid, 'slug' => $slug, 'name' => $name],
				appPrincipals: $appPrincipals
			);
			foreach ($found as $descriptor) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end descriptorsForApplication()

	/**
	 * Collect the promoted placements of one page.
	 *
	 * @param mixed $page One manifest page.
	 * @param array{uuid:string,slug:string,name:string} $application The owning Application.
	 * @param array<int,mixed> $appPrincipals The Application's flattened permissions.
	 *
	 * @return array<int,WidgetDescriptor> The page's promoted placements.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
	 */
	private function descriptorsForPage(mixed $page, array $application, array $appPrincipals): array {
		if (is_array($page) === false) {
			return [];
		}

		$widgets = ($page['widgets'] ?? []);
		if (is_array($widgets) === false) {
			return [];
		}

		$descriptors = [];
		foreach ($widgets as $entry) {
			$descriptor = $this->descriptorForEntry(
				entry: $entry,
				page: $page,
				application: $application,
				appPrincipals: $appPrincipals
			);
			if ($descriptor !== null) {
				$descriptors[] = $descriptor;
			}
		}

		return $descriptors;
	}//end descriptorsForPage()

	/**
	 * Build one descriptor from a widget placement, or null when unpromoted.
	 *
	 * @param mixed $entry The `widgets[]` entry.
	 * @param array<string,mixed> $page The page the entry sits on.
	 * @param array{uuid:string,slug:string,name:string} $application The owning Application.
	 * @param array<int,mixed> $appPrincipals The Application's flattened permissions.
	 *
	 * @return WidgetDescriptor|null The descriptor, or null when the entry is not promoted.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
	 */
	private function descriptorForEntry(
		mixed $entry,
		array $page,
		array $application,
		array $appPrincipals,
	): ?WidgetDescriptor {
		if (is_array($entry) === false) {
			return null;
		}

		$panel = ($entry[self::PROMOTION_KEY] ?? null);
		if (is_array($panel) === false) {
			return null;
		}

		$entryId = (string)($entry['id'] ?? '');
		if ($entryId === '') {
			// The schema requires a non-empty id alongside ncDashboard,
			// because position in widgets[] is not stable across edits and an
			// id derived from position would move a user's panel onto a
			// different widget when the page is reordered. A manifest stored
			// before that rule existed is skipped rather than given an
			// unstable identity.
			$this->logger->warning(
				'DashboardWidgetRegistrar: skipped a promoted widget on app "'
				. $application['slug'] . '" because its placement carries no id.'
			);
			return null;
		}

		$roles = ($entry['roles'] ?? []);
		if (is_array($roles) === false) {
			$roles = [];
		}

		return new WidgetDescriptor(
			applicationUuid: $application['uuid'],
			applicationSlug: $application['slug'],
			applicationName: $application['name'],
			principals: array_merge($appPrincipals, array_values($roles)),
			entryId: $entryId,
			widgetKey: (string)($entry['widgetKey'] ?? ''),
			panel: $this->panelFrom(panel: $panel, entryId: $entryId),
			placement: [
				'pageRoute' => (string)($page['route'] ?? '/'),
				'props' => (array)($entry['props'] ?? []),
				'dataSource' => (array)($entry['dataSource'] ?? []),
			]
		);
	}//end descriptorForEntry()

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
