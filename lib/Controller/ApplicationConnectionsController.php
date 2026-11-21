<?php

/**
 * Buildiq Application Connections Controller
 *
 * `GET /api/applications/{id}/connections`: the sources and integrations of one
 * app built in buildiq, with their health (built-app-connections-page).
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-20-built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Service\ApplicationConnectionsService;
use OCA\Buildiq\Service\CallerContext;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves an app's connections to its makers and to admins.
 *
 * @spec openspec/changes/archive/2026-11-20-built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */
class ApplicationConnectionsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request          The request.
	 * @param CallerContext                    $caller           The session and the permission resolver.
	 * @param ManifestResolverServiceInterface $manifestResolver Finds the application and its manifest.
	 * @param ApplicationConnectionsService    $connections      Lists the connections.
	 */
	public function __construct(
		IRequest $request,
		private readonly CallerContext $caller,
		private readonly ManifestResolverServiceInterface $manifestResolver,
		private readonly ApplicationConnectionsService $connections,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The app's connections, for a user who may edit the app or an admin.
	 *
	 * The caller is checked before any connection is read.
	 *
	 * @param string $id The application slug.
	 *
	 * @return JSONResponse `{healthNeedsIntegriq, connections}`, 401, 403 or 404.
	 *
	 * @no-admin-idor-exempt The application's own `permissions` are checked
	 *                       (owners or editors, or an admin) before anything is read.
	 *
	 * @spec openspec/changes/archive/2026-11-20-built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$application = $this->manifestResolver->findApplication(appSlug: $id);
		if ($application === null) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$resolver = $this->caller->permissionResolver;
		$allowed = $resolver->matchesCaller(
			permissions: (array)($application['permissions'] ?? []),
			caller: $user,
			userGroups: $resolver->resolveUserGroups($user),
			allowAdminBypass: true,
			roles: ['owners', 'editors']
		);
		if ($allowed === false) {
			return new JSONResponse(data: ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$manifest = ($this->manifestResolver->resolve(appSlug: $id, versionSlug: null, caller: $user) ?? []);

		return new JSONResponse(data: $this->connections->list(appSlug: $id, manifest: $manifest));
	}//end index()
}//end class
