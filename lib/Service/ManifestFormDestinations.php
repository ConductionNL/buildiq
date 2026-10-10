<?php

/**
 * Manifest Form Destinations
 *
 * Checks every form page of an app manifest against the object it saves
 * into, through OpenRegister's form destination validator (decision 179).
 * The page designer asks on every save, so an author sees each finding on its
 * field while the pages are a draft; publishing the app refuses while any
 * finding remains (decision 189: a draft saves with findings, publishing
 * needs zero).
 *
 * WHICH PAGES ARE CHECKED
 * -----------------------
 * A `form` page that saves into a register: its `config.destination`, or its
 * `config.register` and `config.schema`, or a `submitEndpoint` shaped like
 * `/api/objects/{register}/{schema}`. A page with a custom submit handler
 * saves through app code, not into an object buildiq can name, so it has no
 * destination to check and is skipped.
 *
 * HOW A PAGE IS MAPPED
 * --------------------
 * Each field writes the property its `key` names. The field's input type is
 * translated to the validator's vocabulary, and the options and limits the
 * field declares are passed on, so a field looser than its property is found.
 * A page published for anonymous visitors (`runtime.externalForms[]` entry
 * enabled for the page) is checked with audience `public`.
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
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Support\ManifestPageShape;

/**
 * Every destination finding on the form pages of a manifest.
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 */
class ManifestFormDestinations {
	/**
	 * Buildiq form field types in the validator's vocabulary.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_TYPES = [
		'boolean' => 'boolean',
		'number' => 'number',
		'string' => 'text',
		'enum' => 'choice',
		'password' => 'text',
		'json' => 'object',
		'file' => 'file',
		'select' => 'choice',
		'textarea' => 'textarea',
		'date' => 'date',
	];

	/**
	 * Field limits passed on as the field's own constraints.
	 *
	 * @var array<int, string>
	 */
	private const LIMITS = ['maxLength', 'minLength', 'maximum', 'minimum', 'pattern', 'maxItems', 'minItems', 'format'];

	/**
	 * Constructor.
	 *
	 * @param FormDestinationCheck $check OpenRegister's validator, through buildiq's one caller.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FormDestinationCheck $check,
	) {
	}//end __construct()

	/**
	 * Every finding on every checkable form page, each naming its page.
	 *
	 * @param array<string, mixed> $manifest The app manifest.
	 *
	 * @return array<int, array<string, mixed>> Findings `{page, pageIndex, field?, property, code, message}`.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	public function findings(array $manifest): array {
		$public = $this->publicPageIds(manifest: $manifest);
		$findings = [];
		foreach ((array)($manifest['pages'] ?? []) as $index => $page) {
			if (is_array($page) === false || (string)($page['type'] ?? '') !== 'form') {
				continue;
			}

			// The destination comes from the normalised page; the fields from the
			// page as authored, because normalising coerces the designer's
			// `select`, `textarea` and `date` types to `string`.
			$destination = (array)(ManifestPageShape::normalise(page: $page)['config']['destination'] ?? []);
			$config = (array)($page['config'] ?? []);
			if ((string)($destination['register'] ?? '') === '' || (string)($destination['schema'] ?? '') === '') {
				continue;
			}

			$pageId = (string)($page['id'] ?? '');
			$options = [];
			if ($pageId !== '' && in_array($pageId, $public, true) === true) {
				$options['audience'] = 'public';
			}

			$found = $this->check->check(
				register: (string)$destination['register'],
				schema: (string)$destination['schema'],
				mapping: $this->mappingOf(config: $config),
				options: $options
			);
			foreach ($found as $finding) {
				$findings[] = array_merge(['page' => $pageId, 'pageIndex' => (int)$index], $finding);
			}
		}

		return $findings;
	}//end findings()

	/**
	 * A form page's config as the validator reads it.
	 *
	 * @param array<string, mixed> $config The normalised form page config.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} The mapping.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	public function mappingOf(array $config): array {
		$fields = [];
		foreach ((array)($config['fields'] ?? []) as $field) {
			if (is_string($field) === true) {
				$field = ['key' => trim($field)];
			}

			if (is_array($field) === false || (string)($field['key'] ?? '') === '') {
				continue;
			}

			$key = (string)$field['key'];
			$entry = [
				'field' => $key,
				'property' => $key,
				'type' => (self::FIELD_TYPES[(string)($field['type'] ?? 'string')] ?? 'text'),
			];

			$options = ($field['options'] ?? ($field['enum'] ?? null));
			if (is_array($options) === true) {
				$entry['options'] = $options;
			}

			$limits = array_merge((array)($field['validation'] ?? []), $field);
			foreach (self::LIMITS as $limit) {
				if (array_key_exists($limit, $limits) === true && $limits[$limit] !== '' && $limits[$limit] !== null) {
					$entry[$limit] = $limits[$limit];
				}
			}

			$fields[] = $entry;
		}

		return ['fields' => $fields, 'fixed' => []];
	}//end mappingOf()

	/**
	 * The ids of the pages published for anonymous visitors.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 *
	 * @return array<int, string> The page ids.
	 */
	private function publicPageIds(array $manifest): array {
		$ids = [];
		foreach ((array)($manifest['runtime']['externalForms'] ?? []) as $entry) {
			if (is_array($entry) === true
				&& (string)($entry['status'] ?? '') === 'enabled'
				&& (string)($entry['pageId'] ?? '') !== ''
			) {
				$ids[] = (string)$entry['pageId'];
			}
		}

		return $ids;
	}//end publicPageIds()
}//end class
