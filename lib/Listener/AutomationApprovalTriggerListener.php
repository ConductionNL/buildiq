<?php

/**
 * OpenBuild AutomationApprovalTriggerListener
 *
 * Trigger-fire half of the `approval` automation action (design.md Decision 1
 * / Decision 2 of automation-approval-steps). `AutomationCompilerService`
 * only compiles the DEFINITION (an OR `ApprovalChain` upsert, at compile
 * time); starting a chain instance against a concretely fired object is an
 * imperative, per-event side effect that has no declarative primitive of its
 * own to piggy-back on — OR's own `AnnotationNotificationListener` plays the
 * equivalent role for the notifications dialect, and `ApprovalChainGateListener`
 * exists only for the (semantically different) BLOCKING gate-a-transition use
 * case, not "start a chain after an object-created/updated/deleted event or a
 * non-blocking lifecycle-transition side effect". This listener is the
 * ADR-031 §Exceptions imperative companion the same way
 * `AutomationCleanupListener` is for provenance-listed artifact removal.
 *
 * Subscribes to `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`
 * and `ObjectTransitionedEvent`, scans the shared `openbuild` register's
 * `automation` objects for one whose `trigger` matches the fired event
 * (schema + trigger type, and — for a lifecycle transition — the transition
 * action name too), and calls `ApprovalService::initializeChain()` for the
 * fired object's uuid against the automation's compiled
 * `provenance.approvalChainName` chain. Idempotency guard: a chain instance
 * already exists for (chainId, objectUuid) is left alone — otherwise an
 * `object-updated`-triggered automation would spawn a fresh `ApprovalStep` on
 * every subsequent save of the same object.
 *
 * Never implements approval logic itself (ADR-022 consume-not-rebuild) — every
 * approval-domain decision (role/group check, separation of duties, chain
 * advancement) stays inside `ApprovalService`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenBuild\Listener
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

namespace OCA\OpenBuild\Listener;

use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ApprovalService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
	 *                                     which scans the `automation` register for matching triggers. The
	 *                                     interface cannot be constructor-injected here: a listener is built
	 *                                     during event dispatch, before OpenRegister's DI registrations are
	 *                                     guaranteed to be available.
	 * @param SchemaMapper $schemaMapper Resolves a schema slug to its numeric id.
	 * @param ApprovalChainMapper $chainMapper Resolves the compiled `ApprovalChain` by schema + name.
	 * @param ApprovalStepMapper $stepMapper Idempotency guard — checks for an
	 *                                       existing instance.
	 * @param ApprovalService $approvalService Starts the chain instance (ADR-022 boundary).
	 * @param IUserSession $userSession Current user session (requester identity).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SchemaMapper $schemaMapper,
		private readonly ApprovalChainMapper $chainMapper,
		private readonly ApprovalStepMapper $stepMapper,
		private readonly ApprovalService $approvalService,
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
	 * OpenBuild listener neither app's author would think to look at.
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
		/** @var ObjectServiceInterface $service */
		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return $service;

	}//end objectService()

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
	 * Scan the shared `openbuild` register's `automation` objects for every
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
			$results = $this->objectService()->findAll(
				config: [
					'filters' => [
						'register' => AutomationCompilerService::REGISTER_SLUG,
						'schema' => AutomationCompilerService::AUTOMATION_SCHEMA,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('OpenBuild: AutomationApprovalTriggerListener could not scan automations: ' . $e->getMessage());
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
	 * spawn a fresh `ApprovalStep` on every subsequent save).
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

		$chain = $this->resolveChain(schemaSlug: (string)($automation['trigger']['schema'] ?? ''), chainName: $chainName);
		if ($chain === null) {
			return;
		}

		try {
			$existingSteps = $this->stepMapper->findByChainAndObject($chain->getId(), $objectUuid);
		} catch (Throwable $e) {
			$existingSteps = [];
		}

		if ($existingSteps !== []) {
			// Already initialised for this object — do not spawn a second
			// instance (v1 has no resubmission-cycle handling; that is the
			// gate-listener's job for the BLOCKING approval-chains flow,
			// out of this change's scope).
			return;
		}

		$requesterId = $this->userSession->getUser()?->getUID();

		try {
			$this->approvalService->initializeChain(
				chain: $chain,
				objectUuid: $objectUuid,
				requesterId: $requesterId
			);
		} catch (Throwable $e) {
			$message = 'OpenBuild: AutomationApprovalTriggerListener failed to initialise chain "' . $chainName . '"'
				. ' for object "' . $objectUuid . '": ' . $e->getMessage();
			$this->logger->error($message, ['exception' => $e]);
		}

	}//end initializeFor()

	/**
	 * Resolve the compiled `ApprovalChain` entity for {@see initializeFor()},
	 * logging (but never throwing) on any resolution failure.
	 *
	 * @param string $schemaSlug The automation's `trigger.schema`.
	 * @param string $chainName The `provenance.approvalChainName`.
	 *
	 * @return ApprovalChain|null
	 */
	private function resolveChain(string $schemaSlug, string $chainName): ?ApprovalChain {
		try {
			$schema = $this->schemaMapper->find($schemaSlug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('OpenBuild: AutomationApprovalTriggerListener could not load schema "' . $schemaSlug . '": ' . $e->getMessage());
			return null;
		}

		$schemaId = $schema->getId();
		if ($schemaId === null) {
			return null;
		}

		try {
			$chain = $this->chainMapper->findBySchemaAndName(schemaId: (int)$schemaId, name: $chainName);
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenBuild: AutomationApprovalTriggerListener could not resolve ApprovalChain "' . $chainName . '": ' . $e->getMessage()
			);
			return null;
		}

		if ($chain === null) {
			$this->logger->warning(
				'OpenBuild: AutomationApprovalTriggerListener found no compiled ApprovalChain "' . $chainName . '" — automation may need recompiling.'
			);
		}

		return $chain;
	}//end resolveChain()

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
