<?php

/**
 * FleetAppId::isEnabledForUser() answers false rather than throwing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the enabled check across the fleet rename.
 *
 * @covers \OCA\Buildiq\Support\FleetAppId
 */
class FleetAppIdEnabledTest extends TestCase {

	/**
	 * The enabled check asks the app manager about the installed id, not the canonical one.
	 *
	 * @return void
	 */
	public function testItAsksAboutTheInstalledLegacyId(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $id === 'docudesk');
		$manager->expects(self::once())->method('isEnabledForUser')->with('docudesk')->willReturn(true);

		self::assertTrue(FleetAppId::isEnabledForUser(appManager: $manager, canonical: 'filinq'));
	}//end testItAsksAboutTheInstalledLegacyId()

	/**
	 * An installed but disabled app reads as not enabled.
	 *
	 * @return void
	 */
	public function testADisabledAppIsNotEnabled(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturn(true);
		$manager->expects(self::once())->method('isEnabledForUser')->with('integriq')->willReturn(false);

		self::assertFalse(FleetAppId::isEnabledForUser(appManager: $manager, canonical: 'integriq'));
	}//end testADisabledAppIsNotEnabled()

	/**
	 * An app that is not installed under any name is not enabled, and is never asked about.
	 *
	 * @return void
	 */
	public function testAnAbsentAppIsNotEnabled(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturn(false);
		$manager->expects(self::never())->method('isEnabledForUser');

		self::assertFalse(FleetAppId::isEnabledForUser(appManager: $manager, canonical: 'stackiq'));
	}//end testAnAbsentAppIsNotEnabled()

	/**
	 * An app manager that throws while answering reads as not enabled.
	 *
	 * @return void
	 */
	public function testAThrowingEnabledCheckIsNotEnabled(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturn(true);
		$manager->method('isEnabledForUser')->willThrowException(new RuntimeException('no user session'));

		self::assertFalse(FleetAppId::isEnabledForUser(appManager: $manager, canonical: 'thematiq'));
	}//end testAThrowingEnabledCheckIsNotEnabled()
}//end class
