<?php

/**
 * Unit tests for FlowRunOutcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Service\Automation\FlowRunOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * How a finished flow run reads as an automation run.
 */
#[CoversClass(FlowRunOutcome::class)]
final class FlowRunOutcomeTest extends TestCase {

	private const ACTIONS = [
		['type' => 'object-op', 'operation' => 'update'],
		['type' => 'ai-step'],
		['type' => 'webhook'],
	];

	/**
	 * A completed run succeeds, each action's node succeeded, and the
	 * duration is the sum of the node durations (the trigger included).
	 *
	 * @return void
	 */
	public function testACompletedRunSucceedsWithOneStepPerAction(): void {
		$log = [
			['transition' => 'trigger', 'status' => 'completed', 'durationMs' => 33],
			['transition' => 'step-1', 'status' => 'completed', 'durationMs' => 278],
			['transition' => 'step-2', 'status' => 'completed', 'durationMs' => 20],
			['transition' => 'step-2-write', 'status' => 'completed', 'durationMs' => 5],
			['transition' => 'step-3', 'status' => 'completed'],
		];

		$this->assertSame(
			expected: [
				'outcome' => 'succeeded',
				'reason' => null,
				'durationMs' => 336,
				'steps' => [
					['action' => 'object-op', 'outcome' => 'succeeded', 'message' => ''],
					['action' => 'ai-step', 'outcome' => 'succeeded', 'message' => ''],
					['action' => 'webhook', 'outcome' => 'succeeded', 'message' => ''],
				],
			],
			actual: FlowRunOutcome::fromFlowRun(status: 'completed', error: null, log: $log, actions: self::ACTIONS)
		);
	}//end testACompletedRunSucceedsWithOneStepPerAction()

	/**
	 * A failing node fails its step with the node's error, later steps were
	 * not reached, and the run fails with the run's own error.
	 *
	 * @return void
	 */
	public function testAFailingNodeFailsItsStepAndTheRest(): void {
		$log = [
			['transition' => 'trigger', 'status' => 'completed', 'durationMs' => 30],
			['transition' => 'step-1', 'status' => 'completed', 'durationMs' => 10],
			['transition' => 'step-2', 'status' => 'completed', 'durationMs' => 10],
			['transition' => 'step-2-write', 'status' => 'failed', 'error' => 'An update matched no object.', 'durationMs' => 8],
		];

		$result = FlowRunOutcome::fromFlowRun(status: 'failed', error: 'An update matched no object.', log: $log, actions: self::ACTIONS);

		$this->assertSame(expected: 'failed', actual: $result['outcome']);
		$this->assertSame(expected: 'An update matched no object.', actual: $result['reason']);
		$this->assertSame(
			expected: [
				['action' => 'object-op', 'outcome' => 'succeeded', 'message' => ''],
				['action' => 'ai-step', 'outcome' => 'failed', 'message' => 'An update matched no object.'],
				['action' => 'webhook', 'outcome' => 'skipped', 'message' => FlowRunOutcome::NOT_REACHED],
			],
			actual: $result['steps']
		);
	}//end testAFailingNodeFailsItsStepAndTheRest()

	/**
	 * A failed run without its own error takes the first node error, else a
	 * fixed sentence; `step-1` does not claim the nodes of `step-10`.
	 *
	 * @return void
	 */
	public function testAFailedRunAlwaysHasAReason(): void {
		$fromNode = FlowRunOutcome::fromFlowRun(
			status: 'dead_letter',
			error: ' ',
			log: [['transition' => 'step-10', 'status' => 'failed', 'error' => 'Webhook answered 500'], 'not an entry'],
			actions: [['type' => 'object-op']]
		);
		$this->assertSame(expected: 'Webhook answered 500', actual: $fromNode['reason']);
		$this->assertSame(expected: 'skipped', actual: $fromNode['steps'][0]['outcome']);

		$none = FlowRunOutcome::fromFlowRun(status: 'stopped', error: null, log: [], actions: []);
		$this->assertSame(expected: ['outcome' => 'failed', 'reason' => FlowRunOutcome::NO_REASON, 'durationMs' => 0, 'steps' => []], actual: $none);
	}//end testAFailedRunAlwaysHasAReason()
}//end class
