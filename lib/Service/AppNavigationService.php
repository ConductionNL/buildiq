<?php

/**
 * Buildiq App Navigation Service
 *
 * Registers per-app top-bar navigation entries for every published Application
 * in `Application::boot()` via INavigationManager::add().
 *
 * Per ADR-031 §Exceptions this is imperative because nav-entry registration
 * requires a closure factory evaluated per request, `IGroupManager` per-request
 * calls, and `INavigationManager::add()` — none of which are OR calculation
 * vocabulary.
 *
 * Permission check order per REQ-OBNAV-002:
 *   1. group:* sentinel in any role array → visible to all signed-in users.
 *   2. user:<uid> match in any role array.
 *   3. group:<gid> or bare group GID match against the user's group memberships.
 *   4. Nextcloud admin bypass.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-5
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-6
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Registers dynamic per-published-app top-bar navigation entries.
 *
 * Called once per request from Application::boot(); entries are closures
 * evaluated by INavigationManager on every request boot cycle so draft→published
 * transitions are picked up automatically without any writeback (REQ-OBNAV-004).
 */
class AppNavigationService {
	/**
	 * Prefix of every per-published-app nav entry id; the runtime host
	 * (DashboardController::builder) uses it to mark the entry active.
	 */
	public const ENTRY_ID_PREFIX = 'openbuild-app-';

	/**
	 * App-config key overriding the base nav order of virtual-app entries.
	 */
	private const ORDER_BASE_CONFIG_KEY = 'nav_order_base';

	/**
	 * Default base order for virtual-app entries. Nextcloud gives apps without
	 * an explicit order 100, so published virtual apps land right after the
	 * default-ordered apps instead of dead last in the menu.
	 */
	private const ORDER_BASE_DEFAULT = 100;

	/**
	 * The shared per-request published-application read.
	 *
	 * Injected in production so this service and DashboardWidgetRegistrar hold
	 * the SAME instance, and therefore the same cache — one query per request.
	 * It is optional and trailing so the constructor signature this service
	 * has always had still works; when it is absent one is built from the
	 * object service passed in. The query itself exists in exactly one place
	 * either way, which is what stops the two paths drifting.
	 *
	 * @var PublishedApplicationProvider|null
	 */
	private ?PublishedApplicationProvider $applicationProvider;

