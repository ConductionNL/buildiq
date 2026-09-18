<?php

/**
 * Registration Form Presenter
 *
 * The shape a form takes on its way OUT of buildiq: presets applied, hidden
 * presets removed from the fields entirely, sections in the administrator's
 * order and fields grouped inside them.
 *
 * A hidden preset is REMOVED from the fields rather than flagged. A field that
 * is merely marked hidden is still in the payload and still readable by anybody
 * who opens the network tab, so "the citizen never sees the channel field" has
 * to mean the citizen is never sent it.
 *
 * The order is the administrator's and nothing else. There is deliberately no
 * fall-back to the target schema's property order: a form that quietly
 * reordered itself the day somebody added a property is worse than one that
 * shows nothing.
 *
 * Presenting is kept apart from the leaf provider, which answers a different
 * question: which forms apply to this object, and what has this person already
 * filled in.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Turns a stored registration form into the shape a consumer renders.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
 */
final class RegistrationFormPresenter {
	/**
	 * Turn a stored form into what the consumer renders.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return array<string, mixed> The served form.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-008)
	 */
	public function serve(array $form): array {
		$presets = $this->presetsOf(form: $form);
		$fields = $this->fieldsWithPresets(
			form: $form,
			hidden: $presets['hidden'],
			visible: $presets['visible']
		);

		$sections = $this->orderedSections(form: $form);

		$steps = [];
		if (is_array($form['steps'] ?? null) === true) {
			$steps = $form['steps'];
		}

		$formLogic = [];
		if (is_array($form['formLogic'] ?? null) === true) {
			$formLogic = $form['formLogic'];
		}

		return [
			'id' => (string)($form['id'] ?? ''),
			'name' => (string)($form['name'] ?? ''),
			'audience' => (string)($form['audience'] ?? ''),
			'channel' => (string)($form['channel'] ?? ''),
			'isDefault' => (($form['isDefault'] ?? false) === true),
			'isPublic' => (($form['isPublic'] ?? false) === true),
			'confirmationText' => (string)($form['confirmationText'] ?? ''),
			'allowSaveForLater' => (($form['allowSaveForLater'] ?? false) === true),
			'sections' => $sections,
			'fields' => $this->orderFields(fields: $fields, sections: $sections),
			'steps' => $steps,
			'formLogic' => $formLogic,
			'presets' => $presets['served'],
		];
	}//end serve()

	/**
	 * The form's presets, split into what travels beside the form, which fields
	 * are removed from it, and which merely arrive with a default filled in.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return array{served: array<int, array<string, mixed>>, hidden: array<int, string>, visible: array<string, mixed>} The presets.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
	 */
	private function presetsOf(array $form): array {
		$served = [];
		$hidden = [];
		$visible = [];

		foreach (($form['presets'] ?? []) as $preset) {
			if (is_array($preset) === false || (string)($preset['field'] ?? '') === '') {
				continue;
			}

			$field = (string)$preset['field'];
			$isHidden = (($preset['hidden'] ?? false) === true);
			$served[] = ['field' => $field, 'value' => ($preset['value'] ?? null), 'hidden' => $isHidden];

			if ($isHidden === true) {
				$hidden[] = $field;
				continue;
			}

			$visible[$field] = ($preset['value'] ?? null);
		}

		return ['served' => $served, 'hidden' => $hidden, 'visible' => $visible];
	}//end presetsOf()

	/**
	 * The fields a consumer may see, with the visible presets filled in.
	 *
	 * A hidden preset's field is removed here, not marked. A field still in the
	 * payload is still readable by anybody who opens the network tab.
	 *
	 * @param array<string, mixed> $form The stored form.
	 * @param array<int, string> $hidden The fields a hidden preset covers.
	 * @param array<string, mixed> $visible The defaults a visible preset sets.
	 *
	 * @return array<int, array<string, mixed>> The fields.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
	 */
	private function fieldsWithPresets(array $form, array $hidden, array $visible): array {
		$fields = [];

		foreach (($form['fields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name === '' || in_array($name, $hidden, true) === true) {
				continue;
			}

			if (array_key_exists($name, $visible) === true) {
				$field['default'] = $visible[$name];
			}

			$fields[] = $field;
		}

		return $fields;
	}//end fieldsWithPresets()

	/**
	 * The form's sections, in the order the administrator set.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return array<int, array<string, mixed>> The sections.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
	 */
	public function orderedSections(array $form): array {
		$sections = [];
		foreach (($form['sections'] ?? []) as $section) {
			if (is_array($section) === true && (string)($section['name'] ?? '') !== '') {
				$sections[] = $section;
			}
		}

		usort(
			$sections,
			static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
		);

		return $sections;
	}//end orderedSections()

	/**
	 * The fields grouped into their sections and ordered inside each, sections
	 * in their own order first and the section-less fields last.
	 *
	 * @param array<int, array<string, mixed>> $fields The served fields.
	 * @param array<int, array<string, mixed>> $sections The ordered sections.
	 *
	 * @return array<int, array<string, mixed>> The ordered fields.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
	 */
	public function orderFields(array $fields, array $sections): array {
		$order = [];
		foreach ($sections as $index => $section) {
			$order[(string)$section['name']] = $index;
		}

		usort(
			$fields,
			static function (array $a, array $b) use ($order): int {
				$sectionA = ($order[(string)($a['section'] ?? '')] ?? PHP_INT_MAX);
				$sectionB = ($order[(string)($b['section'] ?? '')] ?? PHP_INT_MAX);

				if ($sectionA !== $sectionB) {
					return ($sectionA <=> $sectionB);
				}

				return ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0));
			}
		);

		// Usort already reindexes, so the list needs no second pass.
		return $fields;
	}//end orderFields()

	/**
	 * Put the defaults at the front, keeping the rest in their stored order.
	 *
	 * @param array<int, array<string, mixed>> $forms The forms.
	 *
	 * @return array<int, array<string, mixed>> The forms, defaults first.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
	 */
	public function defaultsFirst(array $forms): array {
		$defaults = [];
		$rest = [];
		foreach ($forms as $form) {
			if (($form['isDefault'] ?? false) === true) {
				$defaults[] = $form;
				continue;
			}

			$rest[] = $form;
		}

		return array_merge($defaults, $rest);
	}//end defaultsFirst()
}//end class
