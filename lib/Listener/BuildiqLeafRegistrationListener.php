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
	 * The id the unbuilt render surface used to announce.
	 *
	 * Kept as a named constant, not deleted, because it is the only record that
	 * the id was ever published. A consumer that stored it, or a manifest that
	 * still names it, should find the answer here rather than an absence.
	 * Nothing registers it: see the note in `handle()`.
	 *
	 * @var string
	 */
	public const RETIRED_FORM_PANEL_ID = 'buildiq-registration-form-panel';

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

		// 🔴 THE RENDER SURFACE THAT WAS DECLARED AND NEVER BUILT IS GONE.
		//
		// `buildiq-registration-form-panel` announced a widget and a tab, and
		// buildiq has no client half for either: no `registerIntegration` call
		// anywhere in `src/`, no component, and the id appeared nowhere outside
		// its own constant and its own registration. It also ships no leaf
		// bundle and loads no integration script, so there was nothing on any
		// page that could have rendered it.
		//
		// So every consumer was told a widget and a tab existed, `getLeaves()`
		// returned them, and a host page that made room for them showed an empty
		// space. openregister now logs that as an error naming the missing file,
		// which is how this was found.
		//
		// Removed rather than given a bundle: shipping a `leaves` entry here
		// would mean inventing a widget and a tab nobody designed, to satisfy a
		// declaration nobody implemented. The two DATA-PROVIDER leaves below and
		// above are untouched; they work, they have providers, and a data leaf
		// needs no bundle at all.

		// The owning app asks what its detail page should show for this object,
		// and renders its own manifest unchanged when nothing answers
		// (case-page-layout-per-case-type REQ-OBPL-003). No render surface:
		// buildiq serves the layout, the owning app draws it.
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
