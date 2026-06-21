<?php

/**
 * OpenBuild ApplicationVersionOwnerGuard
 *
 * OpenRegister lifecycle guard for the destructive ApplicationVersion
 * transitions (publish, archive, reopen). Enforces that the caller holds the
 * `owners` role on the *parent* Application — closing the gap documented in the
 * Application schema's `authorization._note`, where OR's schema-level group
 * ACLs (create/update/delete = admin) cannot express the per-Application
 * owners/editors/viewers role rows stored in `permissions`.
 *
 * Per ADR-022/ADR-023 the real role rule lives here, in an app-registered,
 * default-secure, fail-closed `LifecycleGuardInterface` resolved by OR's
 * `LifecycleGuardRegistry` from the schema's
 * `x-openregister-lifecycle.transitions[*].requires` tag — NOT in a wrapper
 * around OR's REST endpoints and NOT in the descriptive `authorization` block
 * (which OR does not enforce for per-object role rows). The guard fires inside
 * OR's `LifecycleValidationListener` on every `publish` / `archive` / `reopen`
 * transition, so the OR write path itself is gated, not merely the in-app
 * controllers.
 *
 * Decision: Nextcloud admins are granted the transition as the audited
 * incident-response escape hatch (design.md Decision 5); every other caller
 * MUST be an owner of the parent Application. Resolving the version's
 * `application` relation prevents the IDOR where a caller who owns Application
 * B could drive a transition on a version belonging to Application A.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Lifecycle
 * @package  OCA\OpenBuild\Lifecycle
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

namespace OCA\OpenBuild\Lifecycle;

use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Owner-only guard for the destructive ApplicationVersion transitions.
 *
 * Fails closed: any inability to resolve the caller or the parent Application
 * denies the transition, and a caller who is neither an owner of the parent
 * Application nor an NC admin is denied. When the parent Application carries no
 * `permissions` block (rechtenblok) at all — the shape of a programmatically /
 * synthetically created Application — only the audited NC-admin escape hatch is
 * granted; every ordinary caller is still denied.
 *
 * @spec openspec/changes/openbuild-rbac/tasks.md#2.1
 */
class ApplicationVersionOwnerGuard implements LifecycleGuardInterface
{
    /**
     * The role buckets that authorise a destructive version transition.
     *
     * Per REQ-OBRBAC-004 publish / archive / reopen are owner-only.
     *
     * @var array<int, string>
     */
    private const OWNER_ROLES = ['owners'];

