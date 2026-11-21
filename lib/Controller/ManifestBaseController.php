<?php

/**
 * Buildiq ManifestBaseController
 *
 * `GET /api/applications/{slug}/manifest-base?_version=<slug>`: the base a
 * version extends, for the page designer of an owner or editor. The designer
 * shows the base with the version's delta merged over it, saves an edit as
 * the delta against this base, and lists the delta paths that no longer
 * target anything in it (app-delta-override D5, D6).
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Service\EditorBaseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves a version's base to the designer of its app.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
class ManifestBaseController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     The request.
	 * @param IUserSession      $userSession The session.
	 * @param EditorBaseService $bases       Resolves the base for an owner or editor.
	 * @param LoggerInterface   $logger      Records a failure.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly EditorBaseService $bases,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The base of the app's production version, or of `?_version=`.
	 *
	 * `{base: null, orphanedDeltaPaths: []}` for a version without a base: the
	 * designer then edits the stored blob as before. 404 for an unknown app or
	 * version and for a caller who is not an owner or editor of the app.
	 *
	 * @param string $slug The app slug.
	 *
	 * @return JSONResponse `{base, orphanedDeltaPaths}`.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function base(string $slug): JSONResponse {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$versionSlug = trim((string)$this->request->getParam('_version', ''));
		if ($versionSlug === '') {
			$versionSlug = null;
		}

		try {
			$result = $this->bases->authorizeBase(appSlug: $slug, versionSlug: $versionSlug, caller: $caller);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: manifest base failed for ' . $slug . ': ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(data: ['error' => 'internal_error'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($result === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
	}//end base()
}//end class
