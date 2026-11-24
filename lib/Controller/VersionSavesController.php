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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
		401 => 'unauthenticated',
		403 => 'buildiq.rbac.no_role',
		404 => 'not_found',
		503 => 'versions_unavailable',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param VersionSaveReader $reader  Reads a version's saves for the signed-in caller.
	 * @param LoggerInterface   $logger  Where a failed read is noted.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
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
		return $this->answer(
			key: 'saves',
			read: fn (): array|int => $this->reader->saves(appSlug: $appSlug, versionSlug: $versionSlug),
			what: $appSlug . '/' . $versionSlug
		);
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
		return $this->answer(
			key: 'manifest',
			read: fn (): array|int => $this->reader->manifest(appSlug: $appSlug, versionSlug: $versionSlug, saveId: $saveId),
			what: $appSlug . '/' . $versionSlug . '#' . $saveId
		);
	}//end show()

	/**
	 * The read's answer under its key, its refusal with the error code, or a 500 without detail.
	 *
	 * @param string   $key  The key the answer goes under.
	 * @param callable $read The read: the answer, or the refusal's HTTP status.
	 * @param string   $what What was read, for the log.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $key, callable $read, string $what): JSONResponse {
		try {
			$result = $read();
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: the saves of {what} could not be read: {message}',
				['what' => $what, 'message' => $e->getMessage(), 'exception' => $e]
			);
			$result = 500;
		}

		if (is_int($result) === true) {
			return new JSONResponse(data: ['error' => (self::CODES[$result] ?? 'internal_error')], statusCode: $result);
		}

		return new JSONResponse(data: [$key => $result], statusCode: 200);
	}//end answer()
}//end class
