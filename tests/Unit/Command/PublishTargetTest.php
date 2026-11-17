<?php

/**
 * Unit tests for the publish target of the publish-templates command.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Command;

use OCA\Buildiq\Command\PublishTarget;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PublishTarget.
 *
 * @covers \OCA\Buildiq\Command\PublishTarget
 * @uses   \OCA\Buildiq\Kernel\Values\BrokeredRepo
 * @uses   \OCA\Buildiq\Kernel\Values\PushTarget
 * @uses   \OCA\Buildiq\Kernel\Values\BrokerAccess
 */
class PublishTargetTest extends TestCase {

	/**
	 * The repo carries the org, no name yet, the visibility, the credential and the acting user.
	 *
	 * @return void
	 */
	public function testTheRepoCarriesTheOrgVisibilityAndAccess(): void {
		$repo = (new PublishTarget(credentialId: 'cred-1', org: 'ConductionNL', user: 'sanne', visibility: 'public'))->brokeredRepo();

		$this->assertSame('ConductionNL', $repo->target->org);
		$this->assertSame('', $repo->target->repo);
		$this->assertSame('public', $repo->target->visibility);
		$this->assertSame('cred-1', $repo->access->credentialId);
		$this->assertSame('sanne', $repo->access->actingUserId);

	}//end testTheRepoCarriesTheOrgVisibilityAndAccess()

	/**
	 * Without an org the repo goes to the credential user's own account (empty org); no user stays null.
	 *
	 * @return void
	 */
	public function testWithoutAnOrgTheRepoGoesToTheCredentialAccount(): void {
		$repo = (new PublishTarget(credentialId: 'cred-2', org: null, user: null, visibility: 'private'))->brokeredRepo();

		$this->assertSame('', $repo->target->org);
		$this->assertSame('private', $repo->target->visibility);
		$this->assertSame('cred-2', $repo->access->credentialId);
		$this->assertNull($repo->access->actingUserId);

	}//end testWithoutAnOrgTheRepoGoesToTheCredentialAccount()
}//end class
