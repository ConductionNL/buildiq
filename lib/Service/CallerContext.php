<?php

/**
 * Buildiq Caller Context
 *
 * The caller's session, group memberships and the shared permission matcher. Controllers and services that check who is calling take the session, the group manager and the permission matcher together, so they come as one object.
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
 */
final class CallerContext {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Session of the calling user.
	 * @param IGroupManager $groupManager Group memberships and admin check.
	 * @param PermissionResolver $permissionResolver Shared role and group matcher.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly PermissionResolver $permissionResolver,
	) {
	}//end __construct()
}//end class
