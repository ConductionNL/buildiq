<?php

/**
 * Contract of TemplateSeedService
 *
 * The public surface of TemplateSeedService that other components call, so they depend
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
 * Contract of TemplateSeedService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TemplateSeedServiceInterface {

	/**
	 * Seed each bundled ApplicationTemplate fixture. A slug that is not present
	 * is created; an already-seeded slug is re-written in place only when the
	 * bundled fixture carries a newer `version` (semver) — so curated template
	 * improvements propagate to upgraded installs instead of being frozen at
	 * the first-seeded version. Admin-created rows (isSeeded !== true) are never
	 * touched. A slug at or below the stored version is skipped (idempotent).
	 *
	 * Per-run counts and collected errors. `seeded` counts new rows, `updated` counts in-place version
	 * upgrades, `skipped` counts up-to-date/admin rows. `deferred` is set when the register/schema is not
	 * provisioned yet (install ordering) — the caller re-runs on the next repair rather than failing the
	 * install. It was returned but never declared, so SeedApplicationTemplates' check for it read as statically
	 * impossible.
	 *
	 * @return array{seeded:int,updated:int,skipped:int,errors:array<int,string>,deferred?:bool}
	 *
	 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-11
	 */
	public function seed(): array;
}//end interface
