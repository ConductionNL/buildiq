<?php

/**
 * Unit tests for the nodes of an AI step.
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
 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\AiStepNodes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Each preset's agent and write nodes, and every refusal.
 */
#[CoversClass(AiStepNodes::class)]
#[UsesClass(UnsupportedAutomationCombinationException::class)]
final class AiStepNodesTest extends TestCase {

	/**
	 * The nodes for a `melding` record with a `categorie` choice list.
	 *
	 * @return AiStepNodes
	 */
	private static function nodes(): AiStepNodes {
		return new AiStepNodes(
			register: 'meldingen',
			schema: 'melding',
			properties: [
				'omschrijving' => ['type' => 'string'],
				'categorie' => ['type' => 'string', 'enum' => ['afval', 'verlichting', 'overig']],
				'locatie' => ['type' => 'string'],
			]
		);
	}//end nodes()

	/**
	 * The classify step of REQ-BQLS-001.
	 *
	 * @return array<string, mixed>
	 */
	private static function classify(): array {
		return [
			'type' => 'ai-step',
			'preset' => 'classify',
			'agent' => 'agent-1',
			'sourceFields' => ['omschrijving'],
			'targetField' => 'categorie',
			'labels' => ['afval', 'verlichting', 'overig'],
		];
	}//end classify()

	/**
	 * Every placeholder in a prompt.
	 *
	 * @param string $prompt The prompt.
	 *
	 * @return list<string>
	 */
	private static function placeholders(string $prompt): array {
		preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $prompt, $matches);

