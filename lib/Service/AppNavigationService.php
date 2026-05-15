<?php

/**
 * OpenBuilt App Navigation Service
 *
 * Registers per-app top-bar navigation entries for every published Application
 * in `Application::boot()` via INavigationManager::add().
 *
 * Per ADR-031 §Exceptions this is imperative because nav-entry registration
 * requires a closure factory evaluated per request, `IGroupManager` per-request
 * calls, and `INavigationManager::add()` — none of which are OR calculation
 * vocabulary.
 *
 * Permission check order per REQ-OBNAV-002:
 *   1. group:* sentinel in any role array → visible to all signed-in users.
 *   2. user:<uid> match in any role array.
 *   3. group:<gid> or bare group GID match against the user's group memberships.
 *   4. Nextcloud admin bypass.
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

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Registers dynamic per-published-app top-bar navigation entries.
 *
 * Called once per request from Application::boot(); entries are closures
 * evaluated by INavigationManager on every request boot cycle so draft→published
 * transitions are picked up automatically without any writeback (REQ-OBNAV-004).
 */
class AppNavigationService
{
    /**
     * Register slug that hosts Application objects.
     */
    private const REGISTER_SLUG = 'openbuilt';

    /**
     * Schema slug for Application objects.
     */
    private const APPLICATION_SCHEMA = 'application';

    /**
     * Status value that indicates a published Application.
     */
    private const STATUS_PUBLISHED = 'published';

    /**
     * Group:* sentinel — when present in any role array, the entry is
     * visible to all signed-in users (REQ-OBNAV-003).
     */
    private const WILDCARD = 'group:*';

    /**
     * Cache of published applications fetched this request (per-request).
     *
     * @var array<array<string,mixed>>|null
     */
    private ?array $cachedApplications = null;

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object service
     * @param IURLGenerator   $urlGenerator  URL generator
     * @param IUserSession    $userSession   User session
     * @param IGroupManager   $groupManager  Group manager
     * @param LoggerInterface $logger        PSR logger
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Register one INavigationManager entry per published Application.
     *
     * Each entry carries a gating closure evaluated per request.  Draft and
     * archived Applications are excluded by the `status == published` filter
     * applied here — no writeback needed when status changes (REQ-OBNAV-004).
     *
     * @param INavigationManager $nav The Nextcloud navigation manager.
     *
     * @return void
     */
    public function registerNavEntries(INavigationManager $nav): void
    {
        try {
            $applications = $this->getPublishedApplications();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AppNavigationService: failed to query published applications: '.$e->getMessage()
            );
            return;
        }

        foreach ($applications as $application) {
            $slug = ($application['slug'] ?? null);
            $name = ($application['name'] ?? null);

            if (is_string($slug) === false || $slug === '') {
                continue;
            }

            if (is_string($name) === false || $name === '') {
                $name = $slug;
            }

            $permissions = ($application['permissions'] ?? []);
            if (is_array($permissions) === false) {
                $permissions = [];
            }

            $iconUrl = $this->urlGenerator->linkToRouteAbsolute(
                'openbuilt.icon.iconLight',
                ['slug' => $slug]
            );

            $appUrl  = '/apps/openbuilt/'.$slug;
            $entryId = 'openbuilt-app-'.$slug;
            $order   = 1000 + (abs(crc32($slug)) % 1000);

            // Capture variables for the closure — PHP closures close over
            // variables by reference unless 'use' explicitly binds them by value.
            $capturedPermissions = $permissions;
            $userSession         = $this->userSession;
            $groupManager        = $this->groupManager;

            $nav->add(
                    function () use (
                        $entryId,
                        $name,
                        $appUrl,
                        $iconUrl,
                        $order,
                        $capturedPermissions,
                        $userSession,
                        $groupManager
                    ): array {
                        $visible = $this->isVisibleForCurrentUser(
                        permissions: $capturedPermissions,
                        userSession: $userSession,
                        groupManager: $groupManager
                        );

                        return [
                            'id'      => $entryId,
                            'name'    => $name,
                            'href'    => $appUrl,
                            'icon'    => $iconUrl,
                            'order'   => $order,
                            'type'    => 'link',
                            'active'  => false,
                            'classes' => '',
                            'enabled' => $visible,
                        ];
                    }
                    );
        }//end foreach
    }//end registerNavEntries()

    /**
     * Determine whether the currently-signed-in user should see a nav entry.
     *
     * Evaluation order per REQ-OBNAV-002:
     *   1. group:* wildcard in any role → always visible.
     *   2. user:<uid> match in any role.
     *   3. group:<gid> / bare GID match against user's group memberships.
     *   4. Nextcloud admin bypass.
     *
     * @param array<string,mixed> $permissions  The Application's permissions block.
     * @param IUserSession        $userSession  The user session.
     * @param IGroupManager       $groupManager The group manager.
     *
     * @return bool True when the entry should be visible.
     */
    public function isVisibleForCurrentUser(
        array $permissions,
        IUserSession $userSession,
        IGroupManager $groupManager,
    ): bool {
        $user = $userSession->getUser();
        if ($user === null) {
            return false;
        }

        $uid = $user->getUID();

        // Flatten all three role arrays into one principal list.
        $owners  = ($permissions['owners'] ?? []);
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

        $allPrincipals = array_merge($owners, $editors, $viewers);

        // 1. Wildcard sentinel — visible to everyone signed in.
        if (in_array(self::WILDCARD, $allPrincipals, strict: true) === true) {
            return true;
        }

        // 2. Direct UID match.
        if (in_array('user:'.$uid, $allPrincipals, strict: true) === true) {
            return true;
        }

        // 3. Group-based match.
        $userGroups = $groupManager->getUserGroupIds(user: $user);

        foreach ($allPrincipals as $principal) {
            if (is_string($principal) === false) {
                continue;
            }

            // Strip "group:" prefix for the normalised comparison.
            if (str_starts_with($principal, 'group:') === true) {
                $gid = substr($principal, strlen('group:'));
            } else {
                $gid = $principal;
            }//end if

            if ($gid === '*') {
                // Already handled by the wildcard sentinel above.
                continue;
            }

            if (in_array($gid, $userGroups, strict: true) === true) {
                return true;
            }
        }//end foreach

        // 4. Nextcloud admin always sees all entries.
        return $groupManager->isAdmin($uid);
    }//end isVisibleForCurrentUser()

    /**
     * Fetch (and cache per-request) all published Applications from OR.
     *
     * @return array<array<string,mixed>> List of normalised Application arrays.
     *
     * @throws \Throwable When the OR query fails.
     */
    private function getPublishedApplications(): array
    {
        if ($this->cachedApplications !== null) {
            return $this->cachedApplications;
        }

        $results = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => self::REGISTER_SLUG,
                    'schema'   => self::APPLICATION_SCHEMA,
                    'status'   => self::STATUS_PUBLISHED,
                ],
                'limit'   => 1000,
            ]
        );

        $applications = [];
        foreach ($results as $item) {
            $applications[] = $this->normaliseObject(object: $item);
        }

        $this->cachedApplications = $applications;
        return $applications;
    }//end getPublishedApplications()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to an associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string,mixed>
     */
    private function normaliseObject(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $inner = $object->getObject();
            if (is_array($inner) === true) {
                return $inner;
            }
        }

        return [];
    }//end normaliseObject()
}//end class
