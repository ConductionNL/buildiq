<?php

/**
 * Buildiq VersionAccessRequest
 *
 * The three values a named-version lookup checks access with: the app slug and
 * version slug the caller asked for, and the caller. Built by
 * ManifestResolverService for its own version gate; it never leaves that class.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-71
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\IUser;

/**
 * A request to open a named ApplicationVersion.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-71
 */
final class VersionAccessRequest {
	/**
	 * Constructor.
	 *
	 * @param string $appSlug The virtual-app slug.
	 * @param string $versionSlug The non-empty version slug.
	 * @param IUser|null $caller The caller, or null when unauthenticated.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $appSlug,
		public readonly string $versionSlug,
		public readonly ?IUser $caller,
	) {
	}//end __construct()
}//end class
