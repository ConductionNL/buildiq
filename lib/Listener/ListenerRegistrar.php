<?php

/**
 * Buildiq listener registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the event listeners this component owns.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class ListenerRegistrar {

	/**
	 * Register the pre-save listeners that guard Application and form writes.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerObjectGuardListeners(IRegistrationContext $context): void {
		// Per ADR-002 the snapshot-on-publish writeback listener has been
		// retired. ApplicationVersion is now a first-class long-lived row,
		// not an append-only snapshot, and `Application.currentVersion` has
		// been removed in favour of an explicit `productionVersion` relation
		// set by the admin. Object time-travel on the ApplicationVersion row
		// captures audit history. The corresponding spec retirement lives
		// in buildiq-versioning-model/specs/buildiq-version-snapshots.
		// Cross-row integrity guard: on every Application save (create or
		// update), verify that `productionVersion` (when set) points at an
		// ApplicationVersion whose `application` relation refers back to
		// this Application (ADR-031 §Exceptions(1) — cross-row validation
		// that OR's per-row x-openregister-validations cannot perform).
		//
		// The CREATE half declares its schema interest up front and is
		// therefore subscribed from boot() — see boot().
		//
		// The UPDATE half stays a plain registration: `ObjectUpdatingEvent`
		// exposes `getNewObject()`/`getOldObject()` but no `getObject()`, which
		// is the accessor OpenRegister's subscription proxy identifies the
		// written row with. It would fail open on every dispatch, so declaring
		// an interest there buys nothing and only adds indirection.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: ProductionVersionGuardListener::class
		);

		// Metadata-lock for hybrid apps (unify-apps-with-app-type). On every
		// Application UPDATE, reject a change to a hybrid app's identity
		// metadata (slug/name) — it mirrors the installed Nextcloud app it
		// customizes and renaming it would desync the baseRef.id link and the
		// /api/app-overrides/{appId} shim key. Cross-row check (compares the
		// proposed payload against the stored row) realized as a pre-save
		// listener, the imperative companion to the same-row
		// `hybrid-requires-baseRef` x-openregister-validations rule (ADR-031
		// §Exceptions(1)). Virtual apps keep full slug/name edit.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: HybridMetadataLockListener::class
		);

		// Live form values (forms-live-values-and-checks, REQ-BQLV-005).
		// Before any object is stored, recompute the fields a built app's form
		// calculates and enforce its blocking eligibility check, so a value
		// changed in the browser never lands. Unfiltered on purpose: the
		// registers a built app's form writes to are created per app and
		// version, so no static register or schema list can name them. Every
		// write that no live form targets returns after one app config read.
		// The same listener keeps that index current from ApplicationVersion
		// saves.
		$context->registerEventListener(
			event: ObjectCreatingEvent::class,
			listener: FormLiveValuesListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: FormLiveValuesListener::class
		);
	}//end registerObjectGuardListeners()

	/**
	 * Register the approval automation listeners and the form catalogue listeners.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerApprovalListeners(IRegistrationContext $context): void {
		// Automation-approval-steps: trigger-fire half of the `approval`
		// action kind (spec REQ-AUTD-004 approval scenarios). No declarative
		// primitive already dispatches "start a compiled ApprovalChain
		// instance when this object-event/lifecycle-transition fires" — OR's
		// own AnnotationNotificationListener plays that role for the
		// notifications dialect, but nothing analogous exists for approval
		// chains started from a plain object event or a non-blocking
		// transition side effect (ApprovalChainGateListener is a BLOCKING
		// gate, a different use case). Registered for all four trigger-shape
		// events the v1 matrix supports (design.md Decision 1 of
		// automation-approval-steps).
		//
		// Deliberately NOT narrowed with ObjectEventSubscription: this
		// listener has no static schema interest at all. It reacts to whatever
		// schema an author names in an `automation` row's trigger config, in
		// whichever per-app register that automation targets — both created at
		// runtime. Declaring anything here would silently stop the listener
		// firing for every automation authored after this line was written.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);

		// Contribute buildiq's leaves to OpenRegister's catalogue
		// (forms-per-case-type REQ-OBRF-006), so a consuming app can ask which
		// form to show for a type without buildiq knowing the app. Guarded on
		// the event class: buildiq boots without OpenRegister.
		if (class_exists('OCA\\OpenRegister\\Event\\RegisterLeafProvidersEvent') === true) {
			$context->registerEventListener(
				event: \OCA\OpenRegister\Event\RegisterLeafProvidersEvent::class,
				listener: \OCA\Buildiq\Integration\BuildiqLeafRegistrationListener::class
			);
		}

		// Announce buildiq's published forms when a schema they submit into is
		// about to change, so a breaking change unpublishes them (decision 179
		// Q7). Guarded on the event class: an OpenRegister without the form
		// destination validator does not dispatch it.
		if (class_exists('OCA\\OpenRegister\\Event\\FormDestinationDependentsEvent') === true) {
			$context->registerEventListener(
				event: \OCA\OpenRegister\Event\FormDestinationDependentsEvent::class,
				listener: \OCA\Buildiq\Listener\FormDestinationDependentsListener::class
			);
		}
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);

		// Automation-approval-steps: on-approve/on-reject follow-up dispatch
		// (design.md Decision 3). OpenRegister dispatches these typed events for
		// every decision regardless of who started the sequence; this listener
		// resolves the originating automation by its `aut-<slug>` chain key and
		// fires the configured follow-up actions through the shared
		// RuleActionDispatcher.
		//
		// ⚠️ The two halves subscribe to events at DIFFERENT levels, and that is
		// the published mapping rather than an oversight (openregister #3302):
		// approval concludes when the SEQUENCE completes, while a rejection is a
		// TASK completing with a rejecting outcome — a rejecting task closes its
		// whole sequence, so the reject half still fires exactly once.
		$context->registerEventListener(
			event: TaskSequenceCompletedEvent::class,
			listener: ApprovalOutcomeListener::class
		);
		$context->registerEventListener(
			event: TaskTerminalEvent::class,
			listener: ApprovalOutcomeListener::class
		);
	}//end registerApprovalListeners()

	/**
	 * Register the document generation automation listeners.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerDocumentListeners(IRegistrationContext $context): void {
		// Automation-document-action: trigger-fire half of the
		// `generateDocument` action kind (spec REQ-AUTD-004 generateDocument
		// scenarios). Mirrors AutomationApprovalTriggerListener exactly —
		// AutomationCompilerService compiles no artifact for this action
		// kind (Docudesk's generate route is stateless), so the actual
		// owner-impersonated call happens here, at trigger-fire time.
		// Registered for the same four trigger-shape events the v1 matrix
		// supports (design.md Decision 2 of automation-document-action).
		//
		// Deliberately NOT narrowed with ObjectEventSubscription, for exactly
		// the reason given on AutomationApprovalTriggerListener above: the
		// schema it reacts to comes from runtime automation config, not from
		// anything statically knowable here.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: DocumentGenerationListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: DocumentGenerationListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: DocumentGenerationListener::class
		);
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: DocumentGenerationListener::class
		);
	}//end registerDocumentListeners()

	/**
	 * Register the deep-link listener for buildiq's own schemas and the records of built apps.
	 *
	 * Called only inside the OpenRegister guard. The event and the listener's
	 * parent class are named as strings here and resolved lazily, so an absent
	 * OpenRegister never fatals: the event then simply never fires.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apps-unified-search-deep-links/tasks.md#task-2.2
	 */
	public function registerDeepLinkListener(IRegistrationContext $context): void {
		$context->registerService(
			BuiltAppDeepLinkListener::class,
			static fn ($c): BuiltAppDeepLinkListener => new BuiltAppDeepLinkListener(
				appId: AppIdentity::APP_ID,
				appManager: $c->get('OCP\\App\\IAppManager'),
				logger: $c->get(LoggerInterface::class),
				applications: $c->get(PublishedApplicationProvider::class),
				manifestResolver: $c->get(ManifestResolverServiceInterface::class)
			)
		);
		$context->registerEventListener(
			'OCA\\OpenRegister\\Event\\DeepLinkRegistrationEvent',
			BuiltAppDeepLinkListener::class
		);
	}//end registerDeepLinkListener()

	/**
	 * The object-lifecycle listeners that declare their register and schema
	 * interest up front, subscribed from Application::boot().
	 *
	 * @return array<int,array{event:string,listener:string,registers:array<int,string>|null,schemas:array<int,string>|null}>
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function filteredObjectListeners(): array {
		return [
			// Cross-row integrity guard, CREATE half (see registerObjectGuardListeners() for the full
			// rationale and the UPDATE half). Declares its schema interest up front:
			// the handler's very first guard is
			// `$schema !== ApplicationVersionService::APPLICATION_SCHEMA` (= the
			// `application` schema slug), read off the entity's own
			// `getSchemaSlug()`. Registered globally it woke on every object write
			// on the instance. No register is declared on purpose — Buildiq
			// creates registers dynamically, one per built app and environment
			// (`buildiq-pet-store-production`, `buildiq-library-desk-development`,
			// …), so a static register list could never cover a register that does
			// not exist yet. Schema-only is exactly the handler's own guard.
			[
				'event' => ObjectCreatingEvent::class,
				'listener' => ProductionVersionGuardListener::class,
				'registers' => null,
				'schemas' => ['built-app'],
			],
			// Automation-designer artifact cleanup (spec REQ-AUTD-005). Automation
			// CRUD (including delete) stays on OR REST per ADR-022 — no delete
			// route on AutomationsController — so removing exactly the
			// provenance-listed compiled artifacts on delete is realized here as
			// the imperative companion to that declarative delete (ADR-031
			// §Exceptions(1)), mirroring the guard listeners in registerObjectGuardListeners().
			//
			// Declares its schema interest up front: the handler's first guard is
			// `$schema !== AutomationCompilerService::AUTOMATION_SCHEMA` (= the
			// `automation` schema slug), read off the entity's own
			// `getSchemaSlug()`. Schema-only, no register — see the
			// ProductionVersionGuardListener note above on Buildiq's dynamically
			// created per-app registers.
			[
				'event' => ObjectDeletedEvent::class,
				'listener' => AutomationCleanupListener::class,
				'registers' => null,
				'schemas' => ['automation'],
			],
		];
	}//end filteredObjectListeners()
}//end class
