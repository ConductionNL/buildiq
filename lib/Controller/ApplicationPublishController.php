<?php

/**
 * OpenBuild Application Publish Controller
 *
 * Exposes the explicit publish / unpublish action that flips an Application's
 * `status` between `draft` and `published`. Only a `published` Application is
 * surfaced in the Nextcloud top-bar app menu (AppNavigationService gates on
 * `status == published`), so publishing is the deliberate "go live" step for a
 * virtual app.
 *
 * Authorisation is per-Application RBAC — OWNERS only (editors may save drafts
 * but not publish, per the Application schema), with the Nextcloud admin bypass.
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
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Exception\InsufficientPermissionException;
use OCA\OpenBuild\Service\ApplicationDeletionService;
use OCA\OpenBuild\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenBuild\Service\VersionPromotionService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing the Application publish / unpublish endpoints.
 */
class ApplicationPublishController extends Controller
{
    /**
     * Roles allowed to publish/unpublish — owners only (editors cannot publish).
     *
     * @var array<int,string>
     */
    private const PUBLISH_ROLES = ['owners'];

    /**
     * Application status indicating the app is live (listed in the app menu).
     */
    private const STATUS_PUBLISHED = 'published';

    /**
     * Application status indicating the app is hidden from the app menu.
     */
    private const STATUS_DRAFT = 'draft';

