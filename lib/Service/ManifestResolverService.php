<?php

/**
 * OpenBuilt ManifestResolverService
 *
 * Encapsulates the two-step slug resolution (Application by slug →
 * ApplicationVersion by application + slug) and the RBAC gate for
 * version-aware manifest endpoint access (spec `openbuilt-version-routing`
 * REQ-OBVR-002 / REQ-OBVR-003).
 *
 * Design decisions:
 *   - Default (no versionSlug): returns productionVersion's manifest —
 *     accessible to every authenticated caller, no RBAC check (Decision 2).
 *   - Non-production version: caller MUST appear in permissions.owners or
 *     permissions.editors on the Application. NC admins are NOT auto-granted
 *     (Decision 7 + REQ-OBVR-003).
 *   - Unknown slug OR unauthorised caller: both return null with the same
 *     404-mapped null to prevent version-slug enumeration (Decision 8).
 *
 * Per ADR-031 §Exceptions: two-step cross-object lookup + RBAC branch +
 * security-shaped 404-for-auth are all imperative — see design.md
 * Declarative-vs-Imperative table.
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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves application slug + optional version slug to a manifest payload,
 * enforcing RBAC for non-production versions.
 */
class ManifestResolverService
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService  OpenRegister object service (ADR-022)
     * @param RegisterMapper  $registerMapper Register slug-to-ID resolver
     * @param SchemaMapper    $schemaMapper   Schema slug-to-ID resolver
     * @param LoggerInterface $logger         PSR logger for diagnostics
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve an application slug (+ optional version slug) to the manifest payload.
     *
     * Step 1  : look up the Application by slug via ObjectService.
     * Step 2a : (no versionSlug) return productionVersion's manifest — no RBAC check.
     * Step 2b : (with versionSlug) look up the ApplicationVersion whose `application`
     *           relation matches + slug matches. Return null if not found.
     * Step 3  : RBAC gate for non-production versions. Production version skips gate.
     *           Unknown or unauthorised both return null (same response — no existence leak).
     * Step 4  : return the resolved ApplicationVersion's `manifest` payload.
     *
     * NOTE on `_version` parameter name: the underscore-prefix form (`_version`) is
     * OpenBuilt's system-reserved namespace marker for query parameters. This prevents
     * collision with user-defined `?version=` params that citizen developers may add
     * to their virtual apps' routes. Callers of this service pass the string value
     * of `_version`; the underscore prefix is stripped at the HTTP layer.
     *
     * @param string      $appSlug     The virtual-app slug from the URL.
     * @param string|null $versionSlug Optional version slug (`?_version=<value>` from the request).
     * @param IUser|null  $caller      The authenticated user, or null for unauthenticated.
     *
     * @return array<string, mixed>|null The manifest payload, or null → caller maps to 404.
     */
    public function resolve(string $appSlug, ?string $versionSlug, ?IUser $caller): ?array
    {
        // Step 1 — resolve Application by slug.
        $application = $this->findApplicationBySlug(appSlug: $appSlug);
        if ($application === null) {
            $this->logger->debug(
                'ManifestResolverService: Application not found for slug={slug}',
                ['slug' => $appSlug]
            );
            return null;
        }

        // Step 2a — no versionSlug: return production manifest (accessible to all).
        if ($versionSlug === null) {
            return $this->resolveProductionManifest(application: $application, appSlug: $appSlug);
        }

        if ($versionSlug === '') {
            return $this->resolveProductionManifest(application: $application, appSlug: $appSlug);
        }

        // Step 2b — named versionSlug: look up the ApplicationVersion.
        $version = $this->findVersionBySlug(application: $application, versionSlug: $versionSlug);
        if ($version === null) {
            // No existence leak — return null (caller maps to 404).
            return null;
        }

        // Step 3 — RBAC gate for non-production access (delegates to checkNonProductionAccess).
        $denied = $this->checkNonProductionAccess(
            application: $application,
            version: $version,
            caller: $caller,
            appSlug: $appSlug,
            versionSlug: $versionSlug
        );
        if ($denied === true) {
            return null;
        }

        // Step 4 — return the resolved ApplicationVersion's manifest payload.
        $manifest = ($version['manifest'] ?? null);
        if (is_array($manifest) === false) {
            $this->logger->warning(
                'ManifestResolverService: ApplicationVersion has no manifest for app={appSlug} version={versionSlug}',
                ['appSlug' => $appSlug, 'versionSlug' => $versionSlug]
            );
            return null;
        }

        return $manifest;
    }//end resolve()

    /**
     * Apply the RBAC gate for non-production version access.
     *
     * Returns true when access is denied (caller maps to 404), false when access
     * is allowed (either it is the production version, or the caller is authorised).
     *
     * NOTE: Nextcloud admins are NOT auto-granted — this is intentional.
     * See design.md Decision 7 / REQ-OBVR-003.
     *
     * @param array<string, mixed> $application The normalised Application data.
     * @param array<string, mixed> $version     The normalised ApplicationVersion data.
     * @param IUser|null           $caller      The authenticated user.
     * @param string               $appSlug     The app slug (for logging).
     * @param string               $versionSlug The version slug (for logging).
     *
     * @return bool True when access is denied; false when access is allowed.
     */
    private function checkNonProductionAccess(
        array $application,
        array $version,
        ?IUser $caller,
        string $appSlug,
        string $versionSlug
    ): bool {
        $prodUuid      = $this->extractProductionVersionUuid(application: $application);
        $resolvedUuid  = (string) ($version['uuid'] ?? $version['id'] ?? '');
        $isProdVersion = $prodUuid !== '' && $resolvedUuid === $prodUuid;

        if ($isProdVersion === true) {
            return false;
        }

        $allowed = $this->isCallerAuthorised(application: $application, caller: $caller);
        if ($allowed === true) {
            return false;
        }

        $callerUid = 'unauthenticated';
        if ($caller !== null) {
            $callerUid = $caller->getUID();
        }

        // Server-side debug log only — MUST NOT be exposed in the HTTP response
        // (security-shaped 404 per Decision 8 / REQ-OBVR-003).
        $this->logger->debug(
            'ManifestResolverService: version_access_denied for caller={callerUid} on app={appSlug} version={versionSlug}',
            [
                'event'       => 'version_access_denied',
                'callerUid'   => $callerUid,
                'appSlug'     => $appSlug,
                'versionSlug' => $versionSlug,
            ]
        );
        return true;
    }//end checkNonProductionAccess()

    /**
     * Look up the Application object by slug via OR's ObjectService.
     *
     * Uses searchObjects with register=openbuilt, schema=application, slug={appSlug}.
     * Returns the first normalised result or null on miss.
     *
     * @param string $appSlug The application slug.
     *
     * @return array<string, mixed>|null
     */
    private function findApplicationBySlug(string $appSlug): ?array
    {
        try {
            $registerId = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
            $schemaId   = $this->schemaMapper->find('application', _multitenancy: false)->getId();

            $results = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'slug'  => $appSlug,
                ]
            );

            if (empty($results) === true) {
                return null;
            }

            return $this->normaliseObject(object: $results[0]);
        } catch (Throwable $e) {
            $this->logger->error(
                'ManifestResolverService: findApplicationBySlug failed for slug={slug}: {message}',
                ['slug' => $appSlug, 'message' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end findApplicationBySlug()

    /**
     * Resolve the production version manifest from Application.productionVersion.
     *
     * When productionVersion is a UUID string, fetch the ApplicationVersion by UUID.
     * When productionVersion is an inline embedded object, use it directly.
     * Falls back to Application.manifest when no productionVersion is set
     * (backwards-compat for apps created before spec C landed).
     *
     * @param array<string, mixed> $application The normalised Application data.
     * @param string               $appSlug     The app slug (for logging).
     *
     * @return array<string, mixed>|null
     */
    private function resolveProductionManifest(array $application, string $appSlug): ?array
    {
        $productionVersion = ($application['productionVersion'] ?? null);

        // ProductionVersion is a UUID or embedded object.
        if (is_string($productionVersion) === true && $productionVersion !== '') {
            // UUID reference — fetch the ApplicationVersion.
            $version = $this->findVersionByUuid(uuid: $productionVersion);
            if ($version !== null) {
                $manifest = ($version['manifest'] ?? null);
                if (is_array($manifest) === true) {
                    return $manifest;
                }
            }
        } else if (is_array($productionVersion) === true) {
            // Inline embedded object — use manifest directly.
            $manifest = ($productionVersion['manifest'] ?? null);
            if (is_array($manifest) === true) {
                return $manifest;
            }
        }

        // Backwards-compat fallback: application-level manifest field.
        $manifest = ($application['manifest'] ?? null);
        if (is_array($manifest) === true) {
            $this->logger->debug(
                'ManifestResolverService: falling back to Application.manifest for slug={slug} (no productionVersion)',
                ['slug' => $appSlug]
            );
            return $manifest;
        }

        $this->logger->warning(
            'ManifestResolverService: no manifest found for application slug={slug}',
            ['slug' => $appSlug]
        );
        return null;
    }//end resolveProductionManifest()

    /**
     * Look up an ApplicationVersion whose application relation matches the given
     * Application AND whose slug matches versionSlug.
     *
     * Two-step lookup: uses the Application's UUID to filter versions by the
     * `application` relation + the `slug` field.
     *
     * @param array<string, mixed> $application The normalised Application data.
     * @param string               $versionSlug The requested version slug.
     *
     * @return array<string, mixed>|null
     */
    private function findVersionBySlug(array $application, string $versionSlug): ?array
    {
        try {
            $applicationUuid = (string) ($application['uuid'] ?? $application['id'] ?? '');
            if ($applicationUuid === '') {
                return null;
            }

            $registerId = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
            $schemaId   = $this->schemaMapper->find('application-version', _multitenancy: false)->getId();

            // Two-step: filter ApplicationVersions by parent application UUID + slug.
            // OR compound-filter note: OR's searchObjects supports direct property
            // equality. For the application relation we filter by UUID equality on
            // the `application` field. For installations where OR stores the relation
            // as a nested object, we fall back to PHP-side filtering after fetchAll.
            $results = $this->objectService->searchObjects(
                query: [
                    '@self'       => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'slug'        => $versionSlug,
                    'application' => $applicationUuid,
                ]
            );

            if (empty($results) === false) {
                $version = $this->normaliseObject(object: $results[0]);
                // Verify application relation ownership (IDOR guard).
                if ($this->versionBelongsToApplication(version: $version, applicationUuid: $applicationUuid) === true) {
                    return $version;
                }
            }

            // Fallback: fetch all versions for this app and match slug in PHP.
            // Required when OR's compound-filter on a relation UUID is not yet
            // stable on this installation (apply-notes workaround).
            return $this->findVersionBySlugFallback(applicationUuid: $applicationUuid, versionSlug: $versionSlug);
        } catch (Throwable $e) {
            $this->logger->error(
                'ManifestResolverService: findVersionBySlug failed: {message}',
                ['message' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end findVersionBySlug()

    /**
     * PHP-side fallback for version-by-slug lookup when OR compound filters
     * on a relation UUID are not reliable on this installation.
     *
     * Fetches all ApplicationVersions for the given application UUID and
     * returns the first one whose `slug` matches.
     *
     * @param string $applicationUuid Parent Application UUID.
     * @param string $versionSlug     The requested version slug.
     *
     * @return array<string, mixed>|null
     */
    private function findVersionBySlugFallback(string $applicationUuid, string $versionSlug): ?array
    {
        try {
            $registerId = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
            $schemaId   = $this->schemaMapper->find('application-version', _multitenancy: false)->getId();

            $allVersions = $this->objectService->searchObjects(
                query: [
                    '@self'       => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'application' => $applicationUuid,
                ]
            );

            foreach ($allVersions as $entry) {
                $version = $this->normaliseObject(object: $entry);
                if (($version['slug'] ?? '') === $versionSlug
                    && $this->versionBelongsToApplication(version: $version, applicationUuid: $applicationUuid) === true
                ) {
                    return $version;
                }
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->error(
                'ManifestResolverService: findVersionBySlugFallback failed: {message}',
                ['message' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end findVersionBySlugFallback()

    /**
     * Fetch an ApplicationVersion by its UUID.
     *
     * @param string $uuid The ApplicationVersion UUID.
     *
     * @return array<string, mixed>|null
     */
    private function findVersionByUuid(string $uuid): ?array
    {
        try {
            $version = $this->objectService->find(
                id: $uuid,
                register: 'openbuilt',
                schema: 'application-version'
            );

            if ($version === null) {
                return null;
            }

            return $this->normaliseObject(object: $version);
        } catch (Throwable $e) {
            $this->logger->debug(
                'ManifestResolverService: findVersionByUuid failed for uuid={uuid}: {message}',
                ['uuid' => $uuid, 'message' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end findVersionByUuid()

    /**
     * Verify that an ApplicationVersion's `application` relation points back at
     * the expected Application UUID (IDOR guard).
     *
     * @param array<string, mixed> $version         The normalised ApplicationVersion data.
     * @param string               $applicationUuid The expected parent Application UUID.
     *
     * @return bool
     */
    private function versionBelongsToApplication(array $version, string $applicationUuid): bool
    {
        $appRelation = ($version['application'] ?? null);

        if (is_string($appRelation) === true) {
            return $appRelation === $applicationUuid;
        }

        if (is_array($appRelation) === true) {
            $relUuid = (string) ($appRelation['uuid'] ?? $appRelation['id'] ?? '');
            return $relUuid === $applicationUuid;
        }

        return false;
    }//end versionBelongsToApplication()

    /**
     * Extract the productionVersion UUID from an Application record.
     *
     * Handles both string UUID and inline embedded object shapes.
     *
     * @param array<string, mixed> $application The normalised Application data.
     *
     * @return string The UUID, or empty string if not determinable.
     */
    private function extractProductionVersionUuid(array $application): string
    {
        $productionVersion = ($application['productionVersion'] ?? null);

        if (is_string($productionVersion) === true) {
            return $productionVersion;
        }

        if (is_array($productionVersion) === true) {
            return (string) ($productionVersion['uuid'] ?? $productionVersion['id'] ?? '');
        }

        return '';
    }//end extractProductionVersionUuid()

    /**
     * Check whether the caller is listed in permissions.owners or permissions.editors
     * on the Application.
     *
     * NOTE: Nextcloud admins are NOT auto-granted (Decision 7 / REQ-OBVR-003).
     * The IGroupManager::isAdmin() check deliberately does NOT exist in this method.
     *
     * @param array<string, mixed> $application The normalised Application data.
     * @param IUser|null           $caller      The authenticated user.
     *
     * @return bool True when the caller is an editor or owner; false otherwise.
     */
    private function isCallerAuthorised(array $application, ?IUser $caller): bool
    {
        if ($caller === null) {
            return false;
        }

        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            return false;
        }

        $callerUid = $caller->getUID();

        foreach (['owners', 'editors'] as $role) {
            $bucket = ($permissions[$role] ?? []);
            if (is_array($bucket) === false) {
                continue;
            }

            if ($this->bucketContainsUid(bucket: $bucket, callerUid: $callerUid) === true) {
                return true;
            }
        }//end foreach

        return false;
    }//end isCallerAuthorised()

    /**
     * Check whether a permissions bucket (owners or editors) contains the caller UID.
     *
     * Only the `user:<uid>` prefix grants individual-user access (canonical form per
     * REQ-OBRBAC-002). Back-compat: unqualified values are group GIDs, not user UIDs —
     * they are intentionally NOT matched here (this method is for UID checks only).
     *
     * @param array<int, mixed> $bucket    The bucket array from permissions.owners or permissions.editors.
     * @param string            $callerUid The calling user's UID.
     *
     * @return bool True when the caller UID is found in the bucket.
     */
    private function bucketContainsUid(array $bucket, string $callerUid): bool
    {
        foreach ($bucket as $principal) {
            if (is_string($principal) === false || $principal === '') {
                continue;
            }

            // Support `user:<uid>` prefix (canonical form per REQ-OBRBAC-002).
            if (str_starts_with($principal, 'user:') === true) {
                $uid = substr($principal, 5);
                if ($uid === $callerUid) {
                    return true;
                }
            }
        }//end foreach

        return false;
    }//end bucketContainsUid()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string, mixed>
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
