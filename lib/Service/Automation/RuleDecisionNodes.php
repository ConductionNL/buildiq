<?php

/**
 * Rule Decision Nodes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The flow nodes of a "Decide with a rule set" step
 * (logic-automation-actions-that-run D7): `openregister.decision-table` on the
 * rule set's stored table, then `openregister.object-write` of its outcome
 * onto the record that started the run. Pure.
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
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;

/**
 * Builds and refuses the nodes of a rule decision.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */
final class RuleDecisionNodes {

	/**
	 * The two nodes of a rule decision: the rule set's stored decision table,
	 * then the write of its outcome onto the record that started the run (D7).
	 *
	 * @param array<string, mixed>                $action   The action.
	 * @param int                                 $index    Its position.
	 * @param CompileInput                        $input    The automation.
	 * @param string                              $register The register.
	 * @param array<string, array<string, mixed>> $ruleSets The resolved rule sets, by slug.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws UnsupportedAutomationCombinationException For a missing, condition-action or tableless rule set, or no record to write to.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
	 */
	public static function build(array $action, int $index, CompileInput $input, string $register, array $ruleSets): array {
		$slug = trim((string)($action['ruleSetSlug'] ?? ''));
		$ruleSet = ($ruleSets[$slug] ?? null);
		if ($slug === '' || is_array($ruleSet) === false) {
			throw new UnsupportedAutomationCombinationException(message: 'Pick a rule set for the rule decision step.');
		}

		if ((string)($ruleSet['ruleType'] ?? '') !== 'decision-table') {
			throw new UnsupportedAutomationCombinationException(
				message: 'The rule set "' . $slug . '" is not a decision table; a step can only decide with a decision table.'
			);
		}

		$tableRef = (string)($ruleSet['tableRef'] ?? '');
		if ($tableRef === '') {
			throw new UnsupportedAutomationCombinationException(message: 'The rule set "' . $slug . '" has no decision table to run.');
		}

		$schema = (string)($input->trigger['schema'] ?? '');
		if ($schema === '') {
			throw new UnsupportedAutomationCombinationException(
				message: 'A rule decision writes onto the record that started the automation, so its trigger must name a schema.'
			);
		}

		$outputMapping = self::outputMapping(action: $action, outputs: array_values((array)($ruleSet['outputs'] ?? [])), slug: $slug);
		$fields = [];
		foreach ($outputMapping as $field) {
			$fields[$field] = '{{json.' . $field . '}}';
		}

		ksort($fields);
		$inputMapping = array_map('strval', (array)($action['inputMapping'] ?? []));
		ksort($inputMapping);
		$id = 'step-' . ($index + 1);

		return [
			[
				'id' => $id,
				'type' => 'openregister.decision-table',
				'config' => ['tableRef' => $tableRef, 'inputMapping' => $inputMapping, 'outputMapping' => $outputMapping],
			],
			[
				'id' => $id . '-write',
				'type' => 'openregister.object-write',
				'config' => [
					'register' => $register,
					'schema' => $schema,
					'operation' => 'update',
					'match' => ['uuid' => '{{json.uuid}}'],
					'fields' => $fields,
				],
			],
		];
	}//end build()

	/**
	 * Where each table output is written: the action's own mapping, or its one
	 * output field when the table has exactly one output (D7).
	 *
	 * @param array<string, mixed> $action  The action.
	 * @param list<mixed>          $outputs The table's output column names.
	 * @param string               $slug    The rule set slug.
	 *
	 * @return array<string, string>
	 *
	 * @throws UnsupportedAutomationCombinationException When it is not clear where an output goes.
	 */
	private static function outputMapping(array $action, array $outputs, string $slug): array {
		$mapping = array_filter(array_map('strval', (array)($action['outputMapping'] ?? [])), static fn (string $field): bool => $field !== '');
		if ($mapping !== []) {
			ksort($mapping);
			return $mapping;
		}

		$field = trim((string)($action['outputField'] ?? ''));
		if ($field === '') {
			throw new UnsupportedAutomationCombinationException(message: 'Pick the field the rule decision is written to.');
		}

		if (count($outputs) !== 1) {
			throw new UnsupportedAutomationCombinationException(
				message: 'The rule set "' . $slug . '" decides ' . count($outputs) . ' outputs; say which field each one is written to.'
			);
		}

		return [(string)$outputs[0] => $field];
	}//end outputMapping()
}//end class
