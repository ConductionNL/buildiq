<?php

/**
 * Contract of ExportService
 *
 * The public surface of ExportService that other components call, so they depend
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

use OCA\Buildiq\Kernel\Values\ExportContents;
use OCA\Buildiq\Kernel\Values\ExportTarget;

/**
 * Contract of ExportService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportServiceInterface {

	/**
	 * Build the ZIP archive for an Application + version into app-data.
	 *
	 * A missing template snapshot or a ZIP that cannot be written surfaces as
	 * the RuntimeException ExportTreeFiles throws.
	 *
	 * @param ExportTarget $target The application, version and job the archive is built for.
	 * @param ExportContents $contents The placeholder context, data register bindings, flows and
	 *                                 application source the tree is filled from.
	 * @param bool $includeServerBundle Add `deploy/`, the files that run the app on its own server.
	 *
	 * @return string Absolute (local) path to the produced ZIP.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag mirrors the export job's includeServerBundle field.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-40
	 * @spec openspec/changes/archive/2026-10-07-data-registers-runtime/tasks.md#task-4.2
	 */
	public function generateAppZip(
		ExportTarget $target,
		ExportContents $contents,
		bool $includeServerBundle = false,
	): string;

	/**
	 * What the last export wrote of the application itself.
	 *
	 * @return array{pages: int, menu: int, schemas: int, records: int}|null Null when no application was bundled.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-is-asynchronous-via-nextcloud-s-ijob
	 */
	public function lastContent(): ?array;

	/**
	 * What the last export could not resolve.
	 *
	 * @return array<int, array{kind: string, ref: string, reason: string}> Skips, empty when everything resolved.
	 *
	 * @spec openspec/specs/app-export-completeness/spec.md#requirement-an-unresolvable-binding-must-be-reported-not-silently-dropped
	 * @spec openspec/specs/app-composition-bindings/spec.md#requirement-a-dangling-binding-must-reach-the-operator
	 */
	public function lastSkipped(): array;

	/**
	 * Resolve the work scratch directory path that holds the generated tree
	 * for a job. The GitHub push target reads the tree from here after
	 * generateAppZip() has populated it. This is a pure path resolver — it
	 * does NOT create or wipe the directory (see prepareScratchDir for that).
	 *
	 * @param string $jobUuid ExportJob UUID.
	 *
	 * @return string Absolute path to the scratch tree directory.
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	public function scratchTreeDir(string $jobUuid): string;
}//end interface
