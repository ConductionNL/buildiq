<?php

/**
 * Buildiq VersionSnapshotsController
 *
 * HTTP surface for named version snapshots: list, take, restore. Role checks
 * live in VersionSnapshotService, which every action calls first.
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
 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Exception\VersionSnapshotException;
use OCA\Buildiq\Service\VersionSnapshotService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the version snapshot endpoints.
 *
 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
 */
class VersionSnapshotsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request
	 * @param VersionSnapshotService $snapshots The snapshot service
	 * @param IUserSession $userSession The session
	 * @param LoggerInterface $logger PSR logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly VersionSnapshotService $snapshots,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List a version's snapshots (`?version=` names it; production otherwise).
	 *
	 * @param string $appSlug The Application slug
	 *
	 * @return JSONResponse The snapshots, or an error envelope
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $appSlug): JSONResponse {
		return $this->respond(
			action: fn (): array => $this->snapshots->listSnapshots(
				appSlug: $appSlug,
				versionSlug: (string)$this->request->getParam('version', ''),
				caller: $this->userSession->getUser()
			),
			status: Http::STATUS_OK
		);
	}//end index()

	/**
	 * Take a snapshot. Body: `{label, version}`.
	 *
	 * @param string $appSlug The Application slug
	 *
	 * @return JSONResponse The snapshot (201), or an error envelope
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(string $appSlug): JSONResponse {
		return $this->respond(
			action: fn (): array => $this->snapshots->takeSnapshot(
				appSlug: $appSlug,
				versionSlug: (string)$this->request->getParam('version', ''),
				label: (string)$this->request->getParam('label', ''),
				caller: $this->userSession->getUser()
			),
			status: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Roll the snapshot's version back to it, keeping a Previous draft snapshot.
	 *
	 * @param string $appSlug The Application slug
	 * @param string $snapshotUuid The snapshot to restore
	 *
	 * @return JSONResponse The updated version and the kept snapshot, or an error envelope
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function restore(string $appSlug, string $snapshotUuid): JSONResponse {
		return $this->respond(
			action: fn (): array => $this->snapshots->restoreSnapshot(
				appSlug: $appSlug,
				snapshotUuid: $snapshotUuid,
				caller: $this->userSession->getUser()
			),
			status: Http::STATUS_OK
		);
	}//end restore()

	/**
	 * Run a service call and map its outcome to a response.
	 *
	 * @param callable $action The service call
	 * @param int $status The success status
	 *
	 * @return JSONResponse
	 */
	private function respond(callable $action, int $status): JSONResponse {
		try {
			return new JSONResponse(data: $action(), statusCode: $status);
		} catch (VersionSnapshotException $e) {
			return new JSONResponse(
				data: ['error' => $e->getErrorCode(), 'message' => $e->getMessage()],
				statusCode: $e->getStatus()
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: version snapshot request failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				data: ['error' => 'internal_error', 'message' => 'The snapshot request failed.'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end respond()
}//end class
