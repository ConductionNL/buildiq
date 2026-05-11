<?php

/**
 * OpenBuilt Applications Controller
 *
 * Serves the per-virtual-app manifest endpoint plus the RBAC-filtered list
 * endpoint used by the editor (REQ-OBRBAC-002 / REQ-OBR-007). Per design.md
 * Decision 6 only the manifest endpoint exists; this controller adds a
 * minimal `listMine` action because OR's schema-level read rule is a
 * coarse group-ACL (not a row-level filter on the Application's
 * `permissions` block) so the list MUST be filtered server-side here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuilt\Controller
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

namespace OCA\OpenBuilt\Controller;

use OCA\OpenBuilt\AppInfo\Application;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the OpenBuilt manifest + list endpoints.
 */
class ApplicationsController extends Controller
{
    /**
     * Nextcloud admin group identifier used as the bypass anchor and the
     * fallback owner per design.md Decision 5 of openbuilt-rbac.
     */
    private const ADMIN_GROUP = 'admin';

    /**
     * Audit-event identifier emitted to the OR audit trail when an admin
     * bypasses the per-Application permissions check (REQ-OBRBAC-006).
     */
    private const EVENT_ADMIN_BYPASS = 'rbac.admin_bypass';

    /**
     * Constructor.
     *
     * @param IRequest              $request          The current HTTP request
     * @param LoggerInterface       $logger           PSR logger for diagnostics
     * @param ObjectService         $objectService    OpenRegister object service (hard dep via info.xml)
     * @param RegisterMapper        $registerMapper   Resolves slugs/UUIDs to numeric register IDs
     * @param SchemaMapper          $schemaMapper     Resolves slugs/UUIDs to numeric schema IDs
     * @param IUserSession          $userSession      Current Nextcloud user session
     * @param IGroupManager         $groupManager     Group membership resolver
     * @param AuditTrailMapper|null $auditTrailMapper Optional OR audit-trail writer (null until OR loaded)
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ?AuditTrailMapper $auditTrailMapper = null,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the stored manifest JSON blob for a given virtual-app slug.
     *
     * Lookup path: slug → BuiltAppRoute → applicationUuid → Application →
     * manifest. The manifest is returned UNWRAPPED (no OR envelope) so
     * useAppManifest in @conduction/nextcloud-vue consumes it directly.
     *
     * Visibility model
     * ----------------
     * Manifests are publicly readable to every authenticated user in the
     * org. `#[NoAdminRequired]` is intentional: the hello-world seed app
     * (and any future "always-on" virtual app) is publicly mountable as
     * soon as a route exists. The manifest body contains the structural
     * description of the UI (routes, widgets, endpoints) but no row-level
     * data — that is fetched separately through OpenRegister and goes
     * through OR's own authorisation layer.
     *
     * Future role-scoped manifests (admin-only apps, group-restricted
     * apps) must NOT be implemented by hardening this endpoint. The
     * canonical extension point is the BuiltAppRoute schema itself: add
     * a `restrictToGroup` (or similar) property and filter the route
     * lookup above on the current user's groups. That keeps the visibility
     * model declarative and avoids scattering per-endpoint ACL logic.
     * Tracked for the RBAC spec (PR #6 / feature/spec-openbuilt-rbac).
     *
     * @param string $slug The virtual-app slug from the URL
     *
     * @return JSONResponse The manifest blob, or a 404 envelope when not found
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getManifest(string $slug): JSONResponse
    {
        try {
            // Resolve register + schema slugs to numeric IDs. OR's searchObjects
            // expects numeric IDs in @self; the slug-resolution shortcut isn't
            // applied at this layer (verified during smoke-test 2026-05-11).
            // _multitenancy=false bypasses the org filter on the LOOKUP only —
            // object-level multitenancy is still enforced via searchObjects below.
            $registerId  = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
            $routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();

            // Step 1 — resolve slug → applicationUuid via the BuiltAppRoute index.
            // Per OR's ObjectService::searchObjects: query shape is
            // { '@self': { register, schema, ... }, <field>: <value>, ... }
            // where @self holds metadata filters and direct keys filter JSON-payload fields.
            $routeResults = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $routeSchema,
                    ],
                    'slug'  => $slug,
                ]
            );

            if (empty($routeResults) === true) {
                $this->logger->debug('OpenBuilt: no BuiltAppRoute found for slug='.$slug);
                return new JSONResponse(
                    data: ['error' => 'not_found', 'message' => 'No published virtual app found for slug '.$slug],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // FindAll renders entities; result entries may be ObjectEntity or arrays.
            $route           = $this->normaliseObject(object: $routeResults[0]);
            $applicationUuid = ($route['applicationUuid'] ?? null);

            if ($applicationUuid === null) {
                $this->logger->warning('OpenBuilt: BuiltAppRoute for slug '.$slug.' is missing applicationUuid');
                return new JSONResponse(
                    data: ['error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid'],
                    statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            // Step 2 — load the Application object.
            $application = $this->objectService->find(
                id: $applicationUuid,
                register: 'openbuilt',
                schema: 'application'
            );

            if ($application === null) {
                $this->logger->warning('OpenBuilt: Application '.$applicationUuid.' (for slug '.$slug.') not found');
                return new JSONResponse(
                    data: ['error' => 'inconsistent_state', 'message' => 'Route points to an Application that does not exist'],
                    statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            $applicationArray = $this->normaliseObject(object: $application);

            // RBAC enforcement per REQ-OBRBAC-002 / REQ-OBR-006 — deny-by-default
            // before any branch that would emit the manifest payload (ADR-005,
            // ADR-022 §Exceptions(1)). Returns 403 with a fixed error envelope
            // when the caller has no role intersection and is not exercising
            // the audited admin bypass declared in REQ-OBRBAC-006.
            $denial = $this->requirePermission(
                application: $application instanceof ObjectEntity ? $application : null,
                applicationArray: $applicationArray,
                slug: $slug
            );
            if ($denial !== null) {
                return $denial;
            }

            $manifest = ($applicationArray['manifest'] ?? null);

            if ($manifest === null) {
                $this->logger->warning('OpenBuilt: Application '.$applicationUuid.' has no manifest property');
                return new JSONResponse(
                    data: ['error' => 'no_manifest', 'message' => 'Application has no manifest'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // Return the manifest UNWRAPPED — useAppManifest expects the bare object.
            return new JSONResponse(data: $manifest, statusCode: Http::STATUS_OK);
        } catch (\Throwable $e) {
            // Generate a correlation ID so the client and server logs share an
            // identifier — operators can grep `correlationId=<id>` in app.log
            // without needing the request timestamp. Per MWest review on PR #2.
            $correlationId = bin2hex(random_bytes(8));
            $this->logger->error(
                'OpenBuilt: getManifest failed for slug '.$slug.': '.$e->getMessage(),
                ['exception' => $e, 'correlationId' => $correlationId, 'slug' => $slug]
            );
            return new JSONResponse(
                data: [
                    'error'         => 'internal_error',
                    'message'       => 'Failed to resolve manifest',
                    'correlationId' => $correlationId,
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end getManifest()

    /**
     * Return two manifest blobs side-by-side so the client diff component
     * can render without a second round-trip (REQ-OBV-005, chain spec #6).
     *
     * Resolves `{slug}` to an Application via the BuiltAppRoute index,
     * accepts the literal string `draft` for either `from`/`to` to mean
     * "the current draft manifest on the Application", otherwise looks
     * up both referenced ApplicationVersion rows. Returns a shape of
     * `{ from: { manifest, version, publishedAt }, to: { manifest,
     * version, publishedAt } }`. Per ADR-032 this is thin glue
     * (~30 LOC of logic); no service class.
     *
     * @param string $slug The virtual-app slug from the URL
     * @param string $from ApplicationVersion UUID or the literal `draft`
     * @param string $to   ApplicationVersion UUID or the literal `draft`
     *
     * @return JSONResponse Both blobs on 200, or a 404 envelope on miss
     *
     * IDOR-safe: slug → BuiltAppRoute lookup enforces org scope via OR's
     * standard multitenancy (RegisterMapper::find + ObjectService::searchObjects),
     * and the resolveVersionBlob() check on `applicationUuid` rejects snapshots
     * that do not belong to this Application. Mirrors getManifest()'s pattern.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function diffVersions(string $slug, string $from, string $to): JSONResponse
    {
        try {
            $registerId  = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
            $routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();

            $routeResults = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $routeSchema,
                    ],
                    'slug'  => $slug,
                ]
            );

            if (empty($routeResults) === true) {
                return new JSONResponse(
                    data: ['error' => 'not_found', 'message' => 'No published virtual app found for slug '.$slug],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $route           = $this->normaliseObject(object: $routeResults[0]);
            $applicationUuid = ($route['applicationUuid'] ?? null);

            if ($applicationUuid === null) {
                return new JSONResponse(
                    data: ['error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid'],
                    statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            $application = $this->objectService->find(
                id: $applicationUuid,
                register: 'openbuilt',
                schema: 'application'
            );

            if ($application === null) {
                return new JSONResponse(
                    data: ['error' => 'not_found', 'message' => 'Application not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $applicationArray = $this->normaliseObject(object: $application);

            $fromBlob = $this->resolveVersionBlob(token: $from, application: $applicationArray, applicationUuid: $applicationUuid);
            if ($fromBlob === null) {
                return new JSONResponse(
                    data: ['error' => 'not_found', 'message' => 'from version not found: '.$from],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $toBlob = $this->resolveVersionBlob(token: $to, application: $applicationArray, applicationUuid: $applicationUuid);
            if ($toBlob === null) {
                return new JSONResponse(
                    data: ['error' => 'not_found', 'message' => 'to version not found: '.$to],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            return new JSONResponse(
                data: ['from' => $fromBlob, 'to' => $toBlob],
                statusCode: Http::STATUS_OK
            );
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt: diffVersions failed for slug '.$slug.': '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to resolve diff'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end diffVersions()

    /**
     * Resolve a `from`/`to` token to a `{ manifest, version, publishedAt }` blob.
     *
     * The literal string `draft` returns the Application's current draft
     * fields. Any other value is treated as an ApplicationVersion UUID
     * and looked up via OR's ObjectService. Returns null on miss so the
     * caller can surface 404.
     *
     * @param string               $token           Token (`draft` or UUID).
     * @param array<string, mixed> $application     Normalised Application data.
     * @param string               $applicationUuid Parent Application UUID for scoping.
     *
     * @return array<string, mixed>|null Blob or null if the version is missing.
     */
    private function resolveVersionBlob(string $token, array $application, string $applicationUuid): ?array
    {
        if ($token === 'draft') {
            return [
                'manifest'    => ($application['manifest'] ?? null),
                'version'     => ($application['version'] ?? null),
                'publishedAt' => null,
            ];
        }

        $version = $this->objectService->find(
            id: $token,
            register: 'openbuilt',
            schema: 'application-version'
        );

        if ($version === null) {
            return null;
        }

        $versionArray = $this->normaliseObject(object: $version);

        // Organisation-scope enforcement: a snapshot from another Application is a miss.
        if (($versionArray['applicationUuid'] ?? null) !== $applicationUuid) {
            return null;
        }

        return [
            'manifest'    => ($versionArray['manifest'] ?? null),
            'version'     => ($versionArray['version'] ?? null),
            'publishedAt' => ($versionArray['publishedAt'] ?? null),
        ];
    }//end resolveVersionBlob()

