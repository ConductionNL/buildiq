<?php

/**
 * Unit tests for the node of a script step.
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
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\ScriptStepNodes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A script step becomes one code node named by the catalogue, or is refused.
 */
#[CoversClass(ScriptStepNodes::class)]
final class ScriptStepNodesTest extends TestCase {

	/**
	 * The pure checks find a script step in actions and a code node in a flow.
	 *
	 * @return void
	 */
	public function testFindsScriptStepsAndCodeNodes(): void {
		$this->assertTrue(condition: ScriptStepNodes::holdsScriptStep(actions: [['type' => 'webhook'], ['type' => 'script-step']]));
		$this->assertFalse(condition: ScriptStepNodes::holdsScriptStep(actions: [['type' => 'webhook'], 'junk']));
		$this->assertTrue(condition: ScriptStepNodes::flowRunsCode(flow: ['nodes' => [['type' => 'openregister.code']]]));
		$this->assertFalse(condition: ScriptStepNodes::flowRunsCode(flow: ['nodes' => [['type' => 'openregister.filter']]]));
		$this->assertFalse(condition: ScriptStepNodes::flowRunsCode(flow: []));
	}//end testFindsScriptStepsAndCodeNodes()

	/**
	 * The node takes its type from the catalogue entry and its position from the step.
	 *
	 * @return void
	 */
	public function testBuildsTheNodeTheCatalogueNames(): void {
		$node = (new ScriptStepNodes(catalogueEntry: ['id' => 'openregister.code']))->build(
			action: ['type' => 'script-step', 'code' => 'return items;'],
			index: 2
		);

		$this->assertSame(expected: 'step-3', actual: $node['id']);
		$this->assertSame(expected: 'openregister.code', actual: $node['type']);
		$this->assertSame(expected: 10000, actual: $node['config']['timeoutMs']);
		$this->assertSame(expected: [], actual: $node['config']['egress']);
	}//end testBuildsTheNodeTheCatalogueNames()

	/**
	 * Without a catalogue entry the step is refused, naming the runner.
	 *
	 * @return void
	 */
	public function testRefusesWithoutTheCatalogueEntry(): void {
		$this->expectException(exception: UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(message: 'flow-code-runner');
		(new ScriptStepNodes(catalogueEntry: null))->build(action: ['code' => 'return items;'], index: 0);
	}//end testRefusesWithoutTheCatalogueEntry()

	/**
	 * A timeout that is not a whole number of seconds is refused.
	 *
	 * @return void
	 */
	public function testRefusesATimeoutThatIsNoWholeNumber(): void {
		$this->expectException(exception: UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(message: '1 to 30 seconds');
		$nodes = new ScriptStepNodes(catalogueEntry: ['id' => 'openregister.code']);
		$nodes->build(action: ['code' => 'return items;', 'timeoutSeconds' => '10'], index: 0);
	}//end testRefusesATimeoutThatIsNoWholeNumber()
}//end class
