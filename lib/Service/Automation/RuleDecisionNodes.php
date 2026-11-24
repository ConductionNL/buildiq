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
	 * Constructor.
	 *
	 * @param string                              $register The register the outcome is written to.
	 * @param string                              $schema   The schema of the record that started the run, or ''.
	 * @param array<string, array<string, mixed>> $ruleSets The resolved rule sets, by slug.
	 */
	public function __construct(
		private readonly string $register,
		private readonly string $schema,
		private readonly array $ruleSets,
	) {
	}//end __construct()

	/**
	 * The two nodes of a rule decision: the rule set's stored decision table,
	 * then the write of its outcome onto the record that started the run (D7).
	 *
	 * @param array<string, mixed> $action The action.
	 * @param int                  $index  Its position.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws UnsupportedAutomationCombinationException For a missing, condition-action or tableless rule set, or no record to write to.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
	 */
	public function build(array $action, int $index): array {
		[$slug, $ruleSet] = $this->ruleSet(action: $action);
		$tableRef = self::tableRef(slug: $slug, ruleSet: $ruleSet);
		if ($this->schema === '') {
			throw new UnsupportedAutomationCombinationException(
				message: 'A rule decision writes onto the record that started the automation, so its trigger must name a schema.'
			);
		}

		$outputMapping = self::outputMapping(action: $action, ruleSet: $ruleSet);
		$inputMapping = array_map('strval', (array)($action['inputMapping'] ?? []));
		ksort($inputMapping);
		$id = 'step-' . ($index + 1);

		return [
			[
				'id' => $id,
				'type' => 'openregister.decision-table',
				'config' => ['tableRef' => $tableRef, 'inputMapping' => $inputMapping, 'outputMapping' => $outputMapping],
			],
			$this->writeNode(id: $id . '-write', outputMapping: $outputMapping),
		];
	}//end build()

	/**
	 * The slug and the resolved rule set an action names.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array{0: string, 1: array<string, mixed>}
	 *
	 * @throws UnsupportedAutomationCombinationException When no known rule set is picked.
	 */
	private function ruleSet(array $action): array {
		$slug = trim((string)($action['ruleSetSlug'] ?? ''));
		$ruleSet = ($this->ruleSets[$slug] ?? null);
		if ($slug === '' || is_array($ruleSet) === false) {
			throw new UnsupportedAutomationCombinationException(message: 'Pick a rule set for the rule decision step.');
		}

		return [$slug, $ruleSet];
	}//end ruleSet()

	/**
	 * The stored decision table of a rule set.
	 *
	 * @param string               $slug    The rule set slug.
	 * @param array<string, mixed> $ruleSet The rule set.
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException For a condition-action or tableless rule set.
	 */
	private static function tableRef(string $slug, array $ruleSet): string {
		if ((string)($ruleSet['ruleType'] ?? '') !== 'decision-table') {
			throw new UnsupportedAutomationCombinationException(
				message: 'The rule set "' . $slug . '" is not a decision table; a step can only decide with a decision table.'
			);
		}

		$tableRef = (string)($ruleSet['tableRef'] ?? '');
		if ($tableRef === '') {
			throw new UnsupportedAutomationCombinationException(message: 'The rule set "' . $slug . '" has no decision table to run.');
		}

		return $tableRef;
	}//end tableRef()

	/**
	 * The write of the table outcome onto the record that started the run.
	 *
	 * @param string                $id            The node id.
	 * @param array<string, string> $outputMapping Output column => field.
	 *
	 * @return array<string, mixed>
	 */
	private function writeNode(string $id, array $outputMapping): array {
		$fields = [];
		foreach ($outputMapping as $field) {
			$fields[$field] = '{{json.' . $field . '}}';
		}

		ksort($fields);

		return [
			'id' => $id,
			'type' => 'openregister.object-write',
			'config' => [
				'register' => $this->register,
				'schema' => $this->schema,
				'operation' => 'update',
				'match' => ['uuid' => '{{json.uuid}}'],
				'fields' => $fields,
			],
		];
	}//end writeNode()

	/**
	 * Where each table output is written: the action's own mapping, or its one
	 * output field when the table has exactly one output (D7).
	 *
	 * @param array<string, mixed> $action  The action.
	 * @param array<string, mixed> $ruleSet The rule set, with the table's output column names in `outputs`.
	 *
	 * @return array<string, string>
	 *
	 * @throws UnsupportedAutomationCombinationException When it is not clear where an output goes.
	 */
	private static function outputMapping(array $action, array $ruleSet): array {
		$mapping = array_filter(array_map('strval', (array)($action['outputMapping'] ?? [])), static fn (string $field): bool => $field !== '');
		if ($mapping !== []) {
			ksort($mapping);
			return $mapping;
		}

		$field = trim((string)($action['outputField'] ?? ''));
		if ($field === '') {
			throw new UnsupportedAutomationCombinationException(message: 'Pick the field the rule decision is written to.');
		}

		$outputs = array_values((array)($ruleSet['outputs'] ?? []));
		if (count($outputs) !== 1) {
			$slug = trim((string)($action['ruleSetSlug'] ?? ''));
			throw new UnsupportedAutomationCombinationException(
				message: 'The rule set "' . $slug . '" decides ' . count($outputs) . ' outputs; say which field each one is written to.'
			);
		}

		return [(string)$outputs[0] => $field];
	}//end outputMapping()
}//end class
