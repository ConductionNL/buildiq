<?php

/**
 * OpenBuild Settings Controller
 *
 * Controller for managing OpenBuild application settings.
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
use OCA\OpenBuild\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for managing OpenBuild application settings.
 */
class SettingsController extends Controller {
	/**
	 * Nextcloud admin group identifier used as the admin-check anchor (H6).
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * Constructor for the SettingsController.
	 *
	 * @param IRequest $request The request object.
	 * @param SettingsService $settingsService The settings service.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group membership resolver for admin guard.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Retrieve all current settings.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-1
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			$this->settingsService->getSettings()
		);
	}//end index()

	/**
	 * Update settings with provided data — the canonical write.
	 *
	 * `\OCA\OpenRegister\AppHost\Routes::standard()` (invoked from
	 * `appinfo/routes.php`) ships `PUT /api/settings` as `settings#update`, and
	 * `AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()` only substitutes
	 * OpenRegister's `GenericSettingsController` when the leaf app does NOT ship
	 * a class of that name. OpenBuild DOES ship this class, so the alias is
	 * skipped and every canonical method routed to `settings#` must exist HERE.
	 * A missing one is not a 404 — the router matches the URL and the dispatcher
	 * reflects the method, so the request dies with a 500 (measured 2026-08-08:
	 * `PUT /apps/openbuild/api/settings` → 500, `ReflectionException: Method
	 * OCA\OpenBuild\Controller\SettingsController::update() does not exist`).
	 *
	 * Persists only the whitelisted `CONFIG_KEYS` / `SECRET_KEYS` (the service
	 * ignores anything else) and returns the freshly re-read settings array, so
	 * the caller sees what was actually stored rather than what it submitted.
	 *
	 * Admin-only: writing OpenBuild configuration affects all users on the
	 * instance; non-admin callers receive 403 (H6 guard). The guard lives in
	 * the method body rather than in an `#[AuthorizedAdminSetting]` attribute
	 * to match `index()`/`load()`'s established posture — Nextcloud's
	 * SecurityMiddleware only evaluates attributes on the DISPATCHED method, so
	 * `#[NoAdminRequired]` alone would leave this admin write open to any
	 * authenticated user.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/settings-and-observability/spec.md#req-obs-002
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === false) {
			return new JSONResponse(['error' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
		}

		$data = $this->request->getParams();
		$config = $this->settingsService->updateSettings($data);

		return new JSONResponse(
			[
				'success' => true,
				'config' => $config,
			]
		);
	}//end update()

	/**
	 * Legacy alias for {@see update()} — `POST /api/settings`.
	 *
	 * The canonical AppHost route table still ships `settings#create` for the
	 * pre-ADR-066 `index/create/load` dialect, so this stays reachable and MUST
	 * remain a real write, not an empty success (ADR-029). It delegates to
	 * `update()`, which carries the H6 admin guard, so both verbs share one
	 * enforcement path and cannot drift apart.
	 *
	 * Admin-only via the guard inside {@see update()}; non-admin callers
	 * receive 403 and unauthenticated callers 401, exactly as before.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Re-import the configuration from openbuild_register.json.
	 *
	 * Forces a fresh import regardless of version, auto-configuring
	 * all schema and register IDs from the import result.
	 *
	 * Admin-only: reloading configuration re-provisions registers and schemas
	 * instance-wide; non-admin callers receive 403 (H6 guard).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function load(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === false) {
			return new JSONResponse(['error' => 'Forbidden.'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->settingsService->reloadConfiguration();

		return new JSONResponse($result);
	}//end load()
}//end class
