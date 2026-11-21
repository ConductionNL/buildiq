<?php

/**
 * Demo Record Values
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Generates one demo record from a schema's properties, so it validates
 * against that schema (capability-requirements-backfill REQ-BQCV-030): every
 * required property gets a value of its type, format, choice list and
 * bounds; optional scalar properties are filled too, so a list page shows
 * them. A property no value can be made for (a relation, a file, a pattern,
 * a read-only field) is left out, and when it is required the schema gets no
 * demo record at all rather than one that fails validation. Pure.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use stdClass;

/**
 * Builds a schema-valid demo record.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
final class DemoRecordValues {

	/**
	 * The scalar types an optional property is filled for.
	 *
	 * @var list<string>
	 */
	private const SCALARS = ['string', 'integer', 'number', 'boolean'];

	/**
	 * The demo record number `$n` of a schema, or null when a required property cannot be filled.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param list<string>         $required   The schema's required property names.
	 * @param int                  $n          The record's number, from 1; varies the values.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
	 */
	public static function record(array $properties, array $required, int $n): ?array {
		$record = [];
		foreach ($properties as $name => $property) {
			$name = (string)$name;
			$isRequired = in_array($name, $required, true);
			if (is_array($property) === false || ($isRequired === false && self::isOptionalScalar(property: $property) === false)) {
				continue;
			}

			[$filled, $value] = self::value(property: $property, name: $name, n: $n);
			if ($filled === true) {
				$record[$name] = $value;
				continue;
			}

			if ($isRequired === true) {
				return null;
			}
		}

		return $record;
	}//end record()

	/**
	 * A value for one property, as `[filled, value]`.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $name     Its name, for a text value.
	 * @param int                  $n        The record's number.
	 *
	 * @return array{0: bool, 1: mixed}
	 */
	private static function value(array $property, string $name, int $n): array {
		if (self::isUnfillable(property: $property) === true) {
			return [false, null];
		}

		if (array_key_exists('const', $property) === true) {
			return [true, $property['const']];
		}

		$enum = ($property['enum'] ?? null);
		if (is_array($enum) === true && $enum !== []) {
			$choices = array_values($enum);
			return [true, $choices[(($n - 1) % count($choices))]];
		}

		return match ((string)($property['type'] ?? 'string')) {
			'string' => [true, self::text(property: $property, name: $name, n: $n)],
			'integer' => [true, (int)self::bounded(property: $property, n: $n)],
			'number' => [true, (float)self::bounded(property: $property, n: $n)],
			'boolean' => [true, ($n % 2) === 1],
			'array' => self::items(property: $property, name: $name, n: $n),
			'object' => self::nested(property: $property, n: $n),
			default => [false, null],
		};
	}//end value()

	/**
	 * Whether a property is one no demo value is made for.
	 *
	 * A relation or file points at something that may not exist, a pattern
	 * cannot be met in general, and a read-only field is the server's to fill.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return bool
	 */
	private static function isUnfillable(array $property): bool {
		foreach (['$ref', 'x-openregister-relation', 'objectConfiguration', 'pattern'] as $key) {
			if (array_key_exists($key, $property) === true) {
				return true;
			}
		}

		return ($property['readOnly'] ?? false) === true
			|| ($property['type'] ?? null) === 'file'
			|| in_array(($property['format'] ?? null), ['uuid', 'binary', 'byte'], true);
	}//end isUnfillable()

	/**
	 * Whether an optional property is a scalar or a choice, the kinds a list page shows.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return bool
	 */
	private static function isOptionalScalar(array $property): bool {
		return is_array($property['enum'] ?? null) === true || in_array(($property['type'] ?? 'string'), self::SCALARS, true);
	}//end isOptionalScalar()

	/**
	 * A text value in the property's format and length.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $name     Its name.
	 * @param int                  $n        The record's number.
	 *
	 * @return string
	 */
	private static function text(array $property, string $name, int $n): string {
		$day = sprintf('2026-01-%02d', (1 + (($n - 1) % 28)));
		$text = match ((string)($property['format'] ?? '')) {
			'date' => $day,
			'date-time' => $day . 'T09:00:00+00:00',
			'time' => '09:00:00',
			'email', 'idn-email' => 'demo' . $n . '@example.com',
			'uri', 'url', 'iri' => 'https://example.com/demo-' . $n,
			default => 'Demo ' . (string)($property['title'] ?? $name) . ' ' . $n,
		};

		$maxLength = ($property['maxLength'] ?? null);
		if (is_int($maxLength) === true && $maxLength >= 0) {
			$text = substr($text, 0, $maxLength);
		}

		$minLength = ($property['minLength'] ?? null);
		if (is_int($minLength) === true && strlen($text) < $minLength) {
			$text = str_pad($text, $minLength, 'x');
		}

		return $text;
	}//end text()

	/**
	 * A number within the property's bounds, near the record's number.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param int                  $n        The record's number.
	 *
	 * @return int|float
	 */
	private static function bounded(array $property, int $n): int|float {
		$value = $n;
		$minimum = ($property['minimum'] ?? null);
		if (is_numeric($minimum) === true && $value < $minimum) {
			$value = $minimum;
		}

		$exclusiveMinimum = ($property['exclusiveMinimum'] ?? null);
		if (is_numeric($exclusiveMinimum) === true && $value <= $exclusiveMinimum) {
			$value = ($exclusiveMinimum + 1);
		}

		$maximum = ($property['maximum'] ?? null);
		if (is_numeric($maximum) === true && $value > $maximum) {
			$value = $maximum;
		}

		return $value;
	}//end bounded()

	/**
	 * A list value: as many items as the property needs, else none.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param string               $name     Its name.
	 * @param int                  $n        The record's number.
	 *
	 * @return array{0: bool, 1: mixed}
	 */
	private static function items(array $property, string $name, int $n): array {
		$count = (int)($property['minItems'] ?? 0);
		if ($count <= 0) {
			return [true, []];
		}

		$items = ($property['items'] ?? []);
		[$filled, $item] = self::value(property: self::arrayOf(value: $items), name: $name, n: $n);
		if ($filled === false) {
			return [false, null];
		}

		return [true, array_fill(0, $count, $item)];
	}//end items()

	/**
	 * An object value built from its own properties.
	 *
	 * @param array<string, mixed> $property The property.
	 * @param int                  $n        The record's number.
	 *
	 * @return array{0: bool, 1: mixed}
	 */
	private static function nested(array $property, int $n): array {
		$record = self::record(
			properties: self::arrayOf(value: $property['properties'] ?? null),
			required: array_values(array_map('strval', self::arrayOf(value: $property['required'] ?? null))),
			n: $n
		);
		if ($record === null) {
			return [false, null];
		}

		if ($record === []) {
			return [true, new stdClass()];
		}

		return [true, $record];
	}//end nested()

	/**
	 * A value when it is an array, else [].
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private static function arrayOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOf()
}//end class
