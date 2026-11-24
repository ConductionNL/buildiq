<?php

/**
 * Buildiq Schema Export Flag
 *
 * Marks a schema blob buildiq creates as exportable, so a list page's Export
 * switch alone decides whether the page offers Export.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Adds `configuration.exportable: true` to a schema blob before it is created.
 *
 * The CnIndexPage of nextcloud-vue draws Export only when the page sets
 * `allowExport` and the schema is flagged exportable, at the top level or in
 * `configuration.exportable`. A blob that already sets either flag, true or
 * false, keeps it: that is the template's or the owner's choice.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 */
final class SchemaExportFlag {
	/**
	 * The blob with `configuration.exportable: true` unless it already says.
	 *
	 * @param array<string,mixed> $blob The schema blob to create.
	 *
	 * @return array<string,mixed> The blob, marked exportable when it was silent.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
	 */
	public static function mark(array $blob): array {
		if (isset($blob['exportable']) === true) {
			return $blob;
		}

		$configuration = [];
		if (isset($blob['configuration']) === true && is_array($blob['configuration']) === true) {
			$configuration = $blob['configuration'];
		}

		if (isset($configuration['exportable']) === true) {
			return $blob;
		}

		$configuration['exportable'] = true;
		$blob['configuration'] = $configuration;

		return $blob;
	}//end mark()
}//end class