    /**
     * Constructor.
     *
     * @param IRequest                      $request             The current HTTP request
     * @param LoggerInterface               $logger              PSR logger
     * @param ObjectService                 $objectService       OR object surface (load + save)
     * @param IUserSession                  $userSession         Current NC user session
     * @param PermissionResolver            $permissionResolver  Shared permission-grammar resolver
     * @param ApplicationDeletionService    $deletionService     Full-teardown service for delete
     * @param VirtualAppCredentialRegistrar $credentialRegistrar Onboards a published credentials[]-declaring app to the broker
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
        private readonly PermissionResolver $permissionResolver,
        private readonly ApplicationDeletionService $deletionService,
        private readonly VirtualAppCredentialRegistrar $credentialRegistrar,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Publish an Application (status → published), making it appear in the menu.
     *
     * Wired in `appinfo/routes.php` under name `applicationPublish#publish`.
     * `#[NoAdminRequired]` — auth is per-Application owner RBAC, not NC admin.
     *
     * @param string $appUuid Parent Application UUID (path param)
     *
     * @return JSONResponse 200 + updated application, or an error envelope
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function publish(string $appUuid): JSONResponse
    {
        return $this->setStatus(appUuid: $appUuid, status: self::STATUS_PUBLISHED);
    }//end publish()

    /**
     * Unpublish an Application (status → draft), removing it from the menu.
     *
     * Wired in `appinfo/routes.php` under name `applicationPublish#unpublish`.
     *
     * @param string $appUuid Parent Application UUID (path param)
     *
     * @return JSONResponse 200 + updated application, or an error envelope
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function unpublish(string $appUuid): JSONResponse
    {
        return $this->setStatus(appUuid: $appUuid, status: self::STATUS_DRAFT);
    }//end unpublish()

    /**
     * Delete an Application and the app wrapper it owns (versions, routes). When
     * $deleteData is true, also delete the per-version registers and all their
     * data; otherwise that data is preserved. Owner-only (admin bypass). Wired
     * as `applicationPublish#destroy`.
     *
     * @param string $appUuid    Parent Application UUID (path param)
     * @param bool   $deleteData When true, also delete the underlying registers
     *                           and every object stored in them (query param)
     *
     * @return JSONResponse 200 + `{deleted, orphanedResources}`, or an error envelope
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function destroy(string $appUuid, bool $deleteData = false): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'unauthenticated'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $application = $this->loadApplication(uuid: $appUuid);
            if ($application === null) {
                return new JSONResponse(
                    data: ['error' => 'not_found', 'detail' => 'Application '.$appUuid.' not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $this->assertOwner(application: $application, user: $user);

            $orphaned = $this->deletionService->deleteApplication(
                appUuid: $appUuid,
                appSlug: (string) ($application['slug'] ?? ''),
                deleteData: $deleteData
            );

            return new JSONResponse(
                data: ['deleted' => true, 'orphanedResources' => $orphaned],
                statusCode: Http::STATUS_OK
            );
        } catch (InsufficientPermissionException $e) {
            return new JSONResponse(
                data: ['error' => 'forbidden', 'detail' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: delete failed for {uuid}: {message}',
                ['uuid' => $appUuid, 'message' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => 'internal_error', 'detail' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end destroy()

    /**
     * Flip an Application's status after an owner-only RBAC check.
     *
     * @param string $appUuid The Application UUID.
     * @param string $status  Target status (`published` or `draft`).
     *
     * @return JSONResponse
     */
    private function setStatus(string $appUuid, string $status): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                data: ['error' => 'unauthenticated'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $application = $this->loadApplication(uuid: $appUuid);
            if ($application === null) {
                return new JSONResponse(
                    data: ['error' => 'not_found', 'detail' => 'Application '.$appUuid.' not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $this->assertOwner(application: $application, user: $user);

            // Strip the OR-managed metadata envelope before writing back — the
            // save path expects the bare object data + the uuid (mirrors how
            // ApplicationCreationService updates the Application).
            unset($application['@self']);
            $application['status'] = $status;
            $saved = $this->objectService->saveObject(
                object: $application,
                register: VersionPromotionService::REGISTER_SLUG,
                schema: VersionPromotionService::APPLICATION_SCHEMA,
                uuid: $appUuid
            );

            // On go-live, onboard the app to the credential broker when its
            // manifest declares credentials[]. Best-effort + never-throw — a
            // broker/Doriath hiccup must not fail the publish (see registrar).
            if ($status === self::STATUS_PUBLISHED) {
                $slug = (string) ($application['slug'] ?? '');
                if ($slug !== '') {
                    $this->credentialRegistrar->onPublish(slug: $slug, caller: $user);
                }
            }

            return new JSONResponse(
                data: [
                    'status'      => $status,
                    'application' => $this->normaliseObject(object: $saved),
                ],
                statusCode: Http::STATUS_OK
            );
        } catch (InsufficientPermissionException $e) {
            return new JSONResponse(
                data: ['error' => 'forbidden', 'detail' => $e->getMessage()],
                statusCode: Http::STATUS_FORBIDDEN
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: publish/unpublish failed for {uuid}: {message}',
                ['uuid' => $appUuid, 'message' => $e->getMessage(), 'exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => 'internal_error', 'detail' => $e->getMessage()],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end setStatus()

    /**
     * Load the Application object by UUID.
     *
     * @param string $uuid Application UUID.
     *
     * @return array<string,mixed>|null Normalised application array, or null when absent.
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
     * Assert the caller holds the `owners` role on the Application (admin bypass allowed).
     *
     * @param array<string,mixed> $application The Application object.
     * @param IUser               $user        The calling user.
     *
     * @return void
     *
     * @throws InsufficientPermissionException When the caller is not an owner/admin.
     */
    private function assertOwner(array $application, IUser $user): void
    {
        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            $permissions = [];
        }

        $userGroups = $this->permissionResolver->resolveUserGroups($user);
        $allowed    = $this->permissionResolver->matchesCaller(
            permissions: $permissions,
            caller: $user,
            userGroups: $userGroups,
            allowAdminBypass: true,
            roles: self::PUBLISH_ROLES
        );

        if ($allowed === true) {
            return;
        }

        throw new InsufficientPermissionException(
            message: 'User '.$user->getUID().' is not an owner of Application '
                .(string) ($application['slug'] ?? ($application['id'] ?? '?'))
        );
    }//end assertOwner()

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