    /**
     * Constructor.
     *
     * @param ObjectService      $objectService      OR object surface used to load the parent Application.
     * @param PermissionResolver $permissionResolver Shared permission-grammar resolver (same grammar as the controllers).
     * @param IUserManager       $userManager        Resolves the acting UID to an IUser for the resolver.
     * @param LoggerInterface    $logger             PSR logger for fail-closed diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly PermissionResolver $permissionResolver,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Authorise a destructive ApplicationVersion transition.
     *
     * @param array<string, mixed> $object The ApplicationVersion payload at its current state.
     * @param string               $action The transition action (publish | archive | reopen).
     * @param string               $userId The acting user's UID.
     *
     * @return GuardResult Allow when the caller owns the parent Application (or
     *                     is a Nextcloud admin exercising the audited escape
     *                     hatch); deny — fail-closed — otherwise.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) GuardResult exposes only the static
     *  allow()/deny() factories mandated by OpenRegister's contract.
     *
     * @spec openspec/changes/openbuild-rbac/tasks.md#2.1
     */
    public function check(array $object, string $action, string $userId): GuardResult
    {
        $caller = $this->userManager->get($userId);
        if ($caller === null) {
            $this->logger->warning(
                'OpenBuild ApplicationVersionOwnerGuard: could not resolve caller UID; denying transition',
                ['uid' => $userId, 'action' => $action]
            );
            return GuardResult::deny('Uw gebruiker kon niet worden bepaald; transitie geweigerd.');
        }

        // User-scope branch (layered-versioned-app-deltas). A `scope: user` row
        // is a single user's personal delta — authorised iff the caller owns it
        // (audited NC-admin bypass aside) AND the parent Application still
        // permits per-user overrides. No group/owners-role logic applies to a
        // user row; the parent's `permissions` block is irrelevant here. Fails
        // closed: a foreign owner, an unresolvable parent, or a disabled flag
        // denies.
        if ((string) ($object['scope'] ?? 'admin') === 'user') {
            if ($this->permissionResolver->matchesUserScopeOwner(version: $object, caller: $caller) === false) {
                $this->logger->warning(
                    'OpenBuild ApplicationVersionOwnerGuard: non-owner denied on user-scoped delta',
                    ['action' => $action, 'uid' => $userId]
                );
                return GuardResult::deny(
                    'U mag deze persoonlijke aanpassing niet '.$action.'; alleen de eigenaar is gemachtigd.'
                );
            }

            $parent = $this->loadParentApplication(version: $object);
            $flag   = ($parent['allowUserOverrides'] ?? false);
            // An NC admin keeps the audited escape hatch even when the flag is off.
            if ($flag !== true && $this->permissionResolver->isAdmin(caller: $caller) === false) {
                $this->logger->warning(
                    'OpenBuild ApplicationVersionOwnerGuard: user-scoped transition denied — '
                    .'parent app does not allow user overrides',
                    ['action' => $action, 'uid' => $userId]
                );
                return GuardResult::deny(
                    'Deze app staat geen persoonlijke aanpassingen (meer) toe; transitie geweigerd.'
                );
            }

            return GuardResult::allow();
        }//end if

        $application = $this->loadParentApplication(version: $object);
        if ($application === null) {
            $this->logger->warning(
                'OpenBuild ApplicationVersionOwnerGuard: parent Application unresolved; denying transition',
                ['action' => $action, 'application' => ($object['application'] ?? null)]
            );
            return GuardResult::deny(
                'De bovenliggende applicatie kon niet worden bepaald; transitie geweigerd.'
            );
        }

        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false || $permissions === []) {
            // No rechtenblok (permissions block). This is the shape of a
            // programmatically / synthetically created Application — e.g. one
            // seeded via `occ`, an import pipeline, or the App Host engine —
            // that never went through the controller path which materialises
            // the owners/editors/viewers role rows. A blanket deny here is the
            // wrong fail-closed posture: it bricks publish/archive/reopen for
            // every such Application, including the documented NC-admin escape
            // hatch (design.md Decision 5), because this branch short-circuits
            // BEFORE the admin bypass inside PermissionResolver::matchesCaller()
            // can run.
            //
            // Security choice: WITHOUT a permissions block no per-Application
            // owner can be proven, so we must NOT widen access to ordinary
            // callers. We therefore grant ONLY the audited NC-admin escape
            // hatch — exactly the principal matchesCaller() would have admitted
            // had the block existed — and continue to deny everyone else. This
            // keeps the guard fail-closed for non-admins (no privilege
            // escalation on orphaned/synthetic Applications) while unblocking
            // the legitimate admin/programmatic publish path.
            if ($this->permissionResolver->isAdmin(caller: $caller) === true) {
                return GuardResult::allow();
            }

            $this->logger->warning(
                'OpenBuild ApplicationVersionOwnerGuard: parent Application has no permissions block; '
                .'denying non-admin caller (admin escape hatch only)',
                ['action' => $action, 'slug' => ($application['slug'] ?? null)]
            );
            return GuardResult::deny(
                'De bovenliggende applicatie heeft geen rechtenblok. Alleen een beheerder mag deze versie '
                .$action.'; vraag een beheerder om het rechtenblok van de applicatie in te stellen.'
            );
        }//end if

        // Owner role required. NC admins are granted as the audited
        // incident-response escape hatch (design.md Decision 5); the
        // PermissionResolver writes no audit here — OR's per-object change
        // trail records the transition itself.
        $userGroups = $this->permissionResolver->resolveUserGroups(user: $caller);
        $allowed    = $this->permissionResolver->matchesCaller(
            permissions: $permissions,
            caller: $caller,
            userGroups: $userGroups,
            allowAdminBypass: true,
            roles: self::OWNER_ROLES
        );

        if ($allowed === true) {
            return GuardResult::allow();
        }

        return GuardResult::deny(
            'U mag deze versie niet '.$action.'. Alleen een eigenaar van de bovenliggende '
            .'applicatie (of een beheerder) is gemachtigd voor publiceren, archiveren of heropenen.'
        );
    }//end check()

    /**
     * Resolve the parent Application from the version's `application` relation.
     *
     * The relation stores the Application UUID. Loading it here — rather than
     * trusting any caller-supplied identifier — is what makes the guard
     * IDOR-safe: the role check is always performed against the Application the
     * version actually belongs to.
     *
     * @param array<string, mixed> $version The ApplicationVersion payload.
     *
     * @return array<string, mixed>|null The parent Application data, or null when unresolvable.
     */
    private function loadParentApplication(array $version): ?array
    {
        $applicationUuid = ($version['application'] ?? null);
        if (is_string($applicationUuid) === false || $applicationUuid === '') {
            return null;
        }

        try {
            $entity = $this->objectService->find(
                id: $applicationUuid,
                register: ApplicationVersionService::REGISTER_SLUG,
                schema: ApplicationVersionService::APPLICATION_SCHEMA
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'OpenBuild ApplicationVersionOwnerGuard: failed to load parent Application: '.$e->getMessage(),
                ['application' => $applicationUuid]
            );
            return null;
        }

        if ($entity === null) {
            return null;
        }

        $data = $entity->jsonSerialize();
        if (is_array($data) === false) {
            return null;
        }

        return $data;
    }//end loadParentApplication()
}//end class
