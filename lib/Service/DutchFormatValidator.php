<?php

/**
 * Buildiq DutchFormatValidator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Checks and normalises the eight Dutch field formats a form field can carry
 * (`bsn`, `iban`, `nl-licence-plate`, `phone-nl`, `phone-international`,
 * `postcode`, `kvk`, `kvk-branch`) with the rules portaliq applies, so a value
 * a buildiq form accepts is one the portal accepts too. The rule tables live in
 * `lib/Settings/dutch-formats.json`, which `src/services/dutchFormats.js` reads
 * as well, so the server and the form cannot drift.
 *
 * A licence plate is normalised to capitals without dashes and a postcode to
 * capitals without the space (form-dutch-field-formats REQ-BQDF-002); an IBAN
 * loses its spaces.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://buildiq.nl
 *
 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\IL10N;
use RuntimeException;

/**
 * The Dutch field formats: check, normalise, and the message for a value that fails.
 *
 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
 */
class DutchFormatValidator {

	/**
	 * The shared rule data file.
	 *
	 * @var string
	 */
	public const RULES_FILE = __DIR__ . '/../Settings/dutch-formats.json';

	/**
	 * The decoded rule data, read once.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $rules = null;

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the refusal messages.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The format names, in the order the designer lists them.
	 *
	 * @return list<string> The names.
	 *
	 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
	 */
	public function formats(): array {
		return array_values(array_map('strval', (array)($this->rules()['formats'] ?? [])));
	}//end formats()

	/**
	 * Whether a format name is one of the Dutch formats.
	 *
	 * @param string $format The name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
	 */
	public function knows(string $format): bool {
		return in_array($format, $this->formats(), true);
	}//end knows()

	/**
	 * The value in its stored form, or null when it fails the format.
	 *
	 * @param string $format The format name.
	 * @param string $value  The value as entered.
	 *
	 * @return string|null The normalised value; null when it fails or the format is unknown.
	 *
	 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
	 */
	public function normalise(string $format, string $value): ?string {
		$value = trim($value);

		return match ($format) {
			'bsn' => $this->bsn(value: $value),
			'iban' => $this->iban(value: $value),
			'nl-licence-plate' => $this->plate(value: $value),
			'phone-nl' => self::phoneNl(value: $value),
			'phone-international' => self::phoneInternational(value: $value),
			'postcode' => $this->postcode(value: $value),
			'kvk' => self::digits(value: $value, length: 8),
			'kvk-branch' => self::digits(value: $value, length: 12),
			default => null,
		};
	}//end normalise()

	/**
	 * The message for a value that fails a format, in the user's language.
	 *
	 * @param string $format The format name.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
	 */
	public function message(string $format): string {
		return match ($format) {
			'bsn' => $this->l10n->t('This BSN is not valid. Check the nine digits.'),
			'iban' => $this->l10n->t('This IBAN is not valid. Check the digits.'),
			'nl-licence-plate' => $this->l10n->t('This is not a Dutch licence plate.'),
			'phone-nl' => $this->l10n->t('Enter a Dutch phone number of ten digits.'),
			'phone-international' => $this->l10n->t('Enter the number with + and the country code.'),
			'postcode' => $this->l10n->t('Enter a postcode like 1234 AB.'),
			'kvk' => $this->l10n->t('A KvK number has eight digits.'),
			'kvk-branch' => $this->l10n->t('A branch number has twelve digits.'),
			default => $this->l10n->t('This value does not have the expected format.'),
		};
	}//end message()

	/**
	 * The rule data.
	 *
	 * @return array<string,mixed> The decoded file.
	 *
	 * @throws RuntimeException When the file is missing or not JSON.
	 */
	private function rules(): array {
		if ($this->rules === null) {
			$decoded = json_decode((string)@file_get_contents(self::RULES_FILE), true);
			if (is_array($decoded) === false) {
				throw new RuntimeException('The Dutch format rules could not be read from ' . self::RULES_FILE . '.');
			}

			$this->rules = $decoded;
		}

		return $this->rules;
	}//end rules()

