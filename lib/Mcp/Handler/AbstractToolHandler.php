<?php

/**
 * Abstract base class for OpenBuilt MCP tool handlers.
 *
 * Provides the shared utilities (slug validation, auth check, error envelope,
 * toArray/extractUuid coercion, deep-link builder, per-Application RBAC) that
 * every concrete handler needs, eliminating duplication across the handler family.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Mcp\Handler
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Mcp\Handler;

use OCA\OpenBuilt\Service\PermissionResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Abstract base for all OpenBuilt MCP tool handler classes.
 *
 * Concrete handlers extend this and implement handle(array $args): array.
 */
abstract class AbstractToolHandler
{

    protected const REGISTER_SLUG = 'openbuilt';

    /**
     * Roles that grant write access to an Application.
     *
     * @var array<int, string>
     */
    private const WRITE_ROLES = ['owners', 'editors'];

    /**
     * Constructor.
     *
     * @param IUserSession       $userSession        User session used to resolve the current authenticated user.
     * @param ContainerInterface $container          DI container used to resolve OpenRegister services lazily.
     * @param LoggerInterface    $logger             PSR logger used for non-fatal warnings and error logging.
     * @param IGroupManager      $groupManager       Group manager used for admin and group membership checks.
     * @param PermissionResolver $permissionResolver Shared permission-grammar resolver (H1 fix).
     */
    public function __construct(
        protected readonly IUserSession $userSession,
        protected readonly ContainerInterface $container,
        protected readonly LoggerInterface $logger,
        protected readonly IGroupManager $groupManager,
        protected readonly ?PermissionResolver $permissionResolver=null,
    ) {
    }//end __construct()

    /**
     * Execute the tool logic for the given arguments.
     *
     * @param array<string, mixed> $args Raw MCP tool arguments.
     *
     * @return array<string, mixed>
     */
    abstract public function handle(array $args): array;

    /**
     * Build a uniform MCP error envelope.
     *
     * @param string $error   Machine-readable error code.
     * @param string $message Human-readable, end-user-safe error message.
     *
     * @return array{isError: true, error: string, message: string}
     */
    protected function errorResult(string $error, string $message): array
    {
        return ['isError' => true, 'error' => $error, 'message' => $message];

    }//end errorResult()

    /**
     * Return the authenticated user's UID, or null if there is no session user.
     *
     * @return string|null
     */
    protected function requireAuthenticatedUser(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        $uid = $user->getUID();
        if ($uid === '') {
            return null;
        }

        return $uid;

    }//end requireAuthenticatedUser()

    /**
     * Check whether the current user is an NC admin.
     *
     * Returns a forbidden error envelope when the user is not signed in or is not
     * an admin. Returns null on success.
     *
     * @return array{isError: true, error: string, message: string}|null Null on allow.
     */
    protected function requireAdminUser(): ?array
    {
        $uid = $this->requireAuthenticatedUser();
        if ($uid === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in.');
        }

        if ($this->groupManager->isAdmin($uid) === false) {
            return $this->errorResult(
                error: 'forbidden',
                message: 'This operation requires Nextcloud admin privileges.'
            );
        }

        return null;

    }//end requireAdminUser()

