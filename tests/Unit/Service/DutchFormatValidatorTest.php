<?php

/**
 * Unit tests for DutchFormatValidator (spec `form-dutch-field-formats`).
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
 *
 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DutchFormatValidator;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The eight Dutch formats against valid and invalid samples.
 */
#[CoversClass(DutchFormatValidator::class)]
class DutchFormatValidatorTest extends TestCase {

	/**
	 * The validator with an IL10N that returns its source text.
	 *
	 * @return DutchFormatValidator
	 */
	private function validator(): DutchFormatValidator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DutchFormatValidator($l10n);
	}//end validator()

	/**
	 * Values each format accepts, with their stored form.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function validSamples(): array {
		return [
			'bsn' => ['bsn', '111222333', '111222333'],
			'iban with spaces' => ['iban', 'nl91 abna 0417 1643 00', 'NL91ABNA0417164300'],
			'german iban' => ['iban', 'DE89370400440532013000', 'DE89370400440532013000'],
			'plate with dashes' => ['nl-licence-plate', 'gz-482-k', 'GZ482K'],
			'old plate with a vowel' => ['nl-licence-plate', 'AB-12-34', 'AB1234'],
			'dutch mobile' => ['phone-nl', '06-12 34 56 78', '0612345678'],
			'dutch +31' => ['phone-nl', '+31 20 123 4567', '+31201234567'],
			'international' => ['phone-international', '+44 (20) 7946 0958', '+442079460958'],
			'postcode with space' => ['postcode', '1234 ab', '1234AB'],
			'postcode without space' => ['postcode', '9999ZZ', '9999ZZ'],
			'kvk' => ['kvk', '12345678', '12345678'],
			'kvk branch' => ['kvk-branch', '000012345678', '000012345678'],
		];
	}//end validSamples()

	/**
	 * Values each format refuses.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function invalidSamples(): array {
		return [
			'bsn failing the elfproef' => ['bsn', '123456789'],
			'bsn of zeros' => ['bsn', '000000000'],
			'bsn of eight digits' => ['bsn', '11122233'],
			'iban with a wrong check digit' => ['iban', 'NL91ABNA0417164301'],
			'dutch iban too long' => ['iban', 'NL91ABNA04171643000'],
			'plate of a new side code with a vowel' => ['nl-licence-plate', 'GA-482-K'],
			'plate of no side code' => ['nl-licence-plate', 'GZ4K82'],
			'dutch phone too short' => ['phone-nl', '061234567'],
			'international without plus' => ['phone-international', '442079460958'],
			'postcode starting with 0' => ['postcode', '0123 AB'],
			'postcode with SS' => ['postcode', '1234 SS'],
			'kvk of nine digits' => ['kvk', '123456789'],
			'branch of eleven digits' => ['kvk-branch', '12345678901'],
			'unknown format' => ['email', 'a@b.nl'],
		];
	}//end invalidSamples()

	/**
	 * A valid value is accepted in its stored form.
	 *
	 * @param string $format   The format.
	 * @param string $value    The entered value.
	 * @param string $expected The stored value.
	 *
	 * @return void
	 */
	#[DataProvider('validSamples')]
	public function testAValidValueIsNormalised(string $format, string $value, string $expected): void {
		$this->assertSame($expected, $this->validator()->normalise(format: $format, value: $value));
	}//end testAValidValueIsNormalised()

	/**
	 * An invalid value is refused.
	 *
	 * @param string $format The format.
	 * @param string $value  The entered value.
	 *
	 * @return void
	 */
	#[DataProvider('invalidSamples')]
	public function testAnInvalidValueIsRefused(string $format, string $value): void {
		$this->assertNull($this->validator()->normalise(format: $format, value: $value));
	}//end testAnInvalidValueIsRefused()

	/**
	 * The format list is portaliq's eight names, and only those are known.
	 *
	 * @return void
	 */
	public function testTheFormatsArePortaliqsEight(): void {
		$validator = $this->validator();

		$this->assertSame(
			['bsn', 'iban', 'nl-licence-plate', 'phone-nl', 'phone-international', 'postcode', 'kvk', 'kvk-branch'],
			$validator->formats()
		);
		$this->assertTrue($validator->knows(format: 'kvk-branch'));
		$this->assertFalse($validator->knows(format: 'email'));
	}//end testTheFormatsArePortaliqsEight()

	/**
	 * A wrong IBAN is refused with the spec's message, translated.
	 *
	 * @return void
	 */
	public function testTheIbanMessageIsTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')
			->with('This IBAN is not valid. Check the digits.')
			->willReturn('Dit IBAN klopt niet. Controleer de cijfers.');

		$this->assertSame('Dit IBAN klopt niet. Controleer de cijfers.', (new DutchFormatValidator($l10n))->message(format: 'iban'));
	}//end testTheIbanMessageIsTranslated()
}//end class
