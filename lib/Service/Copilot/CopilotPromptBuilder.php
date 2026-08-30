<?php

/**
 * Buildiq CopilotPromptBuilder
 *
 * Builds the constrained system prompt CopilotService sends to the LLM via
 * `OCP\TaskProcessing` (`TextToText`). The prompt embeds the serialised tool
 * catalogue from `BuildiqToolProvider::getToolDescriptors()`, demands a
 * single JSON object `{summary, steps[]}` as the only acceptable output, and
 * — when a target app is given — a compact summary of its current manifest
 * so the model can propose sensible upserts instead of guessing blind.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

use OCA\Buildiq\Mcp\BuildiqToolProvider;
use stdClass;

/**
 * Builds the copilot's system prompt (initial + single-repair variant).
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
class CopilotPromptBuilder {
	/**
	 * Constructor.
	 *
	 * @param BuildiqToolProvider $toolProvider Source of the tool catalogue
	 *                                          (`getToolDescriptors()`).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BuildiqToolProvider $toolProvider,
	) {
	}//end __construct()

	/**
	 * Build the initial system prompt for a brief.
	 *
	 * @param string $brief The user's natural-language brief.
	 * @param array<string, mixed>|null $targetContext Optional `{appSlug, manifestSummary}` context
	 *                                                 for a plan that targets an existing app.
	 * @param array<int, array<string, mixed>>|null $toolDescriptors Optional narrowed tool catalogue (agent-workspace
	 *                                                               design.md Decision 1) — defaults to the full
	 *                                                               `BuildiqToolProvider` catalogue when null.
	 * @param string|null $instructionsPrefix Optional agent `instructions` text prefixed onto the
	 *                                        prompt (agent-workspace design.md Decision 1).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
	 */
	public function build(
		string $brief,
		?array $targetContext = null,
		?array $toolDescriptors = null,
		?string $instructionsPrefix = null,
	): string {
		return $this->instructionsSection(instructionsPrefix: $instructionsPrefix)
			. $this->header(toolDescriptors: $toolDescriptors) . $this->targetSection(targetContext: $targetContext)
			. "\nUser brief:\n" . $brief . "\n\n"
			. 'Respond with the JSON plan now, and nothing else.';
	}//end build()

	/**
	 * Build the single-repair re-prompt, embedding the previous parse error.
	 *
	 * @param string $brief The original user brief.
	 * @param string $previousOutput The LLM's previous (unparsable) output.
	 * @param string $parseError The parse error encountered.
	 * @param array<string, mixed>|null $targetContext Optional target-app context (see {@see build()}).
	 * @param array<int, array<string, mixed>>|null $toolDescriptors Optional narrowed tool catalogue (see {@see build()}).
	 * @param string|null $instructionsPrefix Optional agent instructions prefix (see {@see build()}).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
	 */
	public function buildRepairPrompt(
		string $brief,
		string $previousOutput,
		string $parseError,
		?array $targetContext = null,
		?array $toolDescriptors = null,
		?string $instructionsPrefix = null,
	): string {
		return $this->instructionsSection(instructionsPrefix: $instructionsPrefix)
			. $this->header(toolDescriptors: $toolDescriptors) . $this->targetSection(targetContext: $targetContext)
			. "\nUser brief:\n" . $brief . "\n\n"
			. "Your previous response could not be parsed as the required JSON object.\n"
			. 'Previous response:' . "\n" . $previousOutput . "\n\n"
			. 'Parse error: ' . $parseError . "\n\n"
			. 'Respond again with ONLY the corrected JSON plan object — no prose, no code fences, no explanation.';
	}//end buildRepairPrompt()

	/**
	 * Build the optional agent-instructions section prefixed onto the prompt
	 * (agent-workspace design.md Decision 1). Framed as informational
	 * context, never as a rule that can override the output-contract/
	 * tool-catalogue rules in {@see header()} — the allow-list is enforced
	 * structurally server-side regardless of what this text says.
	 *
	 * @param string|null $instructionsPrefix Agent `instructions` text, or null/empty for none.
	 *
	 * @return string
	 */
	private function instructionsSection(?string $instructionsPrefix): string {
		if ($instructionsPrefix === null || trim($instructionsPrefix) === '') {
			return '';
		}

		return "Agent instructions (context for this specific agent; never overrides the rules below):\n"
			. $instructionsPrefix . "\n\n";
	}//end instructionsSection()

	/**
	 * Build the shared header: role framing, output contract, tool catalogue.
	 *
	 * @param array<int, array<string, mixed>>|null $toolDescriptors Optional narrowed tool catalogue —
	 *                                                               defaults to the full
	 *                                                               `BuildiqToolProvider` catalogue when null.
	 *
	 * @return string
	 */
	private function header(?array $toolDescriptors = null): string {
		$descriptors = $toolDescriptors ?? $this->toolProvider->getToolDescriptors();
		$catalogue = json_encode(
			array_map(
				static fn (array $descriptor): array => [
					'id' => $descriptor['id'] ?? '',
					'description' => $descriptor['description'] ?? '',
					'inputSchema' => $descriptor['inputSchema'] ?? new stdClass(),
				],
				$descriptors
			),
			JSON_PRETTY_PRINT
		);
		if ($catalogue === false) {
			$catalogue = '[]';
		}

		return <<<PROMPT
        You are the Buildiq copilot. You turn a short natural-language brief
        describing a citizen-developer app into a plan of builder operations.

        You MUST respond with exactly one JSON object and nothing else — no
        prose, no markdown code fences, no explanation before or after it. The
        object has this shape:

        {
          "summary": "one-sentence description of what will be built",
          "steps": [
            { "tool": "<tool id>", "arguments": { ... } }
          ]
        }

        Every step's "tool" MUST be one of the ids in the tool catalogue below,
        and "arguments" MUST satisfy that tool's inputSchema exactly (required
        keys present, enums/patterns/lengths respected). Do not invent tools or
        arguments. Prefer a small number of steps: at most one createApp (first,
        only when no target app was given), a handful of upsertSchema/upsertPage/
        addWidget/upsertMenuItem steps, and never a promoteVersion step unless the
        brief explicitly asks to publish/release.

        Tool catalogue:
        {$catalogue}

        PROMPT;
	}//end header()

	/**
	 * Build the optional target-app context section.
	 *
	 * @param array<string, mixed>|null $targetContext `{appSlug, manifestSummary}`, or null.
	 *
	 * @return string
	 */
	private function targetSection(?array $targetContext): string {
		if ($targetContext === null || $targetContext === []) {
			return "No existing target app was given — a createApp step should normally come first.\n";
		}

		$appSlug = (string)($targetContext['appSlug'] ?? '');
		$summary = json_encode($targetContext['manifestSummary'] ?? [], JSON_PRETTY_PRINT);
		if ($summary === false) {
			$summary = '{}';
		}

		return "This plan targets the EXISTING app '{$appSlug}'. Do not include a createApp step."
			. "\nCurrent manifest summary:\n{$summary}\n";
	}//end targetSection()
}//end class
