<?php

/**
 * OpenBuilt ApplicationVersionsController
 *
 * REST surface for the versioned-app model (ADR-002 / spec
 * `application-versions`). Exposes CRUD over ApplicationVersion rows
 * scoped to a parent Application slug, plus the strategy-aware delete
 * endpoint defined in spec REQ-OBV-108.
 *
 * Endpoints (registered in appinfo/routes.php):
 *
 *   - GET    /api/applications/{slug}/versions
 *   - GET    /api/applications/{slug}/versions/{versionSlug}
 *   - POST   /api/applications/{slug}/versions
 *   - PUT    /api/applications/{slug}/versions/{versionSlug}
 *   - DELETE /api/applications/{slug}/versions/{versionSlug}?strategy=...
 *
 * All endpoints carry `#[NoAdminRequired]` per spec REQ-OBV-107 — the
 * parent Application's `permissions` RBAC block (owners/editors for
 * write, viewers for read) is enforced server-side here.
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
use OCA\OpenBuilt\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller serving the ApplicationVersion CRUD + strategy-delete surface.
 */
class ApplicationVersionsController extends Controller
{
    /**
     * Nextcloud admin group identifier used as the RBAC bypass anchor.
     */
    private const ADMIN_GROUP = 'admin';

    /**
     * Roles that grant write access to ApplicationVersion rows.
     *
     * @var array<int,string>
     */
    private const WRITE_ROLES = ['owners', 'editors'];

    /**
     * Roles that grant read access to ApplicationVersion rows.
     *
     * @var array<int,string>
     */
    private const READ_ROLES = ['owners', 'editors', 'viewers'];

