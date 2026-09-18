<?php

/**
 * ManifestPageShape — bring a tool-authored page up to what the validator wants.
 *
 * `upsertPage` takes a free-form `config` object and stores it verbatim, which
 * is fine until the page type has fields the canonical manifest validator
 * insists on. Two shapes turned up on the first real plan of the day, from a
 * model that had followed the tool schema exactly:
 *
 *  - a `form` page whose `config.fields` was `["tool", "member", "dueOn"]`,
 *    a list of names, where the validator wants `{key, label, type}` objects,
 *    and which declared neither `submitHandler` nor `submitEndpoint`, so the
 *    form had nowhere to post;
 *  - a `dashboard` page whose `config.layout` was the string `"grid"`, where
 *    the validator wants an array of placements.
 *
 * Each of those makes the WHOLE manifest invalid, which in the copilot wizard
 * means Confirm and create stays disabled and the reader is told to rephrase
 * their brief. The tool descriptions now spell the requirements out, but a
 * description is a hope and this is a guarantee: whatever the model sends, the
 * page that comes out of here is one the validator accepts.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It never invents content. A field list
 * of names keeps those names; a title is derived from the name it was given;
 * a submit endpoint is only written when the page already says which register
 * and schema it belongs to. Where intent is genuinely missing, the page stays
 * as the model wrote it and the validator's message reaches the reader, which
 * is the honest outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * Normalises a page written by the builder tools.
 *
 * @spec openspec/specs/ai-copilot/spec.md#requirement-the-plan-response-carries-a-predicted-manifest-for-review-and-validation
 */
final class ManifestPageShape {

	/**
	 * Field types the canonical validator accepts on a form page.
	 *
	 * @var array<int, string>
	 */
	private const FORM_FIELD_TYPES = ['boolean', 'number', 'string', 'enum', 'password', 'json', 'file'];

	/**
	 * Normalise one page in place of the shape the tool was handed.
	 *
	 * @param array<string, mixed> $page The page as the tool arguments describe it.
	 *
	 * @return array<string, mixed> The same page, in a shape the validator accepts.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public static function normalise(array $page): array {
		$config = $page['config'] ?? [];
		if (is_array($config) === false) {
			return $page;
		}

		$type = (string)($page['type'] ?? '');
		if ($type === 'form') {
			$config = self::normaliseForm(config: $config);
		}

		if ($type === 'dashboard') {
			$config = self::normaliseDashboard(config: $config);
		}

		$page['config'] = $config;

		return $page;
	}//end normalise()

	/**
	 * Bring a form page's config up to the validator's contract: `fields[]`
	 * entries are objects, and exactly one submit destination is named.
	 *
	 * @param array<string, mixed> $config The page's config block.
	 *
	 * @return array<string, mixed>
	 */
	private static function normaliseForm(array $config): array {
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

		$hasHandler = (is_string(($config['submitHandler'] ?? null)) === true && $config['submitHandler'] !== '');
		$hasEndpoint = (is_string(($config['submitEndpoint'] ?? null)) === true && $config['submitEndpoint'] !== '');
		if ($hasHandler === true || $hasEndpoint === true) {
			return $config;
		}

		// No destination named. The page already says which register and schema
		// it belongs to in every plan seen so far, and the OpenRegister objects
		// endpoint is where an index page on the same pair reads from, so the
		// form posts to the collection it lists. Nothing is invented when that
		// pair is absent: the page stays invalid and says so.
		$register = self::stringValue(value: ($config['register'] ?? null));
		$schema = self::stringValue(value: ($config['schema'] ?? null));
		if ($register !== '' && $schema !== '') {
			$config['submitEndpoint'] = '/apps/openregister/api/objects/' . $register . '/' . $schema;
		}

		return $config;
	}//end normaliseForm()

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
	 * Drop a dashboard's `widgets` or `layout` when it is not a list.
	 *
	 * A model that writes `"layout": "grid"` is naming a style, not a set of
	 * placements. The key carries no information the renderer can use and it
	 * fails the whole manifest, so it goes; `addWidget` builds the real one.
	 *
	 * @param array<string, mixed> $config The page's config block.
	 *
	 * @return array<string, mixed>
	 */
	private static function normaliseDashboard(array $config): array {
		foreach (['widgets', 'layout'] as $key) {
			if (array_key_exists($key, $config) === true && is_array($config[$key]) === false) {
				unset($config[$key]);
			}
		}

		return $config;
	}//end normaliseDashboard()

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
