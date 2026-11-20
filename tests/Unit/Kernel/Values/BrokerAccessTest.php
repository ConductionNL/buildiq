<?php

/**
 * Unit tests for BrokerAccess: the credential id and acting user a brokered GitHub call is made with.
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

use OCA\Buildiq\Kernel\Values\BrokerAccess;
use PHPUnit\Framework\TestCase;

/**
 * Construction, defaults and immutability of BrokerAccess.
 *
 * @covers \OCA\Buildiq\Kernel\Values\BrokerAccess
 */
final class BrokerAccessTest extends TestCase {

	/**
	 * The credential id and acting user are kept exactly as given, without trimming.
	 *
	 * @return void
	 */
	public function testKeepsTheCredentialAndActingUserVerbatim(): void {
		$access = new BrokerAccess(credentialId: ' cred-1 ', actingUserId: 'Alice');

		$this->assertSame(expected: ' cred-1 ', actual: $access->credentialId);
		$this->assertSame(expected: 'Alice', actual: $access->actingUserId);
	}//end testKeepsTheCredentialAndActingUserVerbatim()

	/**
	 * Without an acting user the call is made for no user at all, not an empty one.
	 *
	 * @return void
	 */
	public function testActingUserDefaultsToNull(): void {
		$access = new BrokerAccess(credentialId: 'cred-1');

		$this->assertNull(actual: $access->actingUserId);
	}//end testActingUserDefaultsToNull()

	/**
	 * A BrokerAccess cannot be changed after construction.
	 *
	 * @return void
	 */
	public function testPropertiesAreReadOnly(): void {
		$value = new BrokerAccess(credentialId: 'cred-1');

		$this->expectException(exception: \Error::class);
		$this->expectExceptionMessage(message: 'Cannot modify readonly property');

		$value->credentialId = 'cred-2';
	}//end testPropertiesAreReadOnly()
}//end class
