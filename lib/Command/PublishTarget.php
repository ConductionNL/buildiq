<?php

/**
 * Buildiq PublishTarget
 *
 * Where and as whom the publish command writes template repos: the broker
 * credential, the optional org, the acting user and the visibility of a fresh repo.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\Buildiq\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Command;

/**
 * Credential, org, acting user and visibility of one publish run.
 *
 * @spec openspec/specs/github-app-repo-format/spec.md
 */
final class PublishTarget {

	/**
	 * Constructor.
	 *
	 * @param string      $credentialId The github broker credential UUID.
	 * @param string|null $org          Optional org.
	 * @param string|null $user         The acting user UID.
	 * @param string      $visibility   Fresh-repo visibility.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $credentialId,
		public readonly ?string $org,
		public readonly ?string $user,
		public readonly string $visibility,
	) {
	}//end __construct()
}//end class