    /**
     * Return the list of Applications the caller has any role on.
     *
     * Closes the list-endpoint IDOR (REQ-OBRBAC-002 / REQ-OBR-007). OR's
     * schema-level read rule is a coarse group ACL — not a row-level
     * predicate on `permissions.owners ∪ editors ∪ viewers` — so the
     * frontend cannot rely on OR's REST list endpoint without leaking
     * every Application's permissions block and manifest to every
     * authenticated user. This action fetches all Applications via OR
     * and filters them server-side using the same role-derivation rule
     * as `requirePermission`. Admin callers receive the full unfiltered
     * list and a single audit event is recorded (REQ-OBRBAC-006).
     *
     * Output shape mirrors what `ApplicationEditor.vue` previously
     * received from OR REST: a flat array of Application objects with
     * `uuid`, `id`, `slug`, `name`, `status`, `version`, `manifest`,
     * `permissions` — no OR envelope, no pagination metadata.
     *
     * @return JSONResponse The filtered Application list
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listMine(): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(
                    data: ['error' => 'forbidden', 'code' => 'openbuilt.rbac.no_role'],
                    statusCode: Http::STATUS_FORBIDDEN
                );
            }

            $registerId = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
            $appSchema  = $this->schemaMapper->find('application', _multitenancy: false)->getId();

            // Fetch all Applications scoped to the openbuilt register +
            // application schema. OR's multitenancy + RBAC still applies;
            // the per-Application filter below is the load-bearing
            // authorization boundary.
            $results = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $appSchema,
                    ],
                ]
            );

            if (is_array($results) === false) {
                $results = [];
            }

            $userGroups = $this->getUserGroupIds(user: $user);
            $isAdmin    = $this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP);

            $filtered     = [];
            $adminBypassUsed = false;
            foreach ($results as $entry) {
                $app = $this->normaliseObject(object: $entry);
                if ($app === []) {
                    continue;
                }

                $authorised = $this->collectAuthorisedGroups(application: $app);
                $hasRole    = count(array_intersect($userGroups, $authorised)) > 0;

                if ($hasRole === true) {
                    $filtered[] = $app;
                    continue;
                }

                if ($isAdmin === true) {
                    // Admin sees the row via the bypass; single audit
                    // event for the listing covers all rows surfaced
                    // by the bypass (the per-row manifest fetch is the
                    // surface that emits a separate per-slug audit).
                    $filtered[]      = $app;
                    $adminBypassUsed = true;
                }
            }

            if ($adminBypassUsed === true) {
                $this->logger->info(
                    'OpenBuilt: rbac.admin_bypass exercised on Application list',
                    [
                        'actor'     => $user->getUID(),
                        'event'     => self::EVENT_ADMIN_BYPASS.'.list',
                        'count'     => count($filtered),
                        'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ]
                );
            }

            return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error(
                'OpenBuilt: listMine failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to load applications'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end listMine()

    /**
     * Enforce the per-Application RBAC permissions block.
     *
     * Computes the caller's group set and intersects with the Application's
     * `permissions.owners ∪ permissions.editors ∪ permissions.viewers`.
     * Returns null when the caller has any role, or a `JSONResponse` 403
     * with the fixed `openbuilt.rbac.no_role` error envelope otherwise.
     *
     * Admin bypass per design.md Decision 5: a caller in the Nextcloud
     * `admin` group always passes; the bypass is recorded as a
     * `rbac.admin_bypass` event in OR's per-object audit trail
     * (REQ-OBRBAC-006) so it surfaces in REQ-OBRBAC-007's permission
     * history panel. The bypass MUST stay narrow — controller-only,
     * audited — to avoid becoming a hidden parallel auth pathway.
     *
     * @param ObjectEntity|null    $application      The Application entity (for audit-trail write)
     * @param array<string, mixed> $applicationArray The Application data (for permission inspection)
     * @param string               $slug             The slug used in the audit envelope
     *
     * @return JSONResponse|null Null on allow, 403 JSONResponse on deny
     */
    private function requirePermission(
        ?ObjectEntity $application,
        array $applicationArray,
        string $slug
    ): ?JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            // Unauthenticated callers should not reach a #[NoAdminRequired]
            // route — Nextcloud's framework rejects them earlier. Treat as
            // forbidden defensively (ADR-005 deny-by-default).
            return new JSONResponse(
                data: ['error' => 'forbidden', 'code' => 'openbuilt.rbac.no_role'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $userGroups = $this->getUserGroupIds(user: $user);
        $authorised = $this->collectAuthorisedGroups(application: $applicationArray);

        if (count(array_intersect($userGroups, $authorised)) > 0) {
            return null;
        }

        if ($this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === true) {
            $this->recordAdminBypass(application: $application, slug: $slug, actor: $user->getUID());
            return null;
        }

        return new JSONResponse(
            data: ['error' => 'forbidden', 'code' => 'openbuilt.rbac.no_role'],
            statusCode: Http::STATUS_FORBIDDEN
        );
    }//end requirePermission()

    /**
     * Record an admin-bypass event in OR's audit trail (REQ-OBRBAC-006).
     *
     * Writes a structured entry to OpenRegister's per-object audit trail
     * via AuditTrailMapper so the bypass surfaces in REQ-OBRBAC-007's
     * Permission history panel rather than being buried in the Nextcloud
     * log. Falls back to the PSR logger when OR's audit mapper is
     * unavailable (e.g. OR not loaded in a unit-test harness) so the
     * controller never silently drops an audit event.
     *
     * @param ObjectEntity|null $application The Application entity bypassed
     * @param string            $slug        The slug used in the audit envelope
     * @param string            $actor       The bypassing user's UID
     *
     * @return void
     */
    private function recordAdminBypass(?ObjectEntity $application, string $slug, string $actor): void
    {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $context   = [
            'event'     => self::EVENT_ADMIN_BYPASS,
            'actor'     => $actor,
            'slug'      => $slug,
            'timestamp' => $timestamp,
        ];

        if ($this->auditTrailMapper !== null && $application !== null) {
            try {
                $this->auditTrailMapper->createAuditTrailEntry(
                    object: $application,
                    action: self::EVENT_ADMIN_BYPASS,
                    context: $context
                );
                // Mirror to the PSR logger at info level so the bypass is
                // discoverable in operator-facing log streams as well — the
                // audit trail is the system of record, the PSR log is the
                // operational tap.
                $this->logger->info(
                    'OpenBuilt: rbac.admin_bypass exercised',
                    $context
                );
                return;
            } catch (\Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: failed to record admin bypass in OR audit trail; falling back to PSR log',
                    array_merge($context, ['exception' => $e->getMessage()])
                );
            }
        }

        // Fallback path — audit mapper unavailable or no Application
        // entity (defensive). Emit to PSR logger at info level so the
        // event still surfaces somewhere reviewable.
        $this->logger->info(
            'OpenBuilt: rbac.admin_bypass exercised',
            $context
        );
    }//end recordAdminBypass()

