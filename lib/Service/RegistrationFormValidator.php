<?php

/**
 * Registration Form Validator
 *
 * The rules a registration form has to satisfy before it is published, all of
 * them cross-field and none of them expressible as a flat required list.
 *
 * Three of them exist because of a specific way this goes wrong quietly.
 *
 * A second default for the same audience and channel is refused BY NAME,
 * because two defaults means the consumer gets whichever one the store
 * happened to return first, and that is a bug nobody can reproduce.
 *
 * A field pointing at a section the form does not declare is refused, because
 * the served form groups by section and an unknown one would leave the field
 * out of every group. It would not error. It would simply not be asked.
 *
 * A channel the consuming schema has never heard of is refused, because the
 * consumer resolves forms by channel and a form declaring `fax` would be
 * published, correct-looking, and never returned to anybody.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005, REQ-OBRF-007, REQ-OBRF-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Validates a registration form before it is saved.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
 */
final class RegistrationFormValidator {
	/**
	 * The parts that bind a form to one type value.
	 *
	 * @var array<int, string>
	 */
	public const TUPLE_PARTS = ['targetApp', 'register', 'schema', 'typeProperty', 'typeValue'];

	/**
	 * The audiences a form may be written for.
	 *
	 * @var array<int, string>
	 */
	public const AUDIENCES = ['client', 'internal', 'supplier'];

	/**
	 * Validate a form against the forms already stored for its type.
	 *
	 * @param array<string, mixed> $form The form about to be saved.
	 * @param array<int, array<string, mixed>> $existing Forms already stored for the same type.
	 * @param array<int, string>|null $targetProperties The property names the target schema declares, or null when they cannot be read.
	 * @param array<int, string>|null $targetChannels The channel values the consumer accepts, or null when it declares none.
	 *
	 * @return array<int, string> Warnings that do not block the save.
	 *
	 * @throws InvalidArgumentException When a rule refuses the form.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005, REQ-OBRF-007, REQ-OBRF-008)
	 */
	public function validate(
		array $form,
		array $existing = [],
		?array $targetProperties = null,
		?array $targetChannels = null,
	): array {
		$this->assertName($form);
		$this->assertAudience($form);
		$this->assertSections($form);
		$this->assertChannel($form, $targetChannels);
		$this->assertNameIsFree($form, $existing);
		$this->assertOneDefault($form, $existing);

		return $this->presetWarnings($form, $targetProperties);
	}//end validate()

	/**
	 * The identity of the type a form is bound to.
	 *
	 * @param array<string, mixed> $form The form or a lookup.
	 *
	 * @return string The key.
	 */
	public function tupleKey(array $form): string {
		$parts = [];
		foreach (self::TUPLE_PARTS as $part) {
			$parts[] = (string)($form[$part] ?? '');
		}

		return implode('|', $parts);
	}//end tupleKey()

	/**
	 * A form needs a name, because a consumer asks for it by name.
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it has none.
	 */
	private function assertName(array $form): void {
		if (trim((string)($form['name'] ?? '')) === '') {
			throw new InvalidArgumentException('A registration form needs a name; a consumer asks for it by name.');
		}
	}//end assertName()

