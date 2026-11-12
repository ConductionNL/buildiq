<?php

/**
 * Unit tests for how Capabilities decides `canEdit`: it asks the app manager
 * about the signed-in user for this app, and never asks for an anonymous one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit;

use OCA\Buildiq\Capabilities;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Capabilities `canEdit` check.
 *
 * @covers \OCA\Buildiq\Capabilities
 */
final class CapabilitiesCheckTest extends TestCase {

	/**
	 * The signed-in user is checked against the buildiq app, and the whole document is exactly that answer.
	 *
	 * @return void
	 */
	public function testTheSignedInUserIsCheckedForThisApp(): void {
		$user    = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('isEnabledForUser')->with('buildiq', $user)->willReturn(true);

		$doc = (new Capabilities(userSession: $session, appManager: $apps))->getCapabilities();

		self::assertSame(['buildiq' => ['enabled' => true, 'canEdit' => true]], $doc);
	}//end testTheSignedInUserIsCheckedForThisApp()

	/**
	 * Without a user the app manager is not asked, and the app still reports itself enabled.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsNotChecked(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->never())->method('isEnabledForUser');

		$doc = (new Capabilities(userSession: $session, appManager: $apps))->getCapabilities();

		self::assertSame(['buildiq' => ['enabled' => true, 'canEdit' => false]], $doc);
	}//end testAnAnonymousCallerIsNotChecked()
}//end class
