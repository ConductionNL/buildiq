<?php

/**
 * Buildiq Application
 *
 * Main application class for the Buildiq Nextcloud app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\Buildiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\AppInfo;

use OCA\Buildiq\Capabilities;
use OCA\Buildiq\Controller\ControllerRegistrar;
use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\BuildiqToolProviderInterface;
use OCA\Buildiq\Lifecycle\LifecycleRegistrar;
use OCA\Buildiq\Listener\ApprovalOutcomeListener;
use OCA\Buildiq\Listener\AutomationApprovalTriggerListener;
use OCA\Buildiq\Listener\AutomationCleanupListener;
use OCA\Buildiq\Listener\DocumentGenerationListener;
use OCA\Buildiq\Listener\FormLiveValuesListener;
use OCA\Buildiq\Listener\HybridMetadataLockListener;
use OCA\Buildiq\Listener\ProductionVersionGuardListener;
use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Middleware\MiddlewareRegistrar;
use OCA\Buildiq\Repair\RepairRegistrar;
use OCA\Buildiq\Service\ServiceRegistrar;
use OCA\Buildiq\Settings\SettingsRegistrar;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\AppHost\LeafAdoption;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Main application class for the Buildiq Nextcloud app.
 *
 * @spec openspec/specs/openbuild-rbac/spec.md
 */
