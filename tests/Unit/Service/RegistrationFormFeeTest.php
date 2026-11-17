<?php

/**
 * Unit tests for the fee on a registration form.
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

use InvalidArgumentException;
use OCA\Buildiq\Service\RegistrationFormFee;
use PHPUnit\Framework\TestCase;

/**
 * A fee is an amount above zero with an ISO currency and a line, and only a
 * form signed in at substantial or higher may carry one.
 */
final class RegistrationFormFeeTest extends TestCase {
	/**
	 * The fee rules.
	 *
	 * @var RegistrationFormFee
	 */
	private RegistrationFormFee $fee;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fee = new RegistrationFormFee();
	}//end setUp()

	/**
	 * A form without a fee passes whatever its sign-in level.
	 *
	 * @return void
	 */
	public function testAFormWithoutAFeePasses(): void {
		$this->fee->assertValid(['name' => 'intake']);
		$this->fee->assertValid(['name' => 'intake', 'fee' => null, 'minTrust' => 'low']);

		self::assertNull($this->fee->served(['name' => 'intake']));
	}//end testAFormWithoutAFeePasses()

	/**
	 * A well-formed fee on a substantial or high form passes; the currency may
	 * be left out.
	 *
	 * @return void
	 */
	public function testAFeeOnASubstantialOrHighFormPasses(): void {
		$this->fee->assertValid(['minTrust' => 'substantial', 'fee' => ['amount' => 25, 'currency' => 'EUR', 'description' => 'Leges']]);
		$this->fee->assertValid(['minTrust' => 'high', 'fee' => ['amount' => 12.5]]);

		self::assertTrue(true, 'Neither fee was refused.');
	}//end testAFeeOnASubstantialOrHighFormPasses()

	/**
	 * A fee on a form without sign-in, or below substantial, or with an
	 * unknown level, is refused naming the level it needs.
	 *
	 * @return void
	 */
	public function testAFeeBelowSubstantialIsRefused(): void {
		foreach ([null, '', 'low', 'digid'] as $level) {
			try {
				$this->fee->assertValid(['minTrust' => $level, 'fee' => ['amount' => 25]]);
				self::fail('A fee at sign-in level ' . var_export($level, true) . ' was accepted');
			} catch (InvalidArgumentException $e) {
				self::assertSame(
					'A form with a fee needs sign-in at level substantial or high; choose that level or remove the fee.',
					$e->getMessage()
				);
			}
		}
	}//end testAFeeBelowSubstantialIsRefused()

	/**
	 * An amount of zero, below zero, missing or not a number is refused.
	 *
	 * @return void
	 */
	public function testAnAmountThatIsNotAboveZeroIsRefused(): void {
		foreach ([0, -5, -0.01, null, '25'] as $amount) {
			try {
				$this->fee->assertValid(['minTrust' => 'substantial', 'fee' => ['amount' => $amount]]);
				self::fail('Amount ' . var_export($amount, true) . ' was accepted');
			} catch (InvalidArgumentException $e) {
				self::assertSame('A fee needs an amount greater than zero.', $e->getMessage());
			}
		}
	}//end testAnAmountThatIsNotAboveZeroIsRefused()

	/**
	 * A currency that is not a three-letter upper-case code, a description
	 * that is not text, and a fee that is not an object are refused.
	 *
	 * @return void
	 */
	public function testAMalformedCurrencyDescriptionOrFeeIsRefused(): void {
		$cases = [
			[['amount' => 25, 'currency' => 'eur'], 'Unknown currency'],
			[['amount' => 25, 'currency' => 'EURO'], 'Unknown currency'],
			[['amount' => 25, 'description' => 7], 'description is text'],
			[25, 'not a single value'],
		];
		foreach ($cases as [$fee, $reason]) {
			try {
				$this->fee->assertValid(['minTrust' => 'substantial', 'fee' => $fee]);
				self::fail('Fee ' . var_export($fee, true) . ' was accepted');
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString($reason, $e->getMessage());
			}
		}
	}//end testAMalformedCurrencyDescriptionOrFeeIsRefused()

	/**
	 * The served fee carries the amount as a number, the currency (EUR when
	 * none or a malformed one is stored) and the line.
	 *
	 * @return void
	 */
	public function testTheServedFeeIsNormalised(): void {
		self::assertSame(
			['amount' => 25.0, 'currency' => 'EUR', 'description' => 'Leges evenementenvergunning'],
			$this->fee->served(['fee' => ['amount' => 25, 'currency' => 'EUR', 'description' => 'Leges evenementenvergunning']])
		);
		self::assertSame(
			['amount' => 9.95, 'currency' => 'EUR', 'description' => ''],
			$this->fee->served(['fee' => ['amount' => 9.95, 'currency' => 'eur']])
		);
		self::assertSame(
			['amount' => 10.0, 'currency' => 'USD', 'description' => ''],
			$this->fee->served(['fee' => ['amount' => 10, 'currency' => 'USD', 'description' => ['x']]])
		);
	}//end testTheServedFeeIsNormalised()

	/**
	 * A fee without an amount above zero is not served.
	 *
	 * @return void
	 */
	public function testAFeeWithoutAPositiveAmountIsNotServed(): void {
		self::assertNull($this->fee->served(['fee' => ['amount' => 0]]));
		self::assertNull($this->fee->served(['fee' => ['currency' => 'EUR']]));
		self::assertNull($this->fee->served(['fee' => 'EUR 25']));
	}//end testAFeeWithoutAPositiveAmountIsNotServed()
}//end class
