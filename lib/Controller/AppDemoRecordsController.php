<?php

/**
 * Buildiq AppDemoRecordsController
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Fills an app with demo records generated from its own schemas, and removes
 * them again (capability-requirements-backfill REQ-BQCV-030). Only an owner or
 * an editor of the app may, the people who may change its data model; demo
 * records are only created when one of them asks.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Service\AppDemoRecordsService;
use OCA\Buildiq\Service\CallerContext;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates and removes an app's demo records.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */
class AppDemoRecordsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request       The request.
	 * @param AppDemoRecordsService $demoRecords Loads the app, creates and removes the records.
	 * @param CallerContext         $caller      The caller's session, the role matcher and the organisation guard.
	 * @param LoggerInterface       $logger      Records a failed fill or removal.
	 */
	public function __construct(
		IRequest $request,
		private readonly AppDemoRecordsService $demoRecords,
		private readonly CallerContext $caller,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Fill the app's register with demo records from its own schemas.
	 *
	 * @param string $appUuid The Application's uuid.
	 *
	 * @return JSONResponse `{created, skipped, failed, total}`; 401, 403, 404, 409 or 500 otherwise.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function fill(string $appUuid): JSONResponse {
		$application = $this->admitted(appUuid: $appUuid);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		$register = $this->demoRecords->productionRegister(application: $application);
		if ($register === '') {
			return new JSONResponse(data: ['error' => 'no_register'], statusCode: Http::STATUS_CONFLICT);
		}

		try {
			$result = $this->demoRecords->fill(applicationUuid: $appUuid, application: $application, register: $register);
		} catch (Throwable $e) {
			return $this->failed(appUuid: $appUuid, what: 'filled', error: $e);
		}

		$result['total'] = ($result['created'] + AppDemoRecordsService::count(application: $application));
		return new JSONResponse(data: $result);
	}//end fill()

	/**
	 * Delete the app's demo records, keeping every record a maker created.
	 *
	 * @param string $appUuid The Application's uuid.
	 *
	 * @return JSONResponse `{removed, failed}`; 401, 403, 404 or 500 otherwise.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function remove(string $appUuid): JSONResponse {
		$application = $this->admitted(appUuid: $appUuid);
		if ($application instanceof JSONResponse) {
			return $application;
		}

		try {
			$result = $this->demoRecords->remove(applicationUuid: $appUuid, application: $application);
		} catch (Throwable $e) {
			return $this->failed(appUuid: $appUuid, what: 'removed', error: $e);
		}

		return new JSONResponse(data: $result);
	}//end remove()

	/**
	 * The Application when the caller may change its data, else the refusal.
	 *
	 * Another organisation's scoped app answers as an unknown one does
	 * (REQ-BQOS-002), before any role is checked.
	 *
	 * @param string $appUuid The Application's uuid.
	 *
	 * @return array<string,mixed>|JSONResponse
	 */
	private function admitted(string $appUuid): array|JSONResponse {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['error' => 'unauthenticated'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$application = $this->demoRecords->application(uuid: $appUuid);
		if ($application === null || $this->caller->seesApplication(application: $application) === false) {
			return new JSONResponse(data: ['error' => 'not_found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		$resolver = $this->caller->permissionResolver;
		$allowed = $resolver->matchesCaller(
			permissions: $permissions,
			caller: $user,
			userGroups: $resolver->resolveUserGroups($user),
			allowAdminBypass: true,
			roles: ['owners', 'editors']
		);
		if ($allowed === false) {
			return new JSONResponse(data: ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $application;
	}//end admitted()

	/**
	 * The 500 answer of a fill or removal that threw, logged with the app.
	 *
	 * @param string    $appUuid The Application's uuid.
	 * @param string    $what    What failed: `filled` or `removed`.
	 * @param Throwable $error   The error.
	 *
	 * @return JSONResponse
	 */
	private function failed(string $appUuid, string $what, Throwable $error): JSONResponse {
		$this->logger->error(
			'Buildiq: demo records of application {uuid} could not be {what}: {message}',
			['uuid' => $appUuid, 'what' => $what, 'message' => $error->getMessage()]
		);

		return new JSONResponse(data: ['error' => 'internal_error'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failed()
}//end class