	/**
	 * A form is written for somebody, and which somebody changes what it asks.
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the audience is unknown.
	 */
	private function assertAudience(array $form): void {
		$audience = (string)($form['audience'] ?? '');
		if (in_array($audience, self::AUDIENCES, true) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown audience "%s"; expected one of %s.', $audience, implode(', ', self::AUDIENCES))
			);
		}
	}//end assertAudience()

	/**
	 * Refuse a field pointing at a section the form does not declare, naming the
	 * sections that do exist (REQ-OBRF-008).
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a section is unknown.
	 */
	private function assertSections(array $form): void {
		$declared = [];
		foreach (($form['sections'] ?? []) as $section) {
			if (is_array($section) === true && (string)($section['name'] ?? '') !== '') {
				$declared[] = (string)$section['name'];
			}
		}

		$unknown = [];
		foreach (($form['fields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$section = (string)($field['section'] ?? '');
			if ($section !== '' && in_array($section, $declared, true) === false) {
				$unknown[] = $section;
			}
		}

		if ($unknown !== []) {
			throw new InvalidArgumentException(
				sprintf(
					'This form puts a field in %s, which it does not declare. The sections it has are: %s.',
					implode(', ', array_unique($unknown)),
					($declared === [] ? 'none' : implode(', ', $declared))
				)
			);
		}
	}//end assertSections()

	/**
	 * Refuse a channel the consuming schema does not accept, naming the ones it
	 * does (REQ-OBRF-007).
	 *
	 * @param array<string, mixed> $form The form.
	 * @param array<int, string>|null $targetChannels The accepted channels, or null when the consumer declares none.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the channel is not accepted.
	 */
	private function assertChannel(array $form, ?array $targetChannels): void {
		$channel = (string)($form['channel'] ?? '');
		if ($channel === '' || $targetChannels === null || $targetChannels === []) {
			// No channel, or a consumer that declares none: a form without a
			// channel serves every channel, which is the documented fallback.
			return;
		}

		if (in_array($channel, $targetChannels, true) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'The consumer does not accept channel "%s". It accepts: %s.',
					$channel,
					implode(', ', $targetChannels)
				)
			);
		}
	}//end assertChannel()

	/**
	 * Refuse a second form with the same name on the same type (REQ-OBRF-004).
	 *
	 * @param array<string, mixed> $form The form.
	 * @param array<int, array<string, mixed>> $existing Forms already stored.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the name is taken.
	 */
	private function assertNameIsFree(array $form, array $existing): void {
		$key = $this->tupleKey($form);
		$name = (string)$form['name'];
		$selfId = (string)($form['id'] ?? '');

		foreach ($existing as $candidate) {
			if (is_array($candidate) === false || $this->tupleKey($candidate) !== $key) {
				continue;
			}

			if ((string)($candidate['name'] ?? '') !== $name) {
				continue;
			}

			if ($selfId !== '' && (string)($candidate['id'] ?? '') === $selfId) {
				continue;
			}

			throw new InvalidArgumentException(
				sprintf('A form called "%s" already exists for this type; rename this one or edit that one.', $name)
			);
		}
	}//end assertNameIsFree()

	/**
	 * Refuse a second default for the same audience and channel, naming the form
	 * that already holds it (REQ-OBRF-004, REQ-OBRF-007).
	 *
	 * @param array<string, mixed> $form The form.
	 * @param array<int, array<string, mixed>> $existing Forms already stored.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a default already stands.
	 */
	private function assertOneDefault(array $form, array $existing): void {
		if (($form['isDefault'] ?? false) !== true || (string)($form['status'] ?? '') !== 'published') {
			return;
		}

		$key = $this->tupleKey($form);
		$audience = (string)$form['audience'];
		$channel = (string)($form['channel'] ?? '');
		$selfId = (string)($form['id'] ?? '');

		foreach ($existing as $candidate) {
			if (is_array($candidate) === false
				|| $this->tupleKey($candidate) !== $key
				|| ($candidate['isDefault'] ?? false) !== true
				|| (string)($candidate['status'] ?? '') !== 'published'
				|| (string)($candidate['audience'] ?? '') !== $audience
				|| (string)($candidate['channel'] ?? '') !== $channel
			) {
				continue;
			}

			if ($selfId !== '' && (string)($candidate['id'] ?? '') === $selfId) {
				continue;
			}

			throw new InvalidArgumentException(
				sprintf(
					'"%s" is already the default %s form%s for this type; unset it before making this one the default.',
					(string)($candidate['name'] ?? '?'),
					$audience,
					($channel === '' ? '' : sprintf(' on channel %s', $channel))
				)
			);
		}
	}//end assertOneDefault()

	/**
	 * Warn, do not refuse, on a preset naming a property the target schema does
	 * not have.
	 *
	 * A warning rather than a refusal because buildiq reads the target schema
	 * across an app boundary: a schema it cannot read would otherwise make every
	 * save fail, and a form that is merely wrong is easier to fix than a builder
	 * nobody can save in (REQ-OBRF-005).
	 *
	 * @param array<string, mixed> $form The form.
	 * @param array<int, string>|null $targetProperties The properties the target schema declares, or null.
	 *
	 * @return array<int, string> The warnings.
	 */
	private function presetWarnings(array $form, ?array $targetProperties): array {
		if ($targetProperties === null || $targetProperties === []) {
			return [];
		}

		$warnings = [];
		foreach (($form['presets'] ?? []) as $preset) {
			if (is_array($preset) === false) {
				continue;
			}

			$field = (string)($preset['field'] ?? '');
			if ($field !== '' && in_array($field, $targetProperties, true) === false) {
				$warnings[] = sprintf('The preset "%s" names a property the target schema does not have; it will be written and ignored.', $field);
			}
		}

		foreach (($form['fields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name !== '' && in_array($name, $targetProperties, true) === false) {
				$warnings[] = sprintf('The field "%s" names a property the target schema does not have; its answer will be dropped on save.', $name);
			}
		}

		return $warnings;
	}//end presetWarnings()
}//end class
