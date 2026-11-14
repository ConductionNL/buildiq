<?php

/**
 * Buildiq ApprovalOutcomeListener
 *
 * On-approve / on-reject follow-up dispatch for the `approval` automation
 * action (design.md Decision 3 of automation-approval-steps). Subscribes to
 * OpenRegister's `TaskSequenceCompletedEvent` / `TaskTerminalEvent` — already
 * dispatched for every decision, REGARDLESS of who opened the sequence (this
 * app, the declarative approval-chains annotation, or a hand-authored task) —
 * resolves the ORIGINATING automation from the sequence's `aut-<slug>` chain
 * key, and dispatches its configured
 * `onApprove`/`onReject` follow-up actions through the SAME
 * {@see \OCA\Buildiq\Service\RuleActionDispatcher} the rules backend
 * already uses for `manual`-trigger automations — never a new imperative
 * engine, never polling (ADR-031 §Exceptions(1), the identical justification
 * already accepted for `AutomationCompilerService`'s own compile branches).
 *
 * ⚠️ The two halves subscribe at DIFFERENT levels, which is the mapping
 * openregister #3302 published rather than an inconsistency: approval concludes
 * when the SEQUENCE completes, while a rejection is a TASK completing with a
 * rejecting outcome. A rejecting task closes its whole sequence, so the reject
 * half fires exactly once per approval, the same as the approve half.
 *
 * A chain not owned by any automation (name does not start with `aut-`, or no
 * automation matches the slug) is a single lookup, not a scan (task 2.2) —
 * the `aut-` prefix check short-circuits before any register scan runs.
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
 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#2.1
 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#2.2
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Kernel\Contracts\RuleActionDispatcherInterface;
use OCA\Buildiq\Service\AutomationRunLog;
use OCA\Buildiq\Service\AutomationRunRecorder;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches an automation's on-approve/on-reject follow-up actions.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#2.1
 */
class ApprovalOutcomeListener implements IEventListener {
	/**
	 * The `aut-<slug>` provenance prefix (matches `AutomationCompilerService`'s
	 * marker convention exactly).
	 */
	private const PROVENANCE_PREFIX = 'aut-';

