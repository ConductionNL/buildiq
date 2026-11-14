<?php

/**
 * Unit tests for the fee on a served registration form.
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
 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\RegistrationFormPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The served form carries the fee beside the sign-in level, so the portal can
 * ask for payment after submit (REQ-OBRF-010).
 */
final class RegistrationFormPresenterFeeTest extends TestCase {
	/**
	 * A permit with a fee serves it beside its sign-in level.
	 *
	 * @return void
	 */
	public function testTheServedFormCarriesTheFee(): void {
		$served = (new RegistrationFormPresenter())->serve(
			[
				'name' => 'Evenementenvergunning',
				'minTrust' => 'substantial',
				'fee' => ['amount' => 25, 'currency' => 'EUR', 'description' => 'Leges evenementenvergunning'],
			]
		);

		self::assertSame('substantial', $served['minTrust']);
		self::assertSame(['amount' => 25.0, 'currency' => 'EUR', 'description' => 'Leges evenementenvergunning'], $served['fee']);
	}//end testTheServedFormCarriesTheFee()

	/**
	 * A form without a fee serves `fee: null`, so the portal asks for nothing.
	 *
	 * @return void
	 */
	public function testAFormWithoutAFeeServesNull(): void {
		$served = (new RegistrationFormPresenter())->serve(['name' => 'intake']);

		self::assertArrayHasKey('fee', $served);
		self::assertNull($served['fee']);
	}//end testAFormWithoutAFeeServesNull()
}//end class
