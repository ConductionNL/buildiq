<?php

/**
 * OpenBuild ApprovalOutcomeListener
 *
 * On-approve / on-reject follow-up dispatch for the `approval` automation
 * action (design.md Decision 3 of automation-approval-steps). Subscribes to
 * OpenRegister's `ApprovalStepApprovedEvent` / `ApprovalStepRejectedEvent` —
 * already dispatched by `ApprovalService` for every approve/reject decision,
 * REGARDLESS of who initiated the chain (this listener, the declarative
 * approval-chains gate, or a hand-authored `POST /api/approval-chains` +
 * `initializeChain()` call) — resolves the ORIGINATING automation from the
 * chain's `aut-<slug>` provenance name, and dispatches its configured
 * `onApprove`/`onReject` follow-up actions through the SAME
 * {@see \OCA\OpenBuild\Service\RuleActionDispatcher} the rules backend
 * already uses for `manual`-trigger automations — never a new imperative
 * engine, never polling (ADR-031 §Exceptions(1), the identical justification
 * already accepted for `AutomationCompilerService`'s own compile branches).
 *
 * A chain not owned by any automation (name does not start with `aut-`, or no
 * automation matches the slug) is a single lookup, not a scan (task 2.2) —
 * the `aut-` prefix check short-circuits before any register scan runs.
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
 * @spec openspec/changes/automation-approval-steps/tasks.md#2.1
 * @spec openspec/changes/automation-approval-steps/tasks.md#2.2
 * @spec openspec/specs/automation-designer/spec.md#req-autd-004
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Listener;

use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenBuild\Service\RuleActionDispatcher;
use OCA\OpenRegister\Event\ApprovalStepApprovedEvent;
use OCA\OpenRegister\Event\ApprovalStepRejectedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches an automation's on-approve/on-reject follow-up actions.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/automation-approval-steps/tasks.md#2.1
 */
class ApprovalOutcomeListener implements IEventListener
{
    /**
     * The `aut-<slug>` provenance prefix (matches `AutomationCompilerService`'s
     * marker convention exactly).
     */
    private const PROVENANCE_PREFIX = 'aut-';

    /**
     * Constructor.
     *
     * @param ObjectService             $objectService    Resolves the originating automation by slug.
     * @param AutomationCompilerService $compiler         Reuses `mapActionToRuleAction()` — the SAME
     *                                                    action→typed-action mapping the rules backend uses.
     * @param RuleActionDispatcher      $actionDispatcher Shared side-effect dispatcher (same one `RuleEngineService` wires).
     * @param LoggerInterface           $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AutomationCompilerService $compiler,
        private readonly RuleActionDispatcher $actionDispatcher,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Handle an approval outcome event, dispatching the resolved automation's
     * follow-up actions for that outcome.
     *
     * @param Event $event Dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/automation-approval-steps/tasks.md#2.1
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ApprovalStepApprovedEvent) {
            $this->dispatchFollowUp(
                chainName: (string) ($event->getChain()->getName() ?? ''),
                objectUuid: $event->getObjectUuid(),
                outcomeKey: 'onApprove'
            );
            return;
        }

        if ($event instanceof ApprovalStepRejectedEvent) {
            $this->dispatchFollowUp(
                chainName: (string) ($event->getChain()->getName() ?? ''),
                objectUuid: $event->getObjectUuid(),
                outcomeKey: 'onReject'
            );
        }

    }//end handle()

    /**
     * Resolve the originating automation from the chain name and dispatch its
     * follow-up actions for the given outcome.
     *
     * @param string $chainName  The `ApprovalChain`'s `name` (expected `aut-<slug>`).
     * @param string $objectUuid The object the approval step was decided for.
     * @param string $outcomeKey `onApprove` or `onReject`.
     *
     * @return void
     *
     * @spec openspec/changes/automation-approval-steps/tasks.md#2.2
     */
    private function dispatchFollowUp(string $chainName, string $objectUuid, string $outcomeKey): void
    {
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

        foreach ($this->collectFollowUps(automation: $automation, outcomeKey: $outcomeKey) as $followUp) {
            $this->dispatchOne(action: $followUp, objectUuid: $objectUuid);
        }

    }//end dispatchFollowUp()

    /**
     * Collect every `onApprove`/`onReject` follow-up action record declared
     * on the automation's `approval` action(s).
     *
     * @param array<string,mixed> $automation The resolved automation object.
     * @param string              $outcomeKey `onApprove` or `onReject`.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectFollowUps(array $automation, string $outcomeKey): array
    {
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
     * @param array<string,mixed> $action     The raw follow-up action record.
     * @param string              $objectUuid The approved/rejected object's uuid.
     *
     * @return void
     */
    private function dispatchOne(array $action, string $objectUuid): void
    {
        try {
            $mapped = $this->compiler->mapActionToRuleAction(action: $action);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: ApprovalOutcomeListener could not map follow-up action: '.$e->getMessage());
            return;
        }

        $type   = (string) ($mapped['type'] ?? '');
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
            $this->logger->error('OpenBuild: ApprovalOutcomeListener follow-up dispatch failed for action "'.$type.'": '.$e->getMessage());
        }

    }//end dispatchOne()

    /**
     * Find the `automation` object whose `slug` equals the given slug (single
     * lookup, limit 1 — task 2.2).
     *
     * @param string $slug The automation slug (chain name minus the `aut-` prefix).
     *
     * @return array<string,mixed>|null
     */
    private function findAutomationBySlug(string $slug): ?array
    {
        try {
            $results = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => AutomationCompilerService::REGISTER_SLUG,
                        'schema'   => AutomationCompilerService::AUTOMATION_SCHEMA,
                        'slug'     => $slug,
                    ],
                    'limit'   => 1,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: ApprovalOutcomeListener could not resolve automation "'.$slug.'": '.$e->getMessage());
            return null;
        }

        if (is_array($results) === false || $results === []) {
            return null;
        }

        return $this->normalise(object: $results[0]);

    }//end findAutomationBySlug()

    /**
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $object): array
    {
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
