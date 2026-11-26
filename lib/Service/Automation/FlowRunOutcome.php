<?php

/**
 * Flow Run Outcome
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Reads how an OpenRegister flow run of a compiled automation ended, as the
 * automation-run record states it (logic-automation-run-log D1/D2, the flow
 * path): the run's outcome and reason, and one step per automation action.
 * The compiler names the flow node of action N `step-N` (with `step-N-...`
 * helper nodes for the AI step and decision-table write-backs), so a step's
 * outcome is the outcome of the nodes under that id in the run's log. Pure:
 * the caller reads the run and the automation.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

/**
 * Maps a finished flow run onto an automation run's outcome and steps.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */
final class FlowRunOutcome {

	/**
	 * The flow run states that ended well (`stopped` only when OpenRegister
	 * already reads it as completed, see FlowRun::getOutcome()).
	 *
	 * @var array<int, string>
	 */
	private const SUCCEEDED = ['completed'];

	/**
	 * The reason of a failed run whose flow run gives none.
	 *
	 * @var string
	 */
	public const NO_REASON = 'The flow run failed.';

	/**
	 * The message of a step the run never reached.
	 *
	 * @var string
	 */
	public const NOT_REACHED = 'Not reached.';

	/**
	 * The outcome, reason and steps of one finished flow run.
	 *
	 * @param string                           $status  The flow run's outcome (FlowRun::getOutcome()).
	 * @param string|null                      $error   The flow run's error, if any.
	 * @param array<int, mixed>                $log     The flow run's log: one entry per node execution.
	 * @param array<int, array<string, mixed>> $actions The automation's actions, in order.
	 *
	 * @return array{outcome: string, reason: string|null, durationMs: int, steps: list<array{action: string, outcome: string, message: string}>}
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public static function fromFlowRun(string $status, ?string $error, array $log, array $actions): array {
		$succeeded = in_array($status, self::SUCCEEDED, true);
		$reason    = null;
		if ($succeeded === false) {
			$reason = self::reasonOf(error: $error, log: $log);
		}

		$steps = [];
		foreach (array_values($actions) as $index => $action) {
			$type = '';
			if (is_array($action) === true) {
				$type = (string)($action['type'] ?? '');
			}

			$steps[] = self::stepOf(action: $type, entries: self::entriesOf(log: $log, nodeId: 'step-' . ($index + 1)));
		}

		$outcome = 'failed';
		if ($succeeded === true) {
			$outcome = 'succeeded';
		}

		return [
			'outcome' => $outcome,
			'reason' => $reason,
			'durationMs' => self::durationOf(log: $log),
			'steps' => $steps,
		];
	}//end fromFlowRun()

	/**
	 * How long the run's nodes took together: the sum of their `durationMs`.
	 *
	 * @param array<int, mixed> $log The flow run's log.
	 *
	 * @return int
	 */
	private static function durationOf(array $log): int {
		$total = 0;
		foreach ($log as $entry) {
			if (is_array($entry) === true && is_numeric($entry['durationMs'] ?? null) === true) {
				$total += max(0, (int)$entry['durationMs']);
			}
		}

		return $total;
	}//end durationOf()

	/**
	 * The log entries of one action's node and its helper nodes.
	 *
	 * @param array<int, mixed> $log    The flow run's log.
	 * @param string            $nodeId The action's node id (`step-N`).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function entriesOf(array $log, string $nodeId): array {
		$entries = [];
		foreach ($log as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$transition = (string)($entry['transition'] ?? '');
			if ($transition === $nodeId || str_starts_with($transition, $nodeId . '-') === true) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end entriesOf()

	/**
	 * One step: failed when any of its nodes failed, succeeded when they ran,
	 * skipped when the run never reached it.
	 *
	 * @param string                     $action  The action type.
	 * @param list<array<string, mixed>> $entries Its log entries.
	 *
	 * @return array{action: string, outcome: string, message: string}
	 */
	private static function stepOf(string $action, array $entries): array {
		if ($entries === []) {
			return ['action' => $action, 'outcome' => 'skipped', 'message' => self::NOT_REACHED];
		}

		foreach ($entries as $entry) {
			$status = (string)($entry['status'] ?? '');
			if (in_array($status, self::SUCCEEDED, true) === false && $status !== 'stopped') {
				return ['action' => $action, 'outcome' => 'failed', 'message' => (string)($entry['error'] ?? '')];
			}
		}

		return ['action' => $action, 'outcome' => 'succeeded', 'message' => ''];
	}//end stepOf()

	/**
	 * Why a run failed: the run's own error, else the first failed node's.
	 *
	 * @param string|null       $error The flow run's error.
	 * @param array<int, mixed> $log   The flow run's log.
	 *
	 * @return string
	 */
	private static function reasonOf(?string $error, array $log): string {
		if ($error !== null && trim($error) !== '') {
			return $error;
		}

		foreach ($log as $entry) {
			if (is_array($entry) === true && trim((string)($entry['error'] ?? '')) !== '') {
				return (string)$entry['error'];
			}
		}

		return self::NO_REASON;
	}//end reasonOf()
}//end class
