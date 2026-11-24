<?php

/**
 * Buildiq App Page Settings Controller
 *
 * GET and PUT /api/applications/{appSlug}/page-settings/{pageId}: the values
 * saved on a settings page of a virtual app (REQ-BQCV-011). Every user with
 * a role on the app reads them; owners and editors save them.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\AppChangeHistory;
use OCA\Buildiq\Service\AppPageSettingsStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves and saves the options of a virtual app's settings pages.
 *
 * Reading follows the manifest's rule: any of owners, editors or viewers.
 * Saving changes the app for every user, so it is for owners and editors
 * only, without an admin bypass. Another organisation's scoped app, and an
 * app the caller has no role on, answer like an unknown one or with 403
 * before anything is read or written.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */
class AppPageSettingsController extends Controller {
	/**
	 * The roles that read the values.
	 *
	 * @var array<int,string>
	 */
	private const READ_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * The roles that save the values.
	 *
	 * @var array<int,string>
	 */
	private const SAVE_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request            The current request.
	 * @param IUserSession                $userSession        The signed-in user.
	 * @param PermissionResolverInterface $permissionResolver Shared RBAC grammar resolver.
	 * @param AppChangeHistory            $applications       Looks the Application up by slug.
	 * @param AppPageSettingsStore        $store              Keeps the saved values.
	 * @param LoggerInterface             $logger             Logs a failed read or save.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PermissionResolverInterface $permissionResolver,
		private readonly AppChangeHistory $applications,
		private readonly AppPageSettingsStore $store,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The saved values of a settings page: `{values, canSave}`.
	 *
	 * @param string $appSlug The Application slug.
	 * @param string $pageId  The settings page id.
	 *
	 * @return JSONResponse 200 with the values; 400, 401, 403, 404 or 500 otherwise.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
	 */
	#[NoAdminRequired]
	public function show(string $appSlug, string $pageId): JSONResponse {
		try {
			$access = $this->access(appSlug: $appSlug, pageId: $pageId, roles: self::READ_ROLES);
			if ($access instanceof JSONResponse) {
				return $access;
			}

			[$application, $caller] = $access;
			return new JSONResponse(
				data: [
					'values'  => $this->store->values(appUuid: $this->uuidOf(application: $application), pageId: $pageId),
					'canSave' => $this->hasRole(application: $application, caller: $caller, roles: self::SAVE_ROLES),
				],
				statusCode: Http::STATUS_OK
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: the settings of page {page} of {slug} could not be read: {message}',
				['slug' => $appSlug, 'page' => $pageId, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end show()

	/**
	 * Save the values of a settings page; the request body is the values.
	 *
	 * @param string $appSlug The Application slug.
	 * @param string $pageId  The settings page id.
	 *
	 * @return JSONResponse 200 with `{values}`; 400, 401, 403, 404 or 500 otherwise.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
	 */
	#[NoAdminRequired]
	public function update(string $appSlug, string $pageId): JSONResponse {
		try {
			$access = $this->access(appSlug: $appSlug, pageId: $pageId, roles: self::SAVE_ROLES);
			if ($access instanceof JSONResponse) {
				return $access;
			}

			$values = $this->bodyValues();
			if ($values === null) {
				return $this->error(code: 'invalid_values', status: Http::STATUS_BAD_REQUEST);
			}

			try {
				$saved = $this->store->save(appUuid: $this->uuidOf(application: $access[0]), pageId: $pageId, values: $values);
			} catch (InvalidArgumentException $e) {
				return $this->error(code: $e->getMessage(), status: Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse(data: ['values' => $saved], statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: the settings of page {page} of {slug} could not be saved: {message}',
				['slug' => $appSlug, 'page' => $pageId, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end update()

	/**
	 * The Application and caller when the caller holds one of the roles, else the refusal.
	 *
	 * @param string            $appSlug The Application slug.
	 * @param string            $pageId  The settings page id.
	 * @param array<int,string> $roles   The roles that pass.
	 *
	 * @return JSONResponse|array{0: array<string,mixed>, 1: IUser} The refusal, or the Application and caller.
	 */
	private function access(string $appSlug, string $pageId, array $roles): JSONResponse|array {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->store->isPageId(pageId: $pageId) === false) {
			return $this->error(code: 'invalid_page_id', status: Http::STATUS_BAD_REQUEST);
		}

		$application = $this->applications->application(slug: $appSlug);
		if ($application === null || $this->permissionResolver->seesApplication(application: $application, caller: $caller) === false) {
			return $this->error(code: 'not_found', status: Http::STATUS_NOT_FOUND);
		}

		if ($this->uuidOf(application: $application) === '') {
			return $this->error(code: 'not_found', status: Http::STATUS_NOT_FOUND);
		}

		if ($this->hasRole(application: $application, caller: $caller, roles: $roles) === false) {
			return $this->error(code: 'buildiq.rbac.no_role', status: Http::STATUS_FORBIDDEN);
		}

		return [$application, $caller];
	}//end access()

	/**
	 * Whether the caller holds one of the roles on the Application.
	 *
	 * @param array<string,mixed> $application The Application record.
	 * @param IUser               $caller      The signed-in user.
	 * @param array<int,string>   $roles       The roles that pass.
	 *
	 * @return bool True when the caller holds one of them.
	 */
	private function hasRole(array $application, IUser $caller, array $roles): bool {
		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		return $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $caller,
			userGroups: $this->permissionResolver->resolveUserGroups(user: $caller),
			allowAdminBypass: false,
			roles: $roles
		);
	}//end hasRole()

	/**
	 * The values in the request body, or null when it is not a JSON object.
	 *
	 * @return array<string,mixed>|null The values.
	 */
	private function bodyValues(): ?array {
		$params = $this->request->getParams();
		unset($params['appSlug'], $params['pageId'], $params['_route']);
		foreach (array_keys($params) as $key) {
			if (is_string($key) === false) {
				return null;
			}
		}

		return $params;
	}//end bodyValues()

	/**
	 * The uuid of an Application (`id`, else `uuid`).
	 *
	 * @param array<string,mixed> $application The Application record.
	 *
	 * @return string The uuid, or '' when it has none.
	 */
	private function uuidOf(array $application): string {
		return (string)($application['id'] ?? $application['uuid'] ?? '');
	}//end uuidOf()

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
