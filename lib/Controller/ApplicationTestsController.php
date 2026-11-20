<?php

/**
 * Buildiq ApplicationTestsController
 *
 * The REST surface of an app's Tests tab (lifecycle-release-test-gate D4):
 *
 *   GET    /api/applications/{appSlug}/tests                          record tests, owned rule sets, latest run per version
 *   POST   /api/applications/{appSlug}/versions/{versionSlug}/tests   run the suite now ("Run tests") and keep the run
 *   POST   /api/applications/{appSlug}/record-tests                   add a record test
 *   PUT    /api/applications/{appSlug}/record-tests/{id}              change a record test
 *   DELETE /api/applications/{appSlug}/record-tests/{id}              remove a record test
 *
 * Reading needs a role on the app (owner, editor or viewer); running and
 * writing need owner or editor. Nextcloud administrators pass, as on the
 * app's other read and write routes.
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
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\ApplicationTestsService;
use OCA\Buildiq\Service\ReleaseTestGate;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller behind the app's Tests tab.
 */
class ApplicationTestsController extends Controller {

	/**
	 * Roles that may read the tab.
	 *
	 * @var array<int,string>
	 */
	private const READ_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * Roles that may run the suite and write record tests (REQ-BQRT-003).
	 *
	 * @var array<int,string>
	 */
	private const WRITE_ROLES = ['owners', 'editors'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request     The request.
	 * @param IUserSession                $userSession The session.
	 * @param PermissionResolverInterface $permissions Matches the caller against the app's roles.
	 * @param ApplicationTestsService     $tests       The tab's data.
	 * @param ReleaseTestGate             $gate        Runs and keeps a manual run.
	 * @param LoggerInterface             $logger      PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly PermissionResolverInterface $permissions,
		private readonly ApplicationTestsService $tests,
		private readonly ReleaseTestGate $gate,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The record tests, the rule sets the app owns and the latest run per version.
	 *
	 * @param string $appSlug The app's slug.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
	 */
	#[NoAdminRequired]
	public function index(string $appSlug): JSONResponse {
		$application = $this->authorised(appSlug: $appSlug, roles: self::READ_ROLES);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		return new JSONResponse(data: $this->tests->overview(application: $application));
	}//end index()

	/**
	 * Run the suite of one version now and keep the run ("Run tests").
	 *
	 * @param string $appSlug     The app's slug.
	 * @param string $versionSlug The version's slug.
	 *
	 * @return JSONResponse The run.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
	 */
	#[NoAdminRequired]
	public function run(string $appSlug, string $versionSlug): JSONResponse {
		$application = $this->authorised(appSlug: $appSlug, roles: self::WRITE_ROLES);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		$version = $this->tests->version(application: $application, slug: $versionSlug);
		if ($version === null) {
			return self::error(code: 'not_found', detail: 'Version ' . $versionSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $this->gate->runManually(application: $application, version: $version));
	}//end run()

	/**
	 * Add a record test.
	 *
	 * @param string $appSlug The app's slug.
	 *
	 * @return JSONResponse 201 with the stored test.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	#[NoAdminRequired]
	public function createRecordTest(string $appSlug): JSONResponse {
		return $this->writeRecordTest(appSlug: $appSlug, id: null);
	}//end createRecordTest()

	/**
	 * Change a record test of the app.
	 *
	 * @param string $appSlug The app's slug.
	 * @param string $id      The record test.
	 *
	 * @return JSONResponse 200 with the stored test.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	#[NoAdminRequired]
	public function updateRecordTest(string $appSlug, string $id): JSONResponse {
		return $this->writeRecordTest(appSlug: $appSlug, id: $id);
	}//end updateRecordTest()

	/**
	 * Remove a record test of the app.
	 *
	 * @param string $appSlug The app's slug.
	 * @param string $id      The record test.
	 *
	 * @return JSONResponse 204, or 404 when it is not a test of this app.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	#[NoAdminRequired]
	public function destroyRecordTest(string $appSlug, string $id): JSONResponse {
		$application = $this->authorised(appSlug: $appSlug, roles: self::WRITE_ROLES);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		if ($this->tests->deleteRecordTest(application: $application, id: $id) === false) {
			return self::error(code: 'not_found', detail: 'Record test ' . $id . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroyRecordTest()

	/**
	 * Store a record test from the request body.
	 *
	 * @param string      $appSlug The app's slug.
	 * @param string|null $id      The test to change; null adds one.
	 *
	 * @return JSONResponse
	 */
	private function writeRecordTest(string $appSlug, ?string $id): JSONResponse {
		$application = $this->authorised(appSlug: $appSlug, roles: self::WRITE_ROLES);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		$input = [
			'name' => $this->request->getParam('name', ''),
			'schema' => $this->request->getParam('schema', ''),
			'record' => $this->request->getParam('record'),
			'expect' => $this->request->getParam('expect', ''),
			'expectedFields' => $this->request->getParam('expectedFields', []),
		];

		try {
			$saved = $this->tests->saveRecordTest(application: $application, input: $input, id: $id);
		} catch (InvalidArgumentException $e) {
			return self::error(code: 'invalid_record_test', detail: $e->getMessage(), status: Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: a record test of {app} could not be stored: {message}',
				['app' => $appSlug, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return self::error(code: 'record_test_not_stored', detail: null, status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($saved === null) {
			return self::error(code: 'not_found', detail: 'Record test ' . (string)$id . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		$status = Http::STATUS_OK;
		if ($id === null) {
			$status = Http::STATUS_CREATED;
		}

		return new JSONResponse(data: $saved, statusCode: $status);
	}//end writeRecordTest()

	/**
	 * The app, when the caller holds one of the roles on it; else the refusal.
	 *
	 * @param string            $appSlug The app's slug.
	 * @param array<int,string> $roles   The roles that grant access.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function authorised(string $appSlug, array $roles): array|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return self::error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		$application = $this->tests->application(slug: $appSlug);
		if ($application === null) {
			return self::error(code: 'not_found', detail: 'Application ' . $appSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		$permissions = $application['permissions'] ?? [];
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		$allowed = $this->permissions->matchesCaller(
			permissions: $permissions,
			caller: $user,
			userGroups: $this->permissions->resolveUserGroups($user),
			allowAdminBypass: true,
			roles: $roles
		);
		if ($allowed === false) {
			return self::error(code: 'buildiq.rbac.no_role', detail: null, status: Http::STATUS_FORBIDDEN);
		}

		return $application;
	}//end authorised()

	/**
	 * A uniform error envelope.
	 *
	 * @param string      $code   Error code.
	 * @param string|null $detail Optional detail.
	 * @param int         $status HTTP status.
	 *
	 * @return JSONResponse
	 */
	private static function error(string $code, ?string $detail, int $status): JSONResponse {
		$body = ['error' => $code];
		if ($detail !== null) {
			$body['detail'] = $detail;
		}

		return new JSONResponse(data: $body, statusCode: $status);
	}//end error()
}//end class
