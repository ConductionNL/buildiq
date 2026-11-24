<?php

/**
 * AppStartSources
 *
 * The starting points the create-app wizard can build a new app from, beside
 * the default app: a connected database ("Start from a database") and an
 * uploaded spreadsheet ("Start from a spreadsheet"). One object, so the
 * wizard's service takes them as one dependency.
 *
 * @category  Service
 * @package   OCA\Buildiq\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git_id>
 *
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The wizard's starting points: a connected database or a spreadsheet.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author  Conduction b.v. <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 */
final class AppStartSources {

	/**
	 * Constructor.
	 *
	 * @param ConnectedDatabaseReader|null $databases         Reads a connected database register; null refuses that start.
	 * @param DatabaseManifestBuilder|null $databaseManifests Builds the list and detail pages of the chosen tables or the sheet.
	 * @param SpreadsheetAppSource|null    $spreadsheets      Reads and imports an uploaded spreadsheet; null refuses that start.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?ConnectedDatabaseReader $databases = null,
		public readonly ?DatabaseManifestBuilder $databaseManifests = null,
		public readonly ?SpreadsheetAppSource $spreadsheets = null,
	) {
	}//end __construct()

	/**
	 * The page builder, or a new one when none was given.
	 *
	 * @return DatabaseManifestBuilder
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function manifests(): DatabaseManifestBuilder {
		return ($this->databaseManifests ?? new DatabaseManifestBuilder());
	}//end manifests()
}//end class
