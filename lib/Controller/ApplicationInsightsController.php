<?php

/**
 * Buildiq ApplicationInsightsController
 *
 * REST surface for the maintainer-dashboard insights endpoint described
 * in `buildiq-app-detail-overview` / capability `application-insights`:
 *
 *   GET /index.php/apps/buildiq/api/applications/{appUuid}/versions/{versionUuid}/insights?window=7d|30d|90d
 *
 * Returns `{kpis, activity}` for a single ApplicationVersion (REQ-OBAI-001).
 * Successful responses carry `Cache-Control: public, max-age=60`
 * (REQ-OBAI-006). Auth gate (REQ-OBAI-002) lives inside
 * `ApplicationInsightsService`; the controller is `#[NoAdminRequired]` and
 * delegates the RBAC decision to the service so it is testable in
 * isolation.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-17
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-21
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\ApplicationInsightsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Insights endpoint controller.
 */
class ApplicationInsightsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request
	 * @param IUserSession $userSession Current NC user session
	 * @param ApplicationInsightsService $service Insights aggregation owner
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ApplicationInsightsService $service,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the insights payload for an Application + ApplicationVersion.
	 *
	 * Wired in `appinfo/routes.php` under name `applicationInsights#getInsights`.
	 * Annotated `#[NoAdminRequired]` per spec REQ-OBAI-002 — auth is per-
	 * Application RBAC inside the service, not Nextcloud admin.
	 *
	 * Status codes:
	 *   - 200 + payload + `Cache-Control: public, max-age=60` on success
	 *   - 400 on missing or invalid `window` value
	 *   - 404 on unknown app/version, IDOR mismatch, or RBAC denial
	 *
	 * @param string $appUuid Parent Application UUID (path param).
	 * @param string $versionUuid ApplicationVersion UUID (path param).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-17
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-21
	 */
	#[NoAdminRequired]
	public function getInsights(string $appUuid, string $versionUuid): JSONResponse {
		$window = (string)$this->request->getParam('window', '');
		if (in_array($window, ApplicationInsightsService::ALLOWED_WINDOWS, true) === false) {
			return new JSONResponse(
				data: [
					'status' => Http::STATUS_BAD_REQUEST,
					'message' => 'Invalid window parameter; expected one of: 7d, 30d, 90d',
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$caller = $this->userSession->getUser();

		// RBAC guard — REQ-OBAI-002. Mirrors the service's internal check
		// (defence in depth + hydra gate-7 explicit-controller-guard rule).
		// Returns null for unknown app, unknown version, IDOR mismatch, OR
		// RBAC denial; all map to 404 here (no existence leak).
		$resolved = $this->service->requireAuthorisedCaller(
			appUuid: $appUuid,
			versionUuid: $versionUuid,
			caller: $caller
		);
		if ($resolved === null) {
			return new JSONResponse(
				data: ['status' => Http::STATUS_NOT_FOUND, 'message' => 'Not Found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$payload = $this->service->computeInsights(
			appUuid: $appUuid,
			versionUuid: $versionUuid,
			window: $window,
			caller: $caller
		);

		if ($payload === null) {
			return new JSONResponse(
				data: ['status' => Http::STATUS_NOT_FOUND, 'message' => 'Not Found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$response = new JSONResponse(data: $payload, statusCode: Http::STATUS_OK);
		// `private` (not `public`): this payload is per-caller RBAC-gated, so it
		// must never sit in a shared cache. Short max-age keeps the maintainer
		// dashboard fresh and lets a transiently-stale value self-heal quickly.
		$response->addHeader('Cache-Control', 'private, max-age=10');
		return $response;
	}//end getInsights()
}//end class