    /**
     * Constructor.
     *
     * @param IRequest                  $request        The current HTTP request
     * @param LoggerInterface           $logger         PSR logger for diagnostics
     * @param ObjectService             $objectService  OpenRegister object service
     * @param RegisterMapper            $registerMapper Resolves register slugs
     * @param SchemaMapper              $schemaMapper   Resolves schema slugs
     * @param IUserSession              $userSession    Current Nextcloud user session
     * @param IGroupManager             $groupManager   Group membership resolver
     * @param ApplicationVersionService $versionService Owner of the imperative logic
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
        private readonly ApplicationVersionService $versionService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List ApplicationVersions for the named Application (spec REQ-OBV-107).
     *
     * @param string $slug Parent Application slug
     *
     * @return JSONResponse Versions array on 200, error envelope on miss
     */
    #[NoAdminRequired]
    public function index(string $slug): JSONResponse
    {
        $authError = $this->requireRole(slug: $slug, roles: self::READ_ROLES);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $application = $this->loadApplication(slug: $slug);
            if ($application === null) {
                return $this->errorResponse(code: 'not_found', detail: 'Application '.$slug.' not found', status: Http::STATUS_NOT_FOUND);
            }

            $applicationUuid = (string) ($application['id'] ?? $application['uuid'] ?? '');
            $registerId      = $this->registerMapper->find(
                ApplicationVersionService::REGISTER_SLUG,
                _multitenancy: false
            )->getId();
            $schemaId        = $this->schemaMapper->find(
                ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
                _multitenancy: false
            )->getId();

            $rows = $this->objectService->searchObjects(
                query: [
                    '@self'       => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'application' => $applicationUuid,
                ]
            );

            $rowsList = [];
            if (is_array($rows) === true) {
                $rowsList = $rows;
            }

            $normalised = array_map(
                fn ($row): array => $this->normaliseObject(object: $row),
                $rowsList
            );

            return new JSONResponse(data: $normalised, statusCode: Http::STATUS_OK);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: ApplicationVersionsController::index failed for slug '.$slug.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->errorResponse(code: 'internal_error', detail: 'Failed to load versions');
        }//end try
    }//end index()

    /**
     * Fetch a single ApplicationVersion by version slug (spec REQ-OBV-107).
     *
     * @param string $slug        Parent Application slug
     * @param string $versionSlug ApplicationVersion slug
     *
     * @return JSONResponse The version on 200, error envelope on miss
     */
    #[NoAdminRequired]
    public function show(string $slug, string $versionSlug): JSONResponse
    {
        $authError = $this->requireRole(slug: $slug, roles: self::READ_ROLES);
        if ($authError !== null) {
            return $authError;
        }

        $version = $this->findVersionForApplication(slug: $slug, versionSlug: $versionSlug);
        if ($version === null) {
            return $this->errorResponse(code: 'not_found', detail: $versionSlug, status: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: $version, statusCode: Http::STATUS_OK);
    }//end show()

    /**
     * Create an ApplicationVersion under the named Application (spec REQ-OBV-107 / REQ-OBV-102).
     *
     * @param string $slug Parent Application slug
     *
     * @return JSONResponse 201 with the created version, or error envelope
     */
    #[NoAdminRequired]
    public function create(string $slug): JSONResponse
    {
        $authError = $this->requireRole(slug: $slug, roles: self::WRITE_ROLES);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $application = $this->loadApplication(slug: $slug);
            if ($application === null) {
                return $this->errorResponse(code: 'not_found', detail: 'Application '.$slug.' not found', status: Http::STATUS_NOT_FOUND);
            }

            $applicationUuid = (string) ($application['id'] ?? $application['uuid'] ?? '');
            $payload         = $this->collectPayload();

            // Strip any client-supplied UUID — OR mints its own on create.
            unset($payload['id'], $payload['uuid'], $payload['@self']);
            // Honour the back-reference even if the client forgot to send it.
            $payload['application'] = $applicationUuid;

            $payload = $this->versionService->onSave(current: null, next: $payload);

            $promotesTo = (string) ($payload['promotesTo'] ?? '');
            if ($promotesTo !== '') {
                // Cycle guard requires a uuid; for a brand-new row use a stable
                // placeholder string that cannot occur in OR's actual UUID space.
                $this->versionService->guardNoCycle(
                    currentUuid: '__pending_create__',
                    proposedTargetUuid: $promotesTo
                );
            }

            $created = $this->objectService->saveObject(
                object: $payload,
                register: ApplicationVersionService::REGISTER_SLUG,
                schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
            );

            return new JSONResponse(
                data: $this->normaliseObject(object: $created),
                statusCode: Http::STATUS_CREATED
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: ApplicationVersionsController::create failed for slug '.$slug.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->errorResponse(
                code: 'create_failed',
                detail: $e->getMessage(),
                status: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }//end try
    }//end create()

    /**
     * Update an ApplicationVersion (spec REQ-OBV-103 / REQ-OBV-104 / REQ-OBV-107).
     *
     * @param string $slug        Parent Application slug
     * @param string $versionSlug ApplicationVersion slug
     *
     * @return JSONResponse 200 with the updated version, or error envelope
     */
    #[NoAdminRequired]
    public function update(string $slug, string $versionSlug): JSONResponse
    {
        $authError = $this->requireRole(slug: $slug, roles: self::WRITE_ROLES);
        if ($authError !== null) {
            return $authError;
        }

        try {
            $current = $this->findVersionForApplication(slug: $slug, versionSlug: $versionSlug);
            if ($current === null) {
                return $this->errorResponse(code: 'not_found', detail: $versionSlug, status: Http::STATUS_NOT_FOUND);
            }

            $currentUuid = (string) ($current['id'] ?? $current['uuid'] ?? '');
            $payload     = array_merge($current, $this->collectPayload());
            unset($payload['@self']);

            // Preserve immutable fields.
            $payload['application'] = $current['application'] ?? null;
            $payload['id']          = $currentUuid;

            // Cycle guard on cross-row.
            $proposedPromotesTo = $payload['promotesTo'] ?? null;
            if (is_string($proposedPromotesTo) === true) {
                $cycleTarget = $proposedPromotesTo;
                if ($cycleTarget === '') {
                    $cycleTarget = null;
                }

                $this->versionService->guardNoCycle(
                    currentUuid: $currentUuid,
                    proposedTargetUuid: $cycleTarget
                );
            }

            $payload = $this->versionService->onSave(current: $current, next: $payload);

            $updated = $this->objectService->saveObject(
                object: $payload,
                register: ApplicationVersionService::REGISTER_SLUG,
                schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
                uuid: $currentUuid
            );

            return new JSONResponse(
                data: $this->normaliseObject(object: $updated),
                statusCode: Http::STATUS_OK
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: ApplicationVersionsController::update failed for slug '.$slug.'/'.$versionSlug.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return $this->errorResponse(
                code: 'update_failed',
                detail: $e->getMessage(),
                status: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }//end try
    }//end update()

    /**
     * Delete an ApplicationVersion using the requested strategy (spec REQ-OBV-108).
     *
     * Accepts the `strategy` query parameter (`delete-now |
     * orphan-grace | keep-register`). Missing/unknown values yield 400.
     * Attempts to delete the parent Application's production version
     * yield 422.
     *
     * @param string $slug        Parent Application slug
     * @param string $versionSlug ApplicationVersion slug
     *
     * @return JSONResponse 204 on success, error envelope otherwise
     */
    #[NoAdminRequired]
    public function destroy(string $slug, string $versionSlug): JSONResponse
    {
        $authError = $this->requireRole(slug: $slug, roles: self::WRITE_ROLES);
        if ($authError !== null) {
            return $authError;
        }

        $strategy = (string) $this->request->getParam('strategy', '');
        if ($strategy === '') {
            return $this->errorResponse(
                code: 'missing_strategy',
                detail: 'Query parameter `strategy` is required (delete-now | orphan-grace | keep-register).',
                status: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $current = $this->findVersionForApplication(slug: $slug, versionSlug: $versionSlug);
            if ($current === null) {
                return $this->errorResponse(code: 'not_found', detail: $versionSlug, status: Http::STATUS_NOT_FOUND);
            }

            $currentUuid = (string) ($current['id'] ?? $current['uuid'] ?? '');

            $this->versionService->deleteVersion(versionUuid: $currentUuid, strategy: $strategy);

            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (Throwable $e) {
            $this->logger->info(
                'OpenBuilt: ApplicationVersionsController::destroy refused for slug '.$slug.'/'.$versionSlug.': '.$e->getMessage()
            );

            $message = $e->getMessage();
            $status  = Http::STATUS_UNPROCESSABLE_ENTITY;
            $code    = 'delete_failed';
            if (str_contains($message, 'Unknown deletion strategy') === true) {
                $status = Http::STATUS_BAD_REQUEST;
                $code   = 'invalid_strategy';
            }

            return $this->errorResponse(code: $code, detail: $message, status: $status);
        }//end try
    }//end destroy()

    /**
     * Resolve the parent Application by slug, returning a normalised array.
     *
     * @param string $slug Parent Application slug
     *
     * @return array<string,mixed>|null Application record or null when missing
     */
    private function loadApplication(string $slug): ?array
    {
        $registerId = $this->registerMapper->find(
            ApplicationVersionService::REGISTER_SLUG,
            _multitenancy: false
        )->getId();
        $schemaId   = $this->schemaMapper->find(
            ApplicationVersionService::APPLICATION_SCHEMA,
            _multitenancy: false
        )->getId();

        $rows = $this->objectService->searchObjects(
            query: [
                '@self' => [
                    'register' => $registerId,
                    'schema'   => $schemaId,
                ],
                'slug'  => $slug,
            ]
        );

        if (is_array($rows) === false || $rows === []) {
            return null;
        }

        return $this->normaliseObject(object: $rows[0]);
    }//end loadApplication()

    /**
     * Resolve an ApplicationVersion by version slug, scoped to the parent Application.
     *
     * Returns null when either the Application or the version is missing,
     * or when the version's `application` relation does not back-reference
     * this Application (IDOR-safe).
     *
     * @param string $slug        Parent Application slug
     * @param string $versionSlug ApplicationVersion slug
     *
     * @return array<string,mixed>|null Version record or null on miss
     */
    private function findVersionForApplication(string $slug, string $versionSlug): ?array
    {
        $application = $this->loadApplication(slug: $slug);
        if ($application === null) {
            return null;
        }

        $applicationUuid = (string) ($application['id'] ?? $application['uuid'] ?? '');

        $registerId = $this->registerMapper->find(
            ApplicationVersionService::REGISTER_SLUG,
            _multitenancy: false
        )->getId();
        $schemaId   = $this->schemaMapper->find(
            ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
            _multitenancy: false
        )->getId();

        $rows = $this->objectService->searchObjects(
            query: [
                '@self'       => [
                    'register' => $registerId,
                    'schema'   => $schemaId,
                ],
                'slug'        => $versionSlug,
                'application' => $applicationUuid,
            ]
        );

        if (is_array($rows) === false || $rows === []) {
            return null;
        }

        return $this->normaliseObject(object: $rows[0]);
    }//end findVersionForApplication()

    /**
     * Verify the current user has any of the named roles on the parent Application.
     *
     * Admin callers pass via the bypass (see ApplicationsController for the
     * audited variant — this controller's bypass surfaces only in the logs).
     *
     * @param string            $slug  Parent Application slug
     * @param array<int,string> $roles List of role names (`owners`, `editors`, `viewers`)
     *
     * @return JSONResponse|null Null on allow, 401/403/404 envelope on deny
     */
    private function requireRole(string $slug, array $roles): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        $application = $this->loadApplication(slug: $slug);
        if ($application === null) {
            return $this->errorResponse(
                code: 'not_found',
                detail: 'Application '.$slug.' not found',
                status: Http::STATUS_NOT_FOUND
            );
        }

        if ($this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === true) {
            $this->logger->info(
                'OpenBuilt: rbac.admin_bypass on ApplicationVersions endpoint',
                ['actor' => $user->getUID(), 'slug' => $slug, 'roles' => $roles]
            );
            return null;
        }

        $authorised = $this->collectAuthorisedPrincipals(application: $application, roles: $roles);
        if (in_array($user->getUID(), $authorised['users'], true) === true) {
            return null;
        }

        if (count(array_intersect($this->getUserGroupIds(user: $user), $authorised['groups'])) > 0) {
            return null;
        }

        return $this->errorResponse(
            code: 'openbuilt.rbac.no_role',
            status: Http::STATUS_FORBIDDEN
        );
    }//end requireRole()

    /**
     * Flatten the named role buckets into user / group principal lists.
     *
     * Mirrors ApplicationsController::collectAuthorisedGroups but accepts
     * a role filter so read endpoints can include viewers while write
     * endpoints exclude them.
     *
     * @param array<string,mixed> $application The Application data
     * @param array<int,string>   $roles       Role names to include
     *
     * @return array{users: array<int,string>, groups: array<int,string>}
     */
    private function collectAuthorisedPrincipals(array $application, array $roles): array
    {
        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            return ['users' => [], 'groups' => []];
        }

        $userSet  = [];
        $groupSet = [];
        foreach ($roles as $role) {
            $bucket = ($permissions[$role] ?? []);
            if (is_array($bucket) === false) {
                continue;
            }

            $this->absorbPrincipalBucket(bucket: $bucket, userSet: $userSet, groupSet: $groupSet);
        }

        return [
            'users'  => array_keys($userSet),
            'groups' => array_keys($groupSet),
        ];
    }//end collectAuthorisedPrincipals()

    /**
     * Classify a permission-role bucket into user-UID and group-GID sets.
     *
     * @param array<int,mixed>   $bucket   The raw bucket (owners/editors/viewers entries)
     * @param array<string,bool> $userSet  Accumulating UID set (passed by reference)
     * @param array<string,bool> $groupSet Accumulating GID set (passed by reference)
     *
     * @return void
     */
    private function absorbPrincipalBucket(array $bucket, array &$userSet, array &$groupSet): void
    {
        foreach ($bucket as $principal) {
            if (is_string($principal) === false || $principal === '') {
                continue;
            }

            if (str_starts_with($principal, 'user:') === true) {
                $uid = substr($principal, 5);
                if ($uid !== '') {
                    $userSet[$uid] = true;
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
    }//end absorbPrincipalBucket()

    /**
     * Read the current user's group GIDs.
     *
     * @param IUser $user The Nextcloud user
     *
     * @return array<int,string>
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
     * Read the JSON / form payload from the current request.
     *
     * @return array<string,mixed>
     */
    private function collectPayload(): array
    {
        $params = $this->request->getParams();
        unset($params['_route']);
        return $params;
    }//end collectPayload()

    /**
     * Build a uniform error envelope.
     *
     * @param string      $code   Error code
     * @param string|null $detail Optional detail message
     * @param int         $status HTTP status code
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
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object/result entry
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
