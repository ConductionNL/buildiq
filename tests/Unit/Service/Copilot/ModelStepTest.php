<?php

/**
 * Buildiq ModelStepTest
 *
 * The step record of one model call.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Copilot;

use DateTimeImmutable;
use OCA\Buildiq\Service\Copilot\ModelStep;
use PHPUnit\Framework\TestCase;

/**
 * Covers ModelStep::record().
 */
final class ModelStepTest extends TestCase {

	/**
	 * The record carries every field the run shows, in order.
	 *
	 * @return void
	 */
	public function testTheRecordCarriesEveryField(): void {
		$step = ModelStep::record(
			attempt: 1,
			startedAt: new DateTimeImmutable('2026-11-14T09:00:00+00:00'),
			seconds: 1.2345,
			status: ModelStep::STATUS_REPAIR_NEEDED,
			prompt: 'Maak een formulier',
			output: '{steps: oops',
			refusal: 'Syntax error'
		);

		$this->assertSame(
			[
				'attempt'         => 1,
				'kind'            => 'plan',
				'startedAt'       => '2026-11-14T09:00:00+00:00',
				'durationMs'      => 1235,
				'status'          => 'repair-needed',
				'taskType'        => 'core:text2text',
				'promptChars'     => 18,
				'output'          => '{steps: oops',
				'outputTruncated' => false,
				'refusal'         => 'Syntax error',
			],
			$step
		);
		$this->assertSame('repair', ModelStep::record(attempt: 2, startedAt: new DateTimeImmutable(), seconds: 0.0, status: 'successful', prompt: '', output: '')['kind']);
	}//end testTheRecordCarriesEveryField()

	/**
	 * The raw answer is capped at 20,000 characters, counted as characters, not bytes.
	 *
	 * @return void
	 */
	public function testTheAnswerIsCappedAtTwentyThousandCharacters(): void {
		$long = str_repeat('é', 20005);
		$step = ModelStep::record(attempt: 1, startedAt: new DateTimeImmutable(), seconds: -1.0, status: 'successful', prompt: $long, output: $long);

		$this->assertSame(20000, mb_strlen($step['output']));
		$this->assertTrue($step['outputTruncated']);
		$this->assertSame(20005, $step['promptChars']);
		$this->assertSame(0, $step['durationMs'], 'a clock that went backwards is not a negative duration');
	}//end testTheAnswerIsCappedAtTwentyThousandCharacters()
}//end class
