<?php

/**
 * Unit tests for the Buildiq Capabilities class.
 *
 * Covers the buildiq-inline-edit-persistence change (spec
 * buildiq-capability): the document carries `buildiq.enabled === true` and
 * a boolean `buildiq.canEdit` that reflects the calling user's Buildiq
 * access — true for an in-scope user, false for an out-of-scope or anonymous
 * user.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit;

use OCA\Buildiq\Capabilities;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Capabilities.
 */
class CapabilitiesTest extends TestCase {
	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->appManager = $this->createMock(IAppManager::class);

	}//end setUp()

	/**
	 * Build the Capabilities SUT.
	 *
	 * @return Capabilities
	 */
	private function capabilities(): Capabilities {
		return new Capabilities(
			userSession: $this->userSession,
			appManager: $this->appManager
		);

	}//end capabilities()

	/**
	 * The document carries buildiq.enabled === true and a boolean canEdit.
	 *
	 * @return void
	 */
	public function testDocumentShapeIsPresent(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$doc = $this->capabilities()->getCapabilities();

		self::assertArrayHasKey('buildiq', $doc);
		self::assertTrue($doc['buildiq']['enabled']);
		self::assertIsBool($doc['buildiq']['canEdit']);

	}//end testDocumentShapeIsPresent()

	/**
	 * An in-scope user sees canEdit === true.
	 *
	 * @return void
	 */
	public function testCanEditTrueForInScopeUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$doc = $this->capabilities()->getCapabilities();

		self::assertTrue($doc['buildiq']['canEdit']);

	}//end testCanEditTrueForInScopeUser()

	/**
	 * An out-of-scope user sees canEdit === false.
	 *
	 * @return void
	 */
	public function testCanEditFalseForOutOfScopeUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$doc = $this->capabilities()->getCapabilities();

		self::assertFalse($doc['buildiq']['canEdit']);

	}//end testCanEditFalseForOutOfScopeUser()

	/**
	 * An anonymous caller sees canEdit === false.
	 *
	 * @return void
	 */
	public function testCanEditFalseForAnonymous(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$doc = $this->capabilities()->getCapabilities();

		self::assertFalse($doc['buildiq']['canEdit']);

	}//end testCanEditFalseForAnonymous()
}//end class