    /**
     * Verify the current user holds an owners or editors role on the Application
     * identified by $appSlug.
     *
     * When $allowAdminBypass is true (the default) NC admins pass without needing
     * an explicit role entry. Set it to false for promotion (spec REQ-OBVP-007).
     *
     * Returns a forbidden/not_found error envelope on denial, null on allow.
     *
     * @param string $appSlug          Slug of the target Application.
     * @param bool   $allowAdminBypass Whether NC admin group membership grants access.
     *
     * @return array{isError: true, error: string, message: string}|null Null on allow.
     */
    protected function requireWriteRole(string $appSlug, bool $allowAdminBypass=true): ?array
    {
        $uid = $this->requireAuthenticatedUser();
        if ($uid === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in.');
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $apps          = $objectService->searchObjectsBySlug(self::REGISTER_SLUG, 'application', ['slug' => $appSlug]);
        if (is_array($apps) === false || $apps === []) {
            return $this->errorResult(error: 'not_found', message: "No virtual app found for slug '{$appSlug}'.");
        }

        $app = $this->toArray(item: $apps[0]);

        if ($this->callerHasWriteRole(app: $app, uid: $uid, allowAdminBypass: $allowAdminBypass) === true) {
            if ($allowAdminBypass === true && $this->groupManager->isAdmin($uid) === true) {
                $this->logger->info(
                    'OpenBuilt MCP: rbac.admin_bypass',
                    ['actor' => $uid, 'appSlug' => $appSlug]
                );
            }

            return null;
        }

        return $this->errorResult(
            error: 'forbidden',
            message: "You do not have owner or editor access to application '{$appSlug}'."
        );

    }//end requireWriteRole()

    /**
     * Check whether the session caller holds any WRITE_ROLES entry on the Application.
     *
     * Delegates to PermissionResolver when available (H1 fix — unified grammar);
     * falls back to the inline implementation for backward-compatibility in tests
     * that construct handlers without the resolver.
     *
     * @param array<string, mixed> $app              Application data.
     * @param string               $uid              Caller's user ID.
     * @param bool                 $allowAdminBypass Whether NC admin bypass applies.
     *
     * @return bool
     */
    private function callerHasWriteRole(array $app, string $uid, bool $allowAdminBypass=true): bool
    {
        $caller = $this->userSession->getUser();
        if ($caller === null) {
            return false;
        }

        $permissions = ($app['permissions'] ?? []);
        if (is_array($permissions) === false) {
            return false;
        }

        if ($this->permissionResolver !== null) {
            $userGroups = $this->permissionResolver->resolveUserGroups($caller);
            return $this->permissionResolver->matchesCaller(
                permissions: $permissions,
                caller: $caller,
                userGroups: $userGroups,
                allowAdminBypass: $allowAdminBypass,
                roles: self::WRITE_ROLES
            );
        }

        // Inline fallback (no resolver injected — used by tests that pre-date
        // the resolver injection).
        $userSet  = [];
        $groupSet = [];

        foreach (self::WRITE_ROLES as $role) {
            $bucket = ($permissions[$role] ?? []);
            if (is_array($bucket) === false) {
                continue;
            }

            foreach ($bucket as $principal) {
                if (is_string($principal) === false || $principal === '') {
                    continue;
                }

                if (str_starts_with($principal, 'user:') === true) {
                    $pUid = substr($principal, 5);
                    if ($pUid !== '') {
                        $userSet[$pUid] = true;
                    }

                    continue;
                }

                $gid = $principal;
                if (str_starts_with($principal, 'group:') === true) {
                    $gid = substr($principal, 6);
                }

                if ($gid !== '') {
                    $groupSet[$gid] = true;
                }
            }//end foreach
        }//end foreach

        if (isset($userSet[$uid]) === true) {
            return true;
        }

        if ($allowAdminBypass === true && $this->groupManager->isAdmin($uid) === true) {
            return true;
        }

        $userGroups = $this->groupManager->getUserGroups($caller);
        foreach ($userGroups as $group) {
            if ($group instanceof IUser === false && isset($groupSet[$group->getGID()]) === true) {
                return true;
            }
        }

        return false;

    }//end callerHasWriteRole()

    /**
     * Validate that a candidate string matches the OpenBuilt slug shape.
     *
     * @param string $candidate Candidate slug to validate.
     *
     * @return bool
     */
    protected function isValidSlug(string $candidate): bool
    {
        if (strlen($candidate) < 2 || strlen($candidate) > 48) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $candidate);

    }//end isValidSlug()

    /**
     * Build a Nextcloud deep link into the OpenBuilt builder for the given application slug.
     *
     * @param string $slug Application slug (empty falls back to the app root).
     *
     * @return string
     */
    protected function buildDeepLink(string $slug): string
    {
        if ($slug === '') {
            return '/apps/openbuilt';
        }

        return "/apps/openbuilt/builder/{$slug}";

    }//end buildDeepLink()

    /**
     * Build an MCP "source" descriptor pointing at the OpenBuilt app deep link.
     *
     * @param string $uuid  Application UUID.
     * @param string $slug  Application slug used to build the deep link.
     * @param string $label Human-readable label for the source descriptor.
     *
     * @return array{type: string, uuid: string, url: string, label: string}
     */
    protected function sourceDescriptor(string $uuid, string $slug, string $label): array
    {
        return ['type' => 'openbuilt.application', 'uuid' => $uuid, 'url' => $this->buildDeepLink(slug: $slug), 'label' => $label];

    }//end sourceDescriptor()

