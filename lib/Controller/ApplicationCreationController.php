<?php

/**
 * Buildiq ApplicationCreationController
 *
 * Single-endpoint controller for the app-creation wizard
 * (spec `buildiq-app-creation-wizard`, REQ-OBWIZ-001 / REQ-OBWIZ-007).
 *
 * Endpoint: POST /apps/buildiq/api/applications/wizard
 *
 * The endpoint is ADMIN-ONLY (issue #157): app creation provisions an OR
 * Register, mirroring OR's admin-only RegistersController gate (OR #1949).
 * The wizard service sets the caller as the sole owner in the new
 * Application's `permissions.owners` (REQ-OBWIZ-010).
 *
 * That posture is declared at BOTH layers:
 *
 *  - `#[AuthorizedAdminSetting(AdminSettings::class)]`, so NC's
 *    SecurityMiddleware refuses before dispatch, and
 *  - the `IGroupManager::isAdmin()` gate in the body, as defence in depth.
 *
 * The method used to carry `#[NoAdminRequired]`, which does not ADD a layer —
 * it REMOVES one. It tells the middleware "any logged-in user may reach this",
 * leaving the body check as the only thing between a regular user and register
 * provisioning. Hydra gate-9 (semantic-auth) flags exactly that contradiction,
 * and SetupController was corrected the same way in #127.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-8
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-15
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Exception\WizardCreationException;
use OCA\Buildiq\Service\ApplicationCreationService;
use OCA\Buildiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the four-step app-creation wizard.
 *
 * Single action: `wizard()` (POST /api/applications/wizard).
 */
class ApplicationCreationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param ApplicationCreationService $creationService Atomic creation orchestrator
	 * @param IUserSession $userSession Current Nextcloud user session
	 * @param IGroupManager $groupManager Group membership resolver
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly ApplicationCreationService $creationService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Execute the wizard payload and return the newly-created Application UUID.
	 *
	 * Returns 201 `{ "applicationUuid": "<uuid>" }` on success.
	 * Returns 403 when the caller is not an NC admin (issue #157).
	 * Returns 422 when the payload fails server-side validation.
	 * Returns 500 with rollback details when creation fails mid-flight.
	 * Returns 401 when the caller is not authenticated.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-8
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-15
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	#[UserRateLimit(limit: 10, period: 3600)]
	public function wizard(): JSONResponse {
		// Require authentication.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'unauthenticated'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		// Creating a virtual app provisions an OR Register, which mirrors the
		// admin-only gate on OR's RegistersController (OR #1949). Non-admin
		// users who have been denied register-create rights in OR must not be
		// able to regain that privilege via buildiq (issue #157).
		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				data: ['error' => 'forbidden', 'message' => 'Creating virtual apps requires Nextcloud admin privileges.'],
				statusCode: Http::STATUS_FORBIDDEN
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
				'code' => $e->getErrorCode(),
				'failedAtStep' => $e->getFailedAtStep(),
				'message' => $e->getMessage(),
				'rollbackStatus' => $e->getRollbackStatus(),
			];

			if ($e->getOrphanedResources() !== []) {
				$body['orphanedResources'] = $e->getOrphanedResources();
			}

			return new JSONResponse(data: $body, statusCode: $httpStatus);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: ApplicationCreationController::wizard unhandled exception: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: [
					'code' => 'wizard_rollback',
					'failedAtStep' => 'unknown',
					'message' => $e->getMessage(),
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
	private function collectPayload(): array {
		$params = $this->request->getParams();
		unset($params['_route']);
		return $params;
	}//end collectPayload()
}//end class
