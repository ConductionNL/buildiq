<?php

/**
 * Buildiq Caller Context
 *
 * The caller's session, group memberships and the shared permission matcher. Controllers and
 * services that check who is calling take the session, the group manager and the permission
 * matcher together, so they come as one object.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The caller's session, group memberships and the shared permission matcher.
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */
final class CallerContext {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Session of the calling user.
	 * @param IGroupManager $groupManager Group memberships and admin check.
	 * @param PermissionResolver $permissionResolver Shared role and group matcher.
	 * @param AppOrganisationGuard|null $organisationGuard Hides another organisation's scoped app; without it a scoped app is hidden from everyone.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly PermissionResolver $permissionResolver,
		public readonly ?AppOrganisationGuard $organisationGuard = null,
	) {
	}//end __construct()

	/**
	 * Whether the calling user may see this Application at all, before any role.
	 *
	 * A shared app is visible to everyone; a scoped app only to members of its
	 * organisation. Without the guard a scoped app is hidden from everyone, so the
	 * check fails closed (REQ-BQOS-002).
	 *
	 * @param array<string, mixed> $application The Application, as OpenRegister renders it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AppOrganisationGuard::isShared() is a pure scope check with no collaborators.
	 */
	public function seesApplication(array $application): bool {
		if (AppOrganisationGuard::isShared(application: $application) === true) {
			return true;
		}

		return $this->organisationGuard?->canSee(application: $application, user: $this->userSession->getUser()) === true;
	}//end seesApplication()
}//end class
