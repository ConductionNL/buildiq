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
 * Covers ModelStep.
 */
final class ModelStepTest extends TestCase {

	/**
	 * The record carries every field the run shows, in order.
	 *
	 * @return void
	 */
	public function testTheRecordCarriesEveryField(): void {
		$before = new DateTimeImmutable('-1 second');
		$step   = (new ModelStep(attempt: 1, prompt: 'Maak een formulier'))->answered(result: [null, '{steps: oops', 'Syntax error']);

		$this->assertSame(
			['attempt', 'kind', 'startedAt', 'durationMs', 'status', 'taskType', 'promptChars', 'output', 'outputTruncated', 'refusal'],
			array_keys($step)
		);
		$this->assertSame(1, $step['attempt']);
		$this->assertSame('plan', $step['kind']);
		$this->assertGreaterThanOrEqual($before, new DateTimeImmutable($step['startedAt']));
		$this->assertIsInt($step['durationMs']);
		$this->assertGreaterThanOrEqual(0, $step['durationMs']);
		$this->assertSame('repair-needed', $step['status']);
		$this->assertSame('core:text2text', $step['taskType']);
		$this->assertSame(18, $step['promptChars']);
		$this->assertSame('{steps: oops', $step['output']);
		$this->assertFalse($step['outputTruncated']);
		$this->assertSame('Syntax error', $step['refusal']);
	}//end testTheRecordCarriesEveryField()

	/**
	 * The status follows the result and the attempt; the kind follows the attempt.
	 *
	 * @return void
	 */
	public function testTheStatusAndKindFollowTheAttempt(): void {
		$parsed = (new ModelStep(attempt: 1, prompt: ''))->answered(result: [['steps' => []], '{}', '']);
		$failed = (new ModelStep(attempt: 2, prompt: ''))->answered(result: [null, 'oops', 'Syntax error']);
		$repair = (new ModelStep(attempt: 2, prompt: ''))->answered(result: [['steps' => []], '{}', '']);

		$this->assertSame(ModelStep::STATUS_SUCCESSFUL, $parsed['status']);
		$this->assertSame(ModelStep::STATUS_FAILED, $failed['status']);
		$this->assertSame('repair', $failed['kind']);
		$this->assertSame(ModelStep::STATUS_SUCCESSFUL, $repair['status']);
		$this->assertSame('repair', $repair['kind']);
	}//end testTheStatusAndKindFollowTheAttempt()

	/**
	 * A provider error keeps no answer and the error message as the refusal.
	 *
	 * @return void
	 */
	public function testAnErrorKeepsTheMessage(): void {
		$step = (new ModelStep(attempt: 2, prompt: 'abc'))->error(message: 'Provider down');

		$this->assertSame(ModelStep::STATUS_ERROR, $step['status']);
		$this->assertSame('repair', $step['kind']);
		$this->assertSame('', $step['output']);
		$this->assertFalse($step['outputTruncated']);
		$this->assertSame('Provider down', $step['refusal']);
		$this->assertSame(3, $step['promptChars']);
	}//end testAnErrorKeepsTheMessage()

	/**
	 * The raw answer is capped at 20,000 characters, counted as characters, not bytes.
	 *
	 * @return void
	 */
	public function testTheAnswerIsCappedAtTwentyThousandCharacters(): void {
		$long = str_repeat('é', 20005);
		$step = (new ModelStep(attempt: 1, prompt: $long))->answered(result: [['steps' => []], $long, '']);

		$this->assertSame(20000, mb_strlen($step['output']));
		$this->assertTrue($step['outputTruncated']);
		$this->assertSame(20005, $step['promptChars']);
	}//end testTheAnswerIsCappedAtTwentyThousandCharacters()
}//end class
