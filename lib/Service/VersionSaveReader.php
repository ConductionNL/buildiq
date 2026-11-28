<?php

/**
 * VersionSaveReader
 *
 * Reads the saves of one app version for a caller: the app must exist and be
 * visible to the caller, the caller must be an owner, editor or viewer of it
 * (no admin bypass), and the version must belong to it (REQ-BQCV-015).
 *
 * @category  Service
 * @package   OCA\Buildiq\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git_id>
 *
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCP\AppFramework\Http;
use OCP\IUser;
use OCP\IUserSession;

/**
 * A caller's view of a version's saves.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author  Conduction b.v. <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
class VersionSaveReader {

	/**
	 * The roles that read a version's saves.
	 *
	 * @var list<string>
	 */
	private const READ_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * Constructor.
	 *
	 * @param PermissionResolverInterface $permissionResolver The shared role check.
	 * @param AppChangeHistory            $apps               Finds the app by slug and its versions.
	 * @param VersionSaveHistory          $saves              The saved manifests.
	 * @param IUserSession                $userSession        The signed-in user.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly AppChangeHistory $apps,
		private readonly VersionSaveHistory $saves,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The saves of a version, newest first, or the HTTP status that refuses them.
	 *
	 * @param string $appSlug     The app.
	 * @param string $versionSlug The version.
	 *
	 * @return list<array<string,mixed>>|int The saves, or 401, 403, 404 or 503.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	public function saves(string $appSlug, string $versionSlug): array|int {
		$versionUuid = $this->versionUuid(appSlug: $appSlug, versionSlug: $versionSlug);
		if (is_int($versionUuid) === true) {
			return $versionUuid;
		}

		return $this->saves->saves(versionUuid: $versionUuid);
	}//end saves()

	/**
	 * The manifest of one save of a version, or the HTTP status that refuses it.
	 *
	 * @param string $appSlug     The app.
	 * @param string $versionSlug The version.
	 * @param string $saveId      The save.
	 *
	 * @return array<mixed>|int The manifest, or 401, 403, 404 or 503.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	public function manifest(string $appSlug, string $versionSlug, string $saveId): array|int {
		$versionUuid = $this->versionUuid(appSlug: $appSlug, versionSlug: $versionSlug);
		if (is_int($versionUuid) === true) {
			return $versionUuid;
		}

		return ($this->saves->manifest(versionUuid: $versionUuid, saveId: $saveId) ?? Http::STATUS_NOT_FOUND);
	}//end manifest()

	/**
	 * The uuid of the signed-in caller's version, or the HTTP status that refuses it.
	 *
	 * Another organisation's scoped app, an unknown app and an unknown version are all 404,
	 * so the answer tells nothing about apps the caller may not see.
	 *
	 * @param string $appSlug     The app.
	 * @param string $versionSlug The version.
	 *
	 * @return string|int
	 */
	private function versionUuid(string $appSlug, string $versionSlug): string|int {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return Http::STATUS_UNAUTHORIZED;
		}

		$application = $this->apps->application(slug: $appSlug);
		if ($application === null || $this->permissionResolver->seesApplication(application: $application, caller: $caller) === false) {
			return Http::STATUS_NOT_FOUND;
		}

		if ($this->hasReadRole(application: $application, caller: $caller) === false) {
			return Http::STATUS_FORBIDDEN;
		}

		$versions = $this->apps->versions(application: $application);
		if ($versions === null) {
			return Http::STATUS_SERVICE_UNAVAILABLE;
		}

		$match = array_values(array_filter($versions, static fn (array $version): bool => (string)($version['slug'] ?? '') === $versionSlug));
		if ($match === []) {
			return Http::STATUS_NOT_FOUND;
		}

		return (string)($match[0]['id'] ?? $match[0]['uuid'] ?? '');
	}//end versionUuid()

	/**
	 * Whether the caller is an owner, editor or viewer of the app; an admin gets no bypass.
	 *
	 * @param array<string,mixed> $application The app.
	 * @param IUser               $caller      The signed-in user.
	 *
	 * @return bool
	 */
	private function hasReadRole(array $application, IUser $caller): bool {
		$groups = $this->permissionResolver->resolveUserGroups(user: $caller);

		return $this->permissionResolver->matchesCaller(
			permissions: (array)($application['permissions'] ?? []),
			caller: $caller,
			userGroups: $groups,
			allowAdminBypass: false,
			roles: self::READ_ROLES
		);
	}//end hasReadRole()
}//end class
