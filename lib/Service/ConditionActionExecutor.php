<?php

/**
 * OpenBuild ConditionActionExecutor
 *
 * Evaluates a chain of ConditionActionRules against a payload and executes the
 * actions of each rule whose FEEL condition is true. Rules fire in
 * prioriteit DESC, then salience DESC, then declaration order (design.md
 * Decision 4). Actions run synchronously in declaration order; a failing action
 * aborts the rule's remaining actions unless `continueOnError` is set. In
 * dry-run mode side-effecting actions (send-notification, start-workflow,
 * call-rule-set) are recorded but NOT dispatched; `set-veld` always mutates the
 * working payload because it is in-memory and side-effect-free.
 *
 * Side-effect dispatch (NC notifications, n8n workflows) is delegated to an
 * optional ActionDispatcher callable so this evaluator stays pure and unit
 * testable; the RuleEngineService wires the live dispatcher.
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
 * @spec openspec/changes/business-rules-engine/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use Throwable;

/**
 * Synchronous condition-action rule chain executor.
 */
class ConditionActionExecutor
{

    /**
     * Action types that mutate external state and are skipped on dry-run.
     *
     * @var array<int,string>
     */
    private const SIDE_EFFECT_ACTIONS = ['send-notification', 'start-workflow', 'call-rule-set'];

    /**
     * Constructor.
     *
     * @param ExpressionEvaluator $expressionEvaluator Resolves rule conditions.
     *
     * @return void
     */
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator
    ) {

    }//end __construct()

    /**
     * Execute a set of condition-action rules.
     *
     * @param array<int,array<string,mixed>> $rules      The ConditionActionRule objects.
     * @param array<string,mixed>            $payload    The input payload.
     * @param bool                           $dryRun     When true, skip side-effecting actions.
     * @param callable|null                  $dispatcher Optional fn(string $type, array $params, array $payload): mixed.
     *
     * @return array{triggeredRules:array<int,array<string,mixed>>,result:array<string,mixed>,errors:array<int,string>}
     */
    public function execute(array $rules, array $payload, bool $dryRun=false, ?callable $dispatcher=null): array
    {
        $ordered = $this->sortRules(rules: $rules);

        $triggered = [];
        $errors    = [];
        $working   = $payload;

        foreach ($ordered as $rule) {
            if (($rule['actief'] ?? true) === false) {
                continue;
            }

            $condition = (string) ($rule['conditie'] ?? '');
            try {
                $matches = ($condition === '' || (bool) $this->expressionEvaluator->evaluateExpression($condition, $working) === true);
            } catch (Throwable $e) {
                $errors[] = 'Condition error in rule "'.(string) ($rule['naam'] ?? '').'": '.$e->getMessage();
                continue;
            }

            if ($matches === false) {
                continue;
            }

            $outcome     = $this->runActions(rule: $rule, payload: $working, dryRun: $dryRun, dispatcher: $dispatcher);
            $working     = $outcome['payload'];
            $errors      = array_merge($errors, $outcome['errors']);
            $triggered[] = [
                'id'               => (string) ($rule['naam'] ?? ($rule['ruleSetId'] ?? 'rule')),
                'name'             => (string) ($rule['naam'] ?? ''),
                'actions_executed' => $outcome['executed'],
            ];
        }//end foreach

        return [
            'triggeredRules' => $triggered,
            'result'         => $working,
            'errors'         => $errors,
        ];

    }//end execute()

    /**
     * Run a single rule's actions in declaration order.
     *
     * @param array<string,mixed> $rule       The rule.
     * @param array<string,mixed> $payload    The working payload.
     * @param bool                $dryRun     Whether side effects are suppressed.
     * @param callable|null       $dispatcher Optional dispatcher callable.
     *
     * @return array{payload:array<string,mixed>,executed:array<int,string>,errors:array<int,string>}
     */
    private function runActions(array $rule, array $payload, bool $dryRun, ?callable $dispatcher): array
    {
        $continueOnError = (bool) ($rule['continueOnError'] ?? false);
        $executed        = [];
        $errors          = [];
        $working         = $payload;

        foreach (($rule['acties'] ?? []) as $action) {
            $type   = (string) ($action['type'] ?? '');
            $params = ($action['parameters'] ?? []);

            try {
                if ($type === 'set-veld') {
                    $field = (string) ($params['veld'] ?? '');
                    if ($field !== '') {
                        $working[$field] = ($params['waarde'] ?? null);
                    }

                    $executed[] = $type;
                    continue;
                }

                if (in_array($type, self::SIDE_EFFECT_ACTIONS, true) === true) {
                    if ($dryRun === true) {
                        $executed[] = $type.' (dry-run, skipped)';
                        continue;
                    }

                    if ($dispatcher !== null) {
                        $dispatcher($type, $params, $working);
                    }

                    $executed[] = $type;
                    continue;
                }

                $errors[] = 'Unknown action type "'.$type.'" in rule "'.(string) ($rule['naam'] ?? '').'".';
            } catch (Throwable $e) {
                $errors[] = 'Action "'.$type.'" failed in rule "'.(string) ($rule['naam'] ?? '').'": '.$e->getMessage();
                if ($continueOnError === false) {
                    break;
                }
            }//end try
        }//end foreach

        return [
            'payload'  => $working,
            'executed' => $executed,
            'errors'   => $errors,
        ];

    }//end runActions()

    /**
     * Sort rules by prioriteit DESC, salience DESC, declaration order.
     *
     * @param array<int,array<string,mixed>> $rules The rules to sort.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sortRules(array $rules): array
    {
        $indexed = [];
        foreach (array_values($rules) as $position => $rule) {
            $indexed[] = ['position' => $position, 'rule' => $rule];
        }

        usort(
            $indexed,
            static function (array $a, array $b): int {
                $prioA = (int) ($a['rule']['prioriteit'] ?? 0);
                $prioB = (int) ($b['rule']['prioriteit'] ?? 0);
                if ($prioA !== $prioB) {
                    return ($prioB <=> $prioA);
                }

                $salA = (int) ($a['rule']['salience'] ?? 0);
                $salB = (int) ($b['rule']['salience'] ?? 0);
                if ($salA !== $salB) {
                    return ($salB <=> $salA);
                }

                return ($a['position'] <=> $b['position']);
            }
        );

        return array_map(static fn (array $entry): array => $entry['rule'], $indexed);

    }//end sortRules()
}//end class
