<?php

/**
 * Buildiq ScriptSteps
 *
 * Pure reads of the script steps of an automation: whether it holds one,
 * whether a save changes one, and who wrote the code last.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-only-app-owners-change-script-steps-req-bqss-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

/**
 * The script steps of an automation (logic-script-step D1, D3, D6).
 *
 * Buildiq never evaluates a script: these methods only compare and copy the
 * stored text.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-only-app-owners-change-script-steps-req-bqss-002
 */
final class ScriptSteps {

	/**
	 * The action kind.
	 *
	 * @var string
	 */
	public const ACTION = 'script-step';

	/**
	 * The refusal of a change by anyone but an app owner (REQ-BQSS-002).
	 *
	 * @var string
	 */
	public const OWNER_REFUSAL = 'Only app owners can change a script step.';

	/**
	 * The refusal while the instance does not allow script steps (REQ-BQSS-001).
	 *
	 * @var string
	 */
	public const DISABLED_REFUSAL = 'Script steps are turned off for this instance.';

	/**
	 * The provenance key naming the author of the last code change (D6).
	 *
	 * @var string
	 */
	public const AUTHOR_KEY = 'scriptAuthor';

	/**
	 * The default timeout of a script step, in seconds (D1).
	 *
	 * @var integer
	 */
	public const DEFAULT_TIMEOUT = 10;

	/**
	 * The script steps of a list of actions, in order, reduced to the keys a script step owns.
	 *
	 * @param array<int|string, mixed> $actions The automation's actions.
	 *
	 * @return list<array{code: string, timeoutSeconds: int, note: string}>
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-only-app-owners-change-script-steps-req-bqss-002
	 */
	public static function stepsOf(array $actions): array {
		$steps = [];
		foreach ($actions as $action) {
			if (is_array($action) === false || ($action['type'] ?? null) !== self::ACTION) {
				continue;
			}

			$steps[] = [
				'code' => (string)($action['code'] ?? ''),
				'timeoutSeconds' => (int)($action['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT),
				'note' => (string)($action['note'] ?? ''),
			];
		}

		return $steps;
	}//end stepsOf()

	/**
	 * Whether an automation holds a script step.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public static function holds(array $automation): bool {
		return self::stepsOf(actions: self::actionsOf(automation: $automation)) !== [];
	}//end holds()

	/**
	 * Whether a save adds, changes or removes a script step.
	 *
	 * @param array<string, mixed> $before The stored automation, or [] for a new one.
	 * @param array<string, mixed> $after  The automation as it would be saved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-only-app-owners-change-script-steps-req-bqss-002
	 */
	public static function changed(array $before, array $after): bool {
		return self::stepsOf(actions: self::actionsOf(automation: $before)) !== self::stepsOf(actions: self::actionsOf(automation: $after));
	}//end changed()

	/**
	 * The provenance to save: the author of the last code change, never the client's word for it.
	 *
	 * A save that changes a script names the caller; any other save keeps the
	 * stored author. An author the body sends is dropped.
	 *
	 * @param array<string, mixed> $provenance The provenance the body carries.
	 * @param string               $stored     The stored author, '' for none.
	 * @param string|null          $caller     The caller's uid when the save changes a script, else null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public static function authored(array $provenance, string $stored, ?string $caller): array {
		unset($provenance[self::AUTHOR_KEY]);
		$author = ($caller ?? $stored);
		if ($author !== '') {
			$provenance[self::AUTHOR_KEY] = $author;
		}

		return $provenance;
	}//end authored()

	/**
	 * The automation to save, with the author of its last script change in its provenance (D6).
	 *
	 * A save that changes a script names the caller; any other save keeps the
	 * stored author; the body's own word for it is never taken. An automation
	 * that never had a script keeps its provenance as sent.
	 *
	 * @param array<string, mixed> $payload The automation as it would be saved.
	 * @param array<string, mixed> $before  The stored automation, [] for a new one.
	 * @param string               $caller  The caller's uid.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public static function stamp(array $payload, array $before, string $caller): array {
		$provenance = self::arrayOf(value: $payload['provenance'] ?? null);
		$stored     = (string)(self::arrayOf(value: $before['provenance'] ?? null)[self::AUTHOR_KEY] ?? '');
		$author     = null;
		if (self::changed(before: $before, after: $payload) === true) {
			$author = $caller;
		}

		if ($author === null && $stored === '' && array_key_exists(self::AUTHOR_KEY, $provenance) === false) {
			return $payload;
		}

		$payload['provenance'] = self::authored(provenance: $provenance, stored: $stored, caller: $author);
		return $payload;
	}//end stamp()

	/**
	 * A value when it is an array, else [].
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private static function arrayOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOf()

	/**
	 * The actions of an automation, [] when absent or malformed.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function actionsOf(array $automation): array {
		return self::arrayOf(value: $automation['actions'] ?? null);
	}//end actionsOf()
}//end class
