<?php

/**
 * Contract of ApplicationDeletionService
 *
 * The public surface of ApplicationDeletionService that other components call, so they depend
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
 * Contract of ApplicationDeletionService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicationDeletionServiceInterface {

	/**
	 * Delete an Application plus its versions and routes, and — only when
	 * $deleteData is true — its per-version registers, every object stored in
	 * them, and the schema definitions those registers owned.
	 *
	 * By default ($deleteData false) the underlying registers and the data
	 * inside them are PRESERVED: the app wrapper is removed but the user's data
	 * survives in OpenRegister. The caller must opt in (checkbox) to wipe data.
	 *
	 * @param string $appUuid The Application UUID.
	 * @param string $appSlug The Application slug (for log context).
	 * @param bool $deleteData When true, also delete the per-version registers,
	 *                         every object stored in them, and their schemas.
	 *
	 * @return array<int,string> The resources this call TRIED to remove and
	 *                           could not, empty when everything attempted was
	 *                           removed. Data preserved by $deleteData false was
	 *                           never attempted and is never listed here, so an
	 *                           empty array does not mean nothing survives the
	 *                           delete.
	 *
	 * @spec openspec/specs/application-detail-ui/spec.md
	 */
	public function deleteApplication(string $appUuid, string $appSlug, bool $deleteData = false): array;
}//end interface
