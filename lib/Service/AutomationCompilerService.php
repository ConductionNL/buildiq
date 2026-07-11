<?php

/**
 * OpenBuild AutomationCompilerService
 *
 * Compiles a stored `Automation` declarative object (design.md Decision 1 of
 * the automation-designer change) to OpenBuild's EXISTING declarative
 * primitives, and nothing else (ADR-031 — no new imperative engine):
 *
 *   - Dialect backend — event/lifecycle-transition triggers + `send-notification`
 *     compile to an `x-openregister-notifications` entry on the target schema
 *     (the shape verified live at `lib/Settings/register.d/10-business-rules.json:153`);
 *     lifecycle-transition + `object-op`/`webhook` compile to typed
 *     `related-object-upsert`/`webhook-dispatch` records appended to that
 *     transition's `x-openregister-lifecycle` actions.
 *   - Schedules backend — `schedule` trigger + `run-synchronization` compiles
 *     to a `manifest.schedules[]` entry using the existing
 *     `openconnector:synchronization` action (validated shape:
 *     `src/services/manifestValidation/schedules.js`).
 *   - Rules backend — `manual` trigger compiles condition + actions to a
 *     namespaced RuleSet (`aut-<uuid8>`) plus one ConditionActionRule
 *     evaluated by the existing {@see RuleEngineService}.
 *
 * The v1 compilation matrix (design.md Decision 2) is enforced fail-closed —
 * {@see compile()} throws {@see UnsupportedAutomationCombinationException}
 * naming the unsupported trigger/action/condition combination; nothing is
 * ever stubbed, silently dropped, or partially compiled.
 *
 * DEVIATION FROM design.md (documented, not silent — tasks.md apply-notes
 * instruct flagging rather than inventing a runner): design.md's Decision 2
 * matrix table marks `manual` + `run-synchronization` as a ✅ "rules backend"
 * cell. No primitive to invoke an OpenConnector synchronization on demand
 * exists anywhere in openbuild's `lib/` (the ONLY existing trigger for a
 * synchronization run is the AppHost schedules reconciler); the rules
 * engine's typed action vocabulary
 * ({@see ConditionActionExecutor::SIDE_EFFECT_ACTIONS}) has no
 * `run-synchronization` action and inventing an ad-hoc HTTP call into
 * OpenConnector without a verified contract would be worse than declining
 * the cell. This compiler therefore treats `manual` + `run-synchronization`
 * as ⛔ (blocked fail-closed) pending a verified OpenConnector "run now" API
 * — a v1.1 follow-up, not a v1 regression (no scenario in
 * `specs/automation-designer/spec.md` REQ-AUTD-002/004 exercises this cell).
 *
 * Compilation is deterministic (identical input → identical plan + hash),
 * idempotent (recompiling an unchanged automation changes nothing) and
 * reversible (delete removes exactly the provenance-listed artifacts). Every
 * compiled artifact id/key carries the `aut-` prefix so hand-authored
 * entries are never touched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#2.1
 * @spec openspec/changes/automation-designer/tasks.md#2.2
 * @spec openspec/changes/automation-designer/specs/automation-designer/spec.md#req-autd-004
 * @spec openspec/changes/automation-designer/specs/automation-designer/spec.md#req-autd-005
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenBuild\Exception\UnsupportedAutomationCombinationException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pure compile() + I/O-performing apply()/remove()/status().
 */
class AutomationCompilerService
{
    /**
     * Shared OpenBuild register slug.
     */
    public const REGISTER_SLUG = 'openbuild';

    /**
     * Schema slug of the Automation object itself.
     */
    public const AUTOMATION_SCHEMA = 'automation';

    /**
     * The v1 compilation matrix: trigger type → allowed action types.
     *
     * See the class docblock for the one documented deviation from
     * design.md's table (`manual` + `run-synchronization`).
     *
     * @var array<string,array<int,string>>
     */
    private const MATRIX = [
        'object-created'       => ['send-notification'],
        'object-updated'       => ['send-notification'],
        'object-deleted'       => ['send-notification'],
        'lifecycle-transition' => ['send-notification', 'object-op', 'webhook'],
        'schedule'             => ['run-synchronization'],
        'manual'               => ['send-notification', 'object-op', 'webhook'],
    ];

