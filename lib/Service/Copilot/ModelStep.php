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
	 * The attempt number: 1 for the first call, 2 for the repair.
	 *
	 * @var int
	 */
	private int $attempt;

	/**
	 * The prompt sent.
	 *
	 * @var string
	 */
	private string $prompt;

	/**
	 * When the call started.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $startedAt;

	/**
	 * microtime() at the start of the call.
	 *
	 * @var float
	 */
	private float $clock;

	/**
	 * Start the record of one call: the clock runs from here.
	 *
	 * @param int    $attempt 1 for the first call, 2 for the repair.
	 * @param string $prompt  The prompt sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
	 */
	public function __construct(int $attempt, string $prompt) {
		$this->attempt   = $attempt;
		$this->prompt    = $prompt;
		$this->startedAt = new DateTimeImmutable();
		$this->clock     = microtime(true);
	}//end __construct()

	/**
	 * The record of a call that did not reach the model or got no answer.
	 *
	 * @param string $message The provider error.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
	 */
	public function error(string $message): array {
		return $this->toArray(status: self::STATUS_ERROR, answer: ['', $message]);
	}//end error()

	/**
	 * The record of an answered call, from the `[plan|null, rawOutput, parseError]`
	 * result of the attempt.
	 *
	 * A parsed plan is `successful`; an unusable first answer is `repair-needed`
	 * (a repair follows), an unusable second one `failed`.
	 *
	 * @param array{0: array<string, mixed>|null, 1: string, 2: string} $result The attempt's result.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
	 */
	public function answered(array $result): array {
		$status = self::STATUS_SUCCESSFUL;
		if ($result[0] === null) {
			$status = self::STATUS_FAILED;
			if ($this->attempt === 1) {
				$status = self::STATUS_REPAIR_NEEDED;
			}
		}

		return $this->toArray(status: $status, answer: [(string)$result[1], (string)$result[2]]);
	}//end answered()

	/**
	 * The record of the call, as a run keeps it.
	 *
	 * @param string                 $status One of the STATUS_ constants.
	 * @param array{0: string, 1: string} $answer The raw answer and the parse or validator message.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(string $status, array $answer): array {
		[$output, $refusal] = $answer;
		$kind = 'repair';
		if ($this->attempt === 1) {
			$kind = 'plan';
		}

		return [
			'attempt'         => $this->attempt,
			'kind'            => $kind,
			'startedAt'       => $this->startedAt->format(DATE_ATOM),
			'durationMs'      => max(0, (int)round((microtime(true) - $this->clock) * 1000)),
			'status'          => $status,
			'taskType'        => self::TASK_TYPE,
			'promptChars'     => mb_strlen($this->prompt),
			'output'          => mb_substr($output, 0, self::OUTPUT_CAP),
			'outputTruncated' => mb_strlen($output) > self::OUTPUT_CAP,
			'refusal'         => $refusal,
		];
	}//end toArray()
}//end class
