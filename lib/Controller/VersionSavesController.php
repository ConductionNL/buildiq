<?php

/**
 * VersionSavesController
 *
 * `GET /api/applications/{appSlug}/versions/{versionSlug}/saves` lists the saves
 * of a version, newest first, and `GET .../saves/{saveId}` gives one save's
 * manifest, so the version history can roll a version back to an earlier save
 * (REQ-BQCV-015). The rollback itself writes through the versions API, which
 * applies its own write and code rules.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Service\VersionSaveReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The saves of an app version.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
class VersionSavesController extends Controller {

	/**
	 * The error code of each refusal status.
	 *
	 * @var array<int,string>
	 */
	private const CODES = [
		Http::STATUS_UNAUTHORIZED => 'unauthenticated',
		Http::STATUS_FORBIDDEN => 'buildiq.rbac.no_role',
		Http::STATUS_NOT_FOUND => 'not_found',
		Http::STATUS_SERVICE_UNAVAILABLE => 'versions_unavailable',
		Http::STATUS_INTERNAL_SERVER_ERROR => 'internal_error',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     The request.
	 * @param IUserSession      $userSession The signed-in user.
	 * @param VersionSaveReader $reader      Reads a version's saves for the caller.
	 * @param LoggerInterface   $logger      Where a failed read is noted.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly VersionSaveReader $reader,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The saves of a version, newest first, to owners, editors and viewers of the app.
	 *
	 * @param string $appSlug     The app.
	 * @param string $versionSlug The version.
	 *
	 * @return JSONResponse 200 `{saves: [{id, savedAt, user, pages}]}`; 401, 403, 404, 503 or 500.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	#[NoAdminRequired]
	public function index(string $appSlug, string $versionSlug): JSONResponse {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return $this->error(status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$saves = $this->reader->saves(appSlug: $appSlug, versionSlug: $versionSlug, caller: $caller);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: the saves of {slug}/{version} could not be read: {message}',
				['slug' => $appSlug, 'version' => $versionSlug, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return $this->error(status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (is_int($saves) === true) {
			return $this->error(status: $saves);
		}

		return new JSONResponse(data: ['saves' => $saves], statusCode: Http::STATUS_OK);
	}//end index()

	/**
	 * The manifest of one save of a version, to owners, editors and viewers of the app.
	 *
	 * @param string $appSlug     The app.
	 * @param string $versionSlug The version.
	 * @param string $saveId      The save.
	 *
	 * @return JSONResponse 200 `{manifest}`; 401, 403, 404, 503 or 500.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	#[NoAdminRequired]
	public function show(string $appSlug, string $versionSlug, string $saveId): JSONResponse {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return $this->error(status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$manifest = $this->reader->manifest(appSlug: $appSlug, versionSlug: $versionSlug, saveId: $saveId, caller: $caller);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: save {save} of {slug}/{version} could not be read: {message}',
				['save' => $saveId, 'slug' => $appSlug, 'version' => $versionSlug, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return $this->error(status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (is_int($manifest) === true) {
			return $this->error(status: $manifest);
		}

		return new JSONResponse(data: ['manifest' => $manifest], statusCode: Http::STATUS_OK);
	}//end show()

	/**
	 * A refusal with its error code.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function error(int $status): JSONResponse {
		return new JSONResponse(data: ['error' => (self::CODES[$status] ?? 'internal_error')], statusCode: $status);
	}//end error()
}//end class
