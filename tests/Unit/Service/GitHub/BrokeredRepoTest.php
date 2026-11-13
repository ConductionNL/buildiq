<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests that a brokered repository re-targets owner or name without touching the rest.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\GitHub;

use OCA\Buildiq\Service\GitHub\BrokerAccess;
use OCA\Buildiq\Service\GitHub\BrokeredRepo;
use OCA\Buildiq\Service\GitHub\PushTarget;
use PHPUnit\Framework\TestCase;

/**
 * ownedBy() and named() return a new repository; the original and the access stay as they were.
 *
 * @covers \OCA\Buildiq\Service\GitHub\BrokeredRepo
 * @uses   \OCA\Buildiq\Service\GitHub\PushTarget
 * @uses   \OCA\Buildiq\Service\GitHub\BrokerAccess
 */
class BrokeredRepoTest extends TestCase {
	/**
	 * A public repository acme/widgets brokered by credential cred-1 for alice.
	 *
	 * @return BrokeredRepo
	 */
	private static function repo(): BrokeredRepo {
		return new BrokeredRepo(
			target: new PushTarget(org: 'acme', repo: 'widgets', visibility: 'public'),
			access: new BrokerAccess(credentialId: 'cred-1', actingUserId: 'alice')
		);
	}//end repo()

	/**
	 * A new owner keeps the name, visibility and the same access.
	 *
	 * @return void
	 */
	public function testOwnedByChangesOnlyTheOwner(): void {
		$original = self::repo();
		$moved    = $original->ownedBy(owner: 'other-org');

		$this->assertNotSame(expected: $original, actual: $moved);
		$this->assertSame(expected: 'other-org', actual: $moved->target->org);
		$this->assertSame(expected: 'widgets', actual: $moved->target->repo);
		$this->assertSame(expected: 'public', actual: $moved->target->visibility);
		$this->assertSame(expected: $original->access, actual: $moved->access);
		$this->assertSame(expected: '/repos/other-org/widgets', actual: $moved->target->repoPath());
	}//end testOwnedByChangesOnlyTheOwner()

	/**
	 * A new name keeps the owner, visibility and the same access.
	 *
	 * @return void
	 */
	public function testNamedChangesOnlyTheName(): void {
		$original = self::repo();
		$renamed  = $original->named(name: 'gadgets');

		$this->assertNotSame(expected: $original, actual: $renamed);
		$this->assertSame(expected: 'acme', actual: $renamed->target->org);
		$this->assertSame(expected: 'gadgets', actual: $renamed->target->repo);
		$this->assertSame(expected: 'public', actual: $renamed->target->visibility);
		$this->assertSame(expected: $original->access, actual: $renamed->access);
		$this->assertSame(expected: 'cred-1', actual: $renamed->access->credentialId);
		$this->assertSame(expected: 'alice', actual: $renamed->access->actingUserId);
	}//end testNamedChangesOnlyTheName()

	/**
	 * Re-targeting leaves the original repository untouched.
	 *
	 * @return void
	 */
	public function testTheOriginalIsUnchanged(): void {
		$original = self::repo();
		$original->ownedBy(owner: 'other-org')->named(name: 'gadgets');

		$this->assertSame(expected: '/repos/acme/widgets', actual: $original->target->repoPath());
		$this->assertSame(expected: 'public', actual: $original->target->visibility);
	}//end testTheOriginalIsUnchanged()

	/**
	 * Chained re-targeting composes, and the path encodes what it is given.
	 *
	 * @return void
	 */
	public function testChainedRetargetingEncodesThePath(): void {
		$chained = self::repo()->named(name: 'my repo')->ownedBy(owner: 'a/b');

		$this->assertSame(expected: '/repos/a%2Fb/my%20repo', actual: $chained->target->repoPath());
		$this->assertSame(expected: 'public', actual: $chained->target->visibility);
	}//end testChainedRetargetingEncodesThePath()
}//end class
