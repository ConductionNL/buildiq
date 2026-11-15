<?php

/**
 * Contract of PermissionResolver
 *
 * The public surface of PermissionResolver that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of PermissionResolver.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PermissionResolverInterface {

	/**
	 * Whether the caller is a Nextcloud administrator.
	 *
	 * Exposed so guards can grant the audited NC-admin escape hatch in the
	 * degenerate case where no `permissions` block exists to feed
	 * {@see matchesCaller()} (which short-circuits on an empty block before its
	 * own admin bypass can run). Uses the SAME `IGroupManager::isAdmin()` check
	 * that {@see matchesCaller()}'s `allowAdminBypass` path trusts, so the admin
	 * grammar stays in one place.
	 *
	 * @param IUser $caller The authenticated caller.
	 *
	 * @return bool True when the caller belongs to the NC `admin` group.
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function isAdmin(IUser $caller): bool;

	/**
	 * Check whether the caller holds any of the requested roles on the Application.
	 *
	 * Iterates the specified role buckets in the Application's `permissions`
	 * block and tests each principal against the caller using the canonical
	 * grammar (`user:` / `group:` prefixes, bare-value back-compat).
	 *
	 * @param array<string, mixed> $permissions The Application's `permissions` block.
	 * @param IUser $caller The authenticated caller.
	 * @param array<string> $userGroups The caller's group GID list
	 *                                  (pre-resolved by the call
	 *                                  site).
	 * @param bool|null $allowAdminBypass When true, NC admin group
	 *                                    membership grants access
	 *                                    regardless of role
	 *                                    entries. When false
	 *                                    (explicit), no bypass is
	 *                                    applied. Defaults to true
	 *                                    for read/write gates; use
	 *                                    false for promotion
	 *                                    (REQ-OBVP-007).
	 * @param array<int, string> $roles Role bucket names to check
	 *                                  (e.g.
	 *                                  ['owners','editors']).
	 *
	 * @return bool True when the caller matches any entry in any of the given roles.
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function matchesCaller(
		array $permissions,
		IUser $caller,
		array $userGroups,
		?bool $allowAdminBypass,
		array $roles,
	): bool;

	/**
	 * Check whether the caller owns a `scope: user` ApplicationVersion (user-delta RBAC).
	 *
	 * A user-scoped delta (layered-versioned-app-deltas) has exactly one owner —
	 * the UID in its `owner` field. Unlike the admin `permissions` grammar there
	 * is NO group logic on a user row: the caller is authorised iff their UID
	 * equals the row's `owner` (or they are a Nextcloud admin exercising the
	 * audited escape hatch when `allowAdminBypass` is true). Fail-closed: an
	 * absent / non-string owner denies.
	 *
	 * @param array<string, mixed> $version The ApplicationVersion payload (must carry `owner`).
	 * @param IUser $caller The authenticated caller.
	 * @param bool $allowAdminBypass When true, an NC admin is granted regardless of ownership.
	 *
	 * @return bool True when the caller owns the user-scoped row (or is an admin under bypass).
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function matchesUserScopeOwner(array $version, IUser $caller, bool $allowAdminBypass = true): bool;

	/**
	 * Resolve the caller's group GID list from the current session user.
	 *
	 * Convenience wrapper around IGroupManager::getUserGroups() that returns a
	 * flat array of GID strings.
	 *
	 * @param IUser $user The Nextcloud user.
	 *
	 * @return array<int, string> Group GID list.
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function resolveUserGroups(IUser $user): array;
}//end interface
