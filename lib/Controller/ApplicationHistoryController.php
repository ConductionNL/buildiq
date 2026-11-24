<?php

/**
 * Buildiq Application History Controller
 *
 * GET /api/applications/{appSlug}/history: who saved the app or one of its
 * versions, and when, newest first (REQ-BQCV-014). Only a user with a role
 * on the app reads it; anyone else is refused before the trail is read.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\AppChangeHistory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves an app's change history to the users with a role on it.
 *
 * The role check is the shared RBAC grammar (PermissionResolverInterface)
 * over the Application's `permissions`, any of owners, editors or viewers,
 * without an admin bypass: the history is the app team's, as the
 * requirement says. Another organisation's scoped app answers like an
 * unknown one.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */
class ApplicationHistoryController extends Controller {
	/**
	 * The roles that read the history.
	 *
	 * @var array<int,string>
	 */
	private const READ_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request            The current request.
	 * @param IUserSession                $userSession        The signed-in user.
	 * @param PermissionResolverInterface $permissionResolver Shared RBAC grammar resolver.
	 * @param AppChangeHistory            $history            Reads the app, its versions and their audit trail.
	 * @param LoggerInterface             $logger             Logs a failed read.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly AppChangeHistory $history,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The change history of an app: `{entries: [...]}`, newest first.
	 *
	 * @param string $appSlug The Application slug.
	 *
	 * @return JSONResponse 200 with the entries; 401, 403, 404 or 503 otherwise.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
	 */
	#[NoAdminRequired]
	public function index(string $appSlug): JSONResponse {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$application = $this->history->application(slug: $appSlug);
			if ($application === null || $this->permissionResolver->seesApplication(application: $application, caller: $caller) === false) {
				return $this->error(code: 'not_found', status: Http::STATUS_NOT_FOUND);
			}

			$permissions = ($application['permissions'] ?? []);
			if (is_array($permissions) === false) {
				$permissions = [];
			}

			$allowed = $this->permissionResolver->matchesCaller(
				permissions: $permissions,
				caller: $caller,
				userGroups: $this->permissionResolver->resolveUserGroups(user: $caller),
				allowAdminBypass: false,
				roles: self::READ_ROLES
			);
			if ($allowed === false) {
				return $this->error(code: 'buildiq.rbac.no_role', status: Http::STATUS_FORBIDDEN);
			}

			$versions = $this->history->versions(application: $application);
			if ($versions === null) {
				return $this->error(code: 'not_found', status: Http::STATUS_NOT_FOUND);
			}

			if ($this->history->available() === false) {
				return $this->error(code: 'audit_unavailable', status: Http::STATUS_SERVICE_UNAVAILABLE);
			}

			return new JSONResponse(
				data: ['entries' => $this->history->entries(application: $application, versions: $versions)],
				statusCode: Http::STATUS_OK
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: the change history of {slug} could not be read: {message}',
				['slug' => $appSlug, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end index()

	/**
	 * An error envelope.
	 *
	 * @param string $code   The error code.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse The response.
	 */
	private function error(string $code, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $code], statusCode: $status);
	}//end error()
}//end class
