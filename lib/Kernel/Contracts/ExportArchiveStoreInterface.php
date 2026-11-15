<?php

/**
 * Contract of ExportArchiveStore
 *
 * The public surface of ExportArchiveStore that other components call, so they depend
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
 * Contract of ExportArchiveStore.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportArchiveStoreInterface {

	/**
	 * Delete every archive in app data older than the window.
	 *
	 * @param int $now    The current Unix time.
	 * @param int $window The age in seconds after which an archive goes.
	 *
	 * @return int How many archives were deleted.
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
	 */
	public function purgeOlderThan(int $now, int $window): int;

	/**
	 * The bytes of a job's archive in app data.
	 *
	 * @param string $jobUuid The ExportJob UUID.
	 *
	 * @return string|null The archive, or null when app data holds none.
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
	 */
	public function read(string $jobUuid): ?string;
}//end interface