	/**
	 * OpenRegister's object service, resolved at call time.
	 *
	 * @var LazyObjectService
	 */
	private readonly LazyObjectService $objects;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's object service at USE time —
	 *                                      see LazyObjectService for why a listener cannot inject it.
	 * @param AutomationCompilerServiceInterface $compiler Reuses `mapActionToRuleAction()` — the SAME
	 *                                            action→typed-action mapping the rules backend uses.
	 * @param RuleActionDispatcherInterface $actionDispatcher Shared side-effect dispatcher (same one `RuleEngineService` wires).
	 * @param LoggerInterface $logger PSR logger.
	 * @param AutomationRunLog|null $runLog Completes the automation's waiting run with the decision; built at use time when not given.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly AutomationCompilerServiceInterface $compiler,
		private readonly RuleActionDispatcherInterface $actionDispatcher,
		private readonly LoggerInterface $logger,
		private ?AutomationRunLog $runLog = null,
	) {
		$this->objects = new LazyObjectService(container: $container);
	}//end __construct()

	/**
	 * Is this outcome a rejecting one?
	 *
	 * Asks OpenRegister rather than hardcoding the vocabulary: `TaskState`
	 * owns which outcome values reject, and a copy here would drift the moment
	 * a value is added. Resolved through class_exists so an instance without
	 * the task engine degrades to "not rejecting" instead of fataling inside an
	 * event listener, which would break the write that fired it.
	 *
	 * @param string|null $outcome The terminal task's outcome.
	 *
	 * @return bool True when the outcome rejects.
	 */
	private function isRejectingOutcome(?string $outcome): bool {
		$state = 'OCA\\OpenRegister\\Service\\Task\\TaskState';
		if ($outcome === null || class_exists($state) === false) {
			return false;
		}

		return (bool)$state::isRejectingOutcome($outcome);

	}//end isRejectingOutcome()

	/**
	 * The chain key of the sequence a terminal task belongs to.
	 *
	 * The task carries its sequence's uuid but not the chain key, and
	 * dispatchFollowUp() needs the key to tell an automation-owned chain from a
	 * hand-authored one. Returns '' when it cannot be resolved, which the caller
	 * treats as "not ours".
	 *
	 * @param string $sequenceUuid The sequence uuid from the task.
	 *
	 * @return string The chain key, or '' when unresolvable.
	 */
	private function chainKeyForSequence(string $sequenceUuid): string {
		$mapper = 'OCA\\OpenRegister\\Db\\TaskSequenceMapper';
		if ($sequenceUuid === '' || class_exists($mapper) === false) {
			return '';
		}

		try {
			$sequence = $this->container->get($mapper)->findByUuid($sequenceUuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: ApprovalOutcomeListener could not load sequence "' . $sequenceUuid . '": ' . $e->getMessage()
			);
			return '';
		}

		return (string)($sequence->getChainKey() ?? '');

	}//end chainKeyForSequence()

	/**
	 * Handle an approval outcome event, dispatching the resolved automation's
	 * follow-up actions for that outcome.
	 *
	 * @param Event $event Dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#2.1
	 */
	public function handle(Event $event): void {
		// APPROVE: the sequence completing IS the approval concluding, so the
		// chain key and anchor come straight off it.
		if ($event instanceof TaskSequenceCompletedEvent) {
			$sequence = $event->getSequence();
			$this->dispatchFollowUp(
				chainName: (string)($sequence->getChainKey() ?? ''),
				objectUuid: (string)($sequence->getAnchorObjectUuid() ?? ''),
				outcomeKey: 'onApprove'
			);
			return;
		}

		// REJECT: there is no sequence-level rejection event, and there does not
		// need to be — openregister #3302's published mapping puts it at the TASK
		// level ("one completing with a rejecting outcome replaces Rejected").
		// A rejecting task closes its whole sequence, so this fires once.
		if ($event instanceof TaskTerminalEvent) {
			if ($this->isRejectingOutcome(outcome: $event->getOutcome()) === false) {
				return;
			}

			$task = $event->getTask();
			$chainName = $this->chainKeyForSequence(sequenceUuid: (string)($task->getSequenceUuid() ?? ''));
			if ($chainName === '') {
				return;
			}

			$this->dispatchFollowUp(
				chainName: $chainName,
				objectUuid: (string)($task->getObjectUuid() ?? ''),
				outcomeKey: 'onReject'
			);
		}

	}//end handle()

	/**
	 * Resolve the originating automation from the chain name and dispatch its
	 * follow-up actions for the given outcome.
	 *
	 * @param string $chainName The `ApprovalChain`'s `name` (expected `aut-<slug>`).
	 * @param string $objectUuid The object the approval step was decided for.
	 * @param string $outcomeKey `onApprove` or `onReject`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#2.2
	 */
	private function dispatchFollowUp(string $chainName, string $objectUuid, string $outcomeKey): void {
		if (str_starts_with($chainName, self::PROVENANCE_PREFIX) === false) {
			// Not an automation-owned chain (hand-authored via
			// `POST /api/approval-chains`, or a declarative
			// `x-openregister-approval-chains` gate entry) — single string
			// check, no register scan (task 2.2).
			return;
		}

		$slug = substr($chainName, strlen(self::PROVENANCE_PREFIX));
		if ($slug === '') {
			return;
		}

		$automation = $this->findAutomationBySlug(slug: $slug);
		if ($automation === null) {
			// No-op: the chain's provenance name looks automation-owned but
			// no automation currently carries that slug (deleted, or the
			// chain survived a rename) — one lookup, then done.
			return;
		}

		$steps = [];
		foreach ($this->collectFollowUps(automation: $automation, outcomeKey: $outcomeKey) as $followUp) {
			$steps[] = $this->dispatchOne(action: $followUp, objectUuid: $objectUuid);
		}

		$this->runLog()?->approvalDecided(
			automation: $automation,
			objectUuid: $objectUuid,
			approved: $outcomeKey === 'onApprove',
			followUps: $steps
		);

	}//end dispatchFollowUp()

	/**
	 * Collect every `onApprove`/`onReject` follow-up action record declared
	 * on the automation's `approval` action(s).
	 *
	 * @param array<string,mixed> $automation The resolved automation object.
	 * @param string $outcomeKey `onApprove` or `onReject`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collectFollowUps(array $automation, string $outcomeKey): array {
		$actions = $automation['actions'] ?? [];
		if (is_array($actions) === false) {
			return [];
		}

		$followUps = [];
		foreach ($actions as $action) {
			if (is_array($action) === false || ($action['type'] ?? '') !== 'approval') {
				continue;
			}

			$entries = $action[$outcomeKey] ?? [];
			if (is_array($entries) === false) {
				continue;
			}

			foreach ($entries as $entry) {
				if (is_array($entry) === true) {
					$followUps[] = $entry;
				}
			}
		}

		return $followUps;
	}//end collectFollowUps()

	/**
	 * Dispatch one mapped follow-up action through the shared dispatcher.
	 *
	 * An `object-op` `update` follow-up with no explicit target `id`
	 * defaults to the approved/rejected object's own uuid — the dominant
	 * pattern (design.md Seed Data: "on-approve: update the object's own
	 * status field") — without touching {@see AutomationCompilerService::mapActionToRuleAction()}'s
	 * tested output shape for its other callers.
	 *
	 * @param array<string,mixed> $action The raw follow-up action record.
	 * @param string $objectUuid The approved/rejected object's uuid.
	 *
	 * @return array{action: string, outcome: string, message: string} What the follow-up did, for the run log.
	 */
	private function dispatchOne(array $action, string $objectUuid): array {
		try {
			$mapped = $this->compiler->mapActionToRuleAction(action: $action);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: ApprovalOutcomeListener could not map follow-up action: ' . $e->getMessage());
			return ['action' => (string)($action['type'] ?? ''), 'outcome' => 'failed', 'message' => $e->getMessage()];
		}

		$type = (string)($mapped['type'] ?? '');
		$params = $mapped['parameters'] ?? [];
		if (is_array($params) === false) {
			$params = [];
		}

		if ($type === 'object-op' && ($params['operation'] ?? '') === 'update' && empty($params['id']) === true) {
			$params['id'] = $objectUuid;
		}

		try {
			($this->actionDispatcher)($type, $params, []);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: ApprovalOutcomeListener follow-up dispatch failed for action "' . $type . '": ' . $e->getMessage());
			return ['action' => (string)($action['type'] ?? $type), 'outcome' => 'failed', 'message' => $e->getMessage()];
		}

		return ['action' => (string)($action['type'] ?? $type), 'outcome' => 'succeeded', 'message' => ''];

	}//end dispatchOne()

	/**
	 * Find the `automation` object whose `slug` equals the given slug (single
	 * lookup, limit 1 — task 2.2).
	 *
	 * @param string $slug The automation slug (chain name minus the `aut-` prefix).
	 *
	 * @return array<string,mixed>|null
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function findAutomationBySlug(string $slug): ?array {
		try {
			$results = $this->objects->get()->findAll(
				config: [
					'filters' => [
						'register' => AutomationCompilerServiceInterface::REGISTER_SLUG,
						'schema' => AutomationCompilerServiceInterface::AUTOMATION_SCHEMA,
						'slug' => $slug,
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: ApprovalOutcomeListener could not resolve automation "' . $slug . '": ' . $e->getMessage());
			return null;
		}

		if ($results === []) {
			return null;
		}

		return ObjectRecord::toArray(object: $results[0]);
	}//end findAutomationBySlug()
	/**
	 * The run log, built at use time from the same lazily resolved object
	 * service: a listener cannot constructor-inject the recorder's published
	 * dependencies (see LazyObjectService). Null when it cannot be built.
	 *
	 * @return AutomationRunLog|null
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	private function runLog(): ?AutomationRunLog {
		if ($this->runLog !== null) {
			return $this->runLog;
		}

		try {
			$objectService = $this->objects->get();
			$this->runLog  = new AutomationRunLog(
				recorder: new AutomationRunRecorder(objectService: $objectService, time: $this->container->get(ITimeFactory::class), logger: $this->logger),
				objectService: $objectService,
				logger: $this->logger
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: automation runs cannot be recorded: ' . $e->getMessage());
			return null;
		}

		return $this->runLog;
	}//end runLog()
}//end class