    /**
     * Trigger types on which a condition is v1-supported (design.md Decision 2
     * — the rules backend is the only existing primitive that evaluates FEEL).
     *
     * @var array<int,string>
     */
    private const CONDITION_ALLOWED_TRIGGERS = ['manual'];

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object service (ADR-022 boundary).
     * @param SchemaMapper    $schemaMapper  OR schema mapper — mutates schema `configuration`
     *                                       (`x-openregister-notifications` / `x-openregister-lifecycle`).
     * @param LoggerInterface $logger        PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Compile an automation to its CompiledPlan (pure — no I/O).
     *
     * @param array<string,mixed> $automation The Automation object.
     *
     * @return array{notifications:array<int,array<string,mixed>>,lifecycleActions:array<int,array<string,mixed>>,schedules:array<int,array<string,mixed>>,ruleSet:?array<string,mixed>,conditionActionRule:?array<string,mixed>,hash:string}
     *
     * @throws UnsupportedAutomationCombinationException When the matrix (or condition placement) rejects the shape.
     */
    public function compile(array $automation): array
    {
        $slug        = (string) ($automation['slug'] ?? '');
        $trigger     = is_array($automation['trigger'] ?? null) ? $automation['trigger'] : [];
        $triggerType = (string) ($trigger['type'] ?? '');
        $condition   = $automation['condition'] ?? null;
        $actions     = is_array($automation['actions'] ?? null) ? array_values($automation['actions']) : [];
        $enabled     = (bool) ($automation['enabled'] ?? true);

        $this->assertMatrix(triggerType: $triggerType, condition: $condition, actions: $actions);

        $plan = [
            'notifications'       => [],
            'lifecycleActions'    => [],
            'schedules'           => [],
            'ruleSet'             => null,
            'conditionActionRule' => null,
        ];

        if (in_array($triggerType, ['object-created', 'object-updated', 'object-deleted', 'lifecycle-transition'], true) === true) {
            $this->compileDialectBackend(slug: $slug, trigger: $trigger, triggerType: $triggerType, actions: $actions, enabled: $enabled, plan: $plan);
        } else if ($triggerType === 'schedule') {
            $this->compileSchedulesBackend(slug: $slug, trigger: $trigger, actions: $actions, enabled: $enabled, plan: $plan);
        } else if ($triggerType === 'manual') {
            $this->compileRulesBackend(automation: $automation, slug: $slug, condition: $condition, actions: $actions, enabled: $enabled, plan: $plan);
        }

        $plan['hash'] = $this->hashPlan(plan: $plan);
        return $plan;

    }//end compile()

    /**
     * Apply a compiled plan: idempotent upsert of every artifact, removing
     * any prior-provenance artifact no longer present in the new plan.
     *
     * @param array<string,mixed> $automation      The Automation object (must carry an `id`/`uuid`).
     * @param array<string,mixed> $plan            The CompiledPlan from {@see compile()}.
     * @param array<string,mixed> $priorProvenance The automation's PREVIOUS `provenance` block (empty on first compile).
     *
     * @return array<string,mixed> The new `provenance` block.
     */
    public function apply(array $automation, array $plan, array $priorProvenance=[]): array
    {
        $slug        = (string) ($automation['slug'] ?? '');
        $versionUuid = (string) ($automation['versionUuid'] ?? '');

        $notificationKeys = $this->applyNotifications(
            planned: $plan['notifications'],
            priorKeys: (is_array($priorProvenance['notificationKeys'] ?? null) ? $priorProvenance['notificationKeys'] : [])
        );

        $lifecycleActions = $this->applyLifecycleActions(
            slug: $slug,
            planned: $plan['lifecycleActions'],
            priorActions: (is_array($priorProvenance['lifecycleActions'] ?? null) ? $priorProvenance['lifecycleActions'] : [])
        );

        $scheduleIds = $this->applySchedules(
            versionUuid: $versionUuid,
            plannedEntries: $plan['schedules'],
            priorScheduleIds: (is_array($priorProvenance['scheduleIds'] ?? null) ? $priorProvenance['scheduleIds'] : [])
        );

        $ruleSetSlug = $this->applyRuleSet(
            ruleSet: $plan['ruleSet'],
            conditionActionRule: $plan['conditionActionRule'],
            priorRuleSetSlug: (is_string($priorProvenance['ruleSetSlug'] ?? null) ? $priorProvenance['ruleSetSlug'] : null)
        );

        return [
            'notificationKeys'     => $notificationKeys,
            'lifecycleActions'     => $lifecycleActions,
            'scheduleIds'          => $scheduleIds,
            'ruleSetSlug'          => $ruleSetSlug,
            'openconnectorObjects' => [],
            'compiledHash'         => (string) $plan['hash'],
        ];

    }//end apply()

    /**
     * Remove exactly the provenance-listed artifacts (design.md Decision 4 —
     * hand-authored non-`aut-` entries are never touched).
     *
     * @param array<string,mixed> $automation  The Automation object.
     * @param array<string,mixed> $provenance  The automation's `provenance` block.
     *
     * @return void
     */
    public function remove(array $automation, array $provenance): void
    {
        $this->applyNotifications(planned: [], priorKeys: (is_array($provenance['notificationKeys'] ?? null) ? $provenance['notificationKeys'] : []));
        $this->applyLifecycleActions(
            slug: (string) ($automation['slug'] ?? ''),
            planned: [],
            priorActions: (is_array($provenance['lifecycleActions'] ?? null) ? $provenance['lifecycleActions'] : [])
        );
        $this->applySchedules(
            versionUuid: (string) ($automation['versionUuid'] ?? ''),
            plannedEntries: [],
            priorScheduleIds: (is_array($provenance['scheduleIds'] ?? null) ? $provenance['scheduleIds'] : [])
        );

        $ruleSetSlug = ($provenance['ruleSetSlug'] ?? null);
        if (is_string($ruleSetSlug) === true && $ruleSetSlug !== '') {
            $this->removeRuleSet(ruleSetSlug: $ruleSetSlug);
        }

    }//end remove()

