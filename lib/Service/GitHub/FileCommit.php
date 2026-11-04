<?php

/**
 * Buildiq File Commit
 *
 * A file map to commit on one branch of one repository.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\GitHub
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

namespace OCA\Buildiq\Service\GitHub;

/**
 * The repository, branch and files of one commit.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
final class FileCommit {
	/**
	 * Constructor.
	 *
	 * @param BrokeredRepo $repo The repository and the access its calls use.
	 * @param string $branch The target branch.
	 * @param array<string,string> $files The `path => contents` map.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly BrokeredRepo $repo,
		public readonly string $branch,
		public readonly array $files,
	) {
	}//end __construct()
}//end class