    /**
     * Coerce an OR entity, array, or generic value into an associative array.
     *
     * @param mixed $item Value to coerce.
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $item): array
    {
        if (is_array($item) === true) {
            return $item;
        }

        if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
            $serialised = $item->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return (array) $item;

    }//end toArray()

    /**
     * Extract a UUID from a normalised OR object array.
     *
     * @param array<string, mixed> $item Normalised OR object as an associative array.
     *
     * @return string
     */
    protected function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? $item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? ''));
        return (string) $uuid;

    }//end extractUuid()

    /**
     * Resolve <appSlug, versionSlug> to {version, appUuid, appName}, or {error, message}.
     *
     * @param object $objectService OpenRegister ObjectService instance.
     * @param string $appSlug       Application slug to resolve.
     * @param string $versionSlug   ApplicationVersion slug to resolve.
     *
     * @return array{version?: array, appUuid?: string, appName?: string, error?: string, message?: string}
     */
    protected function loadVersion(object $objectService, string $appSlug, string $versionSlug): array
    {
        $apps = $objectService->searchObjectsBySlug(self::REGISTER_SLUG, 'application', ['slug' => $appSlug]);
        if (is_array($apps) === false || $apps === []) {
            return ['error' => 'not_found', 'message' => "No virtual app found for slug '{$appSlug}'."];
        }

        $app     = $this->toArray(item: $apps[0]);
        $appUuid = $this->extractUuid(item: $app);

        $versions = $objectService->searchObjectsBySlug(
            self::REGISTER_SLUG,
            'applicationVersion',
            ['application' => $appUuid, 'slug' => $versionSlug]
        );
        if (is_array($versions) === false || $versions === []) {
            return ['error' => 'not_found', 'message' => "No version '{$versionSlug}' found for app '{$appSlug}'."];
        }

        return [
            'version' => $this->toArray(item: $versions[0]),
            'appUuid' => $appUuid,
            'appName' => (string) ($app['name'] ?? $appSlug),
        ];

    }//end loadVersion()

    /**
     * Save an ApplicationVersion with a new manifest, protected by an OR
     * object lock to serialise concurrent manifest mutations (issue #159).
     *
     * The lock is acquired before the read-modify-write cycle. If OR returns
     * a contention error (another agent holds the lock), a
     * RuntimeException with code 409 is thrown so callers can return an
     * `isError` envelope without retrying blindly.
     *
     * H3: The `method_exists` guard that previously silently skipped locking
     * when ObjectService lacked `lockObject` has been removed. OR has shipped
     * `lockObject` since the concurrency-fix release; failing loudly (503) is
     * safer than silently allowing last-writer-wins data loss.
     *
     * @param ObjectService        $objectService OpenRegister ObjectService instance.
     * @param array<string, mixed> $version       The existing ApplicationVersion as an associative array.
     * @param array<string, mixed> $manifest      The new manifest blob to write onto the version.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException (code 409) when the version is locked by another writer.
     * @throws \RuntimeException (code 503) when the ObjectService does not provide lockObject.
     */
    protected function saveVersionManifest(ObjectService $objectService, array $version, array $manifest): array
    {
        $versionUuid = $this->extractUuid(item: $version);

        // Acquire an OR optimistic lock before the write to prevent last-writer-
        // wins data loss when two concurrent MCP agents mutate the same version.
        // H3: guard removed — fail loudly (503) rather than silently skip locking.
        $locked = false;
        try {
            $objectService->lockObject(
                identifier: $versionUuid,
                process: 'openbuilt.mcp-manifest-edit',
                duration: 30
            );
            $locked = true;
        } catch (\Throwable $lockError) {
            $this->logger->warning(
                'OpenBuilt MCP: manifest lock contention on version '.$versionUuid,
                ['exception' => $lockError->getMessage()]
            );
            throw new \RuntimeException(
                'Version '.$versionUuid.' is currently locked by another writer. Retry after a moment.',
                409,
                $lockError
            );
        }

        try {
            $payload = $version;
            $payload['manifest'] = $manifest;

            // Drop OR-internal `@self` / metadata keys that some readers tack on so
            // saveObject treats the input as a clean property bag.
            unset($payload['@self'], $payload['id'], $payload['uuid']);

            $saved = $objectService->saveObject(
                object: $payload,
                register: self::REGISTER_SLUG,
                schema: 'applicationVersion',
                uuid: $versionUuid,
            );

            return $this->toArray(item: $saved);
        } finally {
            if ($locked === true) {
                try {
                    $objectService->unlockObject(identifier: $versionUuid);
                } catch (\Throwable $unlockError) {
                    $this->logger->warning(
                        'OpenBuilt MCP: failed to release manifest lock on '.$versionUuid,
                        ['exception' => $unlockError->getMessage()]
                    );
                }
            }
        }//end try

    }//end saveVersionManifest()
}//end class
