<?php

/**
 * Unit tests for the sign-in level on a served registration form.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\RegistrationFormPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The served form carries the sign-in level the maker chose (buildiq#935).
 */
final class RegistrationFormPresenterSignInTest extends TestCase {
	/**
	 * A stored level is served as it was chosen.
	 *
	 * @return void
	 */
	public function testAStoredLevelIsServed(): void {
		$served = (new RegistrationFormPresenter())->serve(['name' => 'intake', 'minTrust' => 'substantial']);

		self::assertSame('substantial', $served['minTrust']);
	}//end testAStoredLevelIsServed()

	/**
	 * No level, or one portaliq does not know, is served as null: anonymous
	 * is the absence of a level, never a made-up value.
	 *
	 * @return void
	 */
	public function testNoLevelOrAnUnknownOneIsServedAsNull(): void {
		$presenter = new RegistrationFormPresenter();

		self::assertNull($presenter->serve(['name' => 'intake'])['minTrust']);
		self::assertNull($presenter->serve(['name' => 'intake', 'minTrust' => 0])['minTrust']);
	}//end testNoLevelOrAnUnknownOneIsServedAsNull()
}//end class
