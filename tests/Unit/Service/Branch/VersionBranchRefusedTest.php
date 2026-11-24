<?php

/**
 * Unit tests for VersionBranchRefused: the refusal a branch operation answers.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Branch;

use OCA\Buildiq\Service\Branch\VersionBranchRefused;
use PHPUnit\Framework\TestCase;

/**
 * A refused branch operation carries its code, a plain message and the HTTP status.
 *
 * @covers \OCA\Buildiq\Service\Branch\VersionBranchRefused
 */
class VersionBranchRefusedTest extends TestCase {

	/**
	 * The status is a conflict unless named.
	 *
	 * @return void
	 */
	public function testTheRefusalCarriesItsCodeMessageAndStatus(): void {
		$conflict = new VersionBranchRefused(errorCode: 'target_moved', detail: 'The version changed since you read it.');
		$missing  = new VersionBranchRefused(errorCode: 'no_version', detail: 'There is no such version.', status: 404);

		$this->assertSame(
			expected: ['target_moved', 'The version changed since you read it.', 409],
			actual: [$conflict->errorCode, $conflict->getMessage(), $conflict->status]
		);
		$this->assertSame(
			expected: ['no_version', 'There is no such version.', 404],
			actual: [$missing->errorCode, $missing->getMessage(), $missing->status]
		);
		$this->assertInstanceOf(expected: \RuntimeException::class, actual: $missing);
	}//end testTheRefusalCarriesItsCodeMessageAndStatus()
}//end class
