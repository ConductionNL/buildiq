<?php

/**
 * Buildiq SchemaSlugClaims
 *
 * Tracks which companion schema file claimed each slug while one app repo is
 * parsed, refusing a second claim on the same slug.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\AppRepoParseException;

/**
 * Slug-to-path claims for one parse.
 */
final class SchemaSlugClaims {

	/**
	 * The path that claimed each slug.
	 *
	 * @var array<string,string>
	 */
	private array $paths = [];

	/**
	 * Claim a slug for a schema file.
	 *
	 * @param string $slug The schema slug.
	 * @param string $path The repo-relative schema path.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException schema_slug_duplicate when the slug was already claimed.
	 */
	public function claim(string $slug, string $path): void {
		if (isset($this->paths[$slug]) === true) {
			throw new AppRepoParseException(
				errorCode: 'schema_slug_duplicate',
				message: 'schema slug "' . $slug . '" is declared by both ' . $this->paths[$slug] . ' and ' . $path . '.',
				filePath: $path
			);
		}

		$this->paths[$slug] = $path;

	}//end claim()
}//end class
