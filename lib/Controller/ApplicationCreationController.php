<?php

/**
 * OpenBuilt ApplicationCreationController
 *
 * Single-endpoint controller for the app-creation wizard
 * (spec `openbuilt-app-creation-wizard`, REQ-OBWIZ-001 / REQ-OBWIZ-007).
 *
 * Endpoint: POST /apps/openbuilt/api/applications/wizard
 *
 * The endpoint is `#[NoAdminRequired]` — any authenticated Nextcloud user
 * may create a virtual app; the wizard service sets the caller as the sole
 * owner in the new Application's `permissions.owners` (REQ-OBWIZ-010).
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
use OCA\OpenBuilt\Exception\WizardCreationException;
use OCA\OpenBuilt\Service\ApplicationCreationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the four-step app-creation wizard.
 *
 * Single action: `wizard()` (POST /api/applications/wizard).
 */
class ApplicationCreationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request         The current HTTP request
     * @param LoggerInterface            $logger          PSR logger for diagnostics
     * @param ApplicationCreationService $creationService Atomic creation orchestrator
     * @param IUserSession               $userSession     Current Nextcloud user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly ApplicationCreationService $creationService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Execute the wizard payload and return the newly-created Application UUID.
     *
     * Returns 201 `{ "applicationUuid": "<uuid>" }` on success.
     * Returns 422 when the payload fails server-side validation.
     * Returns 500 with rollback details when creation fails mid-flight.
     * Returns 401 when the caller is not authenticated.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function wizard(): JSONResponse
    {
        // Require authentication.
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                data: ['error' => 'unauthenticated'],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        // Collect the JSON payload from the request body.
        $payload = $this->collectPayload();

        try {
            $applicationUuid = $this->creationService->createApplication(payload: $payload);

            return new JSONResponse(
                data: ['applicationUuid' => $applicationUuid],
                statusCode: Http::STATUS_CREATED
            );
        } catch (WizardCreationException $e) {
            // Decide HTTP status based on whether this was a validation failure
            // (failedAtStep=validate) or a mid-flight creation failure (500).
            $httpStatus = Http::STATUS_INTERNAL_SERVER_ERROR;
            if ($e->getFailedAtStep() === 'validate') {
                $httpStatus = Http::STATUS_UNPROCESSABLE_ENTITY;
            }

            $body = [
                'code'           => $e->getErrorCode(),
                'failedAtStep'   => $e->getFailedAtStep(),
                'message'        => $e->getMessage(),
                'rollbackStatus' => $e->getRollbackStatus(),
            ];

            if ($e->getOrphanedResources() !== []) {
                $body['orphanedResources'] = $e->getOrphanedResources();
            }

            return new JSONResponse(data: $body, statusCode: $httpStatus);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: ApplicationCreationController::wizard unhandled exception: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: [
                    'code'           => 'wizard_rollback',
                    'failedAtStep'   => 'unknown',
                    'message'        => $e->getMessage(),
                    'rollbackStatus' => 'unknown',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end wizard()

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
}//end class
