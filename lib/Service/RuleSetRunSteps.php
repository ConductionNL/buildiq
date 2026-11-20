<?php

/**
 * Rule Set Run Steps
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reads what one wet run of a condition-action rule set did: a step per
 * compiled action, the reason of a skipped run and the first failure.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

/**
 * The steps and reasons of a rule set run, read from its rules and the engine's errors.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */
final class RuleSetRunSteps {

	/**
	 * One step per compiled action, in rule order.
	 *
	 * @param array<int, mixed>  $rules  The condition-action rules.
	 * @param array<int, string> $errors The engine's errors.
	 *
	 * @return list<array{action: string, outcome: string, message: string}>
	 */
	public function stepsOf(array $rules, array $errors): array {
		$steps = [];
		foreach ($rules as $rule) {
			if (is_array($rule) === false || ($rule['active'] ?? true) === false) {
				continue;
			}

			$stopped = false;
			foreach ((array)($rule['actions'] ?? []) as $action) {
				$type = '';
				if (is_array($action) === true) {
					$type = (string)($action['type'] ?? '');
				}

				if ($stopped === true) {
					$steps[] = ['action' => $type, 'outcome' => 'skipped', 'message' => 'Not run: an earlier action failed.'];
					continue;
				}

				$message = $this->failureOf(type: $type, rule: (string)($rule['name'] ?? ''), errors: $errors);
				if ($message === null) {
					$steps[] = ['action' => $type, 'outcome' => 'succeeded', 'message' => ''];
					continue;
				}

				$steps[] = ['action' => $type, 'outcome' => 'failed', 'message' => $message];
				$stopped = ((bool)($rule['continueOnError'] ?? false) === false);
			}
		}//end foreach

		return $steps;
	}//end stepsOf()

	/**
	 * The message the executor gave for a failed action, or null.
	 *
	 * @param string             $type   The action type.
	 * @param string             $rule   The rule name.
	 * @param array<int, string> $errors The engine's errors.
	 *
	 * @return string|null
	 */
	private function failureOf(string $type, string $rule, array $errors): ?string {
		$prefix = 'Action "' . $type . '" failed in rule "' . $rule . '": ';
		foreach ($errors as $error) {
			if (str_starts_with($error, $prefix) === true) {
				return substr($error, strlen($prefix));
			}
		}

		return null;
	}//end failureOf()

	/**
	 * The reason of a skipped run: which condition did not hold.
	 *
	 * @param array<int, mixed> $rules The condition-action rules.
	 *
	 * @return string
	 */
	public function falseConditionReason(array $rules): string {
		$conditions = [];
		foreach ($rules as $rule) {
			if (is_array($rule) === true && ($rule['active'] ?? true) !== false && (string)($rule['condition'] ?? '') !== '') {
				$conditions[] = (string)$rule['condition'];
			}
		}

		if ($conditions === []) {
			return 'No rule of the automation is active.';
		}

		return 'The condition was false: ' . implode('; ', $conditions) . '.';
	}//end falseConditionReason()

	/**
	 * The message of the first failed step, or null.
	 *
	 * @param array<int, array{action: string, outcome: string, message: string}> $steps The steps.
	 *
	 * @return string|null
	 */
	public function firstFailure(array $steps): ?string {
		foreach ($steps as $step) {
			if (($step['outcome'] ?? '') === 'failed') {
				return (string)($step['message'] ?? '');
			}
		}

		return null;
	}//end firstFailure()
}//end class
