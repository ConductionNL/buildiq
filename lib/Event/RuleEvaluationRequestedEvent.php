<?php

/**
 * Buildiq RuleEvaluationRequestedEvent
 *
 * The typed command another app's server dispatches to have buildiq evaluate
 * a rule set it is allowed to call (REQ-BQRX-002). The calling app, the rule
 * set slug and the inputs go in; the outputs, the triggered rule ids and the
 * version come out, or a refusal. Buildiq's listener never throws to the
 * caller: whatever goes wrong becomes a refusal on the event.
 *
 * Usage from another app:
 *
 *     $command = new RuleEvaluationRequestedEvent(app: 'portaliq', ruleSet: 'parkeren-soort-vergunning', inputs: [...]);
 *     $dispatcher->dispatchTyped($command);
 *     $answer = $command->getAnswer(); // null when buildiq is not installed
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\Buildiq\Event
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

namespace OCA\Buildiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Another app asks buildiq for a rule set's decision.
 *
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
 */
class RuleEvaluationRequestedEvent extends Event {

	public const UNKNOWN_RULE_SET = 'unknown-rule-set';

	public const NOT_ALLOWED = 'not-allowed';

	public const INVALID_INPUT = 'invalid-input';

	public const TIMEOUT = 'timeout';

	public const EVALUATION_FAILED = 'evaluation-failed';

	/**
	 * Every refusal the command can answer.
	 *
	 * @var array<int,string>
	 */
	public const REFUSALS = [
		self::UNKNOWN_RULE_SET,
		self::NOT_ALLOWED,
		self::INVALID_INPUT,
		self::TIMEOUT,
		self::EVALUATION_FAILED,
	];

	/**
	 * The answer, once a listener gave one.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $answer = null;

	/**
	 * Constructor.
	 *
	 * @param string              $app     The calling app id.
	 * @param string              $ruleSet The rule set slug.
	 * @param array<string,mixed> $inputs  The inputs, by input name.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $app,
		private readonly string $ruleSet,
		private readonly array $inputs,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The calling app id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function getApp(): string {
		return $this->app;

	}//end getApp()

	/**
	 * The rule set slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function getRuleSet(): string {
		return $this->ruleSet;

	}//end getRuleSet()

	/**
	 * The inputs the caller sent.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function getInputs(): array {
		return $this->inputs;

	}//end getInputs()

	/**
	 * Answer the evaluation's outcome.
	 *
	 * @param array<string,mixed> $result         The outputs, by output name.
	 * @param array<int,string>   $triggeredRules The ids of the rules that matched.
	 * @param string              $version        The rule set version that answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function answer(array $result, array $triggeredRules, string $version): void {
		$this->answer = [
			'ok' => true,
			'result' => $result,
			'triggeredRules' => array_values($triggeredRules),
			'version' => $version,
		];

	}//end answer()

	/**
	 * Refuse the evaluation. A reason outside {@see REFUSALS} is answered as `evaluation-failed`.
	 *
	 * @param string $reason One of the REFUSALS.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function refuse(string $reason): void {
		if (in_array($reason, self::REFUSALS, true) === false) {
			$reason = self::EVALUATION_FAILED;
		}

		$this->answer = ['ok' => false, 'reason' => $reason];

	}//end refuse()

	/**
	 * Whether a listener answered or refused.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function isAnswered(): bool {
		return $this->answer !== null;

	}//end isAnswered()

	/**
	 * The answer: `{ok: true, result, triggeredRules, version}` or `{ok: false, reason}`;
	 * null when nothing answered (buildiq is not installed or not enabled).
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function getAnswer(): ?array {
		return $this->answer;

	}//end getAnswer()
}//end class
