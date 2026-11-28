<?php

/**
 * Release Override
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A request to release an app version although its test suite fails: who
 * asks, whether they own the app, and the reason they give
 * (lifecycle-release-test-gate D2, REQ-BQRT-004).
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

use OCA\Buildiq\Exception\ReleaseOverrideRefusedException;

/**
 * An owner's "release anyway", or a caller's attempt at one.
 */
final class ReleaseOverride {

	/**
	 * Constructor.
	 *
	 * @param string $reason  Why the failures are acceptable.
	 * @param string $userId  Who asks.
	 * @param bool   $byOwner Whether the caller owns the app.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function __construct(
		public readonly string $reason,
		public readonly string $userId,
		public readonly bool $byOwner,
	) {
	}//end __construct()

	/**
	 * The override as a request body names it: `override: {reason}`, or a bare
	 * reason string; any other non-empty value asks for one without a reason.
	 * Null when the body asks for no override.
	 *
	 * @param mixed  $param   The request's `override` parameter.
	 * @param string $userId  Who asks.
	 * @param bool   $byOwner Whether the caller owns the app.
	 *
	 * @return self|null
	 *
	 * @spec openspec/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public static function fromRequest(mixed $param, string $userId, bool $byOwner): ?self {
		if (is_array($param) === true) {
			return new self(reason: trim((string)($param['reason'] ?? '')), userId: $userId, byOwner: $byOwner);
		}

		if ($param === null || $param === false || $param === '') {
			return null;
		}

		// A bare reason string, or any other "yes" (`true`), which carries no reason.
		$reason = '';
		if (is_string($param) === true) {
			$reason = trim($param);
		}

		return new self(reason: $reason, userId: $userId, byOwner: $byOwner);
	}//end fromRequest()

	/**
	 * Refuse the override unless an owner asks and gives a reason.
	 *
	 * @return void
	 *
	 * @throws ReleaseOverrideRefusedException When the caller is not an owner, or gave no reason.
	 *
	 * @spec openspec/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function assertMayRelease(): void {
		if ($this->byOwner === false) {
			throw new ReleaseOverrideRefusedException(
				errorCode: ReleaseOverrideRefusedException::NOT_OWNER,
				message: 'Only an owner of the app may release past failing tests.'
			);
		}

		if (trim($this->reason) === '') {
			throw new ReleaseOverrideRefusedException(
				errorCode: ReleaseOverrideRefusedException::REASON_REQUIRED,
				message: 'Releasing past failing tests needs a reason.'
			);
		}
	}//end assertMayRelease()
}//end class
