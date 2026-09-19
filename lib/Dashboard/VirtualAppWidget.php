<?php

/**
 * Buildiq Virtual App Dashboard Widget
 *
 * One promoted widget placement from a published virtual app, rendered as a
 * native Nextcloud Dashboard panel. There is ONE of this class and N named
 * instances of it: DashboardWidgetRegistrar registers one container service
 * per promoted descriptor under a synthetic service name and hands that name
 * to `IManager::lazyRegisterWidget()`. Nextcloud keys its widget map by
 * `getId()`, so N instances of one class become N distinct widgets.
 *
 * Per ADR-031 §Exceptions this is imperative because Nextcloud dashboard
 * widget registration requires a closure factory evaluated per request,
 * container service registration under a name computed at runtime,
 * `IManager::lazyRegisterWidget()`, and per-request `IGroupManager` calls
 * inside `isEnabled()`. None of those are OpenRegister calculation
 * vocabulary, and none of which OpenRegister has an extension to declare
 * against. It is the same carve-out `AppNavigationService` documents one level
 * up for `INavigationManager::add()`. WHICH widgets are promoted, what they
 * are called, where they link and who may see them stays declarative: it is
 * stated in the app manifest and nothing here decides it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Dashboard
 * @package  OCA\Buildiq\Dashboard
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

namespace OCA\Buildiq\Dashboard;

use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IWidget;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Util;

/**
 * A promoted virtual-app widget on the Nextcloud dashboard.
 */
class VirtualAppWidget implements IWidget, IIconWidget, IConditionalWidget, IAPIWidgetV2 {
	/**
	 * The app id this widget's scripts and icons are served from.
	 */
	public const APP_ID = 'buildiq';

	/**
	 * The webpack entry that registers the browser-side renderer.
	 */
	public const SCRIPT = 'buildiq-ncDashboard';

	/**
	 * Constructor.
	 *
	 * @param WidgetDescriptor $descriptor This widget's promoted placement.
	 * @param VirtualAppWidgetContext $context Shared collaborators and the full descriptor set.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WidgetDescriptor $descriptor,
		private readonly VirtualAppWidgetContext $context,
	) {
	}//end __construct()

	/**
	 * The frozen widget id.
	 *
	 * 🔴 Derived from the Application UUID, never the slug. Changing it drops
	 * the panel from every dashboard that had it, with no error anywhere.
	 * See WidgetDescriptor::deriveId().
	 *
	 * @return string The widget identifier.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-widget-identity-is-frozen-to-the-application-uuid
	 */
	public function getId(): string {
		return $this->descriptor->id;
	}//end getId()

	/**
	 * The panel title, as the author wrote it in the manifest.
	 *
	 * @return string The widget title.
	 */
	public function getTitle(): string {
		return $this->descriptor->title;
	}//end getTitle()

	/**
	 * The declared initial order.
	 *
	 * A hint only. Once a user rearranges their dashboard their arrangement
	 * wins, exactly as it does for every other app's widget.
	 *
	 * @return int The widget order.
	 */
	public function getOrder(): int {
		return $this->descriptor->order;
	}//end getOrder()

	/**
	 * The CSS icon class.
	 *
	 * @return string The icon CSS class.
	 */
	public function getIconClass(): string {
		return 'icon-buildiq-widget';
	}//end getIconClass()

	/**
	 * The panel icon, served from the virtual app's own light icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIconUrl(): string {
		return $this->context->urlGenerator->linkToRouteAbsolute(
			'buildiq.icon.iconLight',
			['slug' => $this->descriptor->applicationSlug]
		);
	}//end getIconUrl()

	/**
	 * Where the panel title points.
	 *
	 * @return string|null The widget URL.
	 */
	public function getUrl(): ?string {
		if ($this->descriptor->link !== '') {
			return $this->descriptor->link;
		}

		return $this->context->urlGenerator->linkToRouteAbsolute(
			'buildiq.dashboard.builder',
			['slug' => $this->descriptor->applicationSlug]
		);
	}//end getUrl()

	/**
	 * Whether the signed-in user may see this widget.
	 *
	 * `Manager::loadLazyPanels()` calls this before registering, so a widget a
	 * user may not see never enters the widget map at all. That covers both
	 * the picker and an id already stored on that user's dashboard from an
	 * earlier grant.
	 *
	 * @return bool True when the widget should be offered and rendered.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-a-user-only-sees-widgets-they-are-allowed-to-see
	 */
	public function isEnabled(): bool {
		return $this->context->isVisible(descriptor: $this->descriptor);
	}//end isEnabled()

	/**
	 * Provide this user's visible descriptors and load the browser entry.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Nextcloud's Util API is static by design.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-the-widget-renders-in-the-nextcloud-panel-chrome
	 */
	public function load(): void {
		$this->context->initialState->provideInitialState(
			'ncDashboardWidgets',
			$this->context->visibleDescriptors()
		);

		Util::addScript(self::APP_ID, self::SCRIPT);
	}//end load()

	/**
	 * Items for clients that cannot run the browser widget.
	 *
	 * @param string $userId The user the items are for.
	 * @param string|null $since Only items newer than this marker.
	 * @param int $limit Maximum number of items.
	 *
	 * @return WidgetItems The projected items, or an honest empty state.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) IAPIWidgetV2 mandates the signature.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-clients-that-cannot-run-the-widget-receive-projected-items
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		return $this->context->projector->project(
			descriptor: $this->descriptor,
			limit: $limit
		);
	}//end getItemsV2()
}//end class
