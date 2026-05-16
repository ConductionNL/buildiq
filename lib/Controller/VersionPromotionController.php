<?php

/**
 * OpenBuilt VersionPromotionController
 *
 * REST surface for the manual promotion flow described in
 * `openbuilt-version-promotion` / ADR-002. Exposes a single endpoint:
 *
 *   POST /index.php/apps/openbuilt/api/applications/{appUuid}/versions/{versionUuid}/promote
 *
 * Body: `{ "strategy": "start-with-source-data" | "migrate-existing-data" |
 *          "empty-start", "confirmAppSlug": "<slug>" }`.
 *
 * The endpoint carries `#[NoAdminRequired]` per spec REQ-OBVP-001 — the
 * authorisation check is per-Application RBAC (owners + editors only),
 * NOT Nextcloud admin (deliberate constraint per spec REQ-OBVP-007).
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
use OCA\OpenBuilt\Exception\InsufficientPermissionException;
use OCA\OpenBuilt\Exception\InvalidStrategyException;
use OCA\OpenBuilt\Exception\NoPromoteTargetException;
use OCA\OpenBuilt\Exception\PromotionFailedException;
use OCA\OpenBuilt\Exception\VersionLockedException;
use OCA\OpenBuilt\Service\VersionPromotionService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing the promotion endpoint.
 */
class VersionPromotionController extends Controller
{
    /**
     * Roles allowed to invoke promotion (spec REQ-OBVP-007).
     *
     * @var array<int,string>
     */
    private const WRITE_ROLES = ['owners', 'editors'];

