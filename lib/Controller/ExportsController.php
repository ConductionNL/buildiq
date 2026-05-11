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
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the OpenBuilt export pipeline.
 */
class ExportsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          Request.
     * @param ExportJobService $exportJobService Job-orchestration service.
     * @param LoggerInterface  $logger           Logger.
     */
    public function __construct(
        IRequest $request,
        private ExportJobService $exportJobService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Queue an export of an Application version.
     *
     * @param string $slug Application slug.
     *
     * @return JSONResponse 202 Accepted with `{ uuid }` on success.
     *
     * @NoAdminRequired
     */
    public function submit(string $slug): JSONResponse
    {
        $body = $this->request->getParams();

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
     *
     * @NoAdminRequired
     */
    public function download(string $uuid): Response
    {
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
