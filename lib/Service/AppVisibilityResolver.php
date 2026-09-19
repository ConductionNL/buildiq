<?php

/**
 * Buildiq App Visibility Resolver
 *
 * The ONE implementation of the per-user visibility check order that gates a
 * published virtual app's surfaces. Two call sites share it:
 *
 *   - AppNavigationService::isVisibleForCurrentUser() — the top-bar entry.
 *   - VirtualAppWidget::isEnabled() — a promoted Nextcloud dashboard widget.
 *
 * It is a class rather than a copied method because two copies of an
 * authorization check drift, and the drift is invisible until someone sees
 * something they should not.
 *
 * Evaluation order (REQ-OBNAV-002, reused verbatim by nc-dashboard-widgets):
 *   1. group:* sentinel in any role array → visible to all signed-in users.
 *   2. user:<uid> match in any role array.
 *   3. group:<gid> or bare group GID match against the user's memberships.
 *   4. Nextcloud admin bypass.
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
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-a-user-only-sees-widgets-they-are-allowed-to-see
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Shared per-user visibility check for published virtual-app surfaces.
 */
class AppVisibilityResolver {
	/**
	 * Group:* sentinel — when present in any role array, the surface is
	 * visible to all signed-in users (REQ-OBNAV-003).
	 */
	public const WILDCARD = 'group:*';

	/**
	 * Decide whether the signed-in user may see a surface of this Application.
	 *
	 * @param array<string,mixed> $permissions The Application's permissions block.
	 * @param array<mixed> $extraPrincipals Extra principals from the surface itself
	 *                                      (a widget placement's `roles` array),
	 *                                      merged with the Application's own.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @return bool True when the surface should be visible.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-a-user-only-sees-widgets-they-are-allowed-to-see
	 */
	public function isVisible(
		array $permissions,
		array $extraPrincipals,
		IUserSession $userSession,
		IGroupManager $groupManager,
	): bool {
		$user = $userSession->getUser();
		if ($user === null) {
			return false;
		}

		$uid = $user->getUID();
		$allPrincipals = array_merge(
			$this->flattenPermissions(permissions: $permissions),
			array_values($extraPrincipals)
		);

		// 1. Wildcard sentinel — visible to everyone signed in.
		if (in_array(self::WILDCARD, $allPrincipals, strict: true) === true) {
			return true;
		}

		// 2. Direct UID match.
		if (in_array('user:' . $uid, $allPrincipals, strict: true) === true) {
			return true;
		}

		// 3. Group-based match.
		$userGroups = $groupManager->getUserGroupIds(user: $user);
		if ($this->principalsMatchGroups(principals: $allPrincipals, userGroups: $userGroups) === true) {
			return true;
		}

		// 4. Nextcloud admin always sees all surfaces.
		return $groupManager->isAdmin($uid);
	}//end isVisible()

	/**
	 * Flatten the three permission role arrays into a single principal list.
	 *
	 * @param array<string,mixed> $permissions The Application's permissions block.
	 *
	 * @return array<mixed> All principals from owners + editors + viewers.
	 */
	public function flattenPermissions(array $permissions): array {
		$owners = ($permissions['owners'] ?? []);
		$editors = ($permissions['editors'] ?? []);
		$viewers = ($permissions['viewers'] ?? []);

		if (is_array($owners) === false) {
			$owners = [];
		}

		if (is_array($editors) === false) {
			$editors = [];
		}

		if (is_array($viewers) === false) {
			$viewers = [];
		}

		return array_merge($owners, $editors, $viewers);
	}//end flattenPermissions()

	/**
	 * Check whether any principal in the list matches one of the user's groups.
	 *
	 * @param array<mixed> $principals All principals from the permissions block.
	 * @param array<string> $userGroups The calling user's group IDs.
	 *
	 * @return bool True when a group match is found.
	 */
	private function principalsMatchGroups(array $principals, array $userGroups): bool {
		foreach ($principals as $principal) {
			if (is_string($principal) === false) {
				continue;
			}

			// Strip "group:" prefix for the normalised comparison.
			$gid = $principal;
			if (str_starts_with($principal, 'group:') === true) {
				$gid = substr($principal, strlen('group:'));
			}

			if ($gid === '*') {
				// Already handled by the wildcard sentinel in the caller.
				continue;
			}

			if (in_array($gid, $userGroups, strict: true) === true) {
				return true;
			}
		}//end foreach

		return false;
	}//end principalsMatchGroups()
}//end class
