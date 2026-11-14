<?php

/**
 * Contract of AppPackageImportService
 *
 * The public surface of AppPackageImportService that other components call, so
 * they depend on this contract instead of the implementation.
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
 * Contract of AppPackageImportService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppPackageImportServiceInterface {
	/**
	 * Import an exported app archive as a new app owned by the given user.
	 *
	 * @param string $archivePath Path of the uploaded or given archive.
	 * @param string $name        The new app's name; empty for the archive's own name.
	 * @param string $slug        The new app's slug; empty for the archive's own slug.
	 * @param string $ownerUid    The user who will own the new app.
	 *
	 * @return array{status:int,data:array<string,mixed>} 201 with the app; 400, 409, 413 or 422 with an error code.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on AppPackageImportService::import()
	 */
	public function import(string $archivePath, string $name, string $slug, string $ownerUid): array;

	/**
	 * Import an exported app archive as a new app, and its records when asked.
	 *
	 * @param string              $archivePath Path of the uploaded or given archive.
	 * @param array<string,mixed> $target      `name`, `slug`, `owner` (UID) and `records` (bool).
	 *
	 * @return array{status:int,data:array<string,mixed>} As import(); with records asked, `data.records` holds the per-schema report.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on AppPackageImportService::importWithRecords()
	 */
	public function importWithRecords(string $archivePath, array $target): array;

	/**
	 * What an archive would import, without importing it.
	 *
	 * @param string $archivePath The archive.
	 *
	 * @return array{status:int,data:array<string,mixed>} 200 with name, slug, pages, schemas and records; 413 or 422 with an error code.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on AppPackageImportService::preview()
	 */
	public function preview(string $archivePath): array;
}//end interface
