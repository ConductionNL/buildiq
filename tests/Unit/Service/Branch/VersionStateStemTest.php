<?php

/**
 * Unit tests for VersionState::stemOf(): the stem of a version register slug.
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
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Branch;

use OCA\Buildiq\Service\Branch\VersionState;
use PHPUnit\Framework\TestCase;

/**
 * Only a register named after a version has a stem.
 *
 * @covers \OCA\Buildiq\Service\Branch\VersionState
 */
class VersionStateStemTest extends TestCase {

	/**
	 * A register without the version prefix, or with the prefix alone, has no stem.
	 *
	 * @return void
	 */
	public function testARegisterThatIsNoVersionHasNoStem(): void {
		$this->assertSame(expected: '', actual: VersionState::stemOf(registerSlug: ''));
		$this->assertSame(expected: '', actual: VersionState::stemOf(registerSlug: 'decidiq'));
		$this->assertSame(expected: '', actual: VersionState::stemOf(registerSlug: 'openbuild-'));
	}//end testARegisterThatIsNoVersionHasNoStem()
}//end class
