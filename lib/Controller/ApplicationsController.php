<?php

/**
 * OpenBuilt Applications Controller
 *
 * Serves the per-virtual-app manifest endpoint, the RBAC-filtered list
 * endpoint used by the editor (REQ-OBRBAC-002 / REQ-OBR-007), the
 * manifest-diff endpoint (openbuilt-versioning REQ-OBV-005) and the
 * clone-from-template action (openbuilt-templates-marketplace
 * REQ-OBTC-004 / REQ-OBTC-005). Per design.md Decision 6 this is the
 * single app-local HTTP surface; `listMine` exists because OR's
 * schema-level read rule is a coarse group-ACL (not a row-level filter on
 * the Application's `permissions` block) so the list MUST be filtered
 * server-side here, and `createFromTemplate` is the thin-glue clone action
 * (ADR-032) that provisions a per-app `openbuilt-{slug}` register and
 * deep-copies the template's companion schemas into it (hybrid register
 * model).
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

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenBuilt\AppInfo\Application;
use OCA\OpenBuilt\Service\ManifestResolverService;
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
use Throwable;

/**
 * Controller for the OpenBuilt manifest, list, diff and clone-from-template endpoints.
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
     * @param IRequest                $request          The current HTTP request
     * @param LoggerInterface         $logger           PSR logger for diagnostics
     * @param ObjectService           $objectService    OpenRegister object service (hard dep via info.xml)
     * @param RegisterMapper          $registerMapper   Resolves slugs/UUIDs to numeric register IDs
     * @param SchemaMapper            $schemaMapper     Resolves slugs/UUIDs to numeric schema IDs
     * @param IUserSession            $userSession      Current Nextcloud user session
     * @param IGroupManager           $groupManager     Group membership resolver
     * @param ManifestResolverService $manifestResolver Version-aware manifest resolver (REQ-OBVR-002)
     * @param AuditTrailMapper|null   $auditTrailMapper Optional OR audit-trail writer (null until OR loaded)
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
        private readonly ManifestResolverService $manifestResolver,
        private readonly ?AuditTrailMapper $auditTrailMapper=null,
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
     * Version routing (spec `openbuilt-version-routing` REQ-OBVR-001):
     * ---------------------------------------------------------------
     * An optional `?_version=<versionSlug>` query parameter selects a specific
     * ApplicationVersion. The underscore-prefix form (`_version`, not `version`)
     * is OpenBuilt's system-reserved namespace marker — it prevents collision
     * with user-defined `?version=` params that citizen developers may add to
     * their virtual apps' routes.
     *
     * When `?_version=` is present, the request is routed through
     * ManifestResolverService which enforces RBAC: viewers and non-members
     * receive 404 (not 403) for non-production versions; unknown version slugs
     * also 404 (same response — no existence leak, REQ-OBVR-003 / Decision 8).
     *
     * When `?_version=` is absent the endpoint behaves exactly as before,
     * returning the production manifest to any authenticated caller with any
     * role on the Application (the existing requirePermission check).
     *
     * Visibility model
     * ----------------
     * `#[NoAdminRequired]` is intentional: the hello-world seed app (and any
     * future "always-on" virtual app) is publicly mountable as soon as a route
     * exists. RBAC lives inside ManifestResolverService (for versioned access)
     * and requirePermission (for the production path).
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
            // REQ-OBVR-001: read the `?_version=` query parameter.
            // The param name uses a leading underscore to avoid colliding with any
            // user-defined `?version=` params in citizen-developer apps. Null when absent.
            $versionSlugRaw = $this->request->getParam('_version');
            $versionSlug    = null;
            if ($versionSlugRaw !== null && $versionSlugRaw !== '') {
                $versionSlug = $versionSlugRaw;
            }

            // When `?_version=` is present, delegate to ManifestResolverService which
            // performs the two-step lookup (Application → ApplicationVersion) and RBAC
            // gate (REQ-OBVR-002 / REQ-OBVR-003). Both "unknown version" and
            // "unauthorised caller" return identical 404 to prevent slug enumeration.
            if ($versionSlug !== null) {
                return $this->resolveVersionedManifestResponse(slug: $slug, versionSlug: $versionSlug);
            }

            // No `?_version=` param: original production-manifest path (backwards-compat).
            $resolved = $this->resolveApplicationBySlug(slug: $slug);
            if ($resolved instanceof JSONResponse) {
                return $resolved;
            }

            [$application, $applicationArray, $applicationUuid] = $resolved;

            // RBAC enforcement per REQ-OBRBAC-002 / REQ-OBR-006 — deny-by-default
            // before any branch that would emit the manifest payload (ADR-005,
            // ADR-022 §Exceptions(1)). Returns 403 with a fixed error envelope
            // when the caller has no role intersection and is not exercising
            // the audited admin bypass declared in REQ-OBRBAC-006.
            $applicationEntity = null;
            if ($application instanceof ObjectEntity) {
                $applicationEntity = $application;
            }

            $denial = $this->requirePermission(
                application: $applicationEntity,
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
        } catch (Throwable $e) {
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
     * Delegate to ManifestResolverService for versioned-manifest access.
     *
     * Performs the two-step lookup (Application → ApplicationVersion) and RBAC
     * gate (REQ-OBVR-002 / REQ-OBVR-003). Both "unknown version" and
     * "unauthorised caller" return identical 404 to prevent slug enumeration.
     *
     * @param string $slug        The virtual-app slug from the URL.
     * @param string $versionSlug The version slug from `?_version=`.
     *
     * @return JSONResponse 200 with manifest, or 404 when not found / not authorised.
     */
    private function resolveVersionedManifestResponse(string $slug, string $versionSlug): JSONResponse
    {
        $caller   = $this->userSession->getUser();
        $manifest = $this->manifestResolver->resolve(
            appSlug: $slug,
            versionSlug: $versionSlug,
            caller: $caller
        );

        if ($manifest === null) {
            return new JSONResponse(
                data: ['status' => Http::STATUS_NOT_FOUND, 'message' => 'Version not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(data: $manifest, statusCode: Http::STATUS_OK);
    }//end resolveVersionedManifestResponse()

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
        if ($this->userSession->getUser() === null) {
            return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

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
     * Resolve a virtual-app slug to the Application object + array form + uuid.
     *
     * Returns either a `JSONResponse` (404 / 500) when resolution fails, or a
     * tuple `[ObjectEntity|array, array, string]` of (raw entity, normalised
     * data, applicationUuid) for the happy path. Splitting this out keeps
     * `getManifest` below PHPMD's 100-line method-length budget.
     *
     * @param string $slug The virtual-app slug from the URL
     *
     * @return JSONResponse|array{0: ObjectEntity|array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    private function resolveApplicationBySlug(string $slug): JSONResponse|array
    {
        // Resolve register + schema slugs to numeric IDs. OR's searchObjects
        // expects numeric IDs in @self; the slug-resolution shortcut isn't
        // applied at this layer (verified during smoke-test 2026-05-11).
        // _multitenancy=false bypasses the org filter on the LOOKUP only —
        // object-level multitenancy is still enforced via searchObjects below.
        $registerId  = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
        $routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();

        // Step 1 — resolve slug → applicationUuid via the BuiltAppRoute index.
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

        return [$application, $this->normaliseObject(object: $application), (string) $applicationUuid];
    }//end resolveApplicationBySlug()

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

            [$filtered, $adminBypassUsed] = $this->filterApplicationsByRole(
                results: $results,
                uid: $user->getUID(),
                userGroups: $userGroups,
                isAdmin: $isAdmin
            );

            if ($adminBypassUsed === true) {
                $this->logger->info(
                    'OpenBuilt: rbac.admin_bypass exercised on Application list',
                    [
                        'actor'     => $user->getUID(),
                        'event'     => self::EVENT_ADMIN_BYPASS.'.list',
                        'count'     => count($filtered),
                        'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                    ]
                );
            }

            return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
        } catch (Throwable $e) {
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
     * Filter a raw OR result set to Applications the caller is authorised to see.
     *
     * Returns a two-element tuple: [filteredApps, adminBypassUsed].
     *
     * @param array<mixed>  $results    Raw OR search result entries.
     * @param string        $uid        Caller's UID.
     * @param array<string> $userGroups Caller's group IDs.
     * @param bool          $isAdmin    Whether the caller is in the Nextcloud admin group.
     *
     * @return array{0: array<array<string,mixed>>, 1: bool} [filtered list, adminBypassUsed].
     */
    private function filterApplicationsByRole(
        array $results,
        string $uid,
        array $userGroups,
        bool $isAdmin
    ): array {
        $filtered        = [];
        $adminBypassUsed = false;

        foreach ($results as $entry) {
            $app = $this->normaliseObject(object: $entry);
            if ($app === []) {
                continue;
            }

            $authorised = $this->collectAuthorisedGroups(application: $app);
            $hasRole    = in_array($uid, $authorised['users'], true)
                || count(array_intersect($userGroups, $authorised['groups'])) > 0;

            if ($hasRole === true) {
                $filtered[] = $app;
                continue;
            }

            if ($isAdmin === true) {
                $filtered[]      = $app;
                $adminBypassUsed = true;
            }
        }//end foreach

        return [$filtered, $adminBypassUsed];
    }//end filterApplicationsByRole()

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

        // Match the caller against the user-UID bucket first (exact UID
        // match), then against the group-GID bucket (intersection with
        // caller's groups). Either match grants access; both buckets are
        // independent so a username and a same-named group don't clash
        // (openbuilt#37).
        if (in_array($user->getUID(), $authorised['users'], true) === true) {
            return null;
        }

        if (count(array_intersect($userGroups, $authorised['groups'])) > 0) {
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
        $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
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
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: failed to record admin bypass in OR audit trail; falling back to PSR log',
                    array_merge($context, ['exception' => $e->getMessage()])
                );
            }//end try
        }//end if

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
     * Flatten `permissions.owners ∪ editors ∪ viewers` into separate
     * user-UID and group-GID buckets.
     *
     * Per REQ-OBRBAC-002 the three role buckets union into the "any role"
     * set the manifest endpoint checks against. Per openbuilt#37 the
     * principal type is encoded in the string itself rather than living
     * in a single shared namespace where a username and a group GID can
     * coincidentally clash (the seeded `owners: ["admin"]` matched
     * everyone in the `admin` group, not just the `admin` user).
     *
     * Recognised prefixes:
     *   - `user:<uid>`   — match if the caller's UID equals `<uid>`.
     *   - `group:<gid>`  — match if any of the caller's group GIDs equals `<gid>`.
     *   - `<value>`      — back-compat: treated as a group GID, same as
     *                      `group:<value>`. The seeded `owners: ["admin"]`
     *                      keeps working under this fallback. Apps that
     *                      want to grant access to a specific user MUST
     *                      use the `user:` prefix to disambiguate.
     *
     * @param array<string, mixed> $application The Application data
     *
     * @return array{users: array<int, string>, groups: array<int, string>}
     *   Two deduplicated lists; `users` are UID values the caller's UID
     *   should be compared against; `groups` are GID values the caller's
     *   group memberships should be intersected with.
     */
    private function collectAuthorisedGroups(array $application): array
    {
        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            return ['users' => [], 'groups' => []];
        }

        $userSet  = [];
        $groupSet = [];
        foreach (['owners', 'editors', 'viewers'] as $role) {
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

        return [
            'users'  => array_keys($userSet),
            'groups' => array_keys($groupSet),
        ];
    }//end collectAuthorisedGroups()

    /**
     * Classify a single permissions principal into the UID or GID accumulator sets.
     *
     * Modifies $userSet and $groupSet by reference.
     *
     * @param mixed               $principal The raw principal value from the permissions bucket.
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

        $gid = $principal;
        if (str_starts_with($principal, 'group:') === true) {
            $gid = substr($principal, 6);
        }

        if ($gid !== '') {
            $groupSet[$gid] = true;
        }
    }//end classifyPrincipal()

    /**
     * Clone an Application from a template.
     *
     * Reads the ApplicationTemplate identified by $templateSlug, creates a
     * per-app `openbuilt-{newSlug}` register, deep-copies its companion JSON
     * schemas into that per-app register (REQ-OBTC-005 / hybrid register
     * model), rewrites manifest schema refs to the new slug, and creates a
     * new Application record in the shared `openbuilt` register, tagged
     * with the caller's UID (multi-user isolation).
     *
     * @param string $templateSlug The source template slug
     *
     * @return JSONResponse The new application's uuid + slug, or an error envelope
     */
    #[NoAdminRequired]
    public function createFromTemplate(string $templateSlug): JSONResponse
    {
        // 1. Auth + request validation.
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        $ownerUid = $user->getUID();

        $validation = $this->validateCloneRequest(body: $this->request->getParams());
        if (isset($validation['error']) === true) {
            return new JSONResponse(data: $validation['error'], statusCode: $validation['status']);
        }

        [$name, $newSlug] = $validation;

        // 2. Resolve shared register + schemas.
        $ctx = $this->resolveSharedContext();
        if ($ctx === null) {
            return $this->errorResponse(
                code: 'not_configured',
                detail: 'OpenBuilt register/schemas not initialised',
                status: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        // 3. Lookup template + slug-collision check (scoped to caller's UID).
        $template = $this->lookupOne(
            registerId: $ctx['register'],
            schemaId: $ctx['templateSchema'],
            slug: $templateSlug
        );
        if ($template === null) {
            return $this->errorResponse(
                code: 'template_not_found',
                detail: $templateSlug,
                status: Http::STATUS_NOT_FOUND
            );
        }

        $existing = $this->lookupOne(
            registerId: $ctx['register'],
            schemaId: $ctx['applicationSchema'],
            slug: $newSlug,
            owner: $ownerUid
        );
        if ($existing !== null) {
            return $this->errorResponse(
                code: 'slug_collision',
                detail: $newSlug,
                status: Http::STATUS_CONFLICT
            );
        }

        // 4. Prepare manifest + companion-schema clone map.
        $companionInput = $this->extractCompanionSchemas(template: $template);
        $rewriteMap     = $this->buildRewriteMap(companions: $companionInput, newSlug: $newSlug);
        $manifest       = $this->buildClonedManifest(template: $template, rewriteMap: $rewriteMap);

        // 5. Provision per-app register + clone companion schemas into it.
        $cloneResult = $this->provisionPerAppArtifacts(
            newSlug: $newSlug,
            ownerUid: $ownerUid,
            companions: $companionInput,
            rewriteMap: $rewriteMap
        );
        if (isset($cloneResult['error']) === true) {
            return new JSONResponse(data: $cloneResult['error'], statusCode: $cloneResult['status']);
        }

        // 6. Persist the Application record (in shared register), tagged with owner.
        $persistResult = $this->persistApplication(
            name: $name,
            newSlug: $newSlug,
            ownerUid: $ownerUid,
            manifest: $manifest,
            template: $template,
            templateSlug: $templateSlug,
            ctx: $ctx
        );
        if (isset($persistResult['error']) === true) {
            return new JSONResponse(data: $persistResult['error'], statusCode: $persistResult['status']);
        }

        return new JSONResponse(
            data: [
                'uuid'             => $persistResult['uuid'],
                'slug'             => $newSlug,
                'register'         => $cloneResult['register']->getSlug(),
                'companionSchemas' => $cloneResult['schemaIds'],
            ],
            statusCode: Http::STATUS_CREATED
        );
    }//end createFromTemplate()

    /**
     * Build a uniform error response.
     *
     * @param string      $code   The error code
     * @param string|null $detail Optional detail message
     * @param int         $status The HTTP status code
     *
     * @return JSONResponse
     */
    private function errorResponse(string $code, ?string $detail=null, int $status=Http::STATUS_BAD_REQUEST): JSONResponse
    {
        $body = ['error' => $code];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new JSONResponse(data: $body, statusCode: $status);
    }//end errorResponse()

    /**
     * Resolve the shared register + schema IDs (template, application).
     *
     * @return array{register:int,templateSchema:int,applicationSchema:int}|null
     */
    private function resolveSharedContext(): ?array
    {
        try {
            return [
                'register'          => $this->registerMapper->find('openbuilt', _multitenancy: false)->getId(),
                'templateSchema'    => $this->schemaMapper->find('application-template', _multitenancy: false)->getId(),
                'applicationSchema' => $this->schemaMapper->find('application', _multitenancy: false)->getId(),
            ];
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: register/schema resolution failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end resolveSharedContext()

    /**
     * Build the cloned manifest (apply rewrite map to template manifest).
     *
     * @param array<string,mixed>  $template   The template record
     * @param array<string,string> $rewriteMap Source-slug → prefixed-slug map
     *
     * @return array<string,mixed>
     */
    private function buildClonedManifest(array $template, array $rewriteMap): array
    {
        $manifestRaw = ($template['manifest'] ?? null);
        $manifest    = [];
        if (is_array($manifestRaw) === true) {
            $manifest = $manifestRaw;
        }

        $rewritten = $this->rewriteSchemaRefs(node: $manifest, map: $rewriteMap);
        if (is_array($rewritten) === true) {
            return $rewritten;
        }

        return [];
    }//end buildClonedManifest()

    /**
     * Provision per-app register + clone companion schemas.
     *
     * @param string                         $newSlug    The new application slug
     * @param string                         $ownerUid   The owner UID
     * @param array<int,array<string,mixed>> $companions The companion schema blobs
     * @param array<string,string>           $rewriteMap Source-slug → prefixed-slug map
     *
     * @return array{register:\OCA\OpenRegister\Db\Register,schemaIds:array<int,int>}|array{error:array<string,mixed>,status:int}
     */
    private function provisionPerAppArtifacts(
        string $newSlug,
        string $ownerUid,
        array $companions,
        array $rewriteMap
    ): array {
        try {
            $register  = $this->provisionPerAppRegister(newSlug: $newSlug, ownerUid: $ownerUid);
            $schemaIds = $this->cloneCompanionSchemas(
                companions: $companions,
                rewriteMap: $rewriteMap,
                perAppRegister: $register
            );

            return ['register' => $register, 'schemaIds' => $schemaIds];
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: companion-schema clone failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'error'  => ['error' => 'clone_failed', 'detail' => 'Failed to provision per-app register/schemas'],
                'status' => Http::STATUS_INTERNAL_SERVER_ERROR,
            ];
        }
    }//end provisionPerAppArtifacts()

    /**
     * Persist the cloned Application record.
     *
     * @param string                                                       $name         Human-readable name
     * @param string                                                       $newSlug      The new application slug
     * @param string                                                       $ownerUid     The owner UID (multi-user isolation)
     * @param array<string,mixed>                                          $manifest     The cloned manifest
     * @param array<string,mixed>                                          $template     The source template record
     * @param string                                                       $templateSlug The source template slug
     * @param array{register:int,templateSchema:int,applicationSchema:int} $ctx          Shared context
     *
     * @return array{uuid:string|null}|array{error:array<string,mixed>,status:int}
     */
    private function persistApplication(
        string $name,
        string $newSlug,
        string $ownerUid,
        array $manifest,
        array $template,
        string $templateSlug,
        array $ctx
    ): array {
        try {
            $created = $this->objectService->saveObject(
                object: [
                    'name'           => $name,
                    'slug'           => $newSlug,
                    'status'         => 'draft',
                    'version'        => '0.1.0',
                    'owner'          => $ownerUid,
                    'manifest'       => $manifest,
                    'templateOrigin' => [
                        'slug'    => (string) ($template['slug'] ?? $templateSlug),
                        'version' => (string) ($template['version'] ?? ''),
                    ],
                ],
                register: $ctx['register'],
                schema: $ctx['applicationSchema']
            );
        } catch (Throwable $e) {
            $this->logger->error('OpenBuilt: application save failed', ['exception' => $e->getMessage()]);
            return [
                'error'  => ['error' => 'clone_failed', 'detail' => $e->getMessage()],
                'status' => Http::STATUS_INTERNAL_SERVER_ERROR,
            ];
        }//end try

        $createdArray = $this->normaliseObject(object: $created);
        return ['uuid' => ($createdArray['uuid'] ?? $createdArray['id'] ?? null)];
    }//end persistApplication()

    /**
     * Validate the clone-from-template request body.
     *
     * @param array<string,mixed> $body The request params
     *
     * @return array{0:string,1:string}|array{error:array<string,mixed>,status:int}
     *         Either [name, slug] on success, or an error+status envelope.
     */
    private function validateCloneRequest(array $body): array
    {
        $name = (string) ($body['name'] ?? '');
        $slug = (string) ($body['slug'] ?? '');

        if ($name === '' || $slug === '' || preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug) !== 1) {
            return [
                'error'  => ['error' => 'invalid_request', 'detail' => 'name and kebab-case slug required'],
                'status' => Http::STATUS_BAD_REQUEST,
            ];
        }

        if (strlen($slug) > 32) {
            return [
                'error'  => ['error' => 'slug_too_long', 'detail' => 'slug must be <= 32 chars'],
                'status' => Http::STATUS_BAD_REQUEST,
            ];
        }

        return [$name, $slug];
    }//end validateCloneRequest()

    /**
     * Extract companionSchemas array from a template record.
     *
     * @param array<string,mixed> $template The template record
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractCompanionSchemas(array $template): array
    {
        $companionRaw = ($template['companionSchemas'] ?? null);
        if (is_array($companionRaw) === false) {
            return [];
        }

        return array_values(
            array_filter(
                $companionRaw,
                static fn ($entry): bool => is_array($entry) === true && isset($entry['slug']) === true
            )
        );
    }//end extractCompanionSchemas()

    /**
     * Build the source-slug → prefixed-slug rewrite map.
     *
     * @param array<int,array<string,mixed>> $companions The companion schema blobs
     * @param string                         $newSlug    The new app slug used as prefix
     *
     * @return array<string,string>
     */
    private function buildRewriteMap(array $companions, string $newSlug): array
    {
        $map = [];
        foreach ($companions as $companion) {
            $sourceSlug       = (string) $companion['slug'];
            $map[$sourceSlug] = $newSlug.'-'.$sourceSlug;
        }

        return $map;
    }//end buildRewriteMap()

    /**
     * Provision (or fetch existing) the per-app register `openbuilt-{newSlug}`.
     *
     * Per the hybrid register model, each cloned app gets its own register so
     * companion schemas don't collide across apps.
     *
     * @param string $newSlug  The new app slug
     * @param string $ownerUid The Nextcloud UID of the owner
     *
     * @return \OCA\OpenRegister\Db\Register
     */
    private function provisionPerAppRegister(string $newSlug, string $ownerUid): \OCA\OpenRegister\Db\Register
    {
        $registerSlug = 'openbuilt-'.$newSlug;

        try {
            return $this->registerMapper->find($registerSlug, _multitenancy: false);
        } catch (Throwable) {
            // Register does not exist yet — create it.
        }

        return $this->registerMapper->createFromArray(
            [
                'slug'        => $registerSlug,
                'title'       => 'OpenBuilt — '.$newSlug,
                'description' => 'Per-app schema namespace for OpenBuilt app `'.$newSlug.'` (owner: '.$ownerUid.').',
                'version'     => '0.1.0',
                'schemas'     => [],
            ]
        );
    }//end provisionPerAppRegister()

    /**
     * Clone companion schemas into the per-app register.
     *
     * Critical fix: companion schemas are CREATED AS SCHEMAS via SchemaMapper
     * (NOT saved as Application objects, which was the bug at the previous
     * line 168). The per-app register's `schemas` array is updated to include
     * the new schema IDs.
     *
     * @param array<int,array<string,mixed>> $companions     The companion schema blobs from the template
     * @param array<string,string>           $rewriteMap     Source-slug → prefixed-slug map
     * @param \OCA\OpenRegister\Db\Register  $perAppRegister The target per-app register
     *
     * @return array<int,int> List of created schema IDs
     */
    private function cloneCompanionSchemas(
        array $companions,
        array $rewriteMap,
        \OCA\OpenRegister\Db\Register $perAppRegister
    ): array {
        $createdIds = [];

        foreach ($companions as $companion) {
            $sourceSlug = (string) $companion['slug'];
            if (isset($rewriteMap[$sourceSlug]) === false) {
                continue;
            }

            $schemaPayload         = $companion;
            $schemaPayload['slug'] = $rewriteMap[$sourceSlug];
            // Ensure a stable version (templates ship with their own; default to 0.1.0).
            if (isset($schemaPayload['version']) === false) {
                $schemaPayload['version'] = '0.1.0';
            }

            $schema       = $this->schemaMapper->createFromArray(object: $schemaPayload);
            $createdIds[] = $schema->getId();
        }

        if ($createdIds !== []) {
            $existing = $perAppRegister->getSchemas();
            $perAppRegister->setSchemas(array_values(array_unique(array_merge($existing, $createdIds))));
            $this->registerMapper->update($perAppRegister);
        }

        return $createdIds;
    }//end cloneCompanionSchemas()

    /**
     * Recursively rewrite manifest page-config schema references.
     *
     * @param mixed                $node The manifest node
     * @param array<string,string> $map  Map of source-slug => prefixed-slug
     *
     * @return mixed The rewritten node
     */
    private function rewriteSchemaRefs(mixed $node, array $map): mixed
    {
        if (is_array($node) === false) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (($key === 'schema' || $key === 'relatedSchema')
                && is_string($value) === true
                && isset($map[$value]) === true
            ) {
                $node[$key] = $map[$value];
                continue;
            }

            if (is_array($value) === true) {
                $node[$key] = $this->rewriteSchemaRefs(node: $value, map: $map);
            }
        }

        return $node;
    }//end rewriteSchemaRefs()

    /**
     * Look up a single object by slug (optionally scoped by owner).
     *
     * @param int|string  $registerId The register ID
     * @param int|string  $schemaId   The schema ID
     * @param string      $slug       The slug to look up
     * @param string|null $owner      Optional owner UID (multi-user isolation scope)
     *
     * @return array<string,mixed>|null
     */
    private function lookupOne(
        int | string $registerId,
        int | string $schemaId,
        string $slug,
        ?string $owner=null
    ): ?array {
        try {
            $query = [
                '@self' => [
                    'register' => $registerId,
                    'schema'   => $schemaId,
                ],
                'slug'  => $slug,
            ];

            if ($owner !== null) {
                // OR records ownership under `@self.owner`, not at the
                // top level. Placing the filter on the top-level `owner`
                // field made every owner-scoped lookup miss (#51) — the
                // slug-collision check then fell through and the org-wide
                // register-slug unique constraint raised `clone_failed`
                // instead of the documented `slug_collision`.
                $query['@self']['owner'] = $owner;
            }

            $results = $this->objectService->searchObjects(query: $query);

            if (is_array($results) === false || count($results) === 0) {
                return null;
            }

            return $this->normaliseObject(object: $results[0]);
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuilt: lookup failed', ['exception' => $e->getMessage()]);
            return null;
        }//end try
    }//end lookupOne()

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
