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
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
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
 * @uses \OCA\Buildiq\Service\Automation\InboundTriggerDeclarations
 * @uses \OCA\Buildiq\Service\FeelToJsonLogic
 * @uses \OCA\Buildiq\Service\FeelParser
 * @uses \OCA\Buildiq\Service\Automation\RuleDecisionNodes
 * @uses \OCA\Buildiq\Service\Automation\RuleSetConditionNodes
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
		self::assertSame(
			expected: [
				'id' => 'trigger',
				'type' => 'openregister.trigger-object',
				'config' => ['event' => 'object.created', 'register' => 'buildiq-permits-v1', 'schema' => 'permit'],
			],
			actual: $flow['nodes'][0]
		);
		self::assertSame(
			expected: [
				'id' => 'step-1',
				'type' => 'openregister.object-write',
				'config' => [
					'register' => 'buildiq-permits-v1',
					'schema' => 'inspectie',
					'operation' => 'create',
					'fields' => ['permit' => '{{id}}', 'status' => 'open'],
				],
				'exit' => true,
			],
			actual: $flow['nodes'][1]
		);
		self::assertSame([['id' => 'e1', 'from' => ['trigger'], 'to' => ['step-1']]], $flow['edges']);
	}//end testANewPermitCreatesAnInspectionRecord()

	/**
	 * An object-write template reads the item's record itself: `{{uuid}}`
	 * compiles to the record's `id` (OpenRegister renders the uuid there), a
	 * `json.` prefix is dropped, and every other placeholder keeps its name.
	 *
	 * @return void
	 */
	public function testTheRecordUuidPlaceholderReadsTheItemId(): void {
		$step = [
			'type' => 'object-op',
			'operation' => 'update',
			'schema' => 'permit',
			'fieldMapping' => [
				'note' => 'Checked {{ uuid }} for {{applicant}}',
				'ref' => '{{json.uuid}}',
				'legacy' => '{{json.applicant}}',
				'uuidCopy' => '{{uuidCopy}}',
				'fixed' => 'checked',
			],
			'match' => ['uuid' => '{{ uuid }}'],
		];
		$flow = $this->builder()->build(self::input(['type' => 'manual'], [$step]), 'reg', ['permit']);

		self::assertSame(['uuid' => '{{id}}'], $flow['nodes'][1]['config']['match']);
		self::assertSame(
			[
				'fixed' => 'checked',
				'legacy' => '{{applicant}}',
				'note' => 'Checked {{id}} for {{applicant}}',
				'ref' => '{{id}}',
				'uuidCopy' => '{{uuidCopy}}',
			],
			$flow['nodes'][1]['config']['fields']
		);
	}//end testTheRecordUuidPlaceholderReadsTheItemId()

	/**
	 * An update names the record it changes; without it, it is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateNamesTheRecordItChanges(): void {
		$step = ['type' => 'object-op', 'operation' => 'update', 'schema' => 'permit', 'fieldMapping' => ['status' => 'checked'], 'match' => ['uuid' => '{{uuid}}']];
		$flow = $this->builder()->build(self::input(['type' => 'manual'], [$step]), 'reg', ['permit']);

		self::assertSame(['uuid' => '{{id}}'], $flow['nodes'][1]['config']['match']);
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

		self::assertSame(
			expected: [
				'id' => 'step-1',
				'type' => 'openconnector.source-call',
				'config' => ['source' => 'planning', 'endpoint' => '/intake', 'method' => 'POST', 'body' => ['id' => '{{id}}']],
				'exit' => true,
			],
			actual: $flow['nodes'][1]
		);

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
	 * An approval or a document step cannot share a flow, and a condition
	 * of an unknown type is refused.
	 *
	 * @return void
	 */
	public function testMixesTheFlowCannotRunAreRefused(): void {
		$refused = [];
		foreach ([
			self::input(['type' => 'manual'], [self::createStep(), ['type' => 'approval']]),
			self::input(['type' => 'manual'], [['type' => 'generateDocument']]),
			self::input(['type' => 'manual'], [], ['type' => 'script', 'expression' => 'x']),
		] as $input) {
			try {
				$this->builder()->build($input, 'reg', ['inspectie']);
			} catch (UnsupportedAutomationCombinationException $e) {
				$refused[] = $e->getMessage();
			}
		}

		self::assertCount(3, $refused);
		self::assertStringContainsString('"approval" step cannot share', $refused[0]);
		self::assertSame('Unknown condition type "script".', $refused[2]);
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
	/**
	 * The resolved rule sets the rule-decision cases use.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function ruleSets(): array {
		return [
			'leges-categorie' => ['ruleType' => 'decision-table', 'version' => '1.0.0', 'tableRef' => 'buildiq.leges-categorie', 'outputs' => ['categorie']],
			'two-out' => ['ruleType' => 'decision-table', 'version' => '1.0.0', 'tableRef' => 'buildiq.two-out', 'outputs' => ['a', 'b']],
			'checks' => ['ruleType' => 'condition-action', 'version' => '1.0.0', 'tableRef' => '', 'outputs' => []],
			'empty' => ['ruleType' => 'decision-table', 'version' => '1.0.0', 'tableRef' => '', 'outputs' => []],
		];
	}//end ruleSets()

	/**
	 * REQ-BQAR-005: "Decide with a rule set" on aanvraag created runs the
	 * stored table and writes its decision onto the same record.
	 *
	 * @return void
	 */
	public function testARuleDecisionRunsTheTableAndWritesTheOutcome(): void {
		$action = ['type' => 'rule-decision', 'ruleSetSlug' => 'leges-categorie', 'inputMapping' => ['type' => 'soort', 'oppervlakte' => 'm2'], 'outputField' => 'categorie'];
		$input = self::input(['type' => 'object-created', 'schema' => 'aanvraag'], [$action]);

		self::assertTrue($this->builder()->wantsFlow($input));
		self::assertSame(['leges-categorie'], $this->builder()->ruleSetSlugs($input));

		$flow = $this->builder()->build($input, 'openbuild-leges-main', ['aanvraag'], self::ruleSets());

		self::assertSame(
			[
				[
					'id' => 'step-1',
					'type' => 'openregister.decision-table',
					'config' => [
						'tableRef' => 'buildiq.leges-categorie',
						'inputMapping' => ['oppervlakte' => 'm2', 'type' => 'soort'],
						'outputMapping' => ['categorie' => 'categorie'],
					],
				],
				[
					'id' => 'step-1-write',
					'type' => 'openregister.object-write',
					'config' => [
						'register' => 'openbuild-leges-main',
						'schema' => 'aanvraag',
						'operation' => 'update',
						'match' => ['uuid' => '{{id}}'],
						'fields' => ['categorie' => '{{categorie}}'],
					],
					'exit' => true,
				],
			],
			array_slice($flow['nodes'], 1)
		);
		self::assertSame(['trigger', 'step-1', 'step-1-write'], array_column($flow['nodes'], 'id'));
		self::assertCount(2, $flow['edges']);
	}//end testARuleDecisionRunsTheTableAndWritesTheOutcome()

	/**
	 * REQ-BQAR-005: a condition-action rule set, a missing one, one without a
	 * table, an unclear output and a trigger without a schema are refused.
	 *
	 * @return void
	 */
	public function testARuleDecisionRefusesWhatItCannotRun(): void {
		$cases = [
			'The rule set "checks" is not a decision table; a step can only decide with a decision table.' => ['ruleSetSlug' => 'checks', 'outputField' => 'x'],
			'Pick a rule set for the rule decision step.' => ['ruleSetSlug' => 'gone', 'outputField' => 'x'],
			'The rule set "empty" has no decision table to run.' => ['ruleSetSlug' => 'empty', 'outputField' => 'x'],
			'The rule set "two-out" decides 2 outputs; say which field each one is written to.' => ['ruleSetSlug' => 'two-out', 'outputField' => 'x'],
			'Pick the field the rule decision is written to.' => ['ruleSetSlug' => 'leges-categorie'],
		];
		foreach ($cases as $message => $action) {
			try {
				$this->builder()->build(self::input(['type' => 'object-created', 'schema' => 'aanvraag'], [['type' => 'rule-decision'] + $action]), 'r', ['aanvraag'], self::ruleSets());
				self::fail('Expected a refusal: ' . $message);
			} catch (UnsupportedAutomationCombinationException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('A rule decision writes onto the record that started the automation, so its trigger must name a schema.');
		$this->builder()->build(self::input(['type' => 'manual'], [['type' => 'rule-decision', 'ruleSetSlug' => 'leges-categorie', 'outputField' => 'c']]), 'r', [], self::ruleSets());
	}//end testARuleDecisionRefusesWhatItCannotRun()

	/**
	 * An explicit output mapping names each output's field.
	 *
	 * @return void
	 */
	public function testARuleDecisionWithAnOutputMapping(): void {
		$action = ['type' => 'rule-decision', 'ruleSetSlug' => 'two-out', 'outputMapping' => ['b' => 'fieldB', 'a' => 'fieldA']];
		$flow = $this->builder()->build(self::input(['type' => 'object-updated', 'schema' => 'aanvraag'], [$action]), 'r', ['aanvraag'], self::ruleSets());

		self::assertSame(['a' => 'fieldA', 'b' => 'fieldB'], $flow['nodes'][1]['config']['outputMapping']);
		self::assertSame(['fieldA' => '{{fieldA}}', 'fieldB' => '{{fieldB}}'], $flow['nodes'][2]['config']['fields']);
	}//end testARuleDecisionWithAnOutputMapping()

	/**
	 * REQ-BQAR-004: a rule-set condition runs its decision table and filters on
	 * the outcome, so the steps run only when the table decides true; no
	 * matching rule decides false instead of failing the run.
	 *
	 * @return void
	 */
	public function testARuleSetConditionGatesTheSteps(): void {
		$input = self::input(['type' => 'object-created', 'schema' => 'aanvraag'], [self::createStep()], ['type' => 'rule-set', 'ruleSetSlug' => 'leges-categorie']);

		self::assertSame(['leges-categorie'], $this->builder()->ruleSetSlugs($input));

		$flow = $this->builder()->build($input, 'r', ['aanvraag', 'inspectie'], self::ruleSets());

		self::assertSame(['trigger', 'condition-table', 'condition', 'step-1'], array_column($flow['nodes'], 'id'));
		self::assertSame(
			[
				'type' => 'openregister.decision-table',
				'config' => [
					'tableRef' => 'buildiq.leges-categorie',
					'outputMapping' => ['categorie' => '_condition.categorie'],
					'defaultOutputs' => ['categorie' => false],
				],
			],
			array_diff_key($flow['nodes'][1], ['id' => true])
		);
		self::assertSame(
			['type' => 'openregister.filter', 'config' => ['condition' => ['===' => [['var' => 'json._condition.categorie'], true]]]],
			array_diff_key($flow['nodes'][2], ['id' => true])
		);
		self::assertSame([['trigger'], ['condition-table'], ['condition']], array_column($flow['edges'], 'from'));
	}//end testARuleSetConditionGatesTheSteps()

	/**
	 * The condition's rule set comes first in the slugs and is not repeated by a step that decides with it.
	 *
	 * @return void
	 */
	public function testTheConditionsRuleSetIsResolvedWithTheSteps(): void {
		$input = self::input(
			['type' => 'object-created', 'schema' => 'aanvraag'],
			[['type' => 'rule-decision', 'ruleSetSlug' => 'two-out'], ['type' => 'rule-decision', 'ruleSetSlug' => 'leges-categorie']],
			['type' => 'rule-set', 'ruleSetSlug' => 'leges-categorie']
		);

		self::assertSame(['leges-categorie', 'two-out'], $this->builder()->ruleSetSlugs($input));
		self::assertSame([], $this->builder()->ruleSetSlugs(self::input(['type' => 'manual'], [], ['type' => 'rule-set', 'ruleSetSlug' => ' '])));
	}//end testTheConditionsRuleSetIsResolvedWithTheSteps()

	/**
	 * A rule-set condition refuses a missing rule set, a condition-action one,
	 * one without a table and a table that decides more than one outcome.
	 *
	 * @return void
	 */
	public function testARuleSetConditionRefusesWhatItCannotRun(): void {
		$cases = [
			'Pick the rule set the condition runs.' => 'gone',
			'The rule set "checks" is not a decision table; a condition can only run a decision table.' => 'checks',
			'The rule set "empty" has no decision table to run.' => 'empty',
			'The rule set "two-out" decides 2 outputs; a condition needs a table that decides one, true or false.' => 'two-out',
		];
		foreach ($cases as $message => $slug) {
			try {
				$this->builder()->build(self::input(['type' => 'manual'], [], ['type' => 'rule-set', 'ruleSetSlug' => $slug]), 'r', [], self::ruleSets());
				self::fail('Expected a refusal: ' . $message);
			} catch (UnsupportedAutomationCombinationException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}//end testARuleSetConditionRefusesWhatItCannotRun()
}//end class
