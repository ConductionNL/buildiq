<?php

/**
 * Buildiq App Health Controller
 *
 * Takes the health samples a running built app posts and serves a version's
 * health to the app detail page.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\AppHealthService;
use OCA\Buildiq\Service\ApplicationInsightsService;
use OCA\Buildiq\Service\ManifestResolverService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Health samples in, a version's health out.
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md
 */
class AppHealthController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession The session.
	 * @param ManifestResolverService $manifestResolver Who may open which version.
	 * @param ApplicationInsightsService $insights Who may see a version's insights.
	 * @param AppHealthService $health Stores and reads the samples.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ManifestResolverService $manifestResolver,
		private readonly ApplicationInsightsService $insights,
		private readonly AppHealthService $health,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Store a running app's samples. The app and version come from the slug and `_version`;
	 * a caller who may not open that version gets the unknown-app answer and nothing is stored.
	 *
	 * @param string $slug The app slug.
	 *
	 * @return JSONResponse `{stored}`, or 404.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function record(string $slug): JSONResponse {
		$versionSlug = $this->request->getParam('_version');
		if (is_string($versionSlug) === false) {
			$versionSlug = null;
		}

		$target = $this->manifestResolver->resolveVersionForCaller(
			appSlug: $slug,
			versionSlug: $versionSlug,
			caller: $this->userSession->getUser()
		);
		if ($target === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$stored = $this->health->record(
			applicationUuid: $target['applicationUuid'],
			versionUuid: $target['versionUuid'],
			pages: $this->request->getParam('pages', [])
		);

		return new JSONResponse(['stored' => $stored]);
	}//end record()

	/**
	 * A version's health over `?window=` (7d, 30d, 90d), for whoever may see its insights.
	 *
	 * @param string $appUuid The application.
	 * @param string $versionUuid The version.
	 *
	 * @return JSONResponse The aggregate, 400 for an unknown window, 404 otherwise.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
	 */
	#[NoAdminRequired]
	public function show(string $appUuid, string $versionUuid): JSONResponse {
		$window = (string)$this->request->getParam('window', '7d');
		if (isset(AppHealthService::WINDOW_DAYS[$window]) === false) {
			return new JSONResponse(['error' => 'window must be 7d, 30d or 90d'], Http::STATUS_BAD_REQUEST);
		}

		$resolved = $this->insights->requireAuthorisedCaller(
			appUuid: $appUuid,
			versionUuid: $versionUuid,
			caller: $this->userSession->getUser()
		);
		if ($resolved === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->health->computeHealth(applicationUuid: $appUuid, versionUuid: $versionUuid, window: $window));
	}//end show()
}//end class