		return array_values(array_unique($matches[1]));
	}//end placeholders()

	/**
	 * A classify step runs the agent with JSON on and writes the label onto the triggering record.
	 *
	 * @return void
	 */
	public function testClassifyCompilesToAnAgentTurnAndAWriteBack(): void {
		$nodes = self::nodes()->build(action: self::classify(), index: 0);

		$this->assertCount(2, $nodes);
		$this->assertSame('step-1', $nodes[0]['id']);
		$this->assertSame('hermiq.agent-step', $nodes[0]['type']);
		$this->assertSame('agent-1', $nodes[0]['config']['agentId']);
		$this->assertSame('aiAnswer', $nodes[0]['config']['output']);
		$this->assertTrue($nodes[0]['config']['expectJson']);
		$this->assertStringContainsString('afval, verlichting, overig', $nodes[0]['config']['prompt']);
		$this->assertSame(['omschrijving'], self::placeholders(prompt: $nodes[0]['config']['prompt']));

		$this->assertSame(
			[
				'id' => 'step-1-write',
				'type' => 'openregister.object-write',
				'config' => [
					'register' => 'meldingen',
					'schema' => 'melding',
					'operation' => 'update',
					'match' => ['uuid' => '{{id}}'],
					'fields' => ['categorie' => '{{aiAnswer.label}}'],
				],
			],
			$nodes[1]
		);
	}//end testClassifyCompilesToAnAgentTurnAndAWriteBack()

	/**
	 * The text presets write the plain answer and ask no JSON.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function textPresets(): array {
		return [
			'summarise' => [['preset' => 'summarise'], 'Summarise the text below'],
			'translate' => [['preset' => 'translate', 'language' => 'English'], 'Translate the text below into English.'],
			'prompt' => [['preset' => 'prompt', 'prompt' => 'Name the street in {{locatie}}.'], 'Name the street in {{locatie}}.'],
		];
	}//end textPresets()

	/**
	 * Summarise, translate and the free prompt write the answer as text.
	 *
	 * @param array<string, mixed> $extra       The preset keys.
	 * @param string               $instruction The instruction the prompt opens with.
	 *
	 * @return void
	 */
	#[DataProvider('textPresets')]
	public function testTextPresetsWriteThePlainAnswer(array $extra, string $instruction): void {
		$action = array_merge(
			['type' => 'ai-step', 'agent' => 'agent-1', 'sourceFields' => ['omschrijving', 'locatie'], 'targetField' => 'samenvatting'],
			$extra
		);

		$nodes = self::nodes()->build(action: $action, index: 2);

		$this->assertSame('step-3', $nodes[0]['id']);
		$this->assertFalse($nodes[0]['config']['expectJson']);
		$this->assertStringStartsWith($instruction, $nodes[0]['config']['prompt']);
		$this->assertStringEndsWith("omschrijving:\n{{omschrijving}}\n\nlocatie:\n{{locatie}}", $nodes[0]['config']['prompt']);
		$this->assertSame(expected: ['samenvatting' => '{{aiAnswer}}'], actual: $nodes[1]['config']['fields']);
	}//end testTextPresetsWriteThePlainAnswer()

	/**
	 * Extract asks JSON and writes each extracted field under its own name.
	 *
	 * @return void
	 */
	public function testExtractWritesEachField(): void {
		$action = ['type' => 'ai-step', 'preset' => 'extract', 'agent' => 'agent-1', 'sourceFields' => ['omschrijving'], 'fields' => ['straat', 'huisnummer']];

		$nodes = self::nodes()->build(action: $action, index: 0);

		$this->assertTrue($nodes[0]['config']['expectJson']);
		$this->assertStringStartsWith('Extract these fields from the text below: straat, huisnummer.', $nodes[0]['config']['prompt']);
		$this->assertSame(
			['huisnummer' => '{{aiAnswer.huisnummer}}', 'straat' => '{{aiAnswer.straat}}'],
			$nodes[1]['config']['fields']
		);
	}//end testExtractWritesEachField()

	/**
	 * Refusals, each with the message the maker sees.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'plain string target' => [['targetField' => 'locatie'], 'Make locatie a choice list of your labels first.'],
			'labels differ from the enum' => [['labels' => ['afval', 'overig']], 'Make categorie a choice list of your labels first.'],
			'no labels' => [['labels' => []], 'Add the labels the AI step picks from.'],
			'unknown preset' => [['preset' => 'search-web'], 'Pick what the AI step does: classify, summarise, extract, translate, prompt.'],
			'no agent' => [['agent' => ' '], 'Pick the Hermiq agent that runs the AI step.'],
			'no source' => [['sourceFields' => []], 'Pick at least one field the AI step reads.'],
			'no target' => [['targetField' => ''], 'Pick the field the AI answer is written to.'],
			'target is a source' => [['targetField' => 'omschrijving'], 'The AI step reads omschrijving, so it cannot also write its answer there.'],
			'translate without language' => [['preset' => 'translate', 'targetField' => 'locatie'], 'Pick the language the AI step translates into.'],
			'empty prompt' => [['preset' => 'prompt', 'targetField' => 'locatie', 'prompt' => ''], 'Write the instruction the AI step follows.'],
			'prompt reads another field' => [
				['preset' => 'prompt', 'targetField' => 'locatie', 'prompt' => 'Use {{bsn}} too.'],
				'The instruction reads {{bsn}}, which is not a field the AI step sends.',
			],
			'label smuggles a field' => [
				['labels' => ['afval', '{{bsn}}'], 'targetField' => 'categorie'],
				'Make categorie a choice list of your labels first.',
			],
			'extract without fields' => [['preset' => 'extract', 'fields' => []], 'Pick the fields the AI step extracts.'],
		];
	}//end refusals()

	/**
	 * An incomplete or unsafe step is refused with a message that names the gap.
	 *
	 * @param array<string, mixed> $change  What differs from the classify step.
	 * @param string               $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testAnIncompleteStepIsRefused(array $change, string $message): void {
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage($message);

		self::nodes()->build(action: array_merge(self::classify(), $change), index: 0);
	}//end testAnIncompleteStepIsRefused()

	/**
	 * A language that holds a placeholder is refused rather than sent.
	 *
	 * @return void
	 */
	public function testALanguageThatSmugglesAFieldIsRefused(): void {
		$action = array_merge(self::classify(), ['preset' => 'translate', 'targetField' => 'locatie', 'language' => '{{bsn}}']);

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('The instruction reads {{bsn}}, which is not a field the AI step sends.');

		self::nodes()->build(action: $action, index: 0);
	}//end testALanguageThatSmugglesAFieldIsRefused()

	/**
	 * Without a trigger schema there is no record to write to; without readable properties a classify step is refused.
	 *
	 * @return void
	 */
	public function testAStepNeedsASchemaAndItsChoiceList(): void {
		try {
			(new AiStepNodes(register: 'meldingen', schema: '', properties: null))->build(action: self::classify(), index: 0);
			$this->fail('A step without a schema was accepted.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('its trigger must name a schema', $e->getMessage());
		}

		$this->expectExceptionMessage('Make categorie a choice list of your labels first.');
		(new AiStepNodes(register: 'meldingen', schema: 'melding', properties: null))->build(action: self::classify(), index: 0);
	}//end testAStepNeedsASchemaAndItsChoiceList()

	/**
	 * The update trigger watches the source fields of every AI step, and nothing else.
	 *
	 * @return void
	 */
	public function testTheUpdateTriggerNamesTheSourceFields(): void {
		$actions = [
			self::classify(),
			['type' => 'send-notification', 'sourceFields' => ['ignored']],
			['type' => 'ai-step', 'preset' => 'summarise', 'sourceFields' => ['locatie', 'omschrijving', ' ']],
		];

		$this->assertSame(
			['event' => 'object.updated', 'register' => 'meldingen', 'schema' => 'melding', 'changedFields' => ['locatie', 'omschrijving']],
			self::nodes()->updateTrigger(actions: $actions)
		);
	}//end testTheUpdateTriggerNamesTheSourceFields()

	/**
	 * The trigger guard, and the two lookups the compiler uses.
	 *
	 * @return void
	 */
	public function testTriggersAndLookups(): void {
		AiStepNodes::assertTrigger(triggerType: 'object-created');
		AiStepNodes::assertTrigger(triggerType: 'object-updated');
		AiStepNodes::assertTrigger(triggerType: 'manual');

		$this->assertTrue(AiStepNodes::holdsAiStep(actions: ['x', self::classify()]));
		$this->assertFalse(AiStepNodes::holdsAiStep(actions: [['type' => 'object-op']]));
		$this->assertTrue(AiStepNodes::flowRunsAgent(flow: ['nodes' => [['type' => 'openregister.trigger-manual'], ['type' => 'hermiq.agent-step']]]));
		$this->assertFalse(AiStepNodes::flowRunsAgent(flow: ['nodes' => [['type' => 'openregister.object-write']]]));
		$this->assertFalse(AiStepNodes::flowRunsAgent(flow: []));

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('not on "object-deleted"');
		AiStepNodes::assertTrigger(triggerType: 'object-deleted');
	}//end testTriggersAndLookups()
}//end class
