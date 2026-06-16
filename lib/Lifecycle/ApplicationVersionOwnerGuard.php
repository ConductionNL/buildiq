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
 * Fails closed: any inability to resolve the caller, the parent Application,
 * or its `permissions.owners` denies the transition.
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
            $this->logger->warning(
                'OpenBuild ApplicationVersionOwnerGuard: parent Application has no permissions block; denying',
                ['action' => $action, 'slug' => ($application['slug'] ?? null)]
            );
            return GuardResult::deny(
                'De bovenliggende applicatie heeft geen rechtenblok; transitie geweigerd.'
            );
        }

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
