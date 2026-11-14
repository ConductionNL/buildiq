<?php

/**
 * Unit tests for AutomationFlowBuilder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The flow one automation compiles to: which automations become a flow, the
 * linear shape, the trigger mapping and the lifecycle refusal, the record step
 * limited to the app's schemas, the webhook through a source, and the FEEL
 * condition as a filter (logic-automation-actions-that-run D1 to D6).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\AutomationFlowBuilder;
use OCA\Buildiq\Service\Automation\CompileInput;
use OCA\Buildiq\Service\FeelParser;
use OCA\Buildiq\Service\FeelToJsonLogic;
use PHPUnit\Framework\TestCase;

/**
 * Selection, shape, triggers, steps, conditions and refusals.
 *
 * @covers \OCA\Buildiq\Service\Automation\AutomationFlowBuilder
 * @uses \OCA\Buildiq\Service\Automation\CompileInput
 * @uses \OCA\Buildiq\Service\FeelToJsonLogic
 * @uses \OCA\Buildiq\Service\FeelParser
 * @uses \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException
 */
final class AutomationFlowBuilderTest extends TestCase {

	/**
	 * The builder.
	 *
	 * @return AutomationFlowBuilder
	 */
	private function builder(): AutomationFlowBuilder {
		return new AutomationFlowBuilder(feel: new FeelToJsonLogic(parser: new FeelParser()));
	}//end builder()

	/**
	 * An automation.
	 *
	 * @param array<string, mixed>       $trigger   The trigger.
	 * @param list<array<string, mixed>> $actions   The actions.
	 * @param mixed                      $condition The condition.
	 *
	 * @return CompileInput
	 */
	private static function input(array $trigger, array $actions, mixed $condition = null): CompileInput {
		return new CompileInput(automation: ['slug' => 'inspect-permit', 'trigger' => $trigger, 'actions' => $actions, 'condition' => $condition]);
	}//end input()

	/**
	 * A create step on the inspectie schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function createStep(): array {
		return ['type' => 'object-op', 'operation' => 'create', 'schema' => 'inspectie', 'fieldMapping' => ['permit' => '{{id}}', 'status' => 'open']];
	}//end createStep()

	/**
	 * A manual trigger or any flow action makes a flow; a notification on an
	 * object event does not.
	 *
	 * @return void
	 */
	public function testWhichAutomationsBecomeAFlow(): void {
		$builder = $this->builder();

		self::assertTrue($builder->wantsFlow(self::input(['type' => 'manual'], [['type' => 'send-notification']])));
		self::assertTrue($builder->wantsFlow(self::input(['type' => 'object-created', 'schema' => 'permit'], [self::createStep()])));
		self::assertFalse($builder->wantsFlow(self::input(['type' => 'object-created', 'schema' => 'permit'], [['type' => 'send-notification']])));
		self::assertFalse($builder->wantsFlow(self::input(['type' => 'schedule'], [['type' => 'run-synchronization']])));
	}//end testWhichAutomationsBecomeAFlow()

