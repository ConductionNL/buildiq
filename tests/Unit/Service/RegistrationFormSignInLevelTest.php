<?php

/**
 * Unit tests for RegistrationFormSignInLevel: the sign-in level a registration form may ask for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\RegistrationFormSignInLevel;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegistrationFormSignInLevel.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\RegistrationFormSignInLevel
 */
class RegistrationFormSignInLevelTest extends TestCase {

	/**
	 * No level, an empty level, or a known level on a non-public form are accepted.
	 *
	 * @return void
	 */
	public function testValidFormsPass(): void {
		$rules = new RegistrationFormSignInLevel();

		$rules->assertValid([]);
		$rules->assertValid(['minTrust' => '', 'isPublic' => true]);
		$rules->assertValid(['minTrust' => null, 'isPublic' => true]);
		$rules->assertValid(['minTrust' => 'substantial']);
		$rules->assertValid(['minTrust' => 'high', 'isPublic' => 'yes']);

		$this->addToAssertionCount(1);
	}//end testValidFormsPass()

	/**
	 * An unknown or non-string level is refused with the level shown as exported.
	 *
	 * @return void
	 */
	public function testAnUnknownLevelIsRefused(): void {
		$rules = new RegistrationFormSignInLevel();

		try {
			$rules->assertValid(['minTrust' => 'medium']);
			$this->fail('An unknown level was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(
				"Unknown sign-in level 'medium'; expected one of low, substantial, high, or none for an anonymous form.",
				$e->getMessage()
			);
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown sign-in level 2;');
		$rules->assertValid(['minTrust' => 2]);
	}//end testAnUnknownLevelIsRefused()

	/**
	 * A public form cannot also require a sign-in level.
	 *
	 * @return void
	 */
	public function testAPublicFormCannotRequireALevel(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A form open to anyone without signing in cannot also ask for a sign-in level; choose one of the two.');

		(new RegistrationFormSignInLevel())->assertValid(['minTrust' => 'low', 'isPublic' => true]);
	}//end testAPublicFormCannotRequireALevel()
}//end class
