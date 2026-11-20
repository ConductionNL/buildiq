<?php

/**
 * Unit tests for AppPushRequest: the access, version and optional new repository of one app push.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Kernel\Values
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Kernel\Values;

use OCA\Buildiq\Kernel\Values\AppPushRequest;
use OCA\Buildiq\Kernel\Values\BrokerAccess;
use OCA\Buildiq\Kernel\Values\PushTarget;
use PHPUnit\Framework\TestCase;

/**
 * Construction, defaults and immutability of AppPushRequest.
 *
 * @covers \OCA\Buildiq\Kernel\Values\AppPushRequest
 * @uses   \OCA\Buildiq\Kernel\Values\BrokerAccess
 * @uses   \OCA\Buildiq\Kernel\Values\PushTarget
 */
final class AppPushRequestTest extends TestCase {

	/**
	 * The access and new-repository values are the very objects passed in.
	 *
	 * @return void
	 */
	public function testKeepsTheSameAccessAndTargetInstances(): void {
		$access  = new BrokerAccess(credentialId: 'cred-1', actingUserId: 'alice');
		$target  = new PushTarget(org: 'ConductionNL', repo: 'pet-store', visibility: 'public');
		$request = new AppPushRequest(access: $access, versionSlug: 'v1-0', newRepo: $target);

		$this->assertSame(expected: $access, actual: $request->access);
		$this->assertSame(expected: 'v1-0', actual: $request->versionSlug);
		$this->assertSame(expected: $target, actual: $request->newRepo);
	}//end testKeepsTheSameAccessAndTargetInstances()

	/**
	 * Without a version or new repository both stay null, so the push targets the default version and existing repository.
	 *
	 * @return void
	 */
	public function testVersionAndNewRepoDefaultToNull(): void {
		$request = new AppPushRequest(access: new BrokerAccess(credentialId: 'cred-1'));

		$this->assertNull(actual: $request->versionSlug);
		$this->assertNull(actual: $request->newRepo);
	}//end testVersionAndNewRepoDefaultToNull()

	/**
	 * A AppPushRequest cannot be changed after construction.
	 *
	 * @return void
	 */
	public function testPropertiesAreReadOnly(): void {
		$value = new AppPushRequest(access: new BrokerAccess(credentialId: 'cred-1'));

		$this->expectException(exception: \Error::class);
		$this->expectExceptionMessage(message: 'Cannot modify readonly property');

		$value->versionSlug = 'v2';
	}//end testPropertiesAreReadOnly()
}//end class