    /**
     * Recompute drift: compare the live artifacts against the last-applied
     * `provenance.compiledHash` (design.md Decision 4).
     *
     * @param array<string,mixed> $automation The Automation object.
     * @param array<string,mixed> $provenance The automation's `provenance` block.
     *
     * @return array{drift:bool,compiledHash:?string,liveHash:?string}
     */
    public function status(array $automation, array $provenance): array
    {
        $expectedHash = (string) ($provenance['compiledHash'] ?? '');
        if ($expectedHash === '') {
            return ['drift' => false, 'compiledHash' => null, 'liveHash' => null];
        }

        try {
            $live = [
                'notifications'       => $this->fetchLiveNotifications(
                    keys: (is_array($provenance['notificationKeys'] ?? null) ? $provenance['notificationKeys'] : [])
                ),
                'lifecycleActions'    => $this->fetchLiveLifecycleActions(
                    slug: (string) ($automation['slug'] ?? ''),
                    marked: (is_array($provenance['lifecycleActions'] ?? null) ? $provenance['lifecycleActions'] : [])
                ),
                'schedules'           => $this->fetchLiveSchedules(
                    versionUuid: (string) ($automation['versionUuid'] ?? ''),
                    ids: (is_array($provenance['scheduleIds'] ?? null) ? $provenance['scheduleIds'] : [])
                ),
                'ruleSet'             => $this->fetchLiveRuleSet(ruleSetSlug: ($provenance['ruleSetSlug'] ?? null)),
                'conditionActionRule' => $this->fetchLiveConditionActionRule(ruleSetSlug: ($provenance['ruleSetSlug'] ?? null)),
            ];
        } catch (Throwable $e) {
            // A fetch failure means live state cannot be confirmed —
            // fail safe by reporting drift so the operator is prompted to
            // recompile rather than trusting a possibly-stale badge.
            $this->logger->warning('OpenBuild: AutomationCompilerService::status() live fetch failed: '.$e->getMessage());
            return ['drift' => true, 'compiledHash' => $expectedHash, 'liveHash' => null];
        }

        $liveHash = $this->hashPlan(plan: $live);

        return [
            'drift'        => ($liveHash !== $expectedHash),
            'compiledHash' => $expectedHash,
            'liveHash'     => $liveHash,
        ];

    }//end status()

    /**
     * Enforce the v1 matrix fail-closed.
     *
     * @param string               $triggerType The trigger type.
     * @param mixed                $condition   The condition block (or null).
     * @param array<int,mixed>     $actions     The action records.
     *
     * @return void
     *
     * @throws UnsupportedAutomationCombinationException
     */
    private function assertMatrix(string $triggerType, mixed $condition, array $actions): void
    {
        $allowedActions = (self::MATRIX[$triggerType] ?? null);
        if ($allowedActions === null) {
            throw new UnsupportedAutomationCombinationException('Unknown or unsupported trigger type "'.$triggerType.'".');
        }

        foreach ($actions as $action) {
            $type = (string) ((is_array($action) ? $action['type'] : null) ?? '');
            if ($type === 'approval') {
                throw new UnsupportedAutomationCombinationException(
                    'The "approval" action is reserved for a future human-task primitive and is not yet expressible declaratively.'
                );
            }

            if (in_array($type, $allowedActions, true) === false) {
                throw new UnsupportedAutomationCombinationException(
                    'Trigger "'.$triggerType.'" + action "'.$type.'" is not yet expressible declaratively.'
                );
            }
        }

        if (is_array($condition) === true && in_array($triggerType, self::CONDITION_ALLOWED_TRIGGERS, true) === false) {
            throw new UnsupportedAutomationCombinationException(
                'A condition is only supported on the "manual" trigger in v1 (trigger "'.$triggerType.'" given).'
            );
        }

    }//end assertMatrix()

