<?php

/**
 * OpenBuilt PermissionResolver Service
 *
 * Single source of truth for the permission-grammar used across all four
 * call sites in OpenBuilt (AbstractToolHandler, ApplicationsController,
 * VersionPromotionController, ManifestResolverService). Replaces four
 * diverging inline implementations that each parsed the same `user:` /
 * `group:` principal grammar differently.
 *
 * Grammar (REQ-OBRBAC-002):
 *   - `user:<uid>`   — grants access to the specific user with UID `<uid>`.
 *   - `group:<gid>`  — grants access to any user whose group membership
 *                      includes `<gid>`.
 *   - `<value>`      — back-compat: treated as a group GID (same as
 *                      `group:<value>`). Apps that want to grant access to a
 *                      specific user MUST use the `user:` prefix.
 *
 * Bare (unqualified) values that look like user IDs are treated as group GIDs
 * for backward-compatibility with seeds that shipped before the prefix mandate.
 * A warning is logged for each bare value encountered so operators can migrate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Service
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

namespace OCA\OpenBuilt\Service;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Centralised principal matching for OpenBuilt Application permissions blocks.
 *
 * All four previous call sites (AbstractToolHandler::callerHasWriteRole,
 * ApplicationsController::classifyPrincipal + collectAuthorisedGroups,
 * VersionPromotionController::assertEditorOrOwner,
 * ManifestResolverService::bucketContainsUid) are replaced by
 * PermissionResolver::matchesCaller().
 */
class PermissionResolver
{
    /**
     * Constructor.
     *
     * @param IGroupManager   $groupManager Group membership resolver.
     * @param LoggerInterface $logger       PSR logger for bare-principal warnings.
     *
     * @return void
     */
    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether the caller holds any of the requested roles on the Application.
     *
     * Iterates the specified role buckets in the Application's `permissions`
     * block and tests each principal against the caller using the canonical
     * grammar (`user:` / `group:` prefixes, bare-value back-compat).
     *
     * @param array<string, mixed> $permissions      The Application's `permissions` block.
     * @param IUser                $caller           The authenticated caller.
     * @param array<string>        $userGroups       The caller's group GID list
     *                                               (pre-resolved by the call
     *                                               site).
     * @param bool|null            $allowAdminBypass When true, NC admin group
     *                                               membership grants access
     *                                               regardless of role
     *                                               entries. When false
     *                                               (explicit), no bypass is
     *                                               applied. Defaults to true
     *                                               for read/write gates; use
     *                                               false for promotion
     *                                               (REQ-OBVP-007).
     * @param array<int, string>   $roles            Role bucket names to check
     *                                               (e.g.
     *                                               ['owners','editors']).
     *
     * @return bool True when the caller matches any entry in any of the given roles.
     */
    public function matchesCaller(
        array $permissions,
        IUser $caller,
        array $userGroups,
        ?bool $allowAdminBypass,
        array $roles
    ): bool {
        if ($permissions === []) {
            return false;
        }

        $callerUid = $caller->getUID();

        // Admin bypass — checked before the role scan so it short-circuits cleanly.
        // Only applied when $allowAdminBypass is explicitly true (not null / false).
        if ($allowAdminBypass === true && $this->groupManager->isAdmin($callerUid) === true) {
            return true;
        }

        // Collect user UIDs and group GIDs from the requested role buckets.
        $userSet  = [];
        $groupSet = [];

        foreach ($roles as $role) {
            $bucket = ($permissions[$role] ?? []);
            if (is_array($bucket) === false) {
                continue;
            }

            foreach ($bucket as $principal) {
                $this->classifyPrincipal(
                    principal: $principal,
                    userSet: $userSet,
                    groupSet: $groupSet
                );
            }//end foreach
        }//end foreach

        // Direct UID match.
        if (isset($userSet[$callerUid]) === true) {
            return true;
        }

        // Group intersection.
        foreach ($userGroups as $gid) {
            if (isset($groupSet[$gid]) === true) {
                return true;
            }
        }

        return false;

    }//end matchesCaller()

    /**
     * Resolve the caller's group GID list from the current session user.
     *
     * Convenience wrapper around IGroupManager::getUserGroups() that returns a
     * flat array of GID strings.
     *
     * @param IUser $user The Nextcloud user.
     *
     * @return array<int, string> Group GID list.
     */
    public function resolveUserGroups(IUser $user): array
    {
        $groups = $this->groupManager->getUserGroups($user);
        $ids    = [];
        foreach ($groups as $group) {
            if ($group instanceof IGroup) {
                $ids[] = $group->getGID();
            }
        }

        return $ids;

    }//end resolveUserGroups()

    /**
     * Classify a single principal string into the UID or GID accumulator maps.
     *
     * Canonical forms:
     *   `user:<uid>`  → added to $userSet  keyed by `<uid>`.
     *   `group:<gid>` → added to $groupSet keyed by `<gid>`.
     *   `<bare>`      → back-compat: added to $groupSet (with a one-time warning).
     *
     * Modifies $userSet and $groupSet by reference.
     *
     * @param mixed               $principal The raw principal value from a permissions bucket.
     * @param array<string, bool> $userSet   Accumulator for user UIDs (keyed, deduped).
     * @param array<string, bool> $groupSet  Accumulator for group GIDs (keyed, deduped).
     *
     * @return void
     */
    private function classifyPrincipal(mixed $principal, array &$userSet, array &$groupSet): void
    {
        if (is_string($principal) === false || $principal === '') {
            return;
        }

        if (str_starts_with($principal, 'user:') === true) {
            $uid = substr($principal, 5);
            if ($uid !== '') {
                $userSet[$uid] = true;
            }

            return;
        }

        if (str_starts_with($principal, 'group:') === true) {
            $gid = substr($principal, 6);
            if ($gid !== '') {
                $groupSet[$gid] = true;
            }

            return;
        }

        // Back-compat: bare value treated as group GID. Log a warning so operators
        // can migrate to the `group:` prefix form.
        $this->logger->warning(
            'OpenBuilt: bare principal value in permissions block — treat as group GID (migrate to group:<gid>)',
            ['principal' => $principal]
        );
        $groupSet[$principal] = true;

    }//end classifyPrincipal()
}//end class
