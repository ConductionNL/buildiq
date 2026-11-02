<?php

/**
 * Buildiq RepoSource
 *
 * The GitHub repository a channel is fetched from: its coordinates, the git ref
 * and the broker credential the fetch authenticates with.
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
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Repository coordinates, ref and credential of one channel fetch.
 *
 * @spec openspec/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
 */
final class RepoSource {

	/**
	 * Constructor.
	 *
	 * @param string      $owner        Repo owner, or '' when unknown.
	 * @param string      $repo         Repo name, or '' when unknown.
	 * @param string|null $ref          Optional git ref.
	 * @param string|null $credentialId Optional broker credential UUID.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
		public readonly ?string $ref,
		public readonly ?string $credentialId,
	) {
	}//end __construct()
}//end class
