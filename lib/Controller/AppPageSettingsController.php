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

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Service\AppPageSettingsAccess;
use OCA\Buildiq\Service\AppPageSettingsStore;
use InvalidArgumentException;
use Throwable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves and saves the options of a virtual app's settings pages.
 *
 * Saving changes the app for every user, so it is for owners and editors
 * only (AppPageSettingsAccess); nothing is read or written before the role
 * check passes.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
 */
class AppPageSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest              $request The current request.
	 * @param AppPageSettingsAccess $access  Decides who reads and saves.
	 * @param AppPageSettingsStore  $store   Keeps the saved values.
	 * @param LoggerInterface       $logger  Logs a failed read or save.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly AppPageSettingsAccess $access,
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
			$grant = $this->grant(appSlug: $appSlug, pageId: $pageId, roles: AppPageSettingsAccess::READ_ROLES);
			if ($grant instanceof JSONResponse) {
				return $grant;
			}

			return new JSONResponse(
				data: [
					'values'  => $this->store->values(appUuid: $grant['appUuid'], pageId: $pageId),
					'canSave' => $grant['canSave'],
				],
				statusCode: Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->failed(action: 'read', appSlug: $appSlug, pageId: $pageId, exception: $e);
		}
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
			$grant = $this->grant(appSlug: $appSlug, pageId: $pageId, roles: AppPageSettingsAccess::SAVE_ROLES);
			if ($grant instanceof JSONResponse) {
				return $grant;
			}

			$values = $this->request->getParams();
			unset($values['appSlug'], $values['pageId'], $values['_route']);

			try {
				$saved = $this->store->save(appUuid: $grant['appUuid'], pageId: $pageId, values: $values);
			} catch (InvalidArgumentException $e) {
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse(data: ['values' => $saved], statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->failed(action: 'save', appSlug: $appSlug, pageId: $pageId, exception: $e);
		}//end try
	}//end update()

	/**
	 * The grant for a valid page id, or the refusal as a response.
	 *
	 * @param string            $appSlug The Application slug.
	 * @param string            $pageId  The settings page id.
	 * @param array<int,string> $roles   The roles that pass.
	 *
	 * @return JSONResponse|array{appUuid: string, canSave: bool} The refusal or the grant.
	 */
	private function grant(string $appSlug, string $pageId, array $roles): JSONResponse|array {
		if ($this->store->isPageId(pageId: $pageId) === false) {
			return new JSONResponse(data: ['error' => 'invalid_page_id'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$grant = $this->access->grant(appSlug: $appSlug, roles: $roles);
		if (isset($grant['error']) === true) {
			return new JSONResponse(data: ['error' => $grant['error']], statusCode: $grant['status']);
		}

		return $grant;
	}//end grant()

	/**
	 * Log a failed read or save and answer 500.
	 *
	 * @param string    $action    `read` or `save`.
	 * @param string    $appSlug   The Application slug.
	 * @param string    $pageId    The settings page id.
	 * @param Throwable $exception What went wrong.
	 *
	 * @return JSONResponse The 500 response.
	 */
	private function failed(string $action, string $appSlug, string $pageId, Throwable $exception): JSONResponse {
		$this->logger->error(
			'Buildiq: could not {action} the settings of page {page} of {slug}: {message}',
			['action' => $action, 'slug' => $appSlug, 'page' => $pageId, 'message' => $exception->getMessage(), 'exception' => $exception]
		);
		return new JSONResponse(data: ['error' => 'internal_error'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failed()
}//end class
