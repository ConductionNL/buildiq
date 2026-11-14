<?php

/**
 * Buildiq RuleEvaluationListener
 *
 * Answers RuleEvaluationRequestedEvent, the typed command another app's
 * server dispatches to evaluate a rule set (design D2 of
 * rules-evaluation-for-other-apps):
 *
 * 1. resolve the active rule set and check the app is in its `callableBy`
 *    (`unknown-rule-set`, `not-allowed`);
 * 2. check the inputs against the declared ones and the payload size guard
 *    (`invalid-input`); undeclared inputs are dropped;
 * 3. evaluate with every action suppressed, under the 500 ms bound (`timeout`);
 * 4. one execution log line names the calling app and the version;
 * 5. answer the result, the triggered rule ids and the version.
 *
 * It never throws to the caller: an unexpected failure is `evaluation-failed`.
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
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Event\RuleEvaluationRequestedEvent;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\RuleSetColumns;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates a rule set for another app.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
 */
class RuleEvaluationListener implements IEventListener {

	/**
	 * The largest input payload, encoded as JSON: the bound RulesController puts on the API.
	 */
	public const MAX_INPUT_BYTES = 65536;

	/**
	 * Constructor.
	 *
	 * @param RuleEngineService    $engine The rule engine.
	 * @param StoredDecisionTables $tables A rule set's decision table, for its declared inputs.
	 * @param LoggerInterface      $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleEngineService $engine,
		private readonly StoredDecisionTables $tables,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer a RuleEvaluationRequestedEvent; any other event is ignored.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof RuleEvaluationRequestedEvent) === false || $event->isAnswered() === true) {
			return;
		}

		try {
			$this->evaluate(command: $event);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: rule set "{ruleSet}" could not be evaluated for {app}',
				['ruleSet' => $event->getRuleSet(), 'app' => $event->getApp(), 'exception' => $e->getMessage()]
			);
			$event->refuse(reason: RuleEvaluationRequestedEvent::EVALUATION_FAILED);
		}

	}//end handle()

	/**
	 * The steps of design D2.
	 *
	 * @param RuleEvaluationRequestedEvent $command The command.
	 *
	 * @return void
	 */
	private function evaluate(RuleEvaluationRequestedEvent $command): void {
		$resolved = $this->engine->resolveForApp(app: $command->getApp(), slug: $command->getRuleSet());
		if ($resolved['status'] !== 'ok' || $resolved['ruleSet'] === null) {
			$command->refuse(reason: $resolved['status']);
			return;
		}

		$inputs = $this->checkedInputs(ruleSet: $resolved['ruleSet'], inputs: $command->getInputs());
		if ($inputs === null) {
			$command->refuse(reason: RuleEvaluationRequestedEvent::INVALID_INPUT);
			return;
		}

		$outcome = $this->engine->evaluateForApp(app: $command->getApp(), ruleSet: $resolved['ruleSet'], payload: $inputs);
		if ($outcome['executionTime'] > RuleEngineService::TIMEOUT_MS) {
			$command->refuse(reason: RuleEvaluationRequestedEvent::TIMEOUT);
			return;
		}

		if ($outcome['errors'] !== []) {
			$command->refuse(reason: RuleEvaluationRequestedEvent::EVALUATION_FAILED);
			return;
		}

		$command->answer(
			result: $outcome['result'],
			triggeredRules: array_map('strval', $outcome['triggeredRules']),
			version: $outcome['version']
		);

	}//end evaluate()

	/**
	 * The inputs checked against the rule set's declared inputs, or null when they are refused.
	 *
	 * @param array<string,mixed> $ruleSet The resolved rule set.
	 * @param array<string,mixed> $inputs  What the caller sent.
	 *
	 * @return array<string,mixed>|null
	 */
	private function checkedInputs(array $ruleSet, array $inputs): ?array {
		$encoded = json_encode($inputs);
		if ($encoded === false || strlen($encoded) > self::MAX_INPUT_BYTES) {
			return null;
		}

		$tables = $this->tables->forRuleSet(ruleSet: $ruleSet);
		$columns = RuleSetColumns::fromTable(table: (array)($tables[0] ?? []));

		return RuleSetColumns::checkInputs(columns: $columns['inputs'], inputs: $inputs);

	}//end checkedInputs()
}//end class
