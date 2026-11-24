<?php

/**
 * Rule Set Condition Nodes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The flow nodes of a rule-set condition (logic-automation-actions-that-run
 * D6): `openregister.decision-table` on the rule set's stored table, writing
 * its one outcome under a working field, then `openregister.filter` that keeps
 * the run going only when that outcome is true. A run no rule matches stops at
 * the filter instead of failing. Pure.
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
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;

/**
 * Builds and refuses the nodes of a rule-set condition.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
 */
final class RuleSetConditionNodes {

	/**
	 * The field the table's outcome is written under, apart from the record's own fields.
	 *
	 * @var string
	 */
	public const RESULT_FIELD = '_condition';

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $ruleSets The resolved rule sets by slug:
	 *                                                      `ruleType`, `tableRef`, `outputs`.
	 */
	public function __construct(
		private readonly array $ruleSets,
	) {
	}//end __construct()

	/**
	 * The decision table and the filter on its outcome.
	 *
	 * @param array<string, mixed> $condition The stored condition: `type` rule-set and `ruleSetSlug`.
	 *
	 * @return list<array<string, mixed>> The two nodes, table first.
	 *
	 * @throws UnsupportedAutomationCombinationException When the rule set is missing, not a table or decides more than one outcome.
	 *
	 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
	 */
	public function build(array $condition): array {
		$slug    = trim((string)($condition['ruleSetSlug'] ?? ''));
		$ruleSet = ($this->ruleSets[$slug] ?? null);
		if ($slug === '' || is_array($ruleSet) === false) {
			throw new UnsupportedAutomationCombinationException(message: 'Pick the rule set the condition runs.');
		}

		$output = self::output(slug: $slug, ruleSet: $ruleSet);
		$field  = self::RESULT_FIELD . '.' . $output;

		return [
			[
				'id' => 'condition-table',
				'type' => 'openregister.decision-table',
				'config' => [
					'tableRef' => self::tableRef(slug: $slug, ruleSet: $ruleSet),
					'outputMapping' => [$output => $field],
					'defaultOutputs' => [$output => false],
				],
			],
			[
				'id' => 'condition',
				'type' => 'openregister.filter',
				'config' => ['condition' => ['===' => [['var' => 'json.' . $field], true]]],
			],
		];
	}//end build()

	/**
	 * The stored table of a decision table rule set.
	 *
	 * @param string               $slug    The rule set's slug.
	 * @param array<string, mixed> $ruleSet The resolved rule set.
	 *
	 * @return string The table's key.
	 *
	 * @throws UnsupportedAutomationCombinationException For a condition-action rule set or one without a table.
	 */
	private static function tableRef(string $slug, array $ruleSet): string {
		if ((string)($ruleSet['ruleType'] ?? '') !== 'decision-table') {
			throw new UnsupportedAutomationCombinationException(
				message: 'The rule set "' . $slug . '" is not a decision table; a condition can only run a decision table.'
			);
		}

		$tableRef = (string)($ruleSet['tableRef'] ?? '');
		if ($tableRef === '') {
			throw new UnsupportedAutomationCombinationException(message: 'The rule set "' . $slug . '" has no decision table to run.');
		}

		return $tableRef;
	}//end tableRef()

	/**
	 * The one outcome a condition reads.
	 *
	 * @param string               $slug    The rule set's slug.
	 * @param array<string, mixed> $ruleSet The resolved rule set.
	 *
	 * @return string The output column's name.
	 *
	 * @throws UnsupportedAutomationCombinationException When the table decides no outcome or more than one.
	 */
	private static function output(string $slug, array $ruleSet): string {
		$outputs = array_values(array_filter(array_map('strval', (array)($ruleSet['outputs'] ?? [])), static fn (string $name): bool => $name !== ''));
		if (count($outputs) !== 1) {
			self::tableRef(slug: $slug, ruleSet: $ruleSet);
			throw new UnsupportedAutomationCombinationException(
				message: 'The rule set "' . $slug . '" decides ' . count($outputs) . ' outputs; a condition needs a table that decides one, true or false.'
			);
		}

		return $outputs[0];
	}//end output()
}//end class
