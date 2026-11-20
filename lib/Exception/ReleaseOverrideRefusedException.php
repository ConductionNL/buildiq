<?php

/**
 * Buildiq ReleaseOverrideRefusedException
 *
 * Thrown when a caller asks to release past a failing test suite without
 * being allowed to: only an owner may, and only with a reason
 * (lifecycle-release-test-gate D2, REQ-BQRT-004). The controllers answer 403
 * for a caller who is not an owner and 400 for a missing reason.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Exception;

use OCP\AppFramework\Http;
use RuntimeException;

/**
 * An override of a failing suite that is not allowed.
 */
final class ReleaseOverrideRefusedException extends RuntimeException {

	/**
	 * The caller is not an owner of the app.
	 */
	public const NOT_OWNER = 'release_override_not_owner';

	/**
	 * The override carries no reason.
	 */
	public const REASON_REQUIRED = 'release_override_reason_required';

	/**
	 * Constructor.
	 *
	 * @param string $errorCode One of {@see self::NOT_OWNER}, {@see self::REASON_REQUIRED}.
	 * @param string $message   Human-readable diagnostic.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function __construct(private readonly string $errorCode, string $message) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The machine-readable error code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * The HTTP status: 403 when the refusal is about who asked, 400 when it is
	 * about what was sent.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function getStatus(): int {
		if ($this->errorCode === self::NOT_OWNER) {
			return Http::STATUS_FORBIDDEN;
		}

		return Http::STATUS_BAD_REQUEST;
	}//end getStatus()
}//end class
