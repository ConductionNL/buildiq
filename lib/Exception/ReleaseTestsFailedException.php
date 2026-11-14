<?php

/**
 * Buildiq ReleaseTestsFailedException
 *
 * Thrown when an app version's test suite has failures and nobody overrode
 * them: the release or the promotion onto production stops and writes
 * nothing; the controllers answer 422 with the report
 * (lifecycle-release-test-gate D2, REQ-BQRT-004).
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

use RuntimeException;

/**
 * 422-mapped: the suite failed and no override was given.
 */
final class ReleaseTestsFailedException extends RuntimeException {

	/**
	 * Machine-readable error code.
	 */
	public const ERROR_CODE = 'release_tests_failed';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $run The stored test run, with its counts and report.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function __construct(private readonly array $run) {
		parent::__construct(
			message: sprintf('The app test suite has %d failed check(s); the version was not released.', (int)($run['failed'] ?? 0))
		);
	}//end __construct()

	/**
	 * The test run that stopped the release.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function getRun(): array {
		return $this->run;
	}//end getRun()

	/**
	 * The machine-readable error code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-release-waits-for-a-green-suite-req-bqrt-004
	 */
	public function getErrorCode(): string {
		return self::ERROR_CODE;
	}//end getErrorCode()
}//end class
