<?php

/**
 * Buildiq App Push Request
 *
 * What an owner pushes: the version, the access, and optionally a repository to create for it.
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
 * The version, access and optional new repository of one application push.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
final class AppPushRequest {
	/**
	 * Constructor.
	 *
	 * @param BrokerAccess $access The broker credential and its owner.
	 * @param string|null $versionSlug Version to push (else the production version, else the first).
	 * @param PushTarget|null $newRepo Repository to create and link on this push; null pushes to the linked one.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly BrokerAccess $access,
		public readonly ?string $versionSlug = null,
		public readonly ?PushTarget $newRepo = null,
	) {
	}//end __construct()
}//end class
