<?php

/**
 * Buildiq Brokered Repo
 *
 * A GitHub repository together with the broker credential every call on it goes out under.
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
 * One repository and the access its calls use.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
final class BrokeredRepo {
	/**
	 * Constructor.
	 *
	 * @param PushTarget $target The owner, repository name and fresh-repo visibility.
	 * @param BrokerAccess $access The broker credential and its owner.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly PushTarget $target,
		public readonly BrokerAccess $access,
	) {
	}//end __construct()

	/**
	 * The same repository and access under the given owner.
	 *
	 * @param string $owner The repository owner.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function ownedBy(string $owner): self {
		return new self(
			target: new PushTarget(org: $owner, repo: $this->target->repo, visibility: $this->target->visibility),
			access: $this->access
		);
	}//end ownedBy()

	/**
	 * The same owner and access under the given repository name.
	 *
	 * @param string $name The repository name.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function named(string $name): self {
		return new self(
			target: new PushTarget(org: $this->target->org, repo: $name, visibility: $this->target->visibility),
			access: $this->access
		);
	}//end named()
}//end class
