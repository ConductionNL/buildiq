<?php

/**
 * Unit tests for CopilotPromptBuilder's target section and its fallbacks for
 * a catalogue or manifest summary that cannot be encoded.
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
 * @covers \OCA\Buildiq\Service\Copilot\CopilotPromptBuilder
 */
class CopilotPromptBuilderTargetTest extends TestCase {

	/**
	 * A builder whose provider must not be asked when a catalogue is given.
	 *
	 * @return CopilotPromptBuilder
	 */
	private function builder(): CopilotPromptBuilder {
		$provider = $this->createMock(BuildiqToolProvider::class);
		$provider->expects($this->never())->method('getToolDescriptors');

		return new CopilotPromptBuilder(toolProvider: $provider);
	}//end builder()

	/**
	 * The text between two markers of a prompt.
	 *
	 * @param string $prompt The prompt.
	 * @param string $from   Start marker, excluded.
	 * @param string $to     End marker, excluded.
	 *
	 * @return string
	 */
	private static function between(string $prompt, string $from, string $to): string {
		$start = (strpos($prompt, $from) + strlen($from));

		return substr($prompt, $start, (strpos($prompt, $to, $start) - $start));
	}//end between()

	/**
	 * An existing app is named, with its version and its manifest summary.
	 *
	 * @return void
	 */
	public function testAnExistingVersionedAppIsTargeted(): void {
		$prompt = $this->builder()->build(
			brief: 'Add a contacts page',
			targetContext: ['appSlug' => 'crm', 'versionSlug' => 'v2', 'manifestSummary' => ['pages' => ['Home']]],
			toolDescriptors: []
		);

		$this->assertStringContainsString(
			"This plan targets the EXISTING app 'crm'. Do not include a createApp step.\n"
			. "The user is editing the 'v2' version. Set versionSlug to 'v2' in every step.\n"
			. "Current manifest summary:\n" . json_encode(['pages' => ['Home']], JSON_PRETTY_PRINT) . "\n",
			$prompt
		);
		$this->assertStringEndsWith("\nUser brief:\nAdd a contacts page\n\nRespond with the JSON plan now, and nothing else.", $prompt);
	}//end testAnExistingVersionedAppIsTargeted()

	/**
	 * Without a version there is no version line, and without a summary it is an empty list.
	 *
	 * @return void
	 */
	public function testAnUnversionedAppWithoutSummary(): void {
		$prompt = $this->builder()->build(brief: 'b', targetContext: ['appSlug' => 'crm'], toolDescriptors: []);

		$this->assertStringContainsString("Do not include a createApp step.\nCurrent manifest summary:\n[]\n", $prompt);
		$this->assertStringNotContainsString('versionSlug to', $prompt);
	}//end testAnUnversionedAppWithoutSummary()

	/**
	 * No target, or an empty one, asks for a createApp step first.
	 *
	 * @return void
	 */
	public function testNoTargetAsksForACreateAppStep(): void {
		$line = "No existing target app was given — a createApp step should normally come first.\n";

		$this->assertStringContainsString($line, $this->builder()->build(brief: 'b', targetContext: null, toolDescriptors: []));
		$this->assertStringContainsString($line, $this->builder()->build(brief: 'b', targetContext: [], toolDescriptors: []));
	}//end testNoTargetAsksForACreateAppStep()

	/**
	 * A catalogue entry keeps only id, description and inputSchema, with defaults.
	 *
	 * @return void
	 */
	public function testCatalogueEntriesAreReducedWithDefaults(): void {
		$prompt = $this->builder()->build(
			brief: 'b',
			toolDescriptors: [['id' => 'buildiq.createApp', 'title' => 'dropped', 'annotations' => ['x' => 1]], []]
		);

		$this->assertSame(
			[
				['id' => 'buildiq.createApp', 'description' => '', 'inputSchema' => []],
				['id' => '', 'description' => '', 'inputSchema' => []],
			],
			json_decode(self::between($prompt, "Tool catalogue:\n", "\nNo existing target"), true)
		);
		$this->assertStringContainsString('"inputSchema": {}', $prompt);
	}//end testCatalogueEntriesAreReducedWithDefaults()

	/**
	 * A catalogue that cannot be encoded becomes an empty list.
	 *
	 * @return void
	 */
	public function testAnUnencodableCatalogueBecomesAnEmptyList(): void {
		$prompt = $this->builder()->build(brief: 'b', toolDescriptors: [['id' => 'buildiq.x', 'description' => "bad \xB1 bytes"]]);

		$this->assertSame('[]', self::between($prompt, "Tool catalogue:\n", "\nNo existing target"));
	}//end testAnUnencodableCatalogueBecomesAnEmptyList()

	/**
	 * A manifest summary that cannot be encoded becomes an empty object, in
	 * the repair prompt as in the plan prompt.
	 *
	 * @return void
	 */
	public function testAnUnencodableManifestSummaryBecomesAnEmptyObject(): void {
		$target = ['appSlug' => 'crm', 'manifestSummary' => ['title' => "bad \xB1 bytes"]];

		$prompt = $this->builder()->build(brief: 'b', targetContext: $target, toolDescriptors: []);
		$repair = $this->builder()->buildRepairPrompt(brief: 'b', previousOutput: 'x', parseError: 'y', targetContext: $target, toolDescriptors: []);

		$this->assertStringContainsString("Current manifest summary:\n{}\n", $prompt);
		$this->assertStringContainsString("Current manifest summary:\n{}\n", $repair);
	}//end testAnUnencodableManifestSummaryBecomesAnEmptyObject()
}//end class
