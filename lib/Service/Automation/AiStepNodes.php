<?php

/**
 * AI Step Nodes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The flow nodes of an "Ask AI" step (ai-llm-steps-and-computed-fields D1-D4):
 * Hermiq's `hermiq.agent-step` with a prompt built here from the preset, then
 * `openregister.object-write` in update mode onto the record that started the
 * run. Pure: it reads the action, the trigger and the trigger schema's
 * properties, and returns nodes or refuses the step with a message that names
 * what is missing. The prompt holds placeholders for the step's source fields
 * and nothing else, so only the fields the maker named reach the model.
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
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;

/**
 * Builds and refuses the nodes of an AI step.
 *
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
 */
final class AiStepNodes {

	/**
	 * The action kind.
	 *
	 * @var string
	 */
	public const ACTION = 'ai-step';

	/**
	 * The node Hermiq contributes to OpenRegister's flow engine.
	 *
	 * @var string
	 */
	public const AGENT_NODE = 'hermiq.agent-step';

	/**
	 * The refusal when that node is not in the catalogue (REQ-BQLS-005).
	 *
	 * @var string
	 */
	public const MISSING_NODE_REFUSAL = 'Install Hermiq to use AI steps.';

	/**
	 * The presets a step can run (D1).
	 *
	 * @var array<int, string>
	 */
	public const PRESETS = ['classify', 'summarise', 'extract', 'translate', 'prompt'];

	/**
	 * The triggers an AI step runs on. `object-updated` watches the source
	 * fields only, so the write of the answer does not start the flow again.
	 *
	 * @var array<int, string>
	 */
	public const TRIGGERS = ['object-created', 'object-updated', 'manual'];

	/**
	 * The private key the agent's answer is kept under on the item (D2).
	 *
	 * @var string
	 */
	public const ANSWER_KEY = 'aiAnswer';

	/**
	 * Constructor.
	 *
	 * @param string                    $register   The register the answer is written to.
	 * @param string                    $schema     The schema of the record that started the run, or ''.
	 * @param array<string, mixed>|null $properties That schema's properties, or null when it could not be read.
	 */
	public function __construct(
		private readonly string $register,
		private readonly string $schema,
		private readonly ?array $properties,
	) {
	}//end __construct()

	/**
	 * Whether an automation holds an AI step.
	 *
	 * @param array<int|string, mixed> $actions The actions.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	public static function holdsAiStep(array $actions): bool {
		foreach ($actions as $action) {
			if (is_array($action) === true && ($action['type'] ?? '') === self::ACTION) {
				return true;
			}
		}

		return false;
	}//end holdsAiStep()

	/**
	 * Whether a flow document runs Hermiq's agent node.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
	 */
	public static function flowRunsAgent(array $flow): bool {
		foreach ((array)($flow['nodes'] ?? []) as $node) {
			if (is_array($node) === true && ($node['type'] ?? '') === self::AGENT_NODE) {
				return true;
			}
		}

		return false;
	}//end flowRunsAgent()

	/**
	 * Every source field the AI steps of an automation read, sorted.
	 *
	 * @param array<int|string, mixed> $actions The actions.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
	 */
	public static function sourceFieldsOf(array $actions): array {
		$fields = [];
		foreach ($actions as $action) {
			if (is_array($action) === true && ($action['type'] ?? '') === self::ACTION) {
				array_push($fields, ...self::sourceFields(action: $action));
			}
		}

		$fields = array_values(array_unique($fields));
		sort($fields);

		return $fields;
	}//end sourceFieldsOf()

