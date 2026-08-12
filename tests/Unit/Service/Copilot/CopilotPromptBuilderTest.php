<?php

/**
 * Unit tests for CopilotPromptBuilder (spec `ai-copilot`, agent-workspace
 * design.md Decision 1 — narrowed tool catalogue + instructions prefix).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service\Copilot;

use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCA\OpenBuild\Service\Copilot\CopilotPromptBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CopilotPromptBuilder.
 */
class CopilotPromptBuilderTest extends TestCase {

	/**
	 * @var OpenBuildToolProvider&MockObject
	 */
	private OpenBuildToolProvider&MockObject $toolProvider;

	/**
	 * Set up shared mocks + the SUT dependency.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->toolProvider = $this->createMock(OpenBuildToolProvider::class);
		$this->toolProvider->method('getToolDescriptors')->willReturn(
			[
				['id' => 'openbuild.createApp', 'description' => 'Create app', 'inputSchema' => ['type' => 'object']],
				['id' => 'openbuild.upsertPage', 'description' => 'Upsert page', 'inputSchema' => ['type' => 'object']],
			]
		);
	}//end setUp()

	/**
	 * With no override, build() embeds the FULL tool catalogue (bare copilot, unchanged).
	 *
	 * @return void
	 */
	public function testBuildEmbedsFullCatalogueByDefault(): void {
		$builder = new CopilotPromptBuilder(toolProvider: $this->toolProvider);
		$prompt = $builder->build(brief: 'x');

		self::assertStringContainsString('openbuild.createApp', $prompt);
		self::assertStringContainsString('openbuild.upsertPage', $prompt);
	}//end testBuildEmbedsFullCatalogueByDefault()

	/**
	 * A narrowed `toolDescriptors` override embeds ONLY the narrowed set —
	 * the excluded tool never appears in the prompt sent to the LLM
	 * (agent-workspace design.md Decision 1).
	 *
	 * @return void
	 */
	public function testBuildEmbedsOnlyNarrowedCatalogueWhenGiven(): void {
		$builder = new CopilotPromptBuilder(toolProvider: $this->toolProvider);
		$prompt = $builder->build(
			brief: 'x',
			toolDescriptors: [['id' => 'openbuild.upsertPage', 'description' => 'Upsert page', 'inputSchema' => ['type' => 'object']]]
		);

		self::assertStringContainsString('openbuild.upsertPage', $prompt);
		self::assertStringNotContainsString('openbuild.createApp', $prompt);
	}//end testBuildEmbedsOnlyNarrowedCatalogueWhenGiven()

	/**
	 * An `instructionsPrefix` is prefixed onto the prompt when given.
	 *
	 * @return void
	 */
	public function testBuildPrefixesInstructionsWhenGiven(): void {
		$builder = new CopilotPromptBuilder(toolProvider: $this->toolProvider);
		$prompt = $builder->build(brief: 'x', instructionsPrefix: 'Never touch existing schemas.');

		self::assertStringContainsString('Never touch existing schemas.', $prompt);
		self::assertStringStartsWith('Agent instructions', $prompt);
	}//end testBuildPrefixesInstructionsWhenGiven()

	/**
	 * With no `instructionsPrefix`, the prompt carries no agent-instructions
	 * section (bare copilot, unchanged).
	 *
	 * @return void
	 */
	public function testBuildOmitsInstructionsSectionByDefault(): void {
		$builder = new CopilotPromptBuilder(toolProvider: $this->toolProvider);
		$prompt = $builder->build(brief: 'x');

		self::assertStringNotContainsString('Agent instructions', $prompt);
	}//end testBuildOmitsInstructionsSectionByDefault()

	/**
	 * buildRepairPrompt() also honours the narrowed catalogue + instructions prefix.
	 *
	 * @return void
	 */
	public function testBuildRepairPromptHonoursNarrowingAndInstructions(): void {
		$builder = new CopilotPromptBuilder(toolProvider: $this->toolProvider);
		$prompt = $builder->buildRepairPrompt(
			brief: 'x',
			previousOutput: 'not json',
			parseError: 'syntax error',
			toolDescriptors: [['id' => 'openbuild.upsertPage', 'description' => 'Upsert page', 'inputSchema' => ['type' => 'object']]],
			instructionsPrefix: 'Be concise.'
		);

		self::assertStringContainsString('openbuild.upsertPage', $prompt);
		self::assertStringNotContainsString('openbuild.createApp', $prompt);
		self::assertStringContainsString('Be concise.', $prompt);
	}//end testBuildRepairPromptHonoursNarrowingAndInstructions()
}//end class
