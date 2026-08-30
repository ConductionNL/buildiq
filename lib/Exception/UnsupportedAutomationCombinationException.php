<?php

/**
 * Buildiq UnsupportedAutomationCombinationException
 *
 * Thrown by {@see \OCA\Buildiq\Service\AutomationCompilerService::compile()}
 * when a trigger/action combination or a condition placement is a ⛔ cell of
 * the v1 compilation matrix (design.md Decision 2 of the automation-designer
 * change) — i.e. no existing declarative primitive (notifications dialect,
 * lifecycle actions, schedules, rules engine) can express it. The automation
 * is fail-closed: nothing is stubbed, silently dropped, or partially
 * compiled. The message names the unsupported combination so the editor and
 * the controller's error envelope can surface it verbatim (REQ-AUTD-003).
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
 * @spec openspec/changes/automation-designer/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Exception;

use RuntimeException;
use Throwable;

/**
 * 422-mapped — the automation's trigger/condition/action shape cannot be
 * compiled to any existing declarative primitive.
 */
final class UnsupportedAutomationCombinationException extends RuntimeException {
	/**
	 * Machine-readable error code.
	 */
	private const ERROR_CODE = 'unsupported_automation_combination';

	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable message naming the unsupported combination.
	 * @param Throwable|null $previous Wrapped causal exception, if any.
	 *
	 * @return void
	 */
	public function __construct(string $message, ?Throwable $previous = null) {
		parent::__construct(message: $message, code: 0, previous: $previous);

	}//end __construct()

	/**
	 * Get the machine-readable error code.
	 *
	 * @return string
	 */
	public function getErrorCode(): string {
		return self::ERROR_CODE;
	}//end getErrorCode()
}//end class
