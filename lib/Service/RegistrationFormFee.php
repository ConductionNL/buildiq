<?php

/**
 * Buildiq Registration Form Fee
 *
 * The fee a filer pays after sending one registration form. buildiq records
 * the maker's fee and serves it beside the sign-in level; the portal asks for
 * the payment after submit. A form with a fee needs sign-in at level
 * `substantial` or higher, the rule the portal applies to a paid intake.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Checks and reads a registration form's fee.
 *
 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
 */
final class RegistrationFormFee {
	/**
	 * The currency of a fee that names none.
	 */
	public const DEFAULT_CURRENCY = 'EUR';

	/**
	 * The lowest sign-in level a form with a fee may ask for.
	 */
	public const MIN_LEVEL = 'substantial';

	/**
	 * Refuse a fee that is malformed, or that sits on a form whose sign-in
	 * level is missing or below `substantial`. A form without a fee passes.
	 *
	 * @param array<string, mixed> $form The form being saved.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the fee or its sign-in level is refused.
	 *
	 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
	 */
	public function assertValid(array $form): void {
		$fee = ($form['fee'] ?? null);
		if ($fee === null) {
			return;
		}

		if (is_array($fee) === false) {
			throw new InvalidArgumentException('A fee is an amount with its currency and description, not a single value.');
		}

		$this->assertParts(fee: $fee);

		if ($this->levelAllowsAFee(level: ($form['minTrust'] ?? null)) === false) {
			throw new InvalidArgumentException(
				'A form with a fee needs sign-in at level substantial or high; choose that level or remove the fee.'
			);
		}
	}//end assertValid()

	/**
	 * Refuse a fee whose amount is not above zero, whose currency is not a
	 * three-letter ISO 4217 code, or whose description is not text.
	 *
	 * @param array<mixed> $fee The fee.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a part is refused.
	 *
	 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
	 */
	private function assertParts(array $fee): void {
		if (self::isPositiveAmount(amount: ($fee['amount'] ?? null)) === false) {
			throw new InvalidArgumentException('A fee needs an amount greater than zero.');
		}

		$currency = ($fee['currency'] ?? null);
		if ($currency !== null && self::isCurrencyCode(currency: $currency) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown currency %s; expected a three-letter ISO 4217 code such as EUR.', var_export($currency, true))
			);
		}

		$description = ($fee['description'] ?? null);
		if ($description !== null && is_string($description) === false) {
			throw new InvalidArgumentException('A fee description is text.');
		}
	}//end assertParts()

	/**
	 * The fee the form serves, or null for a form that costs nothing. Only a
	 * fee with an amount above zero is served; the currency defaults to EUR.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return array{amount: float, currency: string, description: string}|null The fee.
	 *
	 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
	 */
	public function served(array $form): ?array {
		$fee = ($form['fee'] ?? null);
		if (is_array($fee) === false) {
			return null;
		}

		$amount = ($fee['amount'] ?? null);
		if (self::isPositiveAmount(amount: $amount) === false) {
			return null;
		}

		$currency = ($fee['currency'] ?? '');
		if (self::isCurrencyCode(currency: $currency) === false) {
			$currency = self::DEFAULT_CURRENCY;
		}

		$description = ($fee['description'] ?? '');
		if (is_string($description) === false) {
			$description = '';
		}

		return [
			'amount' => (float) $amount,
			'currency' => $currency,
			'description' => $description,
		];
	}//end served()

	/**
	 * Whether a sign-in level is `substantial` or higher, in the order of
	 * RegistrationFormSignInLevel::LEVELS. No level, or an unknown one, is not.
	 *
	 * @param mixed $level The form's `minTrust`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
	 */
	private function levelAllowsAFee(mixed $level): bool {
		$rank = array_search($level, RegistrationFormSignInLevel::LEVELS, true);
		if ($rank === false) {
			return false;
		}

		return $rank >= array_search(self::MIN_LEVEL, RegistrationFormSignInLevel::LEVELS, true);
	}//end levelAllowsAFee()

	/**
	 * Whether a value is a number above zero.
	 *
	 * @param mixed $amount The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
	 */
	private static function isPositiveAmount(mixed $amount): bool {
		return (is_int($amount) === true || is_float($amount) === true) && $amount > 0;
	}//end isPositiveAmount()

	/**
	 * Whether a value is a three-letter upper-case ISO 4217 code.
	 *
	 * @param mixed $currency The value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/forms-fee-on-submit/specs/registration-form-builder/spec.md (REQ-OBRF-010)
	 */
	private static function isCurrencyCode(mixed $currency): bool {
		return is_string($currency) === true && preg_match('/^[A-Z]{3}$/', $currency) === 1;
	}//end isCurrencyCode()
}//end class
