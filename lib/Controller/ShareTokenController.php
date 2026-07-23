<?php

/**
 * OpenBuild ShareTokenController
 *
 * Authenticated owner/editor-only CRUD backing `ShareTokenDialog` (create /
 * list / revoke public share links). This is the write-side counterpart to
 * the anonymous `PublicFormController` — every method here runs under the
 * SAME session/organisation RBAC posture as the rest of the authenticated
 * runtime (`ApplicationsController::saveManifest`'s owner/editor gate,
 * mirrored below), never the token-based posture. `ShareTokenService::issue()`
 * is only ever called after this controller has independently verified the
 * caller may write to the target Application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-token-management-ui-in-the-page-designer-and-app-settings
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\ManifestResolverService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenBuild\Service\ShareTokenService;
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
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Authenticated CRUD for ShareToken (create / list / revoke).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors `ApplicationsController`'s own
 *  dependency set (same slug→Application resolution + owner/editor RBAC posture); every
 *  collaborator is required to keep this controller's authorization self-contained rather
 *  than delegating trust to a shared helper (see `requireWriteAccess()`'s docblock).
 */
class ShareTokenController extends Controller
{

    /**
     * Nextcloud admin group identifier — matches ApplicationsController's
     * audited-bypass anchor for consistency across the runtime.
     *
     * @var string
     */
    private const ADMIN_GROUP = 'admin';

