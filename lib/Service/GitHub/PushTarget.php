<?php

/**
 * Buildiq Push Target
 *
 * The GitHub repository an export is pushed to: the organisation, the repository name
 * and the visibility it is created with.
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
 * The repository a push writes to.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 */
final class PushTarget {
	/**
	 * Constructor.
	 *
	 * @param string $org Target GitHub organisation or owner.
	 * @param string $repo Target repository name.
	 * @param string $visibility Repository visibility (`public`|`private`).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $org,
		public readonly string $repo,
		public readonly string $visibility = 'private',
	) {
	}//end __construct()

	/**
	 * The repository's API path, `/repos/{org}/{repo}`, each part URL-encoded.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public function repoPath(): string {
		return '/repos/' . rawurlencode($this->org) . '/' . rawurlencode($this->repo);
	}//end repoPath()
}//end class
