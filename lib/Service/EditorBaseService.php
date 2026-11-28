<?php

/**
 * Buildiq EditorBaseService
 *
 * Gives the page designer the base a version extends, so it can show the
 * merged manifest, diff an edit against the base on save and preview the
 * result exactly as the server resolves it (app-delta-override D6). Only an
 * owner or editor of the app gets it, for a version they may open; another
 * app named as the base is read only when the caller's organisation scope
 * admits it.
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCP\IUser;

/**
 * Resolves the base of a version for an editor of its app.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
class EditorBaseService {
	private const APPLICATION_SCHEMA = 'built-app';

	private const VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Constructor.
	 *
	 * @param ManifestResolverServiceInterface $manifests   Finds the version a caller may open.
	 * @param PermissionResolverInterface      $permissions Role and organisation checks.
	 * @param RegisterRecordLookup             $records     Reads the Application and the version.
	 * @param ManifestBaseResolver             $bases       Resolves the base.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ManifestResolverServiceInterface $manifests,
		private readonly PermissionResolverInterface $permissions,
		private readonly RegisterRecordLookup $records,
		private readonly ManifestBaseResolver $bases,
	) {
	}//end __construct()

	/**
	 * The base of the version an owner or editor is designing, or null.
	 *
	 * Null for an unknown app or version, a version the caller may not open,
	 * and a caller who is not an owner or editor of the app: one answer, so
	 * the endpoint does not tell which apps exist.
	 *
	 * @param string      $appSlug     The app slug.
	 * @param string|null $versionSlug The version slug, or null for production.
	 * @param IUser       $caller      The caller.
	 *
	 * @return array{base: array<string, mixed>|null, orphanedDeltaPaths: list<string>}|null
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
	 */
	public function authorizeBase(string $appSlug, ?string $versionSlug, IUser $caller): ?array {
		$ids = $this->manifests->resolveVersionForCaller(appSlug: $appSlug, versionSlug: $versionSlug, caller: $caller);
		if ($ids === null) {
			return null;
		}

		$application = $this->records->byUuid(schema: self::APPLICATION_SCHEMA, uuid: $ids['applicationUuid']);
		if ($application === null || $this->designs(application: $application, caller: $caller) === false) {
			return null;
		}

		$version = $this->records->byUuid(schema: self::VERSION_SCHEMA, uuid: $ids['versionUuid']);
		if ($version === null) {
			return null;
		}

		return $this->bases->baseOf(
			version: $version,
			visible: fn (array $base): bool => $this->permissions->seesApplication(application: $base, caller: $caller)
		);
	}//end authorizeBase()

	/**
	 * Whether the caller is an owner or editor of the app, without an admin bypass.
	 *
	 * @param array<string, mixed> $application The Application.
	 * @param IUser                $caller      The caller.
	 *
	 * @return bool
	 */
	private function designs(array $application, IUser $caller): bool {
		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			return false;
		}

		return $this->permissions->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: $this->permissions->resolveUserGroups(user: $caller),
			allowAdminBypass: false,
			roles: ['owners', 'editors']
		);
	}//end designs()
}//end class