	/**
	 * Exactly this many digits.
	 *
	 * @param string $value  The value.
	 * @param int    $length The number of digits.
	 *
	 * @return string|null The value, or null.
	 */
	private static function digits(string $value, int $length): ?string {
		if (preg_match('/^\d{' . $length . '}$/', $value) !== 1) {
			return null;
		}

		return $value;
	}//end digits()

	/**
	 * A BSN: nine digits, not all zero, passing the elfproef.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The value, or null.
	 */
	private function bsn(string $value): ?string {
		if (self::digits(value: $value, length: 9) === null || $value === '000000000') {
			return null;
		}

		$sum = 0;
		for ($index = 0; $index < 8; $index++) {
			$sum += ((9 - $index) * (int)$value[$index]);
		}

		$sum -= (int)$value[8];
		if (($sum % 11) !== 0) {
			return null;
		}

		return $value;
	}//end bsn()

	/**
	 * An IBAN without spaces, in capitals, of its country's length, passing mod 97.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The IBAN, or null.
	 */
	private function iban(string $value): ?string {
		$iban = strtoupper((string)preg_replace('/\s+/', '', $value));
		if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
			return null;
		}

		$length = ($this->rules()['ibanLengths'][substr($iban, 0, 2)] ?? null);
		if ($length !== null && strlen($iban) !== (int)$length) {
			return null;
		}

		if (self::mod97(iban: $iban) !== 1) {
			return null;
		}

		return $iban;
	}//end iban()

	/**
	 * The ISO 7064 mod-97 remainder of an IBAN.
	 *
	 * @param string $iban The IBAN in capitals.
	 *
	 * @return int The remainder.
	 */
	private static function mod97(string $iban): int {
		$remainder = 0;
		foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
			$number = $char;
			if (ctype_alpha($char) === true) {
				$number = (string)(ord($char) - 55);
			}

			$remainder = ((int)($remainder . $number) % 97);
		}

		return $remainder;
	}//end mod97()

	/**
	 * A Dutch licence plate in capitals without dashes, fitting an RDW side code.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The plate, or null.
	 */
	private function plate(string $value): ?string {
		$plate = strtoupper((string)preg_replace('/[\s-]+/', '', $value));
		if (preg_match('/^[A-Z0-9]{6}$/', $plate) !== 1) {
			return null;
		}

		$shape = (string)preg_replace(['/[A-Z]/', '/\d/'], ['L', 'D'], $plate);
		$index = array_search($shape, (array)$this->rules()['plateSideCodes'], true);
		if ($index === false) {
			return null;
		}

		$sideCode = ((int)$index + 1);
		$forbidden = (string)$this->rules()['plateForbiddenLetters'];
		if ($sideCode >= (int)$this->rules()['plateVowelFreeFrom'] && strpbrk($plate, $forbidden) !== false) {
			return null;
		}

		return $plate;
	}//end plate()

	/**
	 * A Dutch phone number: 0 and nine digits, or +31 or 0031 and nine digits.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The number without spaces, dashes and brackets, or null.
	 */
	private static function phoneNl(string $value): ?string {
		$number = (string)preg_replace('/[\s\-()]+/', '', $value);
		if (preg_match('/^(0[1-9]\d{8}|(\+|00)31[1-9]\d{8})$/', $number) !== 1) {
			return null;
		}

		return $number;
	}//end phoneNl()

	/**
	 * An international number: + and 7 to 15 digits, the first not 0.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The number without spaces, dashes and brackets, or null.
	 */
	private static function phoneInternational(string $value): ?string {
		$number = (string)preg_replace('/[\s\-()]+/', '', $value);
		if (preg_match('/^\+[1-9]\d{6,14}$/', $number) !== 1) {
			return null;
		}

		return $number;
	}//end phoneInternational()

	/**
	 * A Dutch postcode in capitals without the space.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The postcode, or null.
	 */
	private function postcode(string $value): ?string {
		if (preg_match('/^([1-9]\d{3})\s?([A-Za-z]{2})$/', $value, $match) !== 1) {
			return null;
		}

		$letters = strtoupper($match[2]);
		if (in_array($letters, (array)$this->rules()['postcodeForbiddenLetters'], true) === true) {
			return null;
		}

		return $match[1] . $letters;
	}//end postcode()
}//end class
