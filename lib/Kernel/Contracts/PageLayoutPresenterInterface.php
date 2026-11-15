<?php

/**
 * Contract of PageLayoutPresenter
 *
 * The public surface of PageLayoutPresenter that other components call, so they depend
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
 * Contract of PageLayoutPresenter.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PageLayoutPresenterInterface {

	/**
	 * Turn a resolved layout into what the consumer renders.
	 *
	 * @param array<string, mixed> $layout The resolved layout.
	 * @param array<string, mixed>|null $schemaWide The schema-wide layout, for the parts this one does not declare.
	 * @param array<string, mixed> $object The host object, for the display conditions.
	 *
	 * @return array<string, mixed> The served layout.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-006, REQ-OBPL-007, REQ-OBPL-008, REQ-OBPL-009)
	 */
	public function serve(array $layout, ?array $schemaWide, array $object): array;
}//end interface
