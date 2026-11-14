<?php

/**
 * Contract of AppVisibilityResolver
 *
 * The public surface of AppVisibilityResolver that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

use OCP\IGroupManager;
use OCP\IUser;

/**
 * Contract of AppVisibilityResolver.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppVisibilityResolverInterface {

	/**
	 * Decide whether the given user may see one placement of this Application.
	 *
	 * The user must pass the Application's own check AND, when the placement
	 * declares `roles`, match one of them. Roles narrow who sees a placement;
	 * they never add viewers the Application itself does not have.
	 *
	 * @param array<mixed> $appPrincipals The Application's flattened permissions.
	 * @param array<mixed> $roles The placement's normalised `roles`; empty means unrestricted.
	 * @param IUser|null $user The user, or null when nobody is signed in.
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @return bool True when the placement should be visible.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-a-user-only-sees-widgets-they-are-allowed-to-see
	 */
	public function isPlacementVisibleToUser(
		array $appPrincipals,
		array $roles,
		?IUser $user,
		IGroupManager $groupManager,
	): bool;
}//end interface
