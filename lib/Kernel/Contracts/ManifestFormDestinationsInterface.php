<?php

/**
 * Contract of ManifestFormDestinations
 *
 * The public surface of ManifestFormDestinations that other components call, so they depend
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
 * Contract of ManifestFormDestinations.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ManifestFormDestinationsInterface {

	/**
	 * Every finding on every checkable form page, each naming its page.
	 *
	 * @param array<string, mixed> $manifest The app manifest.
	 *
	 * @return array<int, array<string, mixed>> Findings `{page, pageIndex, field?, property, code, message}`.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ManifestPageShape is a pure shape rule with no state,
	 * the same one the save path and the MCP page handler apply.
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	public function findings(array $manifest): array;
}//end interface
