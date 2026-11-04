<?php

/**
 * Buildiq Sync Request Params
 *
 * Reads what a GitHub sync call asks for from the request. The acting user is never
 * among them: it comes from the session, through the caller.
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

use OCP\IRequest;

/**
 * The credential, repository and version parameters of one GitHub sync request.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
final class SyncRequestParams {
	/**
	 * Safe GitHub owner/repo/org pattern.
	 */
	public const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The optional `credentialId` param.
	 *
	 * @return string|null The credential id, or null when absent or empty.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function credential(): ?string {
		$credentialId = $this->request->getParam('credentialId');
		if (is_string($credentialId) === true && $credentialId !== '') {
			return $credentialId;
		}

		return null;
	}//end credential()

	/**
	 * What a push asks for.
	 *
	 * @param string $credentialId The validated credential.
	 * @param string|null $actingUserId The session UID (broker owner-guard identity).
	 *
	 * @return AppPushRequest
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function pushRequest(string $credentialId, ?string $actingUserId): AppPushRequest {
		$versionSlugRaw = $this->request->getParam('versionSlug');
		$versionSlug = null;
		if (is_string($versionSlugRaw) === true && $versionSlugRaw !== '') {
			$versionSlug = $versionSlugRaw;
		}

		return new AppPushRequest(
			access: new BrokerAccess(credentialId: $credentialId, actingUserId: $actingUserId),
			versionSlug: $versionSlug,
			newRepo: $this->newRepo()
		);
	}//end pushRequest()

	/**
	 * The repository a link names, from the `owner` and `name` params, with an
	 * empty credential when the request names none.
	 *
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return BrokeredRepo
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function linkRepo(?string $actingUserId): BrokeredRepo {
		return new BrokeredRepo(
			target: new PushTarget(
				org: (string)($this->request->getParam('owner') ?? ''),
				repo: (string)($this->request->getParam('name') ?? '')
			),
			access: new BrokerAccess(credentialId: (string)$this->credential(), actingUserId: $actingUserId)
		);
	}//end linkRepo()

	/**
	 * The repository to create on this push, from the `repo` and `visibility` params.
	 *
	 * @return PushTarget|null The new repository, or null to push to the linked one.
	 */
	private function newRepo(): ?PushTarget {
		$repo = $this->request->getParam('repo');
		if (is_array($repo) === false) {
			return null;
		}

		$owner = (string)($repo['owner'] ?? '');
		$name = (string)($repo['name'] ?? '');
		$org = (string)($repo['org'] ?? '');
		if (preg_match(self::OWNER_REPO_PATTERN, $name) !== 1) {
			return null;
		}

		if ($owner !== '' && preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1) {
			return null;
		}

		if ($org !== '' && preg_match(self::OWNER_REPO_PATTERN, $org) !== 1) {
			return null;
		}

		return new PushTarget(org: $org, repo: $name, visibility: $this->visibility());
	}//end newRepo()

	/**
	 * The optional `visibility` param for a freshly created repo. Defaults to
	 * 'public' (shop discoverability); only an explicit 'private' flips it.
	 *
	 * @return string 'public' or 'private'.
	 */
	private function visibility(): string {
		$visibility = $this->request->getParam('visibility');
		if (is_string($visibility) === true && strtolower($visibility) === 'private') {
			return 'private';
		}

		return 'public';
	}//end visibility()
}//end class
