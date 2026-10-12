<?php

/**
 * Registration Form Target Warnings
 *
 * What a registration form's destination says about it. Until decision 179
 * this class warned about presets and fields naming a property the target
 * schema lacks, and the save went ahead (REQ-OBRF-005, "Saving SHALL warn").
 * It is now a caller of OpenRegister's form destination validator: an unknown
 * property is a finding, and so are a required property no field or preset
 * fills, a field whose type the property cannot hold, and a preset value the
 * property refuses. A form with any finding is not saved, from the first
 * release (decision 181, no report-only mode).
 *
 * The class keeps its name because REQ-OBRF-005 and the open change
 * `forms-per-case-type` cite it; what it returns is findings, not warnings.
 *
 * WHAT A REGISTRATION FORM MAPS
 * -----------------------------
 * Every field writes the property it is named after. Every preset, hidden or
 * visible, is a fixed value: the consumer writes it onto the object it creates
 * (ADR-066), so it fills a required property and is checked against the
 * property's type and enum.
 *
 * WHICH FINDINGS DO NOT APPLY
 * ---------------------------
 * A registration form field has nowhere to declare options or bounds: the
 * `registrationForm` schema gives a field a name, a label and an input type,
 * and the consumer renders the choice list and limits from the target
 * property itself. So `enum-unconstrained` and `constraint-looser` on a field
 * that declares no options or bounds of its own describe a gap the form cannot
 * have, and are left out. Every other finding stands.
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
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-registration-forms-presets-are-checked-against-its-destination
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Maps a registration form onto its destination and returns OpenRegister's findings.
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-registration-forms-presets-are-checked-against-its-destination
 */
final class RegistrationFormTargetWarnings {
	/**
	 * Findings a field that declares no options or bounds of its own cannot earn.
	 *
	 * @var array<int, string>
	 */
	private const RENDERED_FROM_PROPERTY = ['enum-unconstrained', 'constraint-looser'];

	/**
	 * Field keys that declare a field's own options or bounds.
	 *
	 * @var array<int, string>
	 */
	private const OWN_CONSTRAINTS = ['options', 'maxLength', 'minLength', 'maximum', 'minimum', 'pattern', 'maxItems', 'minItems'];

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
	 * Every finding the form's destination has on it.
	 *
	 * @param array<string, mixed> $form The registration form.
	 *
	 * @return array<int, array<string, mixed>> The findings, each `{field?, property, code, message}`; empty when accepted.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	public function findings(array $form): array {
		$fields = $this->fieldsByName(form: $form);
		$options = [];
		$audience = $this->audienceOf(form: $form);
		if ($audience !== null) {
			$options['audience'] = $audience;
		}

		$found = $this->check->check(
			register: (string)($form['register'] ?? ''),
			schema: (string)($form['schema'] ?? ''),
			mapping: $this->mappingOf(form: $form),
			options: $options
		);

		$kept = [];
		foreach ($found as $finding) {
			$field = (string)($finding['field'] ?? '');
			if ($field !== ''
				&& in_array((string)($finding['code'] ?? ''), self::RENDERED_FROM_PROPERTY, true) === true
				&& $this->declaresOwnConstraints(field: ($fields[$field] ?? [])) === false
			) {
				continue;
			}

			$kept[] = $finding;
		}

		return $kept;
	}//end findings()

	/**
	 * The form as OpenRegister's validator reads it: fields and fixed values.
	 *
	 * @param array<string, mixed> $form The registration form.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} The mapping.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-registration-forms-presets-are-checked-against-its-destination
	 */
	public function mappingOf(array $form): array {
		$fields = [];
		foreach ($this->fieldsByName(form: $form) as $name => $field) {
			$entry = ['field' => $name, 'property' => $name];
			$type = (string)($field['type'] ?? '');
			if ($type !== '') {
				$entry['type'] = $type;
			}

			foreach (self::OWN_CONSTRAINTS as $key) {
				if (array_key_exists($key, $field) === true) {
					$entry[$key] = $field[$key];
				}
			}

			$fields[] = $entry;
		}

		$fixed = [];
		foreach ((array)($form['presets'] ?? []) as $preset) {
			if (is_array($preset) === false) {
				continue;
			}

			$property = (string)($preset['field'] ?? '');
			if ($property !== '') {
				$fixed[$property] = ($preset['value'] ?? null);
			}
		}

		return ['fields' => $fields, 'fixed' => $fixed];
	}//end mappingOf()

	/**
	 * The form's fields keyed by the property they write.
	 *
	 * @param array<string, mixed> $form The registration form.
	 *
	 * @return array<string, array<string, mixed>> The fields.
	 */
	private function fieldsByName(array $form): array {
		$fields = [];
		foreach ((array)($form['fields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name !== '') {
				$fields[$name] = $field;
			}
		}

		return $fields;
	}//end fieldsByName()

	/**
	 * Whether a field declares options or bounds of its own.
	 *
	 * @param array<string, mixed> $field The field.
	 *
	 * @return bool True when it does.
	 */
	private function declaresOwnConstraints(array $field): bool {
		foreach (self::OWN_CONSTRAINTS as $key) {
			if (array_key_exists($key, $field) === true) {
				return true;
			}
		}

		return false;
	}//end declaresOwnConstraints()

	/**
	 * Who submits the form, as the validator's `audience` option names it.
	 *
	 * Only a public form names one: it is filled in by somebody who is not
	 * signed in, so its destination has to grant public create (spec,
	 * "An external form MUST have a public destination"). A client, supplier
	 * or internal form is submitted by a signed-in person under their own
	 * rights, which the submit itself checks.
	 *
	 * @param array<string, mixed> $form The registration form.
	 *
	 * @return string|null `public`, or null.
	 */
	private function audienceOf(array $form): ?string {
		if (($form['isPublic'] ?? false) === true) {
			return 'public';
		}

		return null;
	}//end audienceOf()
}//end class
