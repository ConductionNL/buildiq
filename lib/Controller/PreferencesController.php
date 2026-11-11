<?php

/**
 * Buildiq PreferencesController.
 *
 * Generic per-user key/value preferences, backed by Nextcloud IConfig
 * user values. Used by shared @conduction/nextcloud-vue widgets (e.g.
 * CnSupportDialog's "seen" flag) that need to persist a small per-user
 * UI flag cross-device without a bespoke endpoint per feature.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-user preferences controller.
 */
class PreferencesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IConfig $config The Nextcloud config (user values).
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Read a per-user preference value.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @spec            openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-5
	 */
	public function getPreference(string $key): JSONResponse {
		$admitted = $this->admit(key: $key);
		if ($admitted instanceof JSONResponse) {
			return $admitted;
		}

		[$uid, $safeKey] = $admitted;

		$value = $this->config->getUserValue(
			userId: $uid,
			appName: Application::APP_ID,
			key: 'pref_' . $safeKey,
			default: ''
		);

		$stored = null;
		if ($value !== '') {
			$stored = $value;
		}

		return new JSONResponse(data: ['value' => $stored]);
	}//end getPreference()

	/**
	 * Write a per-user preference value. An empty value clears it.
	 *
	 * @param string $key The preference key (kebab/alphanumeric).
	 * @param string $value The value to store (empty string clears it).
	 *
	 * @return JSONResponse `{value: string|null}`.
	 *
	 * @NoAdminRequired
	 * @spec            openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-5
	 */
	public function setPreference(string $key, string $value = ''): JSONResponse {
		$admitted = $this->admit(key: $key);
		if ($admitted instanceof JSONResponse) {
			return $admitted;
		}

		[$uid, $safeKey] = $admitted;

		if ($value === '') {
			$this->config->deleteUserValue(
				userId: $uid,
				appName: Application::APP_ID,
				key: 'pref_' . $safeKey
			);
			return new JSONResponse(data: ['value' => null]);
		}

		$this->config->setUserValue(
			userId: $uid,
			appName: Application::APP_ID,
			key: 'pref_' . $safeKey,
			value: $value
		);

		return new JSONResponse(data: ['value' => $value]);
	}//end setPreference()

	/**
	 * Admit a preference call: a signed-in caller and a key with something safe in it.
	 *
	 * @param string $key The raw preference key.
	 *
	 * @return JSONResponse|array{0: string, 1: string} The 401/400 refusal, or the caller's UID and the safe key.
	 */
	private function admit(string $key): JSONResponse|array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->sanitizeKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return [$user->getUID(), $safeKey];
	}//end admit()

	/**
	 * Restrict keys to a safe charset so callers cannot reach arbitrary
	 * IConfig user values outside the `pref_` namespace.
	 *
	 * @param string $key The raw key.
	 *
	 * @return string The sanitised key, or '' when nothing safe remains.
	 */
	private function sanitizeKey(string $key): string {
		$safe = preg_replace(pattern: '/[^a-z0-9-]/', replacement: '', subject: strtolower($key));
		return substr((string)$safe, offset: 0, length: 64);
	}//end sanitizeKey()
}//end class
