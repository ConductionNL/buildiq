<?php

/**
 * Buildiq AutomationApprovalTriggerListener
 *
 * Trigger-fire half of the `approval` automation action (design.md Decision 1
 * / Decision 2 of automation-approval-steps). `AutomationCompilerService`
 * only writes the DEFINITION; opening an approval against a concretely fired
 * object is an imperative, per-event side effect that has no declarative
 * primitive of its own to piggy-back on — OR's own
 * `AnnotationNotificationListener` plays the equivalent role for the
 * notifications dialect, and the approval GATE listener exists only for the
 * (semantically different) BLOCKING gate-a-transition use case, not "start an
 * approval after an object-created/updated/deleted event or a non-blocking
 * lifecycle-transition side effect". This listener is the ADR-031 §Exceptions
 * imperative companion the same way `AutomationCleanupListener` is for
 * provenance-listed artifact removal.
 *
 * Subscribes to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`
 * and `ObjectTransitionedEvent`, scans the shared `buildiq` register's
 * `automation` objects for one whose `trigger` matches the fired event
 * (schema + trigger type, and — for a lifecycle transition — the transition
 * action name too), compiles the automation's `provenance.approvalChainName`
 * declaration into a task template, and opens a task SEQUENCE for the fired
 * object's uuid. Idempotency guard: a sequence already exists for
 * (templateId, objectUuid) is left alone — otherwise an
 * `object-updated`-triggered automation would open a fresh sequence on every
 * subsequent save of the same object.
 *
 * ⚠️ MIGRATED off the retired approval surface (openregister #3302,
 * flow-approval-consolidation). `ApprovalChain`, `ApprovalChainMapper`,
 * `ApprovalStepMapper` and `ApprovalService` no longer exist; an approval is an
 * ordered task sequence opened from a template compiled out of the schema's
 * `x-openregister-approval-chains` annotation. See `retired-approval-surface.json`
 * in OpenRegister for the full retired list.
 *
 * Never implements approval logic itself (ADR-022 consume-not-rebuild) — every
 * approval-domain decision (role/group check, separation of duties, sequence
 * advancement) stays inside OpenRegister's task engine.
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
 * @spec openspec/changes/automation-approval-steps/tasks.md#1.3
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Initialises a compiled approval chain when its automation's trigger fires.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/automation-approval-steps/tasks.md#1.3
 */
class AutomationApprovalTriggerListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service lazily — see objectService(),
	 *                                      which scans the `automation` register for matching triggers. The
	 *                                      interface cannot be constructor-injected here: a listener is built
	 *                                      during event dispatch, before OpenRegister's DI registrations are
	 *                                      guaranteed to be available.
	 * @param SchemaMapper $schemaMapper Resolves a schema slug to its numeric id.
	 * @param IUserSession $userSession Current user session (requester identity).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve OpenRegister's object service, lazily.
	 *
	 * ⚠️ AN EVENT LISTENER CANNOT CONSTRUCTOR-INJECT A PUBLISHED INTERFACE.
	 *
	 * ADR-084 has consumers type-hint `ObjectServiceInterface` and bind it with
	 * `registerServiceAlias()` in their own composition root, which this app does
	 * (Application::register()). That works for controllers and services, because
	 * they are built from the APP container where the alias lives.
	 *
	 * Listeners are not. Nextcloud's `OC\EventDispatcher\ServiceEventListener`
	 * resolves the listener class from the SERVER container and says so in its own
	 * source: "TODO: fetch from the app containers, otherwise any custom services".
	 * The server container has never seen this app's alias, so the constructor
	 * parameter could not be built and the listener threw
	 * `Could not resolve OCA\OpenRegister\Contract\ObjectServiceInterface!`.
	 *
	 * The failure is worse than a dead listener: the exception propagates out of
	 * `EventDispatcher::dispatch()` into whoever emitted the event. Measured
	 * 2026-08-16, an `ObjectCreatedEvent` raised while Hermiq was persisting a chat
	 * conversation aborted the whole chat turn — a Hermiq request killed by an
	 * Buildiq listener neither app's author would think to look at.
	 *
	 * The fix asks the container for the CONCRETE class, not the interface. That
	 * distinction is the whole repair and it is easy to get wrong: the container
	 * injected into a listener is the same server container, so resolving
	 * `ObjectServiceInterface::class` here would fail in exactly the same way and
	 * merely move the error later. Nextcloud autowires concrete classes across
	 * apps — this app's own composition root says so — so
	 * `Service\ObjectService::class` resolves where the alias cannot.
	 *
	 * The DECLARED TYPE stays the published contract: `ObjectService` implements
	 * it, so every call site and test still sees only ADR-084's interface. The
	 * concrete name appears once, here, at the container boundary — which is the
	 * same shape DocuDesk uses in `DocumentObjectServiceResolver`.
	 *
	 * @return ObjectServiceInterface OpenRegister's published object contract.
	 *
	 * @throws ContainerExceptionInterface When OpenRegister is absent or disabled.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `::class` on a sibling app's concrete
	 *   service is a string, not a call — it triggers no autoload, so an instance
	 *   without OpenRegister still boots (ADR-083 rule 3).
	 */
	private function objectService(): ObjectServiceInterface {
		// ADR-083: establish availability before reaching. class_exists()
		// rather than SettingsService, because this listener injects no
		// settings service and adding a constructor dependency purely to ask a
		// yes/no question is the wrong trade — it answers the same question the
		// container would otherwise have answered fatally.
		//
		// Behaviour is unchanged: every caller already wraps this in a catch
		// that logs and returns null, so an instance without OpenRegister
		// degrades exactly as it does today. What changes is that the
		// dependency is now declared where a reader — and the gate — can see
		// it, instead of being implied by a catch several methods away.
		//
		// Untestable in a unit test: the stub OpenRegister classes the test
		// bootstrap declares (tests/stubs/openregister-stubs.php) make
		// class_exists() true unconditionally in this suite, so this branch
		// only ever fires on a real instance genuinely missing OpenRegister.
		// @codeCoverageIgnoreStart
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'buildiq requires the OpenRegister app, which is not installed on this instance.'
			);
		}
		// @codeCoverageIgnoreEnd

		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		// An assert(), not a `/** @var */` docblock: phpcs forbids an inline doc
		// block inside a method body, while psalm needs the narrowing or the
		// declared return type is a MixedReturnStatement. assert() satisfies
		// both, and costs nothing in production where zend.assertions is off.
		assert($service instanceof ObjectServiceInterface);

		return $service;
	}//end objectService()

	/**
	 * Resolve OpenRegister's approval-chain compiler, lazily.
	 *
	 * Same reasoning as {@see objectService()}: a listener is built from the
	 * SERVER container during event dispatch, so an OpenRegister collaborator
	 * must be reached for at call time, never constructor-injected.
	 *
	 * That is not a style preference here — it is the fix for a real outage.
	 * This listener used to constructor-inject `ApprovalChainMapper`,
	 * `ApprovalStepMapper` and `ApprovalService`. OpenRegister retired all three
	 * in #3302, and because the listener is registered on ObjectCreatedEvent with
	 * NO register or schema filter, Nextcloud then failed to construct it on
	 * every object write in every app on the instance — a 403 with
	 * "Could not resolve ... ApprovalChainMapper", not merely a dead automation.
	 * Reaching lazily turns the next such retirement into a logged degradation.
	 *
	 * @return object The ApprovalChainAnnotationInstaller.
	 *
	 * @throws RuntimeException When OpenRegister is absent or predates the task engine.
	 */
	private function annotationInstaller(): object {
		return $this->openRegisterService(
			fqcn: 'OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller'
		);
	}//end annotationInstaller()

	/**
	 * Resolve OpenRegister's task-sequence mapper, lazily.
	 *
	 * @return object The TaskSequenceMapper.
	 *
	 * @throws RuntimeException When OpenRegister is absent or predates the task engine.
	 */
	private function sequenceMapper(): object {
		return $this->openRegisterService(fqcn: 'OCA\OpenRegister\Db\TaskSequenceMapper');
	}//end sequenceMapper()

	/**
	 * Resolve OpenRegister's task-sequence service, lazily.
	 *
	 * @return object The TaskSequenceService.
	 *
	 * @throws RuntimeException When OpenRegister is absent or predates the task engine.
	 */
	private function sequenceService(): object {
		return $this->openRegisterService(fqcn: 'OCA\OpenRegister\Service\Task\TaskSequenceService');
	}//end sequenceService()

	/**
	 * Establish availability, then reach for one OpenRegister collaborator.
	 *
	 * ADR-083: ask class_exists() before reaching, so an instance without the
	 * app — or on an OpenRegister that predates the task engine — degrades with
	 * a logged message instead of a container fatal.
	 *
	 * @param string $fqcn The collaborator's fully-qualified class name.
	 *
	 * @return object The resolved collaborator.
	 *
	 * @throws RuntimeException When the class is not loadable on this instance.
	 */
	private function openRegisterService(string $fqcn): object {
		// Untestable in a unit test, exactly as objectService() above documents:
		// the stub set declares these classes unconditionally, so class_exists()
		// is always true in this suite and the branch only ever fires on a real
		// instance genuinely missing OpenRegister's task engine.
		// @codeCoverageIgnoreStart
		if (class_exists($fqcn) === false) {
			throw new RuntimeException(
				'buildiq automation approvals require OpenRegister\'s task engine; "' . $fqcn . '" is not available on this instance.'
			);
		}
		// @codeCoverageIgnoreEnd

		$service = $this->container->get($fqcn);
		assert(is_object($service));

		return $service;
	}//end openRegisterService()

	/**
	 * Handle a dispatched object event, initialising every matching
	 * automation's compiled approval chain.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-approval-steps/tasks.md#1.3
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	public function handle(Event $event): void {
		$fired = $this->resolveFiredTrigger(event: $event);
		if ($fired === null) {
			return;
		}

		[$triggerType, $schemaSlug, $objectUuid, $transitionAction] = $fired;
		if ($schemaSlug === '' || $objectUuid === '') {
			return;
		}

		$automations = $this->findMatchingAutomations(
			triggerType: $triggerType,
			schemaSlug: $schemaSlug,
			transitionAction: $transitionAction
		);

		foreach ($automations as $automation) {
			$this->initializeFor(automation: $automation, objectUuid: $objectUuid);
		}

	}//end handle()

	/**
	 * Resolve `[triggerType, schemaSlug, objectUuid, transitionAction]` from
	 * the dispatched event, or null when the event is not one this listener
	 * acts on.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return array{0:string,1:string,2:string,3:?string}|null
	 */
	private function resolveFiredTrigger(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent) {
			return ['object-created', $this->schemaOf(entity: $event->getObject()), $this->uuidOf(entity: $event->getObject()), null];
		}

		if ($event instanceof ObjectUpdatedEvent) {
			return ['object-updated', $this->schemaOf(entity: $event->getObject()), $this->uuidOf(entity: $event->getObject()), null];
		}

		if ($event instanceof ObjectDeletedEvent) {
			return ['object-deleted', $this->schemaOf(entity: $event->getObject()), $this->uuidOf(entity: $event->getObject()), null];
		}

		if ($event instanceof ObjectTransitionedEvent) {
			return [
				'lifecycle-transition',
				$event->getSchema(),
				$this->uuidOf(entity: $event->getObject()),
				$event->getAction(),
			];
		}

		return null;
	}//end resolveFiredTrigger()

	/**
	 * Read a schema slug off an `ObjectEntity`, defensively.
	 *
	 * @param object $entity The `ObjectEntity` instance.
	 *
	 * @return string
	 */
	private function schemaOf(object $entity): string {
		if (method_exists($entity, 'getSchema') === true) {
			$schema = $entity->getSchema();
			if (is_string($schema) === true) {
				return $schema;
			}
		}

		return '';
	}//end schemaOf()

	/**
	 * Read the uuid off an `ObjectEntity`, defensively.
	 *
	 * @param object $entity The `ObjectEntity` instance.
	 *
	 * @return string
	 */
	private function uuidOf(object $entity): string {
		if (method_exists($entity, 'getUuid') === true) {
			$uuid = $entity->getUuid();
			if (is_string($uuid) === true) {
				return $uuid;
			}
		}

		return '';
	}//end uuidOf()

	/**
	 * Scan the shared `buildiq` register's `automation` objects for every
	 * ENABLED automation whose `trigger` matches the fired event and whose
	 * `actions[]` includes a compiled `approval` action.
	 *
	 * @param string $triggerType The fired trigger type.
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string|null $transitionAction The matched transition action name (lifecycle-transition only).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findMatchingAutomations(string $triggerType, string $schemaSlug, ?string $transitionAction): array {
		try {
			// ADR-078 (gate-61): bounded, not unbounded — a real install's
			// configured automations number in the tens, never the thousands
			// this limit would need to matter; the bound exists to cap worst
			// case, not to reflect an expected count.
			$results = $this->objectService()->findAll(
				config: [
					'filters' => [
						'register' => AutomationCompilerService::REGISTER_SLUG,
						'schema' => AutomationCompilerService::AUTOMATION_SCHEMA,
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationApprovalTriggerListener could not scan automations: ' . $e->getMessage());
			return [];
		}

		$matches = [];
		foreach ($results as $row) {
			$automation = $this->normalise(object: $row);
			$isMatch = $this->matches(
				automation: $automation,
				triggerType: $triggerType,
				schemaSlug: $schemaSlug,
				transitionAction: $transitionAction
			);
			if ($isMatch === true) {
				$matches[] = $automation;
			}
		}

		return $matches;
	}//end findMatchingAutomations()

	/**
	 * Whether an automation's trigger matches the fired event, it is enabled,
	 * and it carries a compiled `approval` action.
	 *
	 * @param array<string,mixed> $automation The candidate automation.
	 * @param string $triggerType The fired trigger type.
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string|null $transitionAction The matched transition action name.
	 *
	 * @return bool
	 */
	private function matches(array $automation, string $triggerType, string $schemaSlug, ?string $transitionAction): bool {
		if (($automation['enabled'] ?? true) === false) {
			return false;
		}

		$trigger = $automation['trigger'] ?? null;
		if (is_array($trigger) === false) {
			return false;
		}

		$triggerMatches = $this->triggerMatches(
			trigger: $trigger,
			triggerType: $triggerType,
			schemaSlug: $schemaSlug,
			transitionAction: $transitionAction
		);
		if ($triggerMatches === false) {
			return false;
		}

		return $this->hasApprovalAction(automation: $automation);
	}//end matches()

	/**
	 * Whether the automation's `trigger` block matches the fired event.
	 *
	 * @param array<string,mixed> $trigger The automation's `trigger` block.
	 * @param string $triggerType The fired trigger type.
	 * @param string $schemaSlug The fired object's schema slug.
	 * @param string|null $transitionAction The matched transition action name.
	 *
	 * @return bool
	 */
	private function triggerMatches(array $trigger, string $triggerType, string $schemaSlug, ?string $transitionAction): bool {
		if ((string)($trigger['type'] ?? '') !== $triggerType) {
			return false;
		}

		if ((string)($trigger['schema'] ?? '') !== $schemaSlug) {
			return false;
		}

		if ($triggerType !== 'lifecycle-transition') {
			return true;
		}

		return (string)($trigger['transition'] ?? '') === (string)$transitionAction;
	}//end triggerMatches()

	/**
	 * Whether the automation carries at least one compiled `approval` action.
	 *
	 * @param array<string,mixed> $automation The candidate automation.
	 *
	 * @return bool
	 */
	private function hasApprovalAction(array $automation): bool {
		$actions = $automation['actions'] ?? [];
		if (is_array($actions) === false) {
			return false;
		}

		foreach ($actions as $action) {
			if (is_array($action) === true && ($action['type'] ?? '') === 'approval') {
				return true;
			}
		}

		return false;
	}//end hasApprovalAction()

	/**
	 * Initialise the automation's compiled approval chain for the fired
	 * object's uuid, unless a chain instance already exists for it
	 * (idempotency guard — an `object-updated`-triggered automation must not
	 * open a fresh task sequence on every subsequent save).
	 *
	 * @param array<string,mixed> $automation The matching Automation object.
	 * @param string $objectUuid The fired object's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-approval-steps/tasks.md#1.3
	 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
	 */
	private function initializeFor(array $automation, string $objectUuid): void {
		$provenance = $automation['provenance'] ?? [];
		if (is_array($provenance) === false) {
			$provenance = [];
		}

		$chainName = (string)($provenance['approvalChainName'] ?? '');
		if ($chainName === '') {
			return;
		}

		$template = $this->resolveTemplate(
			schemaSlug: (string)($automation['trigger']['schema'] ?? ''),
			chainName: $chainName
		);
		if ($template === null) {
			return;
		}

		$templateId = (string)($template['templateId'] ?? '');
		if ($templateId === '') {
			$this->logger->warning(
				'Buildiq: AutomationApprovalTriggerListener compiled a template with no id for chain "' . $chainName . '".'
			);
			return;
		}

		try {
			$existing = $this->sequenceMapper()->findForAnchor($objectUuid, $templateId);
		} catch (Throwable $e) {
			$existing = [];
		}

		if ($existing !== []) {
			// Already initialised for this object — do not open a second
			// sequence (v1 has no resubmission-cycle handling; that is the
			// gate-listener's job for the BLOCKING approval-chains flow,
			// out of this change's scope).
			return;
		}

		$requesterId = $this->userSession->getUser()?->getUID();

		try {
			$this->sequenceService()->provision(
				template: $template,
				anchorObjectUuid: $objectUuid,
				requesterId: $requesterId
			);
		} catch (Throwable $e) {
			$message = 'Buildiq: AutomationApprovalTriggerListener failed to open a task sequence for chain "' . $chainName . '"'
				. ' on object "' . $objectUuid . '": ' . $e->getMessage();
			$this->logger->error($message, ['exception' => $e]);
		}

	}//end initializeFor()

	/**
	 * Compile the approval template for {@see initializeFor()}, logging (but
	 * never throwing) on any resolution failure.
	 *
	 * Since OpenRegister #3302 an approval chain is no longer a stored row. It is
	 * a declaration on the schema (`x-openregister-approval-chains`) that
	 * `ApprovalChainAnnotationInstaller::compile()` turns into a task template on
	 * demand — a pure function of the schema, so the same declaration always
	 * compiles to the same template id and provisioning stays idempotent.
	 *
	 * @param string $schemaSlug The automation's `trigger.schema`.
	 * @param string $chainName The `provenance.approvalChainName`.
	 *
	 * @return array<string, mixed>|null The compiled template, or null.
	 */
	private function resolveTemplate(string $schemaSlug, string $chainName): ?array {
		try {
			$schema = $this->schemaMapper->find($schemaSlug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationApprovalTriggerListener could not load schema "' . $schemaSlug . '": ' . $e->getMessage());
			return null;
		}

		if ($schema->getId() === null) {
			return null;
		}

		try {
			$template = $this->annotationInstaller()->compile($schema, $chainName);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: AutomationApprovalTriggerListener could not compile approval chain "' . $chainName . '": ' . $e->getMessage()
			);
			return null;
		}

		if (is_array($template) === false) {
			$this->logger->warning(
				'Buildiq: AutomationApprovalTriggerListener found no approval chain "' . $chainName . '" declared on schema "'
				. $schemaSlug . '" — the automation may need recompiling.'
			);
			return null;
		}

		return $template;
	}//end resolveTemplate()

	/**
	 * Coerce an OR result entry to a plain associative array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
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
	}//end normalise()
}//end class
