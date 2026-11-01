<?php

/**
 * Buildiq Virtual App Widget Context
 *
 * The collaborators every VirtualAppWidget instance shares, plus the full set
 * of promoted descriptors registered this request. It is a separate object so
 * a widget takes two constructor arguments instead of eight, and so the whole
 * shared half can be built once and handed to N widgets.
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
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-the-widget-renders-in-the-nextcloud-panel-chrome
 */

declare(strict_types=1);

namespace OCA\Buildiq\Dashboard;

use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Shared collaborators and descriptor set for the promoted widgets.
 */
final class VirtualAppWidgetContext {
	/**
	 * Every promoted descriptor registered this request.
	 *
	 * @var array<int,WidgetDescriptor>
	 */
	private array $descriptors = [];

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator URL generator.
	 * @param IUserSession $userSession User session.
	 * @param IGroupManager $groupManager Group manager.
	 * @param AppVisibilityResolver $visibility Shared per-user visibility check order.
	 * @param WidgetItemProjector $projector Server-side item projection.
	 * @param IInitialState $initialState Initial state for the browser entry.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IURLGenerator $urlGenerator,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly AppVisibilityResolver $visibility,
		public readonly WidgetItemProjector $projector,
		public readonly IInitialState $initialState,
	) {
	}//end __construct()

	/**
	 * Record a descriptor registered this request.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
	 */
	public function addDescriptor(WidgetDescriptor $descriptor): void {
		$this->descriptors[] = $descriptor;
	}//end addDescriptor()

	/**
	 * Every promoted descriptor registered this request.
	 *
	 * @return array<int,WidgetDescriptor>
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
	 */
	public function descriptors(): array {
		return $this->descriptors;
	}//end descriptors()

	/**
	 * Whether the signed-in user may see this widget.
	 *
	 * The placement's `visibleWhen` expression is deliberately NOT evaluated
	 * here. It reads page context (the loaded object, route params, the page's
	 * own filters) and none of that exists on the Nextcloud dashboard;
	 * evaluating it would mean inventing that context and every answer would
	 * reflect the invented values rather than the author's intent.
	 *
	 * @param WidgetDescriptor $descriptor The promoted widget.
	 *
	 * @return bool True when the widget should be offered and rendered.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-a-user-only-sees-widgets-they-are-allowed-to-see
	 */
	public function isVisible(WidgetDescriptor $descriptor): bool {
		return $this->visibility->isVisible(
			permissions: [],
			extraPrincipals: $descriptor->principals,
			userSession: $this->userSession,
			groupManager: $this->groupManager
		);
	}//end isVisible()

	/**
	 * The descriptors the signed-in user may see.
	 *
	 * @return array<int,array<string,mixed>> Serialised descriptors.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-the-widget-renders-in-the-nextcloud-panel-chrome
	 */
	public function visibleDescriptors(): array {
		$visible = [];
		foreach ($this->descriptors as $descriptor) {
			if ($this->isVisible(descriptor: $descriptor) === true) {
				$visible[] = $descriptor->jsonSerialize();
			}
		}

		return $visible;
	}//end visibleDescriptors()
}//end class
