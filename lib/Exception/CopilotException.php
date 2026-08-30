<?php

/**
 * Buildiq CopilotException
 *
 * Thrown by CopilotService (and mapped to a JSONResponse envelope by
 * CopilotController) for every copilot failure: unavailable provider,
 * an unparsable/invalid LLM plan, an RBAC denial, an unknown/hybrid
 * target app, or a mid-execution step failure. Carries enough metadata
 * for the controller to build the spec-defined error body without
 * parsing the message string (mirrors WizardCreationException).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\Buildiq\Exception
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

namespace OCA\Buildiq\Exception;

use RuntimeException;
use Throwable;

/**
 * Copilot failure envelope (REQ-OBAIC-001/002/004/005).
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */
class CopilotException extends RuntimeException {

	/**
	 * Machine-readable error code, e.g. `unsupported_server`, `no_provider`,
	 * `plan_invalid`, `unsupported_target`, `forbidden`, `not_found`,
	 * `execution_failed`.
	 *
	 * @var string
	 */
	private string $errorCode;

	/**
	 * The HTTP status the controller should respond with.
	 *
	 * @var integer
	 */
	private int $httpStatus;

	/**
	 * Index of the plan step that failed execution, when applicable.
	 *
	 * @var integer|null
	 */
	private ?int $stepIndex;

	/**
	 * Extra machine-readable context (e.g. `violations`, the failed step,
	 * or the failing handler's error envelope) merged into the response body.
	 *
	 * @var array<string, mixed>
	 */
	private array $context;

	/**
	 * Constructor.
	 *
	 * @param string $errorCode Machine-readable error code
	 * @param string $message Human-readable, user-safe diagnostic
	 * @param int $httpStatus HTTP status the controller should use
	 * @param int|null $stepIndex Failed plan-step index, when applicable
	 * @param array<string, mixed> $context Extra machine-readable context
	 * @param Throwable|null $previous Wrapped causal exception
	 *
	 * @return void
	 */
	public function __construct(
		string $errorCode,
		string $message,
		int $httpStatus,
		?int $stepIndex = null,
		array $context = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);
		$this->errorCode = $errorCode;
		$this->httpStatus = $httpStatus;
		$this->stepIndex = $stepIndex;
		$this->context = $context;
	}//end __construct()

	/**
	 * Get the machine-readable error code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * Get the HTTP status the controller should respond with.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 */
	public function getHttpStatus(): int {
		return $this->httpStatus;
	}//end getHttpStatus()

	/**
	 * Get the failed plan-step index, or null when not step-scoped.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 */
	public function getStepIndex(): ?int {
		return $this->stepIndex;
	}//end getStepIndex()

	/**
	 * Get the extra machine-readable context for the response body.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
	 */
	public function getContext(): array {
		return $this->context;
	}//end getContext()
}//end class
