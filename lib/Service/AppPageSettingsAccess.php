<?php

/**
 * Buildiq App Page Settings Access
 *
 * Decides who reads and who saves the values of a virtual app's settings
 * pages (REQ-BQCV-011): any role on the app reads, owners and editors save.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The role check of the settings page values, over the shared RBAC grammar.
 *
 * No admin bypass: the values are the app team's. Another organisation's
 * scoped app answers like an unknown one.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */
final class AppPageSettingsAccess {
	/**
	 * The roles that read the values.
	 *
	 * @var array<int,string>
	 */
	public const READ_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * The roles that save the values.
	 *
	 * @var array<int,string>
	 */
	public const SAVE_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession                $userSession        The signed-in user.
	 * @param PermissionResolverInterface $permissionResolver Shared RBAC grammar resolver.
	 * @param AppChangeHistory            $applications       Looks the Application up by slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly AppChangeHistory $applications,
	) {
	}//end __construct()

	/**
	 * The Application's uuid and whether the caller may save, or the refusal.
	 *
	 * @param string            $appSlug The Application slug.
	 * @param array<int,string> $roles   The roles that pass.
	 *
	 * @return array{appUuid: string, canSave: bool}|array{error: string, status: int} The grant or the refusal.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
	 */
	public function grant(string $appSlug, array $roles): array {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return ['error' => 'unauthenticated', 'status' => 401];
		}

		$application = $this->applications->application(slug: $appSlug);
		if ($application === null || $this->permissionResolver->seesApplication(application: $application, caller: $caller) === false) {
			return ['error' => 'not_found', 'status' => 404];
		}

		$appUuid = (string)($application['id'] ?? $application['uuid'] ?? '');
		if ($appUuid === '') {
			return ['error' => 'not_found', 'status' => 404];
		}

		if ($this->hasRole(application: $application, caller: $caller, roles: $roles) === false) {
			return ['error' => 'buildiq.rbac.no_role', 'status' => 403];
		}

		return [
			'appUuid' => $appUuid,
			'canSave' => $this->hasRole(application: $application, caller: $caller, roles: self::SAVE_ROLES),
		];
	}//end grant()

	/**
	 * Whether the caller holds one of the roles on the Application.
	 *
	 * @param array<string,mixed> $application The Application record.
	 * @param IUser               $caller      The signed-in user.
	 * @param array<int,string>   $roles       The roles that pass.
	 *
	 * @return bool True when the caller holds one of them.
	 */
	private function hasRole(array $application, IUser $caller, array $roles): bool {
		$granted = $application['permissions'] ?? null;
		$groups  = $this->permissionResolver->resolveUserGroups(user: $caller);

		return is_array($granted) === true
			&& $this->permissionResolver->matchesCaller(permissions: $granted, caller: $caller, userGroups: $groups, allowAdminBypass: false, roles: $roles);
	}//end hasRole()
}//end class