    /**
     * Constructor.
     *
     * @param IRequest                $request          The current HTTP request
     * @param LoggerInterface         $logger           PSR logger
     * @param ObjectService           $objectService    OR object surface (load source + parent)
     * @param IUserSession            $userSession      Current NC user session
     * @param VersionPromotionService $promotionService Imperative promotion flow owner
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly VersionPromotionService $promotionService,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Promote a source ApplicationVersion to its `promotesTo` neighbour.
     *
     * Wired in `appinfo/routes.php` under name `VersionPromotion#promote`.
     * Annotated `#[NoAdminRequired]` per spec REQ-OBVP-001 — auth is
     * per-Application RBAC, not Nextcloud admin (spec REQ-OBVP-007).
     *
     * @param string $appUuid     Parent Application UUID (path param)
     * @param string $versionUuid Source ApplicationVersion UUID (path param)
     *
     * @return JSONResponse 200 + updated target on success, error envelope otherwise
     */
    #[NoAdminRequired]
    public function promote(string $appUuid, string $versionUuid): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        try {
            $application = $this->loadApplication(uuid: $appUuid);
            if ($application === null) {
                return $this->errorResponse(
                    code: 'not_found',
                    detail: 'Application '.$appUuid.' not found',
                    status: Http::STATUS_NOT_FOUND
                );
            }

            $source = $this->loadVersion(uuid: $versionUuid);
            if ($source === null) {
                return $this->errorResponse(
                    code: 'not_found',
                    detail: 'ApplicationVersion '.$versionUuid.' not found',
                    status: Http::STATUS_NOT_FOUND
                );
            }

            // IDOR-safe — verify the source's parent matches the URL appUuid.
            if ((string) ($source['application'] ?? '') !== $appUuid) {
                return $this->errorResponse(
                    code: 'not_found',
                    detail: 'ApplicationVersion '.$versionUuid.' does not belong to Application '.$appUuid,
                    status: Http::STATUS_NOT_FOUND
                );
            }

            // RBAC check (spec REQ-OBVP-007) — owners + editors only;
            // NC admins NOT auto-granted.
            $this->assertEditorOrOwner(application: $application, user: $user);

            $strategy = (string) $this->request->getParam('strategy', '');

            $updated = $this->promotionService->promote(source: $source, strategy: $strategy);
            return new JSONResponse(data: $updated, statusCode: Http::STATUS_OK);
        } catch (Throwable $e) {
            return $this->mapExceptionToResponse(error: $e);
        }//end try
    }//end promote()

    /**
     * Map a thrown exception to its HTTP response.
     *
     * Split out from {@see promote()} to keep the controller method below
     * PHPMD's cyclomatic-complexity threshold and to keep the response
     * mapping in one auditable surface (spec REQ-OBVP-001..-009).
     *
     * @param Throwable $error The thrown exception
     *
     * @return JSONResponse Error envelope with the spec-defined code + status
     */
    private function mapExceptionToResponse(Throwable $error): JSONResponse
    {
        if ($error instanceof NoPromoteTargetException) {
            $this->logger->info('OpenBuilt: promote rejected (no target): '.$error->getMessage());
            return $this->errorResponse(
                code: $error->getErrorCode(),
                detail: $error->getMessage(),
                status: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        if ($error instanceof InvalidStrategyException) {
            $this->logger->info('OpenBuilt: promote rejected (invalid strategy): '.$error->getMessage());
            return $this->errorResponse(
                code: $error->getErrorCode(),
                detail: $error->getMessage(),
                status: Http::STATUS_BAD_REQUEST
            );
        }

        if ($error instanceof VersionLockedException) {
            return $this->buildLockedResponse(error: $error);
        }

        if ($error instanceof InsufficientPermissionException) {
            $this->logger->info('OpenBuilt: promote rejected (rbac): '.$error->getMessage());
            return $this->errorResponse(
                code: $error->getErrorCode(),
                detail: $error->getMessage(),
                status: Http::STATUS_FORBIDDEN
            );
        }

        if ($error instanceof PromotionFailedException) {
            $this->logger->error('OpenBuilt: promotion failed (500): '.$error->getMessage(), ['exception' => $error]);
            return new JSONResponse(
                data: [
                    'error'    => $error->getErrorCode(),
                    'strategy' => $error->getStrategy(),
                    'message'  => $error->getMessage(),
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        $this->logger->error(
            'OpenBuilt: VersionPromotionController::promote unexpected failure: '.$error->getMessage(),
            ['exception' => $error]
        );
        return $this->errorResponse(
            code: 'internal_error',
            detail: $error->getMessage(),
            status: Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }//end mapExceptionToResponse()

    /**
     * Build the 409 response for a VersionLockedException with optional lock context.
     *
     * @param VersionLockedException $error The contention exception
     *
     * @return JSONResponse
     */
    private function buildLockedResponse(VersionLockedException $error): JSONResponse
    {
        $this->logger->info('OpenBuilt: promote rejected (locked): '.$error->getMessage());
        $body = [
            'error'  => $error->getErrorCode(),
            'detail' => $error->getMessage(),
        ];

        if ($error->getLockedBy() !== null) {
            $body['lockedBy'] = $error->getLockedBy();
        }

        if ($error->getExpiresAt() !== null) {
            $body['expiresAt'] = $error->getExpiresAt();
        }

        return new JSONResponse(data: $body, statusCode: Http::STATUS_CONFLICT);
    }//end buildLockedResponse()

    /**
     * Load the parent Application by UUID via OR's object service.
     *
     * @param string $uuid Application UUID
     *
     * @return array<string,mixed>|null
     */
    private function loadApplication(string $uuid): ?array
    {
        $entity = $this->objectService->find(
            id: $uuid,
            register: VersionPromotionService::REGISTER_SLUG,
            schema: VersionPromotionService::APPLICATION_SCHEMA
        );

        if ($entity === null) {
            return null;
        }

        return $this->normaliseObject(object: $entity);
    }//end loadApplication()

    /**
     * Load an ApplicationVersion by UUID via OR's object service.
     *
     * @param string $uuid ApplicationVersion UUID
     *
     * @return array<string,mixed>|null
     */
    private function loadVersion(string $uuid): ?array
    {
        $entity = $this->objectService->find(
            id: $uuid,
            register: VersionPromotionService::REGISTER_SLUG,
            schema: VersionPromotionService::APPLICATION_VERSION_SCHEMA
        );

        if ($entity === null) {
            return null;
        }

        return $this->normaliseObject(object: $entity);
    }//end loadVersion()

    /**
     * Verify the calling user holds `owners` or `editors` role on the Application.
     *
     * Spec REQ-OBVP-007 — NC admins are NOT auto-granted. The check is
     * performed in-controller (NOT via `#[AuthorizedAdminSetting]`) so it
     * does not collide with the `#[NoAdminRequired]` annotation.
     *
     * @param array<string,mixed> $application Application data
     * @param IUser               $user        Current Nextcloud user
     *
     * @return void
     *
     * @throws InsufficientPermissionException When the user lacks owner/editor role
     */
    private function assertEditorOrOwner(array $application, IUser $user): void
    {
        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            throw new InsufficientPermissionException(
                message: 'Application has no permissions block; no caller may promote.'
            );
        }

        $uid = $user->getUID();
        foreach (self::WRITE_ROLES as $role) {
            $bucket = ($permissions[$role] ?? []);
            if (is_array($bucket) === false) {
                continue;
            }

            foreach ($bucket as $principal) {
                if (is_string($principal) === false) {
                    continue;
                }

                if ($principal === 'user:'.$uid || $principal === $uid) {
                    return;
                }
            }
        }

        throw new InsufficientPermissionException(
            message: 'User '.$uid.' is not an owner or editor on Application '
                .(string) ($application['slug'] ?? ($application['id'] ?? '?'))
        );
    }//end assertEditorOrOwner()

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
}//end class
