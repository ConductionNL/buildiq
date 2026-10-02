<?php

/**
 * Registration Form Target Warnings
 *
 * What a form says about a target schema it does not own. A preset or a field
 * that names a property the consuming schema does not declare is wrong, and the
 * answer is a warning rather than a refusal.
 *
 * A refusal would be worse. Buildiq reads the target schema across an app
 * boundary, so a schema it cannot read right now would make every save fail,
 * and a builder nobody can save in is a harder failure to recover from than a
 * form that is merely wrong. The warning says what will happen to the answer:
 * a preset is written and ignored, a field's answer is dropped on save.
 *
 * It lives apart from RegistrationFormValidator because the two speak with
 * different force. Everything in the validator refuses; everything here warns,
 * and mixing the two in one class is how a warning quietly becomes a refusal.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Warns about presets and fields the target schema does not declare.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
 */
final class RegistrationFormTargetWarnings {
	/**
	 * Every warning this form earns against the target schema.
	 *
	 * @param array<string, mixed> $form The form.
	 * @param array<int, string>|null $targetProperties The properties the target schema declares, or null.
	 *
	 * @return array<int, string> The warnings.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
	 */
	public function forForm(array $form, ?array $targetProperties): array {
		if ($targetProperties === null || $targetProperties === []) {
			return [];
		}

		$presets = $this->unknownNames(
			entries: ($form['presets'] ?? []),
			nameKey: 'field',
			known: $targetProperties
		);

		$fields = $this->unknownNames(
			entries: ($form['fields'] ?? []),
			nameKey: 'name',
			known: $targetProperties
		);

		$warnings = [];
		foreach ($presets as $name) {
			$warnings[] = sprintf(
				'The preset "%s" names a property the target schema does not have; it will be written and ignored.',
				$name
			);
		}

		foreach ($fields as $name) {
			$warnings[] = sprintf(
				'The field "%s" names a property the target schema does not have; its answer will be dropped on save.',
				$name
			);
		}

		return $warnings;
	}//end forForm()

	/**
	 * The names in these entries that the target schema does not declare.
	 *
	 * @param mixed $entries The presets or fields.
	 * @param string $nameKey The key each entry names its property under.
	 * @param array<int, string> $known The properties the target schema declares.
	 *
	 * @return array<int, string> The names nothing answers to.
	 */
	private function unknownNames(mixed $entries, string $nameKey, array $known): array {
		if (is_array($entries) === false) {
			return [];
		}

		$unknown = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = (string)($entry[$nameKey] ?? '');
			if ($name !== '' && in_array($name, $known, true) === false) {
				$unknown[] = $name;
			}
		}

		return $unknown;
	}//end unknownNames()
}//end class
