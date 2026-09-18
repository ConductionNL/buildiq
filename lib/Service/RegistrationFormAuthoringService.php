<?php

/**
 * Registration Form Authoring Service
 *
 * The write half of forms-per-case-type. The leaf provider refuses an edit with
 * "a registration form is edited in buildiq, where the rules that validate it
 * live"; this is where they live.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * `RegistrationFormValidator` had no caller. Every one of its refusals, the
 * name a form has to carry, the audience the resolver knows, the sections that
 * have to hold fields, the channel the consumer accepts, the name that is
 * already taken on this type and the second default, was asserted in a unit
 * test and enforced nowhere. A validator nothing calls is the same as no
 * validation, and it looks identical from a green suite.
 *
 * WHAT IT STILL CANNOT CHECK
 * --------------------------
 * The preset field names and the channel enum are properties of the CONSUMER's
 * schema, which this service cannot read. Both are passed as null, which the
 * validator documents as "cannot be read" rather than "empty", so a preset
 * naming a property that does not exist is neither warned about nor refused
 * yet. That is tasks 2.2 and 6.1 of forms-per-case-type, and saying so here is
 * the only thing that keeps anyone from assuming it is covered.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;

/**
 * Saves the registration forms a case type carries.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
 */
class RegistrationFormAuthoringService {
	/**
	 * The schema holding registration forms, equal to the leaf provider's.
	 *
	 * @var string
	 */
	private const SCHEMA = 'registrationForm';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param RegistrationFormValidator $validator The rules a form has to pass.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly RegistrationFormValidator $validator,
	) {
	}//end __construct()

	/**
	 * Save one form.
	 *
	 * @param array<string, mixed> $form The form to store.
	 *
	 * @return array<string, mixed> `{form, warnings}`.
	 *
	 * @throws InvalidArgumentException When a rule refuses it.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
	 */
	public function save(array $form): array {
		$register = (string)($form['register'] ?? '');
		$schema = (string)($form['schema'] ?? '');
		if ($register === '' || $schema === '') {
			throw new InvalidArgumentException('A form has to say which register and schema it is for.');
		}

		// The validator excludes the form's own id from both uniqueness rules,
		// so the stored set is handed over whole rather than filtered here: one
		// place deciding what counts as a collision, not two.
		$warnings = $this->validator->validate(
			$form,
			$this->storedFor($register, $schema),
			null,
			null
		);

		$this->objectService->saveObject(
			object: $form,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
		);

		return ['form' => $form, 'warnings' => $warnings];
	}//end save()

	/**
	 * The forms stored for a register and schema, in every status, because a
	 * draft that is about to be published still has to have a free name.
	 *
	 * @param string $register The consuming app's register.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The forms.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
	 */
	public function listFor(string $register, string $schema): array {
		return $this->storedFor($register, $schema);
	}//end listFor()

	/**
	 * Read the stored forms for one register and schema.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The forms.
	 */
	private function storedFor(string $register, string $schema): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['register' => $register], 'limit' => 500]);

		if (is_array($rows) === false) {
			return [];
		}

		$mine = [];
		foreach ($rows as $row) {
			if (is_array($row) === false
				|| (string)($row['register'] ?? '') !== $register
				|| (string)($row['schema'] ?? '') !== $schema
			) {
				continue;
			}

			$mine[] = $row;
		}

		return $mine;
	}//end storedFor()

	/**
	 * The register slug holding buildiq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('buildiq', 'register', 'buildiq');
	}//end registerSlug()
}//end class
