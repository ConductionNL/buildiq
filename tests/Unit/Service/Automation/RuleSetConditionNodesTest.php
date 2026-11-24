<?php

/**
 * Unit tests for RuleSetConditionNodes: a rule-set condition as a decision
 * table and a filter on its one outcome, and the rule sets it refuses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\RuleSetConditionNodes;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RuleSetConditionNodes.
 *
 * @covers \OCA\Buildiq\Service\Automation\RuleSetConditionNodes
 * @uses \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException
 */
final class RuleSetConditionNodesTest extends TestCase {

	/**
	 * The nodes over these rule sets.
	 *
	 * @return RuleSetConditionNodes
	 */
	private static function nodes(): RuleSetConditionNodes {
		return new RuleSetConditionNodes(ruleSets: [
			'large-claim' => ['ruleType' => 'decision-table', 'tableRef' => 'buildiq.large-claim', 'outputs' => ['', 'large']],
			'two-out' => ['ruleType' => 'decision-table', 'tableRef' => 'buildiq.two-out', 'outputs' => ['a', 'b']],
			'checks' => ['ruleType' => 'condition-action', 'tableRef' => 'x', 'outputs' => ['ok']],
			'no-table' => ['ruleType' => 'decision-table', 'tableRef' => '', 'outputs' => ['ok']],
			'not-read' => 'unreadable',
		]);
	}//end nodes()

	/**
	 * The table writes its outcome under the working field, false when no rule
	 * matches, and the filter keeps the run only for a strict true.
	 *
	 * @return void
	 */
	public function testTheTableDecidesAndTheFilterReadsItsOutcome(): void {
		self::assertSame(
			expected: [
				[
					'id' => 'condition-table',
					'type' => 'openregister.decision-table',
					'config' => [
						'tableRef' => 'buildiq.large-claim',
						'outputMapping' => ['large' => '_condition.large'],
						'defaultOutputs' => ['large' => false],
					],
				],
				[
					'id' => 'condition',
					'type' => 'openregister.filter',
					'config' => ['condition' => ['===' => [['var' => 'json._condition.large'], true]]],
				],
			],
			actual: self::nodes()->build(condition: ['type' => 'rule-set', 'ruleSetSlug' => ' large-claim '])
		);
	}//end testTheTableDecidesAndTheFilterReadsItsOutcome()

	/**
	 * Each rule set a condition cannot run is refused with its reason.
	 *
	 * @return void
	 */
	public function testRefusesWhatAConditionCannotRun(): void {
		$cases = [
			'Pick the rule set the condition runs.' => ['ruleSetSlug' => ''],
			'Pick the rule set the condition runs. ' => ['ruleSetSlug' => 'missing'],
			'Pick the rule set the condition runs.  ' => ['ruleSetSlug' => 'not-read'],
			'The rule set "checks" is not a decision table; a condition can only run a decision table.' => ['ruleSetSlug' => 'checks'],
			'The rule set "no-table" has no decision table to run.' => ['ruleSetSlug' => 'no-table'],
			'The rule set "two-out" decides 2 outputs; a condition needs a table that decides one, true or false.' => ['ruleSetSlug' => 'two-out'],
		];
		foreach ($cases as $message => $condition) {
			try {
				self::nodes()->build(condition: ['type' => 'rule-set'] + $condition);
				self::fail(message: 'Expected a refusal: ' . $message);
			} catch (UnsupportedAutomationCombinationException $e) {
				self::assertSame(expected: trim(string: $message), actual: $e->getMessage());
			}
		}
	}//end testRefusesWhatAConditionCannotRun()
}//end class
