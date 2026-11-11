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

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Task\ResolvedChain;
use OCA\OpenRegister\Service\Task\SequenceAnchor;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
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
	 * OpenRegister's object service, resolved at call time.
	 *
	 * @var LazyObjectService
	 */
	private readonly LazyObjectService $objects;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service lazily — see LazyObjectService,
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
		$this->objects = new LazyObjectService(container: $container);
	}//end __construct()

	/**
	 * Resolve OpenRegister's approval-chain compiler, lazily.
	 *
	 * Same reasoning as {@see LazyObjectService}: a listener is built from the
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
	 * @return TaskSequenceService The TaskSequenceService.
	 *
	 * @throws RuntimeException When OpenRegister is absent or predates the task engine.
	 */
	private function sequenceService(): TaskSequenceService {
		$service = $this->openRegisterService(fqcn: TaskSequenceService::class);
		assert($service instanceof TaskSequenceService);

		return $service;
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
		// Untestable in a unit test, exactly as LazyObjectService::get() documents:
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
	 * @SuppressWarnings(PHPMD.StaticAccess) FiredAutomationTrigger is a pure value read off the event.
	 */
	public function handle(Event $event): void {
		$fired = FiredAutomationTrigger::fromEvent(event: $event);
		if ($fired === null || $fired->isAddressable() === false) {
			return;
		}

		$objectUuid = $fired->objectUuid();
		$automations = $this->findMatchingAutomations(fired: $fired);

		foreach ($automations as $automation) {
			$this->initializeFor(automation: $automation, objectUuid: $objectUuid);
		}

	}//end handle()

	/**
	 * Scan the shared `buildiq` register's `automation` objects for every
	 * ENABLED automation whose `trigger` matches the fired event and whose
	 * `actions[]` includes a compiled `approval` action.
	 *
	 * @param FiredAutomationTrigger $fired The fired trigger.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findMatchingAutomations(FiredAutomationTrigger $fired): array {
		try {
			// ADR-078 (gate-61): bounded, not unbounded — a real install's
			// configured automations number in the tens, never the thousands
			// this limit would need to matter; the bound exists to cap worst
			// case, not to reflect an expected count.
			$results = $this->objects->get()->findAll(
				config: ['filters' => FiredAutomationTrigger::AUTOMATION_FILTERS, 'limit' => 1000]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationApprovalTriggerListener could not scan automations: ' . $e->getMessage());
			return [];
		}

		return $fired->select(rows: $results, actionType: 'approval');
	}//end findMatchingAutomations()

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
				chain: new ResolvedChain(template: $template),
				anchor: new SequenceAnchor(objectUuid: $objectUuid, requesterId: $requesterId)
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
}//end class
