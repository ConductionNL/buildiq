<?php

/**
 * RepoDescriptor: descriptor fields shared by the app and template repo serialisers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Derives repo descriptor fields from a stored record and its manifest.
 *
 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
 */
final class RepoDescriptor {

	/**
	 * The descriptor category: the record's, else the manifest's, else `general`.
	 *
	 * @param array<string,mixed> $record   The application or template object.
	 * @param array<string,mixed> $manifest Its manifest blob.
	 *
	 * @return string
	 *
	 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
	 */
	public static function category(array $record, array $manifest): string {
		foreach ([$record, $manifest] as $source) {
			$category = trim((string)($source['category'] ?? ''));
			if ($category !== '') {
				return $category;
			}
		}

		return 'general';
	}//end category()
}//end class
