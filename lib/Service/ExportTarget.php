<?php

/**
 * Buildiq ExportTarget
 *
 * Which application, version and job one export run builds an archive for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The application, version and export job an archive is built for.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-4.1
 */
final class ExportTarget {

	/**
	 * Constructor.
	 *
	 * @param string $applicationUuid Source Application UUID.
	 * @param string $versionSlug     Semver of the Application version.
	 * @param string $jobUuid         ExportJob UUID, used as the ZIP filename.
	 * @param string $applicationSlug Slug of the application whose agents to collect.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $applicationUuid,
		public readonly string $versionSlug,
		public readonly string $jobUuid,
		public readonly string $applicationSlug = '',
	) {
	}//end __construct()
}//end class