	/**
	 * The shared per-user visibility check order.
	 *
	 * @var AppVisibilityResolver
	 */
	private AppVisibilityResolver $visibilityResolver;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param IURLGenerator $urlGenerator URL generator
	 * @param IUserSession $userSession User session
	 * @param IGroupManager $groupManager Group manager
	 * @param IAppConfig $appConfig App config (nav order base override)
	 * @param LoggerInterface $logger PSR logger
	 * @param PublishedApplicationProvider|null $applicationProvider Shared published-app read
	 * @param AppVisibilityResolver|null $visibilityResolver Shared visibility check order
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		?PublishedApplicationProvider $applicationProvider = null,
		?AppVisibilityResolver $visibilityResolver = null,
	) {
		$this->applicationProvider = $applicationProvider;
		$this->visibilityResolver = ($visibilityResolver ?? new AppVisibilityResolver());
	}//end __construct()

	/**
	 * Register one INavigationManager entry per published Application.
	 *
	 * Each entry carries a gating closure evaluated per request.  Draft and
	 * archived Applications are excluded by the `status == published` filter
	 * applied here — no writeback needed when status changes (REQ-OBNAV-004).
	 *
	 * @param INavigationManager $nav The Nextcloud navigation manager.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-4
	 */
	public function registerNavEntries(INavigationManager $nav): void {
		try {
			$applications = $this->getPublishedApplications();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'AppNavigationService: failed to query published applications: ' . $e->getMessage()
			);
			return;
		}

		$orderBase = $this->appConfig->getValueInt(
			'buildiq',
			self::ORDER_BASE_CONFIG_KEY,
			self::ORDER_BASE_DEFAULT
		);

		foreach ($applications as $application) {
			$slug = ($application['slug'] ?? null);
			$name = ($application['name'] ?? null);

			if (is_string($slug) === false || $slug === '') {
				continue;
			}

			if (is_string($name) === false || $name === '') {
				$name = $slug;
			}

			$permissions = ($application['permissions'] ?? []);
			if (is_array($permissions) === false) {
				$permissions = [];
			}

			$iconUrl = $this->urlGenerator->linkToRouteAbsolute(
				'buildiq.icon.iconLight',
				['slug' => $slug]
			);

			// Point at the virtual-app runtime host (/builder/{slug}). Generate the
			// href via IURLGenerator (not a hand-built string) so linkToRoute adds
			// the `/index.php` front-controller segment exactly when the instance
			// requires it — the link then resolves on both instance kinds.
			$appUrl = $this->urlGenerator->linkToRoute('buildiq.dashboard.builder', ['slug' => $slug]);
			$entryId = self::ENTRY_ID_PREFIX . $slug;
			$order = $orderBase + (abs(crc32($slug)) % 100);

			// Capture variables for the closure — PHP closures close over
			// variables by reference unless 'use' explicitly binds them by value.
			$capturedPermissions = $permissions;
			$userSession = $this->userSession;
			$groupManager = $this->groupManager;

			$nav->add(
				function () use (
					$entryId,
					$name,
					$appUrl,
					$iconUrl,
					$order,
					$capturedPermissions,
					$userSession,
					$groupManager
				): array {
					$visible = $this->isVisibleForCurrentUser(
						permissions: $capturedPermissions,
						userSession: $userSession,
						groupManager: $groupManager
					);

					// NavigationManager has no 'enabled' concept — entries
					// are filtered by 'type' (getAll('link')). Returning a
					// non-'link' type is the only way a closure entry can
					// hide itself from the app menu per user.
					$entryType = 'openbuild-hidden';
					if ($visible === true) {
						$entryType = 'link';
					}

					return [
						'id' => $entryId,
						'name' => $name,
						'href' => $appUrl,
						'icon' => $iconUrl,
						'order' => $order,
						'type' => $entryType,
						'active' => false,
						'classes' => '',
						'enabled' => $visible,
					];
				}
			);
		}//end foreach
	}//end registerNavEntries()

	/**
	 * Determine whether the currently-signed-in user should see a nav entry.
	 *
	 * Evaluation order per REQ-OBNAV-002:
	 *   1. group:* wildcard in any role → always visible.
	 *   2. user:<uid> match in any role.
	 *   3. group:<gid> / bare GID match against user's group memberships.
	 *   4. Nextcloud admin bypass.
	 *
	 * @param array<string,mixed> $permissions The Application's permissions block.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @return bool True when the entry should be visible.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-5
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-6
	 */
	public function isVisibleForCurrentUser(
		array $permissions,
		IUserSession $userSession,
		IGroupManager $groupManager,
	): bool {
		// Delegated, not duplicated: VirtualAppWidget::isEnabled() calls the
		// same resolver with the widget placement's `roles` as extra
		// principals. Two copies of an authorization check drift, and the
		// drift is invisible until someone sees something they should not.
		return $this->visibilityResolver->isVisible(
			permissions: $permissions,
			extraPrincipals: [],
			userSession: $userSession,
			groupManager: $groupManager
		);
	}//end isVisibleForCurrentUser()

	/**
	 * Fetch all published Applications through the shared per-request provider.
	 *
	 * @return array<array<string,mixed>> List of normalised Application arrays.
	 *
	 * @throws \Throwable When the OR query fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-4
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-7
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
	 */
	private function getPublishedApplications(): array {
		if ($this->applicationProvider === null) {
			$this->applicationProvider = new PublishedApplicationProvider(
				objectService: $this->objectService
			);
		}

		return $this->applicationProvider->getPublishedApplications();
	}//end getPublishedApplications()
}//end class
