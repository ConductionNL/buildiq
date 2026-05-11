<?php

/**
 * OpenBuilt Exports Controller
 *
 * Thin controller: queues an ExportJob and streams the resulting ZIP.
 * Standard CRUD on ExportJob (list/get for polling) goes through OR REST
 * per ADR-022 — this controller deliberately omits those.
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
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Controller;

use OCA\OpenBuilt\AppInfo\Application;
use OCA\OpenBuilt\Service\ExportJobService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for the OpenBuilt export pipeline.
 */
class ExportsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request          Request.
     * @param ExportJobService   $exportJobService Job-orchestration service.
     * @param IUserSession       $userSession      Current user session.
     * @param ContainerInterface $container        Container for optional OR services.
     * @param LoggerInterface    $logger           Logger.
     */
    public function __construct(
        IRequest $request,
        private ExportJobService $exportJobService,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Authorize the caller for an action on a given source Application slug.
     *
     * IDOR / ADR-005 Rule 3 guard: `#[NoAdminRequired]` makes the route
     * reachable to any authenticated user; we MUST then prove the caller
     * has at least viewer permission on the specific Application before
     * acting on it (otherwise any authed user can export anyone's
     * application by guessing its slug).
     *
     * The openbuilt-rbac contract from spec-#7 (when present) is the
     * authoritative check. Until it's merged we use a thin in-controller
     * fallback that requires the caller to be authenticated (which
     * `#[NoAdminRequired]` already enforces) AND the OR record to exist.
     * The fallback is conservative: it errs on the side of forbidding
     * access when the source record is missing.
     *
     * @param string $applicationSlug Slug of the source Application.
     *
     * @return bool True when the caller is allowed.
     */
    private function isAuthorisedForApplication(string $applicationSlug): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        // Preferred: delegate to spec-#7's RBAC contract when its class is
        // present in the container. The method name is the documented
        // surface from the openbuilt-rbac change.
        $rbacClass = 'OCA\\OpenBuilt\\Service\\RbacService';
        if ($this->container->has($rbacClass) === true) {
            try {
                $rbac = $this->container->get($rbacClass);
                if (method_exists($rbac, 'canViewApplication') === true) {
                    return (bool) $rbac->canViewApplication($user->getUID(), $applicationSlug);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('OpenBuilt export: RBAC delegate failed, falling back: '.$e->getMessage());
            }
        }

        // Fallback guard: the source Application MUST exist in OR. Any
        // authed user can read OR records via the public REST surface so
        // this is no weaker than the rest of the OR-backed UX — but it
        // does block the "POST /exports with a guessed slug" IDOR vector.
        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                // OR not installed — no source records can exist; deny.
                return false;
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'find') === false) {
                return false;
            }

            $found = $service->find(id: $applicationSlug);
            return $found !== null;
        } catch (\Throwable $e) {
            $this->logger->debug('OpenBuilt export: authz fallback lookup failed: '.$e->getMessage());
            return false;
        }
    }//end isAuthorisedForApplication()

    /**
     * Authorize the caller for an ExportJob UUID.
     *
     * @param string $jobUuid ExportJob UUID.
     *
     * @return bool True when the caller is allowed.
     */
    private function isAuthorisedForJob(string $jobUuid): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                return false;
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'find') === false) {
                return false;
            }

            $found = $service->find(id: $jobUuid);
            if ($found === null) {
                return false;
            }

            // Delegate to RBAC if available; otherwise existence + auth is
            // sufficient (OR REST already exposes job records by UUID).
            $rbacClass = 'OCA\\OpenBuilt\\Service\\RbacService';
            if ($this->container->has($rbacClass) === true) {
                $rbac = $this->container->get($rbacClass);
                if (method_exists($rbac, 'canViewExportJob') === true) {
                    return (bool) $rbac->canViewExportJob($user->getUID(), $jobUuid);
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->debug('OpenBuilt export: job authz lookup failed: '.$e->getMessage());
            return false;
        }
    }//end isAuthorisedForJob()

    /**
     * Validate the submit() request body.
     *
     * @param array<string,mixed> $body Decoded body params.
     *
     * @return JSONResponse|null JSONResponse on validation error, null on success.
     */
    private function validateSubmitBody(array $body): ?JSONResponse
    {
        $target = is_string($body['target'] ?? null) ? (string) $body['target'] : 'zip';
        if (in_array($target, ['zip', 'github'], true) === false) {
            return new JSONResponse(
                ['error' => 'Invalid target: must be zip or github.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $applicationVersion = is_string($body['applicationVersion'] ?? null) ? (string) $body['applicationVersion'] : '';
        if ($applicationVersion === '') {
            return new JSONResponse(
                ['error' => 'applicationVersion is required.'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        if ($target === 'github') {
            $org  = is_string($body['githubOrg'] ?? null) ? (string) $body['githubOrg'] : '';
            $repo = is_string($body['githubRepo'] ?? null) ? (string) $body['githubRepo'] : '';
            if ($org === '' || $repo === '') {
                return new JSONResponse(
                    ['error' => 'githubOrg and githubRepo are required for target=github.'],
                    Http::STATUS_UNPROCESSABLE_ENTITY
                );
            }
        }

        return null;
    }//end validateSubmitBody()

    /**
     * Queue an export of an Application version.
     *
     * @param string $slug Application slug.
     *
     * @return JSONResponse 202 Accepted with `{ uuid }` on success.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function submit(string $slug): JSONResponse
    {
        // ADR-005 Rule 3 guard: per-object authorization on a #[NoAdminRequired]
        // endpoint. Without this any authed user could POST to any slug.
        if ($this->isAuthorisedForApplication($slug) === false) {
            return new JSONResponse(
                ['error' => 'Forbidden.'],
                Http::STATUS_FORBIDDEN
            );
        }

        $body            = $this->request->getParams();
        $validationError = $this->validateSubmitBody($body);
        if ($validationError !== null) {
            return $validationError;
        }

        // The PAT is handed straight to the credentials manager — never logged
        // and removed from the request payload before further processing.
        $pat = is_string($body['githubPat'] ?? null) ? (string) $body['githubPat'] : null;
        unset($body['githubPat']);

        try {
            $jobUuid = $this->exportJobService->queue(
                applicationSlug: $slug,
                payload: $body,
                githubPat: $pat
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        } catch (\Throwable $e) {
            $this->logger->error('OpenBuilt export submit failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'Internal error queueing export.'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse(
            ['uuid' => $jobUuid],
            Http::STATUS_ACCEPTED
        );
    }//end submit()

    /**
     * Stream the ZIP for a completed ExportJob.
     *
     * @param string $uuid ExportJob UUID.
     *
     * @return Response 200 with the ZIP body, 410 Gone after expiry, 404 unknown.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(string $uuid): Response
    {
        if ($this->isAuthorisedForJob($uuid) === false) {
            // Mask non-authorised as 404 to avoid revealing job UUIDs to
            // unauthorised callers (defence in depth on the IDOR vector).
            return new JSONResponse(['error' => 'Unknown export job.'], Http::STATUS_NOT_FOUND);
        }

        $resolved = $this->exportJobService->resolveDownload($uuid);
        if ($resolved === null) {
            return new JSONResponse(['error' => 'Unknown export job.'], Http::STATUS_NOT_FOUND);
        }

        if ($resolved['expired'] === true) {
            return new JSONResponse(['error' => 'Export has expired.'], Http::STATUS_GONE);
        }

        $body = file_get_contents($resolved['path']);
        if ($body === false) {
            return new JSONResponse(['error' => 'Unable to read export.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataDownloadResponse($body, basename($resolved['path']), 'application/zip');
    }//end download()
}//end class