	/**
	 * A new permit creates an inspection: the object trigger on the version's
	 * register, then the record step, linked in order, named aut-<slug>.
	 *
	 * @return void
	 */
	public function testANewPermitCreatesAnInspectionRecord(): void {
		$flow = $this->builder()->build(self::input(['type' => 'object-created', 'schema' => 'permit'], [self::createStep()]), 'buildiq-permits-v1', ['permit', 'inspectie']);

		self::assertSame('aut-inspect-permit', $flow['name']);
		self::assertSame(['id' => 'trigger', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'created', 'register' => 'buildiq-permits-v1', 'schema' => 'permit']], $flow['nodes'][0]);
		self::assertSame(['id' => 'step-1', 'type' => 'openregister.object-write', 'config' => ['register' => 'buildiq-permits-v1', 'schema' => 'inspectie', 'operation' => 'create', 'fields' => ['permit' => '{{json.id}}', 'status' => 'open']]], $flow['nodes'][1]);
		self::assertSame([['id' => 'e1', 'from' => ['trigger'], 'to' => ['step-1']]], $flow['edges']);
	}//end testANewPermitCreatesAnInspectionRecord()

	/**
	 * An update names the record it changes; without it, it is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateNamesTheRecordItChanges(): void {
		$step = ['type' => 'object-op', 'operation' => 'update', 'schema' => 'permit', 'fieldMapping' => ['status' => 'checked'], 'match' => ['uuid' => '{{uuid}}']];
		$flow = $this->builder()->build(self::input(['type' => 'manual'], [$step]), 'reg', ['permit']);

		self::assertSame(['uuid' => '{{json.uuid}}'], $flow['nodes'][1]['config']['match']);
		self::assertSame('openregister.trigger-manual', $flow['nodes'][0]['type']);

		unset($step['match']);
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('which record it changes');
		$this->builder()->build(self::input(['type' => 'manual'], [$step]), 'reg', ['permit']);
	}//end testAnUpdateNamesTheRecordItChanges()

	/**
	 * A target schema from another app is refused and named.
	 *
	 * @return void
	 */
	public function testATargetSchemaFromAnotherAppIsRefused(): void {
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('"inspectie", which is not a schema of this app');
		$this->builder()->build(self::input(['type' => 'manual'], [self::createStep()]), 'reg', ['permit']);
	}//end testATargetSchemaFromAnotherAppIsRefused()

	/**
	 * A record step on a lifecycle transition is refused with the reason.
	 *
	 * @return void
	 */
	public function testARecordStepOnALifecycleTransitionIsRefused(): void {
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(AutomationFlowBuilder::TRANSITION_REFUSAL);
		$this->builder()->build(self::input(['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'approve'], [self::createStep()]), 'reg', ['permit', 'inspectie']);
	}//end testARecordStepOnALifecycleTransitionIsRefused()

	/**
	 * A webhook posts through a source; a bare URL is refused.
	 *
	 * @return void
	 */
	public function testAWebhookCallsAConfiguredSource(): void {
		$step = ['type' => 'webhook', 'source' => 'planning', 'path' => '/intake', 'payloadTemplate' => ['id' => '{{id}}']];
		$flow = $this->builder()->build(self::input(['type' => 'object-created', 'schema' => 'request'], [$step]), 'reg', ['request']);

		self::assertSame(['id' => 'step-1', 'type' => 'openconnector.source-call', 'config' => ['source' => 'planning', 'endpoint' => '/intake', 'method' => 'POST', 'body' => ['id' => '{{id}}']]], $flow['nodes'][1]);

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(AutomationFlowBuilder::BARE_URL_REFUSAL);
		$this->builder()->build(self::input(['type' => 'manual'], [['type' => 'webhook', 'url' => 'https://example.com/hook']]), 'reg', []);
	}//end testAWebhookCallsAConfiguredSource()

	/**
	 * Only large claims start the step: the FEEL condition becomes a filter
	 * between the trigger and the step; an untranslatable one is refused.
	 *
	 * @return void
	 */
	public function testAFeelConditionGatesTheSteps(): void {
		$flow = $this->builder()->build(self::input(['type' => 'object-created', 'schema' => 'claim'], [self::createStep()], ['type' => 'feel', 'expression' => 'amount > 1000']), 'reg', ['claim', 'inspectie']);

		self::assertSame('openregister.filter', $flow['nodes'][1]['type']);
		self::assertSame((new FeelToJsonLogic(parser: new FeelParser()))->translate('amount > 1000'), $flow['nodes'][1]['config']['condition']);
		self::assertSame([['trigger'], ['condition']], array_column($flow['edges'], 'from'));

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('cannot run in a flow');
		$this->builder()->build(self::input(['type' => 'manual'], [], ['type' => 'feel', 'expression' => '1..5']), 'reg', []);
	}//end testAFeelConditionGatesTheSteps()

	/**
	 * An approval or a document step cannot share a flow, and a rule-set
	 * condition is refused until its decision table compiles.
	 *
	 * @return void
	 */
	public function testMixesTheFlowCannotRunAreRefused(): void {
		$refused = [];
		foreach ([
			self::input(['type' => 'manual'], [self::createStep(), ['type' => 'approval']]),
			self::input(['type' => 'manual'], [['type' => 'generateDocument']]),
			self::input(['type' => 'manual'], [], ['type' => 'rule-set', 'ruleSetSlug' => 'fees']),
		] as $input) {
			try {
				$this->builder()->build($input, 'reg', ['inspectie']);
			} catch (UnsupportedAutomationCombinationException $e) {
				$refused[] = $e->getMessage();
			}
		}

		self::assertCount(3, $refused);
		self::assertStringContainsString('"approval" step cannot share', $refused[0]);
		self::assertStringContainsString('rule-set condition', $refused[2]);
	}//end testMixesTheFlowCannotRunAreRefused()

	/**
	 * The same automation builds the same document twice.
	 *
	 * @return void
	 */
	public function testRecompilingIsIdentical(): void {
		$input = self::input(['type' => 'object-updated', 'schema' => 'permit'], [self::createStep(), ['type' => 'send-notification', 'channels' => ['nc-notification']]]);

		self::assertSame(
			json_encode($this->builder()->build($input, 'reg', ['permit', 'inspectie'])),
			json_encode($this->builder()->build($input, 'reg', ['inspectie', 'permit']))
		);
	}//end testRecompilingIsIdentical()
}//end class
