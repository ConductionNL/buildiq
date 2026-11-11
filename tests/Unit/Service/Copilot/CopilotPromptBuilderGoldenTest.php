<?php

/**
 * Golden test for the full prompts CopilotPromptBuilder produces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Copilot;

use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Service\Copilot\CopilotPromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins every prompt byte for build() and buildRepairPrompt() across the
 * default and narrowed catalogue (including descriptors missing keys), the
 * target-app and version sections, and the instructions prefix. Set
 * GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\Copilot\CopilotPromptBuilder
 */
final class CopilotPromptBuilderGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/copilot-prompts.golden.json';

	/**
	 * Build every recorded prompt.
	 *
	 * @return array<string,string>
	 */
	private function prompts(): array {
		$provider = $this->createMock(BuildiqToolProvider::class);
		$provider->method('getToolDescriptors')->willReturn(
			[
				['id' => 'buildiq.createApp', 'description' => 'Create app', 'inputSchema' => ['type' => 'object', 'required' => ['slug']]],
				['id' => 'buildiq.upsertPage', 'description' => 'Upsert "page" / route'],
			]
		);
		$builder = new CopilotPromptBuilder(toolProvider: $provider);
		$narrowed = [['id' => 'buildiq.addWidget'], ['description' => 'no id', 'inputSchema' => []]];
		$target = ['appSlug' => 'loans', 'versionSlug' => 'v2', 'manifestSummary' => ['pages' => ['tools']]];

		return [
			'default' => $builder->build(brief: 'A loan tracker'),
			'empty target' => $builder->build(brief: 'b', targetContext: []),
			'target without version' => $builder->build(brief: 'b', targetContext: ['appSlug' => 'loans']),
			'target with version' => $builder->build(brief: 'b', targetContext: $target),
			'narrowed' => $builder->build(brief: 'b', toolDescriptors: $narrowed),
			'empty narrowed' => $builder->build(brief: 'b', toolDescriptors: []),
			'blank instructions' => $builder->build(brief: 'b', instructionsPrefix: "  \n"),
			'instructions' => $builder->build(brief: 'b', targetContext: $target, toolDescriptors: $narrowed, instructionsPrefix: 'Be terse.'),
			'repair' => $builder->buildRepairPrompt(brief: 'b', previousOutput: 'not json', parseError: 'Syntax error'),
			'repair full' => $builder->buildRepairPrompt(
				brief: 'b',
				previousOutput: '{',
				parseError: 'eof',
				targetContext: $target,
				toolDescriptors: $narrowed,
				instructionsPrefix: 'Agent X'
			),
		];

	}//end prompts()

	/**
	 * Every prompt matches its recorded bytes.
	 *
	 * @return void
	 */
	public function testPromptsMatchRecorded(): void {
		$prompts = $this->prompts();
		if (getenv('GOLDEN_RECORD') === '1' && is_file(self::FIXTURE) === false) {
			file_put_contents(self::FIXTURE, json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $prompts);

	}//end testPromptsMatchRecorded()
}//end class
