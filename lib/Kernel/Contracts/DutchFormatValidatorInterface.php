<?php

/**
 * Contract of DutchFormatValidator
 *
 * The public surface of DutchFormatValidator that other components call, so they depend
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
 * @spec openspec/changes/archive/2026-11-24-form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of DutchFormatValidator.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DutchFormatValidatorInterface {

	/**
	 * Whether a format name is one of the Dutch formats.
	 *
	 * @param string $format The name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-24-form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
	 */
	public function knows(string $format): bool;

	/**
	 * The value in its stored form, or null when it fails the format.
	 *
	 * @param string $format The format name.
	 * @param string $value  The value as entered.
	 *
	 * @return string|null The normalised value; null when it fails or the format is unknown.
	 *
	 * @spec openspec/changes/archive/2026-11-24-form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
	 */
	public function normalise(string $format, string $value): ?string;

	/**
	 * The message for a value that fails a format, in the user's language.
	 *
	 * @param string $format The format name.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/archive/2026-11-24-form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
	 */
	public function message(string $format): string;
}//end interface