	/**
	 * Refuse an AI step on a trigger it cannot run on.
	 *
	 * @param string $triggerType The automation's trigger type.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException For a deletion, a schedule or a lifecycle transition.
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	public static function assertTrigger(string $triggerType): void {
		if (in_array($triggerType, self::TRIGGERS, true) === false) {
			throw new UnsupportedAutomationCombinationException(
				message: 'An AI step runs when a record is created, when its source fields change, or by hand, not on "' . $triggerType . '".'
			);
		}
	}//end assertTrigger()

	/**
	 * The two nodes of an AI step: the agent turn, then the write of its answer
	 * onto the record that started the run (D2).
	 *
	 * @param array<string, mixed> $action The action.
	 * @param int                  $index  Its position.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws UnsupportedAutomationCombinationException For a step that is incomplete or would write outside its labels.
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	public function build(array $action, int $index): array {
		if ($this->schema === '') {
			throw new UnsupportedAutomationCombinationException(
				message: 'An AI step writes onto the record that started the automation, so its trigger must name a schema.'
			);
		}

		$preset = (string)($action['preset'] ?? '');
		if (in_array($preset, self::PRESETS, true) === false) {
			throw new UnsupportedAutomationCombinationException(message: 'Pick what the AI step does: ' . implode(', ', self::PRESETS) . '.');
		}

		$agent = trim((string)($action['agent'] ?? ''));
		if ($agent === '') {
			throw new UnsupportedAutomationCombinationException(message: 'Pick the Hermiq agent that runs the AI step.');
		}

		$sources = self::sourceFields(action: $action);
		if ($sources === []) {
			throw new UnsupportedAutomationCombinationException(message: 'Pick at least one field the AI step reads.');
		}

		$fields = $this->writtenFields(action: $action, preset: $preset, sources: $sources);
		$id = 'step-' . ($index + 1);

		return [
			[
				'id' => $id,
				'type' => self::AGENT_NODE,
				'config' => [
					'agentId' => $agent,
					'prompt' => self::prompt(action: $action, preset: $preset, sources: $sources),
					'output' => self::ANSWER_KEY,
					'expectJson' => in_array($preset, ['classify', 'extract'], true),
				],
			],
			[
				'id' => $id . '-write',
				'type' => 'openregister.object-write',
				'config' => [
					'register' => $this->register,
					'schema' => $this->schema,
					'operation' => 'update',
					'match' => ['uuid' => '{{json.uuid}}'],
					'fields' => $fields,
				],
			],
		];
	}//end build()

	/**
	 * The trigger a flow on `object-updated` starts on: the update of the AI
	 * steps' source fields only (REQ-BQLS-002, T07).
	 *
	 * @param array<int|string, mixed> $actions The actions.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	public function updateTrigger(array $actions): array {
		return [
			'event' => 'object.updated',
			'register' => $this->register,
			'schema' => $this->schema,
			'changedFields' => self::sourceFieldsOf(actions: $actions),
		];
	}//end updateTrigger()

	/**
	 * The trimmed, distinct source fields of one step.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return list<string>
	 */
	private static function sourceFields(array $action): array {
		$fields = [];
		foreach ((array)($action['sourceFields'] ?? []) as $field) {
			$field = trim((string)$field);
			if ($field !== '' && in_array($field, $fields, true) === false) {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end sourceFields()

	/**
	 * The fields the write step sets, read from the agent's answer.
	 *
	 * @param array<string, mixed> $action  The action.
	 * @param string               $preset  The preset.
	 * @param list<string>         $sources The source fields.
	 *
	 * @return array<string, string>
	 *
	 * @throws UnsupportedAutomationCombinationException For a missing or source target, or a classify target that is not its labels.
	 */
	private function writtenFields(array $action, string $preset, array $sources): array {
		if ($preset === 'extract') {
			$fields = [];
			foreach (self::names(values: (array)($action['fields'] ?? [])) as $field) {
				self::assertNotSource(field: $field, sources: $sources);
				$fields[$field] = '{{json.' . self::ANSWER_KEY . '.' . $field . '}}';
			}

			if ($fields === []) {
				throw new UnsupportedAutomationCombinationException(message: 'Pick the fields the AI step extracts.');
			}

			ksort($fields);
			return $fields;
		}

		$target = trim((string)($action['targetField'] ?? ''));
		if ($target === '') {
			throw new UnsupportedAutomationCombinationException(message: 'Pick the field the AI answer is written to.');
		}

		self::assertNotSource(field: $target, sources: $sources);
		if ($preset === 'classify') {
			$this->assertLabelsTarget(target: $target, labels: self::names(values: (array)($action['labels'] ?? [])));
			return [$target => '{{json.' . self::ANSWER_KEY . '.label}}'];
		}

		return [$target => '{{json.' . self::ANSWER_KEY . '}}'];
	}//end writtenFields()

	/**
	 * Refuse a written field that the step also reads: the write would change
	 * its own input.
	 *
	 * @param string       $field   The written field.
	 * @param list<string> $sources The source fields.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException When it is a source.
	 */
	private static function assertNotSource(string $field, array $sources): void {
		if (in_array($field, $sources, true) === true) {
			throw new UnsupportedAutomationCombinationException(
				message: 'The AI step reads ' . $field . ', so it cannot also write its answer there.'
			);
		}
	}//end assertNotSource()

	/**
	 * A classification lands only on a choice list of exactly its labels (D4).
	 *
	 * @param string       $target The target field.
	 * @param list<string> $labels The step's labels.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException When there are no labels or the field is not that choice list.
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-classification-only-lands-on-a-declared-label-req-bqls-003
	 */
	private function assertLabelsTarget(string $target, array $labels): void {
		if ($labels === []) {
			throw new UnsupportedAutomationCombinationException(message: 'Add the labels the AI step picks from.');
		}

		$enum = self::names(values: (array)(($this->properties[$target] ?? [])['enum'] ?? []));
		$wanted = $labels;
		sort($enum);
		sort($wanted);
		if ($enum !== $wanted) {
			throw new UnsupportedAutomationCombinationException(message: 'Make ' . $target . ' a choice list of your labels first.');
		}
	}//end assertLabelsTarget()

	/**
	 * The prompt of a step: a fixed English instruction per preset, then one
	 * placeholder per source field (D2, D3).
	 *
	 * @param array<string, mixed> $action  The action.
	 * @param string               $preset  The preset.
	 * @param list<string>         $sources The source fields.
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException For an empty instruction or one that reads another field.
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	private static function prompt(array $action, string $preset, array $sources): string {
		$instruction = match ($preset) {
			'classify' => 'Classify the text below as exactly one of these labels: '
				. implode(', ', self::names(values: (array)($action['labels'] ?? [])))
				. '. Answer as JSON with one key "label" holding that label, written exactly as listed.',
			'summarise' => 'Summarise the text below in a few sentences. Answer with the summary only.',
			'extract' => 'Extract these fields from the text below: '
				. implode(', ', self::names(values: (array)($action['fields'] ?? [])))
				. '. Answer as JSON with exactly these keys, and null for a field the text does not give.',
			'translate' => self::translateInstruction(action: $action),
			default => self::ownInstruction(action: $action),
		};

		self::assertOnlySources(text: $instruction, sources: $sources);

		$parts = [$instruction];
		foreach ($sources as $field) {
			$parts[] = $field . ":\n{{" . $field . '}}';
		}

		return implode("\n\n", $parts);
	}//end prompt()

	/**
	 * Refuse an instruction that names any field but a source (D3): Hermiq
	 * renders every `{{...}}` in the prompt from the record, so a placeholder in
	 * a label, a language or the maker's own prompt would send that field too.
	 *
	 * @param string       $text    The instruction.
	 * @param list<string> $sources The source fields.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException For a placeholder outside the sources.
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	private static function assertOnlySources(string $text, array $sources): void {
		preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $text, $matches);
		foreach ($matches[1] as $name) {
			if (in_array($name, $sources, true) === false) {
				throw new UnsupportedAutomationCombinationException(
					message: 'The instruction reads {{' . $name . '}}, which is not a field the AI step sends.'
				);
			}
		}
	}//end assertOnlySources()

	/**
	 * The instruction of a translate step.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException Without a language.
	 */
	private static function translateInstruction(array $action): string {
		$language = trim((string)($action['language'] ?? ''));
		if ($language === '') {
			throw new UnsupportedAutomationCombinationException(message: 'Pick the language the AI step translates into.');
		}

		return 'Translate the text below into ' . $language . '. Answer with the translation only.';
	}//end translateInstruction()

	/**
	 * The maker's own instruction.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException For an empty prompt.
	 */
	private static function ownInstruction(array $action): string {
		$prompt = trim((string)($action['prompt'] ?? ''));
		if ($prompt === '') {
			throw new UnsupportedAutomationCombinationException(message: 'Write the instruction the AI step follows.');
		}

		return $prompt;
	}//end ownInstruction()

	/**
	 * The trimmed, non-empty, distinct strings of a list.
	 *
	 * @param array<int|string, mixed> $values The values.
	 *
	 * @return list<string>
	 */
	private static function names(array $values): array {
		$names = [];
		foreach ($values as $value) {
			if (is_scalar($value) === false) {
				continue;
			}

			$value = trim((string)$value);
			if ($value !== '' && in_array($value, $names, true) === false) {
				$names[] = $value;
			}
		}

		return $names;
	}//end names()
}//end class
