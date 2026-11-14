<?php

/**
 * Buildiq ModelStep
 *
 * One language model call of a copilot turn, as a run keeps it.
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
 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

use DateTimeImmutable;

/**
 * Builds the step record of one model call: attempt number, kind (`plan` or
 * `repair`), start time, duration, status, task type, prompt size, the raw
 * answer capped at 20,000 characters, and the parse or validator message that
 * caused a repair.
 *
 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
 */
final class ModelStep {

	/**
	 * The longest raw answer a step keeps, in characters.
	 */
	public const OUTPUT_CAP = 20000;

	/**
	 * The step's answer parsed into a plan.
	 */
	public const STATUS_SUCCESSFUL = 'successful';

	/**
	 * The step's answer could not be used, so a repair followed.
	 */
	public const STATUS_REPAIR_NEEDED = 'repair-needed';

	/**
	 * The step's answer could not be used and no repair followed.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The model was not reached or did not answer.
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * The task type every copilot call runs as.
	 */
	public const TASK_TYPE = 'core:text2text';

	/**
	 * The record of one call.
	 *
	 * @param int               $attempt   1 for the first call, 2 for the repair.
	 * @param DateTimeImmutable $startedAt When the call started.
	 * @param float             $seconds   How long it took, from microtime().
	 * @param string            $status    One of the STATUS_ constants.
	 * @param string            $prompt    The prompt sent.
	 * @param string            $output    The raw answer.
	 * @param string            $refusal   The parse or validator message, empty when none.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
	 */
	public static function record(
		int $attempt,
		DateTimeImmutable $startedAt,
		float $seconds,
		string $status,
		string $prompt,
		string $output,
		string $refusal='',
	): array {
		$capped = mb_substr($output, 0, self::OUTPUT_CAP);
		$kind   = 'repair';
		if ($attempt === 1) {
			$kind = 'plan';
		}

		return [
			'attempt'         => $attempt,
			'kind'            => $kind,
			'startedAt'       => $startedAt->format(DATE_ATOM),
			'durationMs'      => max(0, (int)round($seconds * 1000)),
			'status'          => $status,
			'taskType'        => self::TASK_TYPE,
			'promptChars'     => mb_strlen($prompt),
			'output'          => $capped,
			'outputTruncated' => mb_strlen($output) > self::OUTPUT_CAP,
			'refusal'         => $refusal,
		];
	}//end record()
}//end class