    /**
     * Constructor.
     *
     * @param IRequest                $request            Current HTTP request.
     * @param ShareTokenService       $shareTokenService  Token issue/revoke/list.
     * @param ManifestResolverService $manifestResolver   Production-manifest resolution.
     * @param ObjectService           $objectService      OpenRegister object service (ADR-022).
     * @param RegisterMapper          $registerMapper     Resolves the `openbuild` register slug.
     * @param SchemaMapper            $schemaMapper       Resolves the `application` schema slug.
     * @param IUserSession            $userSession        Current Nextcloud user session.
     * @param IGroupManager           $groupManager       Group membership resolver.
     * @param PermissionResolver      $permissionResolver Shared permission-grammar resolver.
     * @param LoggerInterface         $logger             PSR logger for diagnostics.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) One constructor-injected dependency
     *  per collaborator (standard NC DI); mirrors `ApplicationsController`'s identically-shaped
     *  constructor for the same slug-resolution + owner/editor RBAC responsibility.
     */
    public function __construct(
        IRequest $request,
        private readonly ShareTokenService $shareTokenService,
        private readonly ManifestResolverService $manifestResolver,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly PermissionResolver $permissionResolver,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/applications/{slug}/share-tokens — list tokens for an Application.
     *
     * @param string      $slug   The Application slug.
     * @param string|null $pageId Optional page-id filter (scopes the page-designer's in-context list).
     *
     * @return JSONResponse 200 with the token list, 403/404 on failure.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-token-management-ui-in-the-page-designer-and-app-settings
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $slug, ?string $pageId=null): JSONResponse
    {
        $resolved = $this->requireWriteAccess(slug: $slug);
        if ($resolved instanceof JSONResponse) {
            return $resolved;
        }

        $tokens = $this->shareTokenService->listForApplication(
            applicationUuid: $resolved['uuid'],
            pageId: $pageId
        );

        // Unlike the (already-hashed) `passwordHash` — stripped by the
        // service — the opaque `token` value IS re-exposed here, mirroring
        // OpenRegister's `CaseTokenService::listForObject()` precedent: an
        // owner reopening `ShareTokenDialog` days later needs to re-copy a
        // link without revoking + re-minting it. This is an owner/editor-
        // authenticated endpoint (requireWriteAccess() above), never the
        // anonymous public path.
        return new JSONResponse(data: ['tokens' => $tokens], statusCode: Http::STATUS_OK);
    }//end index()

    /**
     * POST /api/applications/{slug}/share-tokens — create a new share token.
     *
     * @param string $slug The Application slug.
     *
     * @return JSONResponse 201 with the created token (including the plaintext `token`), 400/403/404 on failure.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-sharetoken-schema-scopes-one-token-to-one-application-and-page
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-page-can-only-be-issued-a-token-when-its-config-declares-publicenabled
     */
    #[NoAdminRequired]
    public function create(string $slug): JSONResponse
    {
        $resolved = $this->requireWriteAccess(slug: $slug);
        if ($resolved instanceof JSONResponse) {
            return $resolved;
        }

        $pageId = (string) $this->request->getParam('pageId', '');
        if ($pageId === '') {
            return new JSONResponse(
                data: ['error' => 'bad_request', 'message' => 'pageId is required'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $mode = (string) $this->request->getParam('mode', 'submit');

        try {
            $created = $this->shareTokenService->issue(
                applicationUuid: $resolved['uuid'],
                applicationManifest: $resolved['manifest'],
                pageId: $pageId,
                mode: $mode,
                boundObjectId: $this->request->getParam('boundObjectId'),
                expiresAt: $this->request->getParam('expiresAt'),
                password: $this->request->getParam('password'),
                allowedPrefillFields: (array) $this->request->getParam('allowedPrefillFields', []),
                requireEmailVerification: ((bool) $this->request->getParam('requireEmailVerification', false))
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                data: ['error' => 'bad_request', 'message' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (Throwable $e) {
            $this->logger->error('ShareTokenController: create failed for slug '.$slug.': '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to create share token'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        return new JSONResponse(data: $created, statusCode: Http::STATUS_CREATED);
    }//end create()

    /**
     * DELETE /api/applications/{slug}/share-tokens/{tokenUuid} — revoke a token.
     *
     * Idempotent (REQ: "Revoking a token SHALL take effect immediately for
     * subsequent requests" — revoking an already-revoked token is a 200, not
     * an error, since the desired end state already holds).
     *
     * @param string $slug      The Application slug.
     * @param string $tokenUuid The ShareToken's own OR object UUID.
     *
     * @return JSONResponse 200 on revoke (or already-revoked), 403/404 on failure.
     *
     * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#scenario-revoked-token-stops-resolving
     */
    #[NoAdminRequired]
    public function revoke(string $slug, string $tokenUuid): JSONResponse
    {
        $resolved = $this->requireWriteAccess(slug: $slug);
        if ($resolved instanceof JSONResponse) {
            return $resolved;
        }

        $this->shareTokenService->revoke(tokenUuid: $tokenUuid);

        return new JSONResponse(data: ['status' => 'revoked'], statusCode: Http::STATUS_OK);
    }//end revoke()

    /**
     * Resolve `{slug}` to its Application + production manifest, enforcing the
     * SAME owner/editor-or-audited-admin-bypass RBAC as
     * `ApplicationsController::saveManifest()` (mirrored here rather than
     * shared, to keep this controller's authorization self-contained and
     * independently auditable per the fleet's semantic-auth gate).
     *
     * @param string $slug The Application slug.
     *
     * @return JSONResponse|array{uuid: string, manifest: array<string, mixed>} A 403/404 JSONResponse on failure, or the resolved tuple.
     */
    private function requireWriteAccess(string $slug): JSONResponse|array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'forbidden', 'code' => 'openbuild.rbac.no_role'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        try {
            $registerId = $this->registerMapper->find('openbuild', _multitenancy: false)->getId();
            $schemaId   = $this->schemaMapper->find('application', _multitenancy: false)->getId();

            $results = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'slug'  => $slug,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error('ShareTokenController: application lookup failed for slug '.$slug.': '.$e->getMessage(), ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'internal_error', 'message' => 'Failed to resolve application'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try

        if (empty($results) === true) {
            return new JSONResponse(
                data: ['error' => 'not_found', 'message' => 'Application not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $applicationArray = $this->normaliseObject(object: $results[0]);

        $hasWrite = $this->permissionResolver->matchesCaller(
            permissions: ($applicationArray['permissions'] ?? []),
            caller: $user,
            userGroups: $this->permissionResolver->resolveUserGroups($user),
            allowAdminBypass: false,
            roles: ['owners', 'editors']
        );

        if ($hasWrite === false && $this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === false) {
            return new JSONResponse(
                data: ['error' => 'forbidden', 'code' => 'openbuild.rbac.no_role'],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        $applicationUuid = (string) ($applicationArray['uuid'] ?? $applicationArray['id'] ?? '');
        $manifest        = $this->manifestResolver->resolveProductionManifestForApplication(
            application: $applicationArray,
            appSlug: $slug
        );

        if ($manifest === null) {
            $manifest = ['pages' => [], 'menu' => []];
        }

        return ['uuid' => $applicationUuid, 'manifest' => $manifest];
    }//end requireWriteAccess()

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
