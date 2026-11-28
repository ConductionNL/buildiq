<?php

/**
 * Buildiq AutomationRunLog
 *
 * Turns what one buildiq dispatch point did into one automation-run record
 * through the AutomationRunRecorder: the rule engine's run of an automation's
 * `aut-` rule set, an approval being requested, the decision on it, and a
 * generated document. Every method swallows its own failure: a run that
 * cannot be recorded is logged and the automation carries on (design D2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records the runs buildiq dispatches.
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */
final class AutomationRunLog {

	/**
	 * The prefix of a rule set an automation compiled.
	 */
	public const RULE_SET_PREFIX = 'aut-';

	/**
	 * The step an approval run waits on.
	 */
	public const APPROVAL_STEP = 'approval requested';

	/**
	 * The most automations one lookup reads (ADR-078: bounded).
	 */
	private const AUTOMATION_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param AutomationRunRecorderInterface $recorder      Writes and reads the run records.
	 * @param ObjectServiceInterface         $objectService Finds the automation an `aut-` rule set belongs to.
	 * @param LoggerInterface                $logger        Logs a run that could not be recorded.
	 * @param RuleSetRunSteps                $steps         Reads the steps and reasons of a rule set run.
	 *
	 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function __construct(
		private readonly AutomationRunRecorderInterface $recorder,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
		private readonly RuleSetRunSteps $steps=new RuleSetRunSteps(),
	) {
	}//end __construct()

	/**
	 * Record a wet run of an automation's `aut-` rule set (the `manual` trigger).
	 *
	 * The run is `skipped` when no rule's condition held, `failed` when a
	 * condition or an action failed, and `succeeded` otherwise. Each compiled
	 * action is one step: an action whose error the executor reported failed
	 * with that message, the actions after it were not run, the rest succeeded.
	 * A rule set no automation owns is not recorded.
	 *
	 * @param string                    $ruleSetSlug The rule set that ran.
	 * @param array<string, mixed>      $payload     The input; only the subject's uuid is read from it.
	 * @param array<string, mixed>      $outcome     The engine's answer: triggeredRules and errors.
	 * @param array<int, mixed>         $rules       The rule set's condition-action rules.
	 * @param string                    $actor       The uid that ran it, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automation-designer/spec.md#scenario-a-manual-run-with-a-failing-action-is-recorded-with-the-failure
	 * @spec openspec/specs/automation-designer/spec.md#scenario-a-false-condition-is-a-skipped-run-with-its-reason
	 */
	public function ruleSetRan(string $ruleSetSlug, array $payload, array $outcome, array $rules, string $actor): void {
		if (str_starts_with($ruleSetSlug, self::RULE_SET_PREFIX) === false) {
			return;
		}

		try {
			$automation = $this->automationForRuleSet(ruleSetSlug: $ruleSetSlug);
			if ($automation === null) {
				return;
			}

			$run    = $this->recorder->start(
				automation: $automation,
				trigger: 'manual',
				subject: self::subjectOf(automation: $automation, payload: $payload),
				actor: $actor
			);
			$errors = array_values(array_map('strval', (array)($outcome['errors'] ?? [])));

			if ((array)($outcome['triggeredRules'] ?? []) === [] && $errors === []) {
				$this->recorder->finish(run: $run, outcome: 'skipped', reason: $this->steps->falseConditionReason(rules: $rules));
				return;
			}

			foreach ($this->steps->stepsOf(rules: $rules, errors: $errors) as $step) {
				$run = $this->recorder->step(run: $run, action: $step['action'], outcome: $step['outcome'], message: $step['message']);
			}

			if ($errors !== []) {
				$this->recorder->finish(run: $run, outcome: 'failed', reason: $errors[0]);
				return;
			}

			$this->recorder->finish(run: $run, outcome: 'succeeded');
		} catch (Throwable $e) {
			$this->failed(automation: $ruleSetSlug, error: $e);
		}//end try
	}//end ruleSetRan()

	/**
	 * Record that an automation asked for approval on an object: a `waiting`
	 * run with the step "approval requested".
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param string               $trigger    The trigger that fired it.
	 * @param string               $schemaSlug The object's schema.
	 * @param string               $objectUuid The object.
	 * @param string               $actor      The uid that caused the trigger, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automation-designer/spec.md#scenario-an-approval-run-waits-and-is-completed-by-the-decision
	 */
	public function approvalRequested(array $automation, string $trigger, string $schemaSlug, string $objectUuid, string $actor): void {
		try {
			$run = $this->recorder->start(
				automation: $automation,
				trigger: $trigger,
				subject: ['schema' => $schemaSlug, 'uuid' => $objectUuid],
				actor: $actor
			);
			$run = $this->recorder->step(run: $run, action: self::APPROVAL_STEP, outcome: 'waiting');
			$this->recorder->finish(run: $run, outcome: 'waiting');
		} catch (Throwable $e) {
			$this->failed(automation: (string)($automation['slug'] ?? ''), error: $e);
		}
	}//end approvalRequested()

