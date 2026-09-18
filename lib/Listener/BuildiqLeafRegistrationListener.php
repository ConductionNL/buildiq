<?php

/**
 * Buildiq Leaf Registration Listener
 *
 * Contributes buildiq's leaves to OpenRegister's catalogue when OpenRegister
 * dispatches `RegisterLeafProvidersEvent`. ADR-066: the consuming app places
 * the leaf on its own object, so the id and the descriptor are declared here
 * once and both halves carry the same id, which is what gate-24 pairs.
 *
 * Guarded on the event class, because a buildiq running without OpenRegister
 * must boot rather than fatal on a missing class.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Integration\PageLayoutLeafProvider;
use OCA\Buildiq\Integration\RegistrationFormLeafProvider;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\OpenRegister\Service\Integration\LeafDescriptor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers buildiq's leaves on OpenRegister's catalogue.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-006)
 */
final class BuildiqLeafRegistrationListener implements IEventListener {
	/**
	 * The render-surface half of the registration-form leaf.
	 *
	 * @var string
	 */
	public const FORM_PANEL_ID = 'buildiq-registration-form-panel';

	/**
	 * Constructor.
	 *
	 * @param RegistrationFormLeafProvider $forms The registration-form data provider.
	 * @param PageLayoutLeafProvider $layouts The page-layout data provider.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegistrationFormLeafProvider $forms,
		private readonly PageLayoutLeafProvider $layouts,
	) {
	}//end __construct()

	/**
	 * Contribute both halves of the registration-form leaf.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-006)
	 */
	public function handle(Event $event): void {
		if ($event instanceof RegisterLeafProvidersEvent === false) {
			return;
		}

		$event->registerLeaf(
			new LeafDescriptor(
				id: RegistrationFormLeafProvider::LEAF_ID,
				label: 'Registration forms',
				icon: 'FormSelect',
				kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
				requiredApp: 'buildiq',
				group: 'Forms',
			),
			$this->forms,
		);

		$event->registerLeaf(
			new LeafDescriptor(
				id: self::FORM_PANEL_ID,
				label: 'Registration forms',
				icon: 'FormSelect',
				kinds: [LeafDescriptor::KIND_RENDER_SURFACE],
				requiredApp: 'buildiq',
				group: 'Forms',
				surfaces: ['widget', 'tab'],
			),
			null,
		);

		// case-page-layout-per-case-type REQ-OBPL-003 — the owning app asks what
		// its detail page should show for this object, and renders its own
		// manifest unchanged when nothing answers. No render surface: buildiq
		// serves the layout, the owning app draws it.
		$event->registerLeaf(
			new LeafDescriptor(
				id: PageLayoutLeafProvider::LEAF_ID,
				label: 'Page layout',
				icon: 'ViewDashboardOutline',
				kinds: [LeafDescriptor::KIND_DATA_PROVIDER],
				requiredApp: 'buildiq',
				group: 'Design',
			),
			$this->layouts,
		);
	}//end handle()
}//end class
