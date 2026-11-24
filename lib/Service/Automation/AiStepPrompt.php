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
 * Writes the prompt of an AI step: a fixed English instruction per preset and
 * one placeholder per source field, and refuses an instruction that would send
 * any other field (D2, D3).
 *
 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
 */
final class AiStepPrompt {

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
	public function prompt(array $action, string $preset, array $sources): string {
		$instruction = match ($preset) {
			'classify' => 'Classify the text below as exactly one of these labels: '
				. implode(', ', $this->names(values: (array)($action['labels'] ?? [])))
				. '. Answer as JSON with one key "label" holding that label, written exactly as listed.',
			'summarise' => 'Summarise the text below in a few sentences. Answer with the summary only.',
			'extract' => 'Extract these fields from the text below: '
				. implode(', ', $this->names(values: (array)($action['fields'] ?? [])))
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
	 *
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	public function names(array $values): array {
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
