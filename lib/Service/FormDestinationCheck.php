<?php

/**
 * Form Destination Check
 *
 * Asks OpenRegister whether a form's mapping is valid against the object it
 * submits into. Decision 179 (Ruben, 10 October 2026): "when building a form
 * we should know the destination object and the form should at least be valid
 * against that." OpenRegister owns the rules (`FormDestinationValidator`, the
 * service behind `POST /api/forms/validate`); buildiq keeps no copy of them.
 *
 * WHY IN-PROCESS AND NOT OVER HTTP
 * --------------------------------
 * The endpoint resolves a schema by a bare reference, and two registers may
 * each hold a schema called `case`. Here the schema is resolved INSIDE the
 * form's register first, and the resolved entity is handed to the same
 * validator the endpoint calls. Same rules, same findings, no ambiguity.
 *
 * A CHECK THAT CANNOT RUN REFUSES
 * -------------------------------
 * The save refuses from the first release (decision 181, no report mode). A
 * check that did not run looks exactly like one that passed, so a schema that
 * cannot be read, or an OpenRegister without the validator, gives a finding
 * (`destination-unchecked`) and the form is not saved.
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Judges a form mapping against its destination through OpenRegister's validator.
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 */
class FormDestinationCheck {
	/**
	 * OpenRegister's validator, looked up by name: buildiq boots without OpenRegister.
	 *
	 * @var string
	 */
	public const VALIDATOR = 'OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator';

	/**
	 * Finding code: the form names no register or schema.
	 *
	 * @var string
	 */
	public const DESTINATION_MISSING = 'destination-missing';

	/**
	 * Finding code: the check could not run, so the form is not saved.
	 *
	 * @var string
	 */
	public const DESTINATION_UNCHECKED = 'destination-unchecked';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's validator.
	 * @param RegistrationFormTargetSchemaReader $schemas Resolves the schema inside its register.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly RegistrationFormTargetSchemaReader $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every finding OpenRegister has on this mapping into this destination.
	 *
	 * @param string $register The destination register slug.
	 * @param string $schema The destination schema slug.
	 * @param array<string, mixed> $mapping `{fields: [{field, property, type, ...}], fixed: {property: value}}`.
	 * @param array<string, mixed> $options `audience`: `public` or `authenticated` when the form is not internal.
	 *
	 * @return array<int, array<string, mixed>> The findings; empty when the destination accepts the form.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	public function check(string $register, string $schema, array $mapping, array $options = []): array {
		if (trim($register) === '' || trim($schema) === '') {
			return [
				self::finding(
					code: self::DESTINATION_MISSING,
					message: 'A form has to name the register and schema it saves into.'
				),
			];
		}

		try {
			$entity = $this->schemas->schemaOf(registerSlug: $register, schemaSlug: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: destination check could not read schema "' . $schema . '" in register "' . $register . '": ' . $e->getMessage(),
				['exception' => $e]
			);

			return [
				self::finding(
					code: self::DESTINATION_UNCHECKED,
					message: sprintf('The schema "%s" could not be read, so the form could not be checked and is not saved.', $schema)
				),
			];
		}

		if ($entity === null) {
			return [
				self::finding(
					code: self::DESTINATION_UNCHECKED,
					message: sprintf('The register "%s" holds no schema called "%s", so the form has nowhere to save into.', $register, $schema)
				),
			];
		}

		$validator = $this->validator();
		if ($validator === null) {
			return [
				self::finding(
					code: self::DESTINATION_UNCHECKED,
					message: 'OpenRegister on this server cannot check forms yet, so the form could not be checked and is not saved.'
				),
			];
		}

		$findings = $validator->validate(mapping: $mapping, schema: $entity, options: $options);
		if (is_array($findings) === false) {
			return [];
		}

		return array_values(array_filter($findings, 'is_array'));
	}//end check()

	/**
	 * OpenRegister's validator, or null when this OpenRegister does not ship it.
	 *
	 * @return object|null The validator.
	 */
	private function validator(): ?object {
		try {
			if ($this->container->has(self::VALIDATOR) === false) {
				return null;
			}

			$validator = $this->container->get(self::VALIDATOR);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: OpenRegister form validator unavailable: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}

		if (is_object($validator) === false || method_exists($validator, 'validate') === false) {
			return null;
		}

		return $validator;
	}//end validator()

	/**
	 * One buildiq-side finding, shaped like OpenRegister's.
	 *
	 * @param string $code The code.
	 * @param string $message The sentence for the author.
	 *
	 * @return array{property: string, code: string, message: string} The finding.
	 */
	private static function finding(string $code, string $message): array {
		return ['property' => '', 'code' => $code, 'message' => $message];
	}//end finding()
}//end class
