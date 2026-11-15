<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests the repository API path of a push target.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\GitHub;

use OCA\Buildiq\Kernel\Values\PushTarget;
use PHPUnit\Framework\TestCase;

/**
 * The path names owner and repository, each URL-encoded, and a new target is private by default.
 *
 * @covers \OCA\Buildiq\Kernel\Values\PushTarget
 */
class PushTargetTest extends TestCase {
	/**
	 * A plain owner and repository form the path as they are.
	 *
	 * @return void
	 */
	public function testThePathNamesOwnerAndRepository(): void {
		$target = new PushTarget(org: 'ConductionNL', repo: 'my.app_1');

		$this->assertSame(expected: '/repos/ConductionNL/my.app_1', actual: $target->repoPath());
		$this->assertSame(expected: 'private', actual: $target->visibility);
	}//end testThePathNamesOwnerAndRepository()

	/**
	 * Characters that would change the path are encoded, so a name cannot reach another endpoint.
	 *
	 * @return void
	 */
	public function testEachSegmentIsEncoded(): void {
		$target = new PushTarget(org: 'a/b', repo: '../c d?x', visibility: 'public');

		$this->assertSame(expected: '/repos/a%2Fb/..%2Fc%20d%3Fx', actual: $target->repoPath());
	}//end testEachSegmentIsEncoded()
}//end class
