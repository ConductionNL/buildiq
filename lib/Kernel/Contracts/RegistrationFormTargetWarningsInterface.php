<?php

/**
 * Contract of RegistrationFormTargetWarnings
 *
 * The public surface of RegistrationFormTargetWarnings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of RegistrationFormTargetWarnings.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegistrationFormTargetWarningsInterface {

	/**
	 * The form as OpenRegister's validator reads it: fields and fixed values.
	 *
	 * @param array<string, mixed> $form The registration form.
	 *
	 * @return array{fields: array<int, array<string, mixed>>, fixed: array<string, mixed>} The mapping.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-registration-forms-presets-are-checked-against-its-destination
	 */
	public function mappingOf(array $form): array;
}//end interface