	/**
	 * Complete the waiting run of an automation on an object with the decision
	 * and each follow-up action. The run fails when a follow-up failed. With no
	 * waiting run (one recorded before this log existed, or already pruned) a
	 * new run is written, so the decision is not lost.
	 *
	 * @param array<string, mixed>                                          $automation The automation.
	 * @param string                                                        $objectUuid The object the approval was on.
	 * @param bool                                                          $approved   Whether it was approved.
	 * @param array<int, array{action: string, outcome: string, message: string}> $followUps  What each follow-up did.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automation-designer/spec.md#scenario-an-approval-run-waits-and-is-completed-by-the-decision
	 */
	public function approvalDecided(array $automation, string $objectUuid, bool $approved, array $followUps): void {
		$decision = 'Rejected';
		if ($approved === true) {
			$decision = 'Approved';
		}

		$steps   = array_merge([['action' => 'approval', 'outcome' => 'succeeded', 'message' => $decision]], $followUps);
		$failure = $this->steps->firstFailure(steps: $steps);
		$outcome = 'succeeded';
		if ($failure !== null) {
			$outcome = 'failed';
		}

		try {
			$waiting = $this->waitingRun(automationUuid: self::idOf(automation: $automation), objectUuid: $objectUuid);
			if ($waiting !== null) {
				$this->recorder->complete(runUuid: $waiting, outcome: $outcome, steps: $steps, reason: $failure);
				return;
			}

			$run = $this->recorder->start(
				automation: $automation,
				trigger: (string)($automation['trigger']['type'] ?? 'lifecycle-transition'),
				subject: ['schema' => (string)($automation['trigger']['schema'] ?? ''), 'uuid' => $objectUuid],
				actor: ''
			);
			foreach ($steps as $step) {
				$run = $this->recorder->step(run: $run, action: $step['action'], outcome: $step['outcome'], message: $step['message']);
			}

			$this->recorder->finish(run: $run, outcome: $outcome, reason: $failure);
		} catch (Throwable $e) {
			$this->failed(automation: (string)($automation['slug'] ?? ''), error: $e);
		}//end try
	}//end approvalDecided()

	/**
	 * Record one document an automation generated on an object, or the
	 * reason it could not.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param string               $trigger    The trigger that fired it.
	 * @param string               $schemaSlug The object's schema.
	 * @param string               $objectUuid The object.
	 * @param string|null          $error      Why generation failed, or null when it succeeded.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function documentGenerated(array $automation, string $trigger, string $schemaSlug, string $objectUuid, ?string $error): void {
		try {
			$run = $this->recorder->start(
				automation: $automation,
				trigger: $trigger,
				subject: ['schema' => $schemaSlug, 'uuid' => $objectUuid],
				actor: ''
			);
			if ($error === null) {
				$run = $this->recorder->step(run: $run, action: 'generateDocument', outcome: 'succeeded', message: 'Document generated');
				$this->recorder->finish(run: $run, outcome: 'succeeded');
				return;
			}

			$run = $this->recorder->step(run: $run, action: 'generateDocument', outcome: 'failed', message: $error);
			$this->recorder->finish(run: $run, outcome: 'failed', reason: $error);
		} catch (Throwable $e) {
			$this->failed(automation: (string)($automation['slug'] ?? ''), error: $e);
		}
	}//end documentGenerated()

	/**
	 * The automation whose provenance names this rule set, or null.
	 *
	 * @param string $ruleSetSlug The `aut-` rule set slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function automationForRuleSet(string $ruleSetSlug): ?array {
		$found = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => AutomationCompilerServiceInterface::REGISTER_SLUG,
					'schema' => AutomationCompilerServiceInterface::AUTOMATION_SCHEMA,
				],
				'limit' => self::AUTOMATION_LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ((array)($found['results'] ?? $found) as $row) {
			$automation = ObjectRecord::castArray(object: $row);
			$provenance = ($automation['provenance'] ?? []);
			if (is_array($provenance) === true && ($provenance['ruleSetSlug'] ?? null) === $ruleSetSlug) {
				$automation['id'] = self::idOf(automation: $automation);
				return $automation;
			}
		}

		return null;
	}//end automationForRuleSet()

	/**
	 * The uuid of the newest waiting run of an automation on an object.
	 *
	 * @param string $automationUuid The automation.
	 * @param string $objectUuid     The object.
	 *
	 * @return string|null
	 */
	private function waitingRun(string $automationUuid, string $objectUuid): ?string {
		if ($automationUuid === '' || $objectUuid === '') {
			return null;
		}

		$page = $this->recorder->runsOf(automationUuid: $automationUuid, filters: ['outcome' => 'waiting'], limit: 100);
		foreach ((array)($page['results'] ?? []) as $run) {
			if (is_array($run) === false) {
				continue;
			}

			if (($run['outcome'] ?? null) === 'waiting' && (string)($run['subject']['uuid'] ?? '') === $objectUuid) {
				return (string)($run['id'] ?? '');
			}
		}

		return null;
	}//end waitingRun()

	/**
	 * The subject of a manual run: the automation's schema and the uuid the payload names.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param array<string, mixed> $payload    The input.
	 *
	 * @return array{schema: string, uuid: string}|null
	 */
	private static function subjectOf(array $automation, array $payload): ?array {
		$self = $payload['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$uuid = (string)($self['id'] ?? ($payload['uuid'] ?? ($payload['id'] ?? '')));
		if ($uuid === '') {
			return null;
		}

		return ['schema' => (string)($automation['trigger']['schema'] ?? ''), 'uuid' => $uuid];
	}//end subjectOf()

	/**
	 * An automation's uuid.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private static function idOf(array $automation): string {
		return (string)(ObjectRecord::uuid(record: $automation) ?? ($automation['id'] ?? ($automation['uuid'] ?? '')));
	}//end idOf()

	/**
	 * Log a run that could not be recorded.
	 *
	 * @param string    $automation What names the automation.
	 * @param Throwable $error      Why.
	 *
	 * @return void
	 */
	private function failed(string $automation, Throwable $error): void {
		$this->logger->error(
			'Buildiq: the run of automation ' . $automation . ' could not be recorded: ' . $error->getMessage(),
			['exception' => $error]
		);
	}//end failed()
}//end class
