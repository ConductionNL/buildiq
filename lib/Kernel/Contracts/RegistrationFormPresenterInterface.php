<?php

/**
 * Contract of RegistrationFormPresenter
 *
 * The public surface of RegistrationFormPresenter that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of RegistrationFormPresenter.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegistrationFormPresenterInterface {

	/**
	 * Turn a stored form into what the consumer renders.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return array<string, mixed> The served form.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-008)
	 */
	public function serve(array $form): array;

	/**
	 * Put the defaults at the front, keeping the rest in their stored order.
	 *
	 * @param array<int, array<string, mixed>> $forms The forms.
	 *
	 * @return array<int, array<string, mixed>> The forms, defaults first.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
	 */
	public function defaultsFirst(array $forms): array;
}//end interface
