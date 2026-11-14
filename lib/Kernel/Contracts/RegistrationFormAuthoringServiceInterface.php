<?php

/**
 * Contract of RegistrationFormAuthoringService
 *
 * The public surface of RegistrationFormAuthoringService that other components call, so they depend
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

use InvalidArgumentException;
use OCA\Buildiq\Exception\FormDestinationRefusedException;

/**
 * Contract of RegistrationFormAuthoringService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegistrationFormAuthoringServiceInterface {

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
	public function listFor(string $register, string $schema): array;

	/**
	 * Save one form.
	 *
	 * The rules run first, then OpenRegister's destination check. A draft is
	 * saved with its findings, which come back for the designer to mark on
	 * each field (decision 189). A form in any other status (published,
	 * retired) with a finding is not saved: there is no report-only mode
	 * (decision 181).
	 *
	 * @param array<string, mixed> $form The form to store.
	 *
	 * @return array<string, mixed> `{form, warnings, findings}`; `warnings` stays for callers that read it and is empty.
	 *
	 * @throws InvalidArgumentException When a rule refuses it.
	 * @throws FormDestinationRefusedException When a form that is not a draft has findings.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-a-draft-form-saves-with-its-findings
	 */
	public function save(array $form): array;

	/**
	 * What the consuming schema declares, for the builder's pickers.
	 *
	 * The builder offers the target schema's own property names and its channel
	 * values rather than free text, because a typed property name is a field
	 * whose answer is dropped on save and nothing in the form says so.
	 *
	 * @param string $register The consuming app's register.
	 * @param string $schema The schema the form writes into.
	 * @param string $channelProperty The property carrying the intake channel.
	 *
	 * @return array{properties: array<int, string>|null, channels: array<int, string>|null, note: string|null} What it declares.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-007)
	 */
	public function targetFor(string $register, string $schema, string $channelProperty = ''): array;
}//end interface