class Application extends App implements IBootstrap {
	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/openbuild-rbac/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		(new MiddlewareRegistrar())->registerApiContractMiddleware(context: $context);
		(new ServiceRegistrar())->registerOpenRegisterContracts(context: $context);
		(new ServiceRegistrar())->registerContractBindings(context: $context);
		$this->registerAppHostPlumbing(context: $context);
		(new ServiceRegistrar())->registerDashboardServices(context: $context);
		(new ControllerRegistrar())->registerConcreteControllers(context: $context);
		(new ServiceRegistrar())->registerSettingsService(context: $context);
		(new RepairRegistrar())->registerSettingsRepairStep(context: $context);
		(new ControllerRegistrar())->registerSettingsController(context: $context);
		(new SettingsRegistrar())->registerAdminSettings(context: $context);
		(new ControllerRegistrar())->registerObservabilityControllers(context: $context);
		$this->registerObjectGuardListeners(context: $context);
		$this->registerApprovalListeners(context: $context);
		$this->registerDocumentListeners(context: $context);
		$this->registerToolsAndCapabilities(context: $context);
	}//end register()

	/**
	 * Wire the generic AppHost plumbing when OpenRegister's Bootstrap is loadable.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private function registerAppHostPlumbing(IRegistrationContext $context): void {
		// ADR-040 AppHost adoption: one call wires the standard plumbing —
		// the generic dashboard/settings/preferences controllers, the
		// observability (health + metrics) controllers, the install repair
		// steps, the admin settings panel + section, and the manifest-driven
		// deep-link listener (reads src/manifest.json `deepLinks`, replacing
		// the bespoke DeepLinkRegistrationListener that was deleted).
		//
		// Every Bootstrap registration is a lazy closure, so a disabled/absent
		// OpenRegister never fatals NC bootstrap (see Bootstrap docblock).
		//
		// `mcpProvider` is wired below explicitly (not via Bootstrap) because
		// the domain registrations that follow override the relevant aliases.
		//
		// GUARDED: `Bootstrap::register` is an eager static call that fatals if
		// OpenRegister's AppHost engine class is not autoloadable on this
		// instance (e.g. a stale optimized/authoritative composer classmap that
		// predates AppHost, or a stub OCA\OpenRegister PSR-4 prefix). That fatal
		// would abort the WHOLE register() method BEFORE the domain listeners
		// below — silently disabling the per-Application RBAC guards and the
		// hybrid metadata-lock (so a hybrid app's immutable identity fields would
		// become writable). Skip the generic AppHost plumbing when it is absent
		// and fall through to Buildiq's own concrete controllers + listeners,
		// which is what this app actually relies on (the comment that the lazy
		// closures "never fatal NC bootstrap" only held for the closures, not for
		// resolving the Bootstrap class itself).
		//
		// LOAD-ORDER HAZARD (measured, not theoretical). OC_App::getEnabledApps()
		// sort()s the app list, and Coordinator::registerApps() walks THAT sorted
		// list registering each app's autoloader and then calling $app->register()
		// for one app at a time. So every app registers before the PSR-4 prefix of
		// every alphabetically-LATER app exists: `buildiq` < `openregister`, so
		// OCA\OpenRegister\ is not autoloadable at this point on a perfectly
		// healthy instance with OpenRegister enabled.
		//
		// That is what actually happened here, under the CLI SAPI: `Buildiq:
		// OpenRegister AppHost\Bootstrap is not autoloadable` was logged on EVERY
		// occ call in CI (3 per E2E run, run 31081906401) while
		// lib/AppHost/Bootstrap.php existed on OpenRegister all along. The
		// class_exists() guard below turned a fatal into a SILENT degradation, so
		// for every occ command, background job and repair step the generic
		// dashboard/settings/preferences controllers, the observability
		// controllers, the install repair steps and the deep-link listener were
		// simply absent — with nothing anywhere to say so.
		//
		// Scope, honestly: in that SAME run /api/health and /api/metrics answered
		// 200, and those routes exist ONLY as Bootstrap::register() DI aliases, so
		// under the web SAPI the guard was answering true. The CLI/web divergence
		// is not explained — see REQ-OBS-006's Notes. The prelude removes the
		// dependence on whatever that difference is.
		//
		// The fix is to put OpenRegister's prefix on the autoloader ourselves,
		// which is exactly what Nextcloud will do a few iterations later. Two
		// properties make this the correct call. First, the prelude touches ONLY
		// the autoloader (a PSR-4 loader for OCA\OpenRegister\ over its lib/,
		// public IAppManager only — the private OC_App::registerAutoloading() it
		// used to call is gone in Nextcloud 35) and is idempotent. Second,
		// IAppManager::loadApp() would NOT be correct here: it marks OpenRegister
		// loaded and calls Coordinator::bootApp(), booting OpenRegister BEFORE
		// its own register() has run.
		//
		// The prelude lives in its own class so the "never throws" contract is
		// reachable from a unit test — Application cannot be constructed without
		// a Nextcloud DI container. It swallows every Throwable and reports
		// nothing: when OpenRegister really is absent, the class_exists() guard
		// below still skips the AppHost plumbing exactly as before.
		OpenRegisterAutoloader::register();

		if (class_exists(Bootstrap::class) === true) {
			Bootstrap::register(
				context: $context,
				adoption: new LeafAdoption(
					appId: self::APP_ID,
					options: [
						'namespace' => 'OCA\\Buildiq',
						'sectionName' => 'Buildiq',
						'observability' => true,
					]
				)
			);
		}

		// No else branch, deliberately. It used to write a line to the PHP error
		// log, and with the prelude above in place that line no longer means what
		// it said: before the prelude it fired on a HEALTHY instance, because the
		// probe was answering "not autoloadable YET" — measured 3x per E2E run,
		// once per occ call. It was the symptom, not a diagnostic: the very noise
		// that made the real defect read as ambient log spam.
		//
		// Now a false class_exists() means OpenRegister genuinely is absent or
		// disabled, which is a whole-instance condition an app cannot usefully
		// narrate from its composition root: no PSR logger is resolvable this
		// early, so the only channel left is the one hydra gate-2 forbids in
		// lib/, for good reason. The degraded state is reported where it can be
		// read instead — /api/health, wired by `observability => true` and bound
		// below regardless of load order. Same choice doriath made.
		//
	}//end registerAppHostPlumbing()

	/**
	 * Register the pre-save listeners that guard Application and form writes.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private function registerObjectGuardListeners(IRegistrationContext $context): void {
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
	 */
	private function registerApprovalListeners(IRegistrationContext $context): void {
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
				listener: \OCA\Buildiq\Listener\BuildiqLeafRegistrationListener::class
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
	 */
	private function registerDocumentListeners(IRegistrationContext $context): void {
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
	 * Register the MCP tool provider, the RBAC lifecycle guard and the capability.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 */
	private function registerToolsAndCapabilities(IRegistrationContext $context): void {
		// Register BuildiqToolProvider as the MCP tool provider for the AI Chat Companion.
		// The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::buildiq' is the format
		// that OR's McpToolsService enumerates to discover per-app providers (hydra ADR-035).
		// The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator);
		// until then Buildiq implements the test stub at tests/Stubs/Mcp/IMcpToolProvider.php.
		$context->registerServiceAlias(
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::buildiq',
			BuildiqToolProvider::class
		);
		$context->registerServiceAlias(
			BuildiqToolProviderInterface::class,
			BuildiqToolProvider::class
		);

		(new LifecycleRegistrar())->registerOwnerGuard(context: $context);

		// Edit-availability capability (buildiq-inline-edit-persistence, spec
		// buildiq-capability). Advertises `{ buildiq: { enabled, canEdit } }`
		// so a fleet app's in-place edit button has a robust per-user signal
		// (IAppManager::isEnabledForUser respects the NC app group-restriction)
		// instead of inferring availability from OC.appswebroots. `canEdit` is a
		// UI hint only — the write/delete endpoints re-check access server-side.
		$context->registerCapability(Capabilities::class);

		// Repair steps (InitializeSettings + MigrateToVersionedModel + …) are declared in info.xml.
	}//end registerToolsAndCapabilities()

	/**
	 * Register an object-lifecycle listener that declares its interest up front.
	 *
	 * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
	 * a listener reacts to and routes dispatches through a single shared proxy,
	 * so an uninterested listener is neither constructed nor invoked. When
	 * OpenRegister is absent — Buildiq carries no hard dependency on it — this
	 * degrades to the plain global registration it replaced, which is exactly
	 * the behaviour every listener had before.
	 *
	 * MUST be called from boot(), never from register(). Nextcloud enables each
	 * app's own autoloader immediately before calling that app's register(), so
	 * during register() OpenRegister's classes are only autoloadable to apps
	 * that happen to be registered after it — the class_exists() guard below
	 * would then resolve differently purely by app load order and silently fall
	 * back to an unfiltered registration. boot() runs only after every app's
	 * register() has completed, so the guard is order-independent there.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $event OpenRegister event class name.
	 * @param string $listener Listener class name.
	 * @param array<int,string>|null $registers Register slugs the listener reacts to, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs the listener reacts to, or null for all.
	 *
	 * @return void
	 */
	private function registerFilteredObjectListener(
		IEventDispatcher $dispatcher,
		string $event,
		string $listener,
		?array $registers,
		?array $schemas,
	): void {
		$subscription = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';
		if (class_exists($subscription) === true) {
			$subscription::subscribe(
				dispatcher: $dispatcher,
				event: $event,
				listener: $listener,
				registers: $registers,
				schemas: $schemas
			);
			return;
		}

		// Loud on purpose. This fallback is correct but UNFILTERED, and while it
		// was silent it was indistinguishable from a working narrowing.
		\OCP\Server::get(LoggerInterface::class)->warning(
			'OpenRegister ObjectEventSubscription unavailable: ' . $listener
			. ' fell back to an UNFILTERED registration for ' . $event
			. ' and will be invoked on every object write instance-wide.',
			['app' => self::APP_ID]
		);

		$dispatcher->addServiceListener($event, $listener);
	}//end registerFilteredObjectListener()

	/**
	 * Boot the application.
	 *
	 * Registers per-published-app top-bar navigation entries via
	 * AppNavigationService (REQ-OBNAV-001 / buildiq-nextcloud-nav).
	 * Lazily resolved from the DI container to avoid instantiating the
	 * service tree when OR is not installed.
	 *
	 * Also subscribes the narrowed object-lifecycle listeners — see
	 * registerFilteredObjectListener() for why that cannot happen in register().
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-17-openbuild-nextcloud-nav/tasks.md
	 */
	public function boot(IBootContext $context): void {
		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

		// Cross-row integrity guard, CREATE half (see register() for the full
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
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatingEvent::class,
			listener: ProductionVersionGuardListener::class,
			registers: null,
			schemas: ['built-app']
		);

		// Automation-designer artifact cleanup (spec REQ-AUTD-005). Automation
		// CRUD (including delete) stays on OR REST per ADR-022 — no delete
		// route on AutomationsController — so removing exactly the
		// provenance-listed compiled artifacts on delete is realized here as
		// the imperative companion to that declarative delete (ADR-031
		// §Exceptions(1)), mirroring the guard listeners in register().
		//
		// Declares its schema interest up front: the handler's first guard is
		// `$schema !== AutomationCompilerService::AUTOMATION_SCHEMA` (= the
		// `automation` schema slug), read off the entity's own
		// `getSchemaSlug()`. Schema-only, no register — see the
		// ProductionVersionGuardListener note above on Buildiq's dynamically
		// created per-app registers.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectDeletedEvent::class,
			listener: AutomationCleanupListener::class,
			registers: null,
			schemas: ['automation']
		);

		$this->registerNavEntries(context: $context);

		// Promoted dashboard widgets, beside the nav entries and reading the
		// SAME published-Application query (PublishedApplicationProvider is a
		// shared service, so this adds no second read).
		//
		// Its own guard, not the one above. Sharing a try block would mean a
		// nav-entry failure silently skipped the widgets and a widget failure
		// silently skipped nothing, and neither would be visible in the log as
		// what it was.
		$this->registerDashboardWidgets(context: $context);
	}//end boot()

	/**
	 * Register the per-published-app top-bar navigation entries.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 */
	private function registerNavEntries(IBootContext $context): void {
		try {
			(new ServiceRegistrar())->registerNavEntries(container: $context->getAppContainer());
		} catch (\Throwable $e) {
			// Boot must never throw — log and continue.
			// OpenRegister may not be installed on this instance.
			\OCP\Server::get(LoggerInterface::class)->warning(
				'Buildiq: nav-entry registration failed during boot: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end registerNavEntries()

	/**
	 * Register the promoted virtual-app dashboard widgets.
	 *
	 * Never throws. An instance without OpenRegister, a failing object
	 * service, or a Nextcloud without the Dashboard app all land here as a
	 * warning and zero registered widgets, and the dashboard renders its other
	 * widgets normally.
	 *
	 * @param IBootContext $context The boot context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
	 */
	private function registerDashboardWidgets(IBootContext $context): void {
		try {
			(new ServiceRegistrar())->registerDashboardWidgets(container: $context->getAppContainer());
		} catch (\Throwable $e) {
			\OCP\Server::get(LoggerInterface::class)->warning(
				'Buildiq: dashboard-widget registration failed during boot: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end registerDashboardWidgets()
}//end class