    /**
     * Return the given user's Nextcloud group ID list.
     *
     * @param IUser $user The Nextcloud user
     *
     * @return array<int, string>
     */
    private function getUserGroupIds(IUser $user): array
    {
        $groups = $this->groupManager->getUserGroups($user);
        $ids    = [];
        foreach ($groups as $group) {
            $ids[] = $group->getGID();
        }

        return $ids;
    }//end getUserGroupIds()

    /**
     * Flatten `permissions.owners ∪ editors ∪ viewers` to a deduplicated array.
     *
     * Per REQ-OBRBAC-002 the three role buckets union into the "any role"
     * set the manifest endpoint checks against.
     *
     * @param array<string, mixed> $application The Application data
     *
     * @return array<int, string>
     */
    private function collectAuthorisedGroups(array $application): array
    {
        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            return [];
        }

        $merged = [];
        foreach (['owners', 'editors', 'viewers'] as $role) {
            $bucket = ($permissions[$role] ?? []);
            if (is_array($bucket) === false) {
                continue;
            }

            foreach ($bucket as $gid) {
                if (is_string($gid) === true && $gid !== '') {
                    $merged[$gid] = true;
                }
            }
        }

        return array_keys($merged);
    }//end collectAuthorisedGroups()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
     *
     * FindAll() and find() may return ObjectEntity instances; we normalise to an
     * array so the caller can use array access uniformly. Uses jsonSerialize()
     * when present (the canonical ObjectEntity surface).
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