    /**
     * Dialect backend: event/lifecycle-transition triggers.
     *
     * @param string               $slug        Automation slug.
     * @param array<string,mixed>  $trigger     Trigger block.
     * @param string               $triggerType Trigger type.
     * @param array<int,mixed>     $actions     Action records.
     * @param bool                 $enabled     Automation enabled flag.
     * @param array<string,mixed>  $plan        The plan being built (by reference).
     *
     * @return void
     */
    private function compileDialectBackend(string $slug, array $trigger, string $triggerType, array $actions, bool $enabled, array &$plan): void
    {
        $schema      = (string) ($trigger['schema'] ?? '');
        $transition  = (string) ($trigger['transition'] ?? '');
        $marker      = 'aut-'.$slug;
        $notifIndex  = 0;

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');

            if ($type === 'send-notification') {
                $notifIndex++;
                $dialectTrigger = ($triggerType === 'lifecycle-transition')
                    ? ['type' => 'transition', 'action' => $transition]
                    : ['type' => $this->dialectEventType(triggerType: $triggerType)];

                $plan['notifications'][] = [
                    'schema' => $schema,
                    'key'    => 'aut-'.$slug.'-'.$notifIndex,
                    'entry'  => [
                        'trigger'    => $dialectTrigger,
                        'enabled'    => $enabled,
                        'channels'   => array_values((array) ($action['channels'] ?? ['nc-notification'])),
                        'recipients' => array_values((array) ($action['recipients'] ?? [])),
                        'subject'    => (array) ($action['subject'] ?? []),
                    ],
                ];
                continue;
            }

            if ($triggerType !== 'lifecycle-transition') {
                // The matrix already blocked any other action type for
                // object-* triggers — defensive no-op.
                continue;
            }

            if ($type === 'object-op') {
                $plan['lifecycleActions'][] = [
                    'schema'     => $schema,
                    'transition' => $transition,
                    'marker'     => $marker,
                    'action'     => [
                        'type'         => 'related-object-upsert',
                        'operation'    => (string) ($action['operation'] ?? 'create'),
                        'schema'       => (string) ($action['schema'] ?? ''),
                        'fieldMapping' => (array) ($action['fieldMapping'] ?? []),
                        'marker'       => $marker,
                    ],
                ];
            } else if ($type === 'webhook') {
                $plan['lifecycleActions'][] = [
                    'schema'     => $schema,
                    'transition' => $transition,
                    'marker'     => $marker,
                    'action'     => [
                        'type'            => 'webhook-dispatch',
                        'url'             => (string) ($action['url'] ?? ''),
                        'payloadTemplate' => (array) ($action['payloadTemplate'] ?? []),
                        'marker'          => $marker,
                    ],
                ];
            }
        }//end foreach

    }//end compileDialectBackend()

    /**
     * Map an object-event trigger type to the notifications dialect's event
     * name (verified shape: `10-business-rules.json` uses `transition`; ADR-031
     * documents `created|updated|deleted` as the dialect's event types).
     *
     * @param string $triggerType The automation trigger type.
     *
     * @return string
     */
    private function dialectEventType(string $triggerType): string
    {
        return match ($triggerType) {
            'object-created' => 'created',
            'object-updated' => 'updated',
            'object-deleted' => 'deleted',
            default => $triggerType,
        };

    }//end dialectEventType()

    /**
     * Schedules backend: `schedule` trigger + `run-synchronization`.
     *
     * @param string              $slug    Automation slug.
     * @param array<string,mixed> $trigger Trigger block.
     * @param array<int,mixed>    $actions Action records.
     * @param bool                $enabled Automation enabled flag.
     * @param array<string,mixed> $plan    The plan being built (by reference).
     *
     * @return void
     */
    private function compileSchedulesBackend(string $slug, array $trigger, array $actions, bool $enabled, array &$plan): void
    {
        $index = 0;
        foreach ($actions as $action) {
            if ((string) ($action['type'] ?? '') !== 'run-synchronization') {
                continue;
            }

            $index++;
            $entry = [
                'id'        => 'aut-'.$slug.'-'.$index,
                'enabled'   => $enabled,
                'action'    => 'openconnector:synchronization',
                'arguments' => ['synchronizationId' => (string) ($action['synchronizationId'] ?? '')],
            ];

            $cron = (string) ($trigger['cron'] ?? '');
            if ($cron !== '') {
                $entry['cron'] = $cron;
            } else {
                $entry['interval'] = (int) ($trigger['interval'] ?? 86400);
            }

            $plan['schedules'][] = $entry;
        }

    }//end compileSchedulesBackend()

    /**
     * Rules backend: `manual` trigger.
     *
     * @param array<string,mixed> $automation The full Automation object (for slug/name/applicationSlug/uuid).
     * @param string              $slug       Automation slug.
     * @param mixed               $condition  Condition block (or null).
     * @param array<int,mixed>    $actions    Action records.
     * @param bool                $enabled    Automation enabled flag.
     * @param array<string,mixed> $plan       The plan being built (by reference).
     *
     * @return void
     */
    private function compileRulesBackend(array $automation, string $slug, mixed $condition, array $actions, bool $enabled, array &$plan): void
    {
        $ruleSetSlug   = 'aut-'.$this->shortUuid(automation: $automation);
        $conditie      = '';
        $mappedActions = [];

        if (is_array($condition) === true) {
            $conditionType = (string) ($condition['type'] ?? '');
            if ($conditionType === 'feel') {
                $conditie = (string) ($condition['expression'] ?? '');
            } else if ($conditionType === 'rule-set') {
                $refSlug = (string) ($condition['ruleSetSlug'] ?? '');
                if ($refSlug !== '') {
                    // No existing primitive gates our own actions on a
                    // referenced RuleSet's boolean result (documented v1
                    // simplification) — the reference is dispatched
                    // fire-and-forget ahead of the mapped actions, which
                    // always run unconditionally in this shape.
                    $mappedActions[] = ['type' => 'call-rule-set', 'parameters' => ['ruleSetSlug' => $refSlug]];
                }
            }
        }

        foreach ($actions as $action) {
            $mappedActions[] = $this->mapActionToRuleAction(action: $action);
        }

        $plan['ruleSet'] = [
            'slug'        => $ruleSetSlug,
            'naam'        => (string) ($automation['name'] ?? $slug),
            'versie'      => '1.0.0',
            'status'      => 'active',
            'ruleType'    => 'condition-action',
            'eigenaarApp' => (string) ($automation['applicationSlug'] ?? ''),
        ];

        $plan['conditionActionRule'] = [
            'ruleSetId' => $ruleSetSlug,
            'naam'      => (string) ($automation['name'] ?? $slug),
            'conditie'  => $conditie,
            'acties'    => $mappedActions,
            'actief'    => $enabled,
        ];

    }//end compileRulesBackend()

    /**
     * Map one Automation action record to a ConditionActionRule typed action.
     *
     * @param array<string,mixed> $action The Automation action record.
     *
     * @return array<string,mixed>
     */
    private function mapActionToRuleAction(array $action): array
    {
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            'send-notification' => [
                'type'       => 'send-notification',
                'parameters' => [
                    'subject'      => (string) ((is_array($action['subject'] ?? null) ? ($action['subject']['en'] ?? '') : ($action['subject'] ?? ''))),
                    'recipientUid' => (string) ($action['recipientUid'] ?? ''),
                ],
            ],
            'object-op' => [
                'type'       => 'object-op',
                'parameters' => [
                    'schema'       => (string) ($action['schema'] ?? ''),
                    'operation'    => (string) ($action['operation'] ?? 'create'),
                    'object'       => (array) ($action['fieldMapping'] ?? []),
                    'register'     => self::REGISTER_SLUG,
                ],
            ],
            'webhook' => [
                'type'       => 'webhook',
                'parameters' => [
                    'url'     => (string) ($action['url'] ?? ''),
                    'payload' => (array) ($action['payloadTemplate'] ?? []),
                ],
            ],
            default => ['type' => $type, 'parameters' => []],
        };

    }//end mapActionToRuleAction()

    /**
     * Derive the first 8 hex characters of the automation's own uuid
     * (design.md Decision 6 — rule-set slugs are keyed off the automation
     * object uuid so version clones get distinct rule sets).
     *
     * @param array<string,mixed> $automation The Automation object.
     *
     * @return string
     *
     * @throws RuntimeException When the automation has no persisted id/uuid yet.
     */
    private function shortUuid(array $automation): string
    {
        $id = (string) ($automation['id'] ?? $automation['uuid'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Automation must be persisted (have an id) before it can be compiled.');
        }

        $hex = str_replace('-', '', $id);
        return substr($hex, 0, 8);

    }//end shortUuid()

    /**
     * Canonical-JSON sha256 hash of the four compiled backends (excludes the
     * `hash` key itself).
     *
     * @param array<string,mixed> $plan The plan (or a live-fetched equivalent).
     *
     * @return string `sha256:<hex>`
     */
    private function hashPlan(array $plan): string
    {
        $hashable = [
            'notifications'       => ($plan['notifications'] ?? []),
            'lifecycleActions'    => ($plan['lifecycleActions'] ?? []),
            'schedules'           => ($plan['schedules'] ?? []),
            'ruleSet'             => ($plan['ruleSet'] ?? null),
            'conditionActionRule' => ($plan['conditionActionRule'] ?? null),
        ];

        return 'sha256:'.hash(algo: 'sha256', data: $this->canonicalise(value: $hashable));

    }//end hashPlan()

    /**
     * Recursively key-sort associative arrays for a byte-stable JSON string;
     * list arrays keep their order (it is semantically meaningful).
     *
     * @param mixed $value The value to canonicalise.
     *
     * @return string Canonical JSON.
     */
    private function canonicalise(mixed $value): string
    {
        return json_encode($this->canonicaliseValue(value: $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    }//end canonicalise()

    /**
     * Recursive helper for {@see canonicalise()}.
     *
     * @param mixed $value The value to canonicalise.
     *
     * @return mixed
     */
    private function canonicaliseValue(mixed $value): mixed
    {
        if (is_array($value) === false) {
            return $value;
        }

        $isList = array_is_list($value);
        if ($isList === true) {
            return array_map(fn ($v) => $this->canonicaliseValue(value: $v), $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonicaliseValue(value: $v);
        }

        return $out;

    }//end canonicaliseValue()

    /**
     * Upsert planned notification entries onto their target schemas, removing
     * any prior-provenance key no longer planned.
     *
     * @param array<int,array<string,mixed>> $planned   Planned `{schema,key,entry}` records.
     * @param array<int,array<string,mixed>> $priorKeys Prior-provenance `{schema,key}` records.
     *
     * @return array<int,array<string,mixed>> The new provenance `notificationKeys` list.
     */
    private function applyNotifications(array $planned, array $priorKeys): array
    {
        $bySchema = [];
        foreach ($planned as $p) {
            $bySchema[(string) $p['schema']][] = $p;
        }

        $priorBySchema = [];
        foreach ($priorKeys as $p) {
            $priorBySchema[(string) ($p['schema'] ?? '')][] = (string) ($p['key'] ?? '');
        }

        $result  = [];
        $schemas = array_unique(array_merge(array_keys($bySchema), array_keys($priorBySchema)));

        foreach ($schemas as $schemaSlug) {
            if ($schemaSlug === '') {
                continue;
            }

            $schema = $this->loadSchema(slug: $schemaSlug);
            if ($schema === null) {
                continue;
            }

            $config = ($schema->getConfiguration() ?? []);
            $map    = (is_array($config['x-openregister-notifications'] ?? null) ? $config['x-openregister-notifications'] : []);

            $keepKeys = array_map(static fn (array $p): string => (string) $p['key'], ($bySchema[$schemaSlug] ?? []));
            foreach (($priorBySchema[$schemaSlug] ?? []) as $oldKey) {
                if (in_array($oldKey, $keepKeys, true) === false) {
                    unset($map[$oldKey]);
                }
            }

            foreach (($bySchema[$schemaSlug] ?? []) as $p) {
                $map[$p['key']] = $p['entry'];
                $result[]       = ['schema' => $schemaSlug, 'key' => $p['key']];
            }

            $config['x-openregister-notifications'] = $map;
            $schema->setConfiguration($config);
            $this->schemaMapper->update($schema);
        }//end foreach

        return $result;

    }//end applyNotifications()

    /**
     * Upsert planned lifecycle actions onto their target schemas/transitions,
     * stripping every marker-tagged action first (handles a transition change
     * between compiles) then re-adding the planned ones.
     *
     * @param string                          $slug         Automation slug (derives the `aut-<slug>` marker).
     * @param array<int,array<string,mixed>>  $planned      Planned `{schema,transition,marker,action}` records.
     * @param array<int,array<string,mixed>>  $priorActions Prior-provenance `{schema,transition,marker}` records.
     *
     * @return array<int,array<string,mixed>> The new provenance `lifecycleActions` list.
     */
    private function applyLifecycleActions(string $slug, array $planned, array $priorActions): array
    {
        $marker = 'aut-'.$slug;

        $plannedBySchema = [];
        foreach ($planned as $p) {
            $plannedBySchema[(string) $p['schema']][] = $p;
        }

        $schemas = array_unique(
            array_merge(
                array_keys($plannedBySchema),
                array_map(static fn (array $p): string => (string) ($p['schema'] ?? ''), $priorActions)
            )
        );

        $result = [];
        foreach ($schemas as $schemaSlug) {
            if ($schemaSlug === '') {
                continue;
            }

            $schema = $this->loadSchema(slug: $schemaSlug);
            if ($schema === null) {
                continue;
            }

            $config    = ($schema->getConfiguration() ?? []);
            $lifecycle = (is_array($config['x-openregister-lifecycle'] ?? null) ? $config['x-openregister-lifecycle'] : null);
            if ($lifecycle === null) {
                continue;
            }

            $transitions = (is_array($lifecycle['transitions'] ?? null) ? $lifecycle['transitions'] : []);

            foreach ($transitions as $tName => $t) {
                $actions = (is_array($t['actions'] ?? null) ? $t['actions'] : []);
                $transitions[$tName]['actions'] = array_values(
                    array_filter(
                        $actions,
                        static fn ($a): bool => is_array($a) === false || ($a['marker'] ?? null) !== $marker
                    )
                );
            }

            foreach (($plannedBySchema[$schemaSlug] ?? []) as $p) {
                $tName = (string) $p['transition'];
                if (isset($transitions[$tName]) === false) {
                    continue;
                }

                $actions   = (is_array($transitions[$tName]['actions'] ?? null) ? $transitions[$tName]['actions'] : []);
                $actions[] = $p['action'];
                $transitions[$tName]['actions'] = $actions;
                $result[] = ['schema' => $schemaSlug, 'transition' => $tName, 'marker' => $marker];
            }

            $lifecycle['transitions']                = $transitions;
            $config['x-openregister-lifecycle']       = $lifecycle;
            $schema->setConfiguration($config);
            $this->schemaMapper->update($schema);
        }//end foreach

        return $result;

    }//end applyLifecycleActions()

    /**
     * Upsert planned schedules entries into the target ApplicationVersion's
     * manifest, removing any prior-provenance id no longer planned.
     *
     * @param string                          $versionUuid      Target ApplicationVersion uuid.
     * @param array<int,array<string,mixed>>  $plannedEntries   Planned schedules entries.
     * @param array<int,string>               $priorScheduleIds Prior-provenance schedule ids.
     *
     * @return array<int,string> The new provenance `scheduleIds` list.
     */
    private function applySchedules(string $versionUuid, array $plannedEntries, array $priorScheduleIds): array
    {
        if ($plannedEntries === [] && $priorScheduleIds === []) {
            return [];
        }

        $version = $this->loadApplicationVersion(uuid: $versionUuid);
        if ($version === null) {
            $this->logger->warning('OpenBuild: AutomationCompilerService could not load ApplicationVersion "'.$versionUuid.'" to apply schedules.');
            return [];
        }

        $manifest  = (is_array($version['manifest'] ?? null) ? $version['manifest'] : []);
        $schedules = (is_array($manifest['schedules'] ?? null) ? $manifest['schedules'] : []);

        $plannedIds = array_map(static fn (array $e): string => (string) $e['id'], $plannedEntries);

        $schedules = array_values(
            array_filter(
                $schedules,
                static function ($s) use ($priorScheduleIds, $plannedIds): bool {
                    $id = (string) ((is_array($s) ? $s['id'] : null) ?? '');
                    return in_array($id, $priorScheduleIds, true) === false || in_array($id, $plannedIds, true) === true;
                }
            )
        );

        foreach ($plannedEntries as $entry) {
            $idx = null;
            foreach ($schedules as $i => $s) {
                if ((is_array($s) ? ($s['id'] ?? null) : null) === $entry['id']) {
                    $idx = $i;
                    break;
                }
            }

            if ($idx !== null) {
                $schedules[$idx] = $entry;
            } else {
                $schedules[] = $entry;
            }
        }

        if ($schedules === []) {
            unset($manifest['schedules']);
        } else {
            $manifest['schedules'] = array_values($schedules);
        }

        $version['manifest'] = $manifest;

        try {
            $this->objectService->saveObject(object: $version, register: self::REGISTER_SLUG, schema: 'applicationVersion', uuid: $versionUuid);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: AutomationCompilerService failed to save schedules onto ApplicationVersion "'.$versionUuid.'": '.$e->getMessage());
        }

        return $plannedIds;

    }//end applySchedules()

    /**
     * Upsert the compiled RuleSet + ConditionActionRule (manual trigger), or
     * remove a prior one when the automation no longer compiles to the rules
     * backend.
     *
     * @param array<string,mixed>|null $ruleSet             Planned RuleSet fields, or null.
     * @param array<string,mixed>|null $conditionActionRule Planned ConditionActionRule fields, or null.
     * @param string|null               $priorRuleSetSlug    Prior-provenance RuleSet slug, if any.
     *
     * @return string|null The applied RuleSet slug, or null.
     */
    private function applyRuleSet(?array $ruleSet, ?array $conditionActionRule, ?string $priorRuleSetSlug): ?string
    {
        if ($ruleSet === null || $conditionActionRule === null) {
            if ($priorRuleSetSlug !== null && $priorRuleSetSlug !== '') {
                $this->removeRuleSet(ruleSetSlug: $priorRuleSetSlug);
            }

            return null;
        }

        $existingRuleSet = $this->findOneObject(schema: RuleEngineService::RULE_SET_SCHEMA, filters: ['slug' => $ruleSet['slug']]);
        try {
            if ($existingRuleSet !== null) {
                $id = (string) ($existingRuleSet['id'] ?? $existingRuleSet['uuid'] ?? '');
                $this->objectService->saveObject(object: $ruleSet, register: self::REGISTER_SLUG, schema: RuleEngineService::RULE_SET_SCHEMA, uuid: $id);
            } else {
                $this->objectService->saveObject(object: $ruleSet, register: self::REGISTER_SLUG, schema: RuleEngineService::RULE_SET_SCHEMA);
            }
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: AutomationCompilerService failed to save RuleSet "'.$ruleSet['slug'].'": '.$e->getMessage());
        }

        $existingRule = $this->findOneObject(schema: RuleEngineService::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $ruleSet['slug']]);
        try {
            if ($existingRule !== null) {
                $id = (string) ($existingRule['id'] ?? $existingRule['uuid'] ?? '');
                $this->objectService->saveObject(object: $conditionActionRule, register: self::REGISTER_SLUG, schema: RuleEngineService::CONDITION_RULE_SCHEMA, uuid: $id);
            } else {
                $this->objectService->saveObject(object: $conditionActionRule, register: self::REGISTER_SLUG, schema: RuleEngineService::CONDITION_RULE_SCHEMA);
            }
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild: AutomationCompilerService failed to save ConditionActionRule for "'.$ruleSet['slug'].'": '.$e->getMessage());
        }

        return (string) $ruleSet['slug'];

    }//end applyRuleSet()

    /**
     * Delete the RuleSet + its ConditionActionRule by slug.
     *
     * @param string $ruleSetSlug The RuleSet slug to remove.
     *
     * @return void
     */
    private function removeRuleSet(string $ruleSetSlug): void
    {
        $rule = $this->findOneObject(schema: RuleEngineService::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $ruleSetSlug]);
        if ($rule !== null) {
            $id = (string) ($rule['id'] ?? $rule['uuid'] ?? '');
            if ($id !== '') {
                $this->deleteObjectQuietly(uuid: $id, schema: RuleEngineService::CONDITION_RULE_SCHEMA);
            }
        }

        $ruleSet = $this->findOneObject(schema: RuleEngineService::RULE_SET_SCHEMA, filters: ['slug' => $ruleSetSlug]);
        if ($ruleSet !== null) {
            $id = (string) ($ruleSet['id'] ?? $ruleSet['uuid'] ?? '');
            if ($id !== '') {
                $this->deleteObjectQuietly(uuid: $id, schema: RuleEngineService::RULE_SET_SCHEMA);
            }
        }

    }//end removeRuleSet()

    /**
     * Best-effort object delete — logs but never throws.
     *
     * @param string $uuid   The object uuid.
     * @param string $schema The schema slug.
     *
     * @return void
     */
    private function deleteObjectQuietly(string $uuid, string $schema): void
    {
        try {
            $this->objectService->deleteObject(uuid: $uuid, register: self::REGISTER_SLUG, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: AutomationCompilerService failed to delete '.$schema.' "'.$uuid.'": '.$e->getMessage());
        }

    }//end deleteObjectQuietly()

    /**
     * Fetch the live notification entries for a set of provenance keys.
     *
     * @param array<int,array<string,mixed>> $keys `{schema,key}` records.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchLiveNotifications(array $keys): array
    {
        $result = [];
        foreach ($keys as $k) {
            $schemaSlug = (string) ($k['schema'] ?? '');
            $key        = (string) ($k['key'] ?? '');
            if ($schemaSlug === '' || $key === '') {
                continue;
            }

            $schema = $this->loadSchema(slug: $schemaSlug);
            if ($schema === null) {
                continue;
            }

            $config = ($schema->getConfiguration() ?? []);
            $map    = (is_array($config['x-openregister-notifications'] ?? null) ? $config['x-openregister-notifications'] : []);

            $result[] = ['schema' => $schemaSlug, 'key' => $key, 'entry' => ($map[$key] ?? null)];
        }

        return $result;

    }//end fetchLiveNotifications()

    /**
     * Fetch the live marker-tagged lifecycle actions.
     *
     * @param string                          $slug   Automation slug.
     * @param array<int,array<string,mixed>>  $marked `{schema,transition}` records.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchLiveLifecycleActions(string $slug, array $marked): array
    {
        $marker = 'aut-'.$slug;
        $result = [];
        foreach ($marked as $m) {
            $schemaSlug = (string) ($m['schema'] ?? '');
            $tName      = (string) ($m['transition'] ?? '');
            if ($schemaSlug === '' || $tName === '') {
                continue;
            }

            $schema = $this->loadSchema(slug: $schemaSlug);
            if ($schema === null) {
                continue;
            }

            $config      = ($schema->getConfiguration() ?? []);
            $lifecycle   = (is_array($config['x-openregister-lifecycle'] ?? null) ? $config['x-openregister-lifecycle'] : []);
            $transitions = (is_array($lifecycle['transitions'] ?? null) ? $lifecycle['transitions'] : []);
            $actions     = (is_array($transitions[$tName]['actions'] ?? null) ? $transitions[$tName]['actions'] : []);

            foreach ($actions as $a) {
                if (is_array($a) === true && ($a['marker'] ?? null) === $marker) {
                    $result[] = ['schema' => $schemaSlug, 'transition' => $tName, 'marker' => $marker, 'action' => $a];
                }
            }
        }

        return $result;

    }//end fetchLiveLifecycleActions()

    /**
     * Fetch the live schedules entries for a set of provenance ids.
     *
     * @param string             $versionUuid Target ApplicationVersion uuid.
     * @param array<int,string>  $ids         Provenance schedule ids.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchLiveSchedules(string $versionUuid, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $version = $this->loadApplicationVersion(uuid: $versionUuid);
        if ($version === null) {
            return [];
        }

        $manifest  = (is_array($version['manifest'] ?? null) ? $version['manifest'] : []);
        $schedules = (is_array($manifest['schedules'] ?? null) ? $manifest['schedules'] : []);

        $result = [];
        foreach ($schedules as $s) {
            $id = (is_array($s) ? ($s['id'] ?? null) : null);
            if (is_string($id) === true && in_array($id, $ids, true) === true) {
                $result[] = $s;
            }
        }

        return $result;

    }//end fetchLiveSchedules()

    /**
     * Fetch the live RuleSet's comparable fields.
     *
     * @param mixed $ruleSetSlug Provenance RuleSet slug, or null.
     *
     * @return array<string,mixed>|null
     */
    private function fetchLiveRuleSet(mixed $ruleSetSlug): ?array
    {
        if (is_string($ruleSetSlug) === false || $ruleSetSlug === '') {
            return null;
        }

        $ruleSet = $this->findOneObject(schema: RuleEngineService::RULE_SET_SCHEMA, filters: ['slug' => $ruleSetSlug]);
        if ($ruleSet === null) {
            return null;
        }

        return [
            'slug'        => (string) ($ruleSet['slug'] ?? ''),
            'naam'        => (string) ($ruleSet['naam'] ?? ''),
            'versie'      => (string) ($ruleSet['versie'] ?? ''),
            'status'      => (string) ($ruleSet['status'] ?? ''),
            'ruleType'    => (string) ($ruleSet['ruleType'] ?? ''),
            'eigenaarApp' => (string) ($ruleSet['eigenaarApp'] ?? ''),
        ];

    }//end fetchLiveRuleSet()

    /**
     * Fetch the live ConditionActionRule's comparable fields.
     *
     * @param mixed $ruleSetSlug Provenance RuleSet slug, or null.
     *
     * @return array<string,mixed>|null
     */
    private function fetchLiveConditionActionRule(mixed $ruleSetSlug): ?array
    {
        if (is_string($ruleSetSlug) === false || $ruleSetSlug === '') {
            return null;
        }

        $rule = $this->findOneObject(schema: RuleEngineService::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $ruleSetSlug]);
        if ($rule === null) {
            return null;
        }

        return [
            'ruleSetId' => (string) ($rule['ruleSetId'] ?? ''),
            'naam'      => (string) ($rule['naam'] ?? ''),
            'conditie'  => (string) ($rule['conditie'] ?? ''),
            'acties'    => (array) ($rule['acties'] ?? []),
            'actief'    => (bool) ($rule['actief'] ?? true),
        ];

    }//end fetchLiveConditionActionRule()

    /**
     * Load a schema entity by slug.
     *
     * @param string $slug The schema slug.
     *
     * @return Schema|null
     */
    private function loadSchema(string $slug): ?Schema
    {
        try {
            return $this->schemaMapper->find($slug, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: AutomationCompilerService could not load schema "'.$slug.'": '.$e->getMessage());
            return null;
        }

    }//end loadSchema()

    /**
     * Load an ApplicationVersion by uuid.
     *
     * @param string $uuid The ApplicationVersion uuid.
     *
     * @return array<string,mixed>|null
     */
    private function loadApplicationVersion(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        try {
            $entity = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: 'applicationVersion');
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: AutomationCompilerService could not load ApplicationVersion "'.$uuid.'": '.$e->getMessage());
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->normalise(object: $entity);

    }//end loadApplicationVersion()

    /**
     * Find a single object by schema + filters in the shared register.
     *
     * @param string              $schema  The schema slug.
     * @param array<string,mixed> $filters Equality filters.
     *
     * @return array<string,mixed>|null
     */
    private function findOneObject(string $schema, array $filters): ?array
    {
        try {
            $results = $this->objectService->findAll(
                config: ['filters' => array_merge(['register' => self::REGISTER_SLUG, 'schema' => $schema], $filters), 'limit' => 1]
            );
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: AutomationCompilerService findOneObject failed for schema "'.$schema.'": '.$e->getMessage());
            return null;
        }

        if (is_array($results) === false || $results === []) {
            return null;
        }

        return $this->normalise(object: $results[0]);

    }//end findOneObject()

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
