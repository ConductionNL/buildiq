<?php

/**
 * FormPageConfig — the form-page half of ManifestPageShape.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A form page's `fields[]` entries become `{key, label, type}` objects and the
 * form names exactly one place to post to. Like ManifestPageShape it never
 * invents content: a submit endpoint is only written when the page already
 * says which register and schema it belongs to.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Normalises the config block of a form page.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */
final class FormPageConfig {

	/**
	 * Field types the canonical validator accepts on a form page.
	 *
	 * @var array<int, string>
	 */
	private const FORM_FIELD_TYPES = ['boolean', 'number', 'string', 'enum', 'password', 'json', 'file'];

	/**
	 * Bring a form page's config up to the validator's contract: `fields[]`
	 * entries are objects, and exactly one submit destination is named.
	 *
	 * @param array<string, mixed> $config The page's config block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function normalise(array $config): array {
		if (isset($config['fields']) === true && is_array($config['fields']) === true) {
			$fields = [];
			foreach ($config['fields'] as $field) {
				$normalised = self::normaliseField(field: $field);
				if ($normalised !== null) {
					$fields[] = $normalised;
				}
			}

			$config['fields'] = $fields;
		}

		return self::withDestination(config: self::withSubmitDestination(config: self::withDestinationPair(config: $config)));
	}//end normalise()
	/**
	 * Fill `register` and `schema` from a `destination` the form names.
	 *
	 * Decision 179: every form names its destination object. `destination`
	 * is the authority when present; `register`/`schema` and the derived
	 * `submitEndpoint` follow from it, so the three cannot disagree.
	 *
	 * @param array<string, mixed> $config The page's config block.
	 *
	 * @return array<string, mixed>
	 */
	private static function withDestinationPair(array $config): array {
		$destination = ($config['destination'] ?? null);
		if (is_array($destination) === false) {
			return $config;
		}

		$register = self::stringValue(value: ($destination['register'] ?? null));
		$schema = self::stringValue(value: ($destination['schema'] ?? null));
		if ($register !== '' && $schema !== '') {
			$config['register'] = $register;
			$config['schema'] = $schema;
		}

		return $config;
	}//end withDestinationPair()
	/**
	 * Record the form's destination `{register, schema}` when it posts into one.
	 *
	 * Read from the register/schema pair, or else from a submit endpoint
	 * shaped like OpenRegister's `/api/objects/{register}/{schema}`. A form
	 * with a custom submit handler posts through app code and gets none.
	 *
	 * @param array<string, mixed> $config The page's config block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	private static function withDestination(array $config): array {
		if (self::stringValue(value: ($config['submitHandler'] ?? null)) !== '') {
			return $config;
		}

		$register = self::stringValue(value: ($config['register'] ?? null));
		$schema = self::stringValue(value: ($config['schema'] ?? null));
		$endpoint = self::stringValue(value: ($config['submitEndpoint'] ?? null));
		$match = [];
		if (preg_match('#^/(?:index\.php/)?(?:apps/openregister/)?api/objects/([^/]+)/([^/]+)/?$#', $endpoint, $match) === 1) {
			$register = $match[1];
			$schema = $match[2];
		}

		if ($register !== '' && $schema !== '') {
			$config['destination'] = ['register' => $register, 'schema' => $schema];
		}

		return $config;
	}//end withDestination()
	/**
	 * Make sure a form names exactly one place to post to.
	 *
	 * A destination the caller named is left alone. With none, the page already
	 * says which register and schema it belongs to in every plan seen so far,
	 * and the OpenRegister objects endpoint is where an index page on the same
	 * pair reads from, so the form posts to the collection it lists. Nothing is
	 * invented when that pair is absent: the page stays invalid and says so.
	 *
	 * @param array<string, mixed> $config The page's config block.
	 *
	 * @return array<string, mixed>
	 */
	private static function withSubmitDestination(array $config): array {
		foreach (['submitHandler', 'submitEndpoint'] as $key) {
			if (self::stringValue(value: ($config[$key] ?? null)) !== '') {
				return $config;
			}
		}

		$register = self::stringValue(value: ($config['register'] ?? null));
		$schema = self::stringValue(value: ($config['schema'] ?? null));
		if ($register !== '' && $schema !== '') {
			$config['submitEndpoint'] = '/apps/openregister/api/objects/' . $register . '/' . $schema;
		}

		return $config;
	}//end withSubmitDestination()
	/**
	 * Turn one `fields[]` entry into the `{key, label, type}` object the
	 * validator wants, or drop it when there is no key to build on.
	 *
	 * @param mixed $field A field entry: a bare property name, or an object.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function normaliseField(mixed $field): ?array {
		if (is_string($field) === true) {
			$key = trim($field);
			if ($key === '') {
				return null;
			}

			return ['key' => $key, 'label' => self::humanise(value: $key), 'type' => 'string'];
		}

		if (is_array($field) === false) {
			return null;
		}

		$key = self::stringValue(value: ($field['key'] ?? null));
		if ($key === '') {
			return null;
		}

		$field['key'] = $key;
		if (is_string(($field['label'] ?? null)) === false || trim($field['label']) === '') {
			$field['label'] = self::humanise(value: $key);
		}

		if (in_array(($field['type'] ?? null), self::FORM_FIELD_TYPES, true) === false) {
			$field['type'] = 'string';
		}

		return $field;
	}//end normaliseField()
	/**
	 * Read a value as a trimmed string, or '' when it is not one.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return string
	 */
	private static function stringValue(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end stringValue()
	/**
	 * Turn a property name into a readable label: `borrowedOn` reads
	 * `Borrowed on`, `asset_tag` reads `Asset tag`.
	 *
	 * @param string $value A property name.
	 *
	 * @return string
	 */
	private static function humanise(string $value): string {
		$spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $value);
		if (is_string($spaced) === false) {
			$spaced = $value;
		}

		$spaced = trim(preg_replace('/[-_]+/', ' ', $spaced) ?? $spaced);
		$spaced = mb_strtolower($spaced);
		if ($spaced === '') {
			return $value;
		}

		return (mb_strtoupper(mb_substr($spaced, 0, 1)) . mb_substr($spaced, 1));
	}//end humanise()
}//end class
