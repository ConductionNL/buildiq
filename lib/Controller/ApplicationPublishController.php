<?php

/**
 * Buildiq Application Publish Controller
 *
 * Exposes the explicit publish / unpublish action that flips an Application's
 * `status` between `draft` and `published`. Only a `published` Application is
 * surfaced in the Nextcloud top-bar app menu (AppNavigationService gates on
 * `status == published`), so publishing is the deliberate "go live" step for a
 * virtual app.
 *
 * Authorisation is per-Application RBAC — OWNERS only (editors may save drafts
 * but not publish, per the Application schema), with the Nextcloud admin bypass.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Exception\InsufficientPermissionException;
use OCA\Buildiq\Service\ApplicationDeletionService;
use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\VersionPromotionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing the Application publish / unpublish endpoints.
 */
class ApplicationPublishController extends Controller {
	/**
	 * Roles allowed to publish/unpublish — owners only (editors cannot publish).
	 *
	 * @var array<int,string>
	 */
	private const PUBLISH_ROLES = ['owners'];

	/**
	 * Application status indicating the app is live (listed in the app menu).
	 */
	private const STATUS_PUBLISHED = 'published';

	/**
	 * Application status indicating the app is hidden from the app menu.
	 */
	private const STATUS_DRAFT = 'draft';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request
	 * @param LoggerInterface $logger PSR logger
	 * @param ObjectServiceInterface $objectService OR object surface (load + save)
	 * @param IUserSession $userSession Current NC user session
	 * @param PermissionResolver $permissionResolver Shared permission-grammar resolver
	 * @param ApplicationDeletionService $deletionService Full-teardown service for delete
	 * @param VirtualAppCredentialRegistrar $credentialRegistrar Onboards a published credentials[]-declaring app to the broker
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserSession $userSession,
		private readonly PermissionResolver $permissionResolver,
		private readonly ApplicationDeletionService $deletionService,
		private readonly VirtualAppCredentialRegistrar $credentialRegistrar,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Publish an Application (status → published), making it appear in the menu.
	 *
	 * Wired in `appinfo/routes.php` under name `applicationPublish#publish`.
	 * `#[NoAdminRequired]` — auth is per-Application owner RBAC, not NC admin.
	 *
	 * @param string $appUuid Parent Application UUID (path param)
	 *
	 * @return JSONResponse 200 + updated application, or an error envelope
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function publish(string $appUuid): JSONResponse {
		return $this->setStatus(appUuid: $appUuid, status: self::STATUS_PUBLISHED);
	}//end publish()

	/**
	 * Unpublish an Application (status → draft), removing it from the menu.
	 *
	 * Wired in `appinfo/routes.php` under name `applicationPublish#unpublish`.
	 *
	 * @param string $appUuid Parent Application UUID (path param)
	 *
	 * @return JSONResponse 200 + updated application, or an error envelope
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function unpublish(string $appUuid): JSONResponse {
		return $this->setStatus(appUuid: $appUuid, status: self::STATUS_DRAFT);
	}//end unpublish()

	/**
	 * Delete an Application and the app wrapper it owns (versions, routes). The
	 * optional `deleteData` request param opts into ALSO deleting the
	 * per-version registers and every object stored in them; by default that
	 * data is preserved. Owner-only (admin bypass). Wired as
	 * `applicationPublish#destroy`.
	 *
	 * `deleteData` is read from the request (not a typed bool method arg) and
	 * resolved safe-by-default via {@see wantsDataPurge()}: only an explicit
	 * affirmative purges. This deliberately does NOT rely on PHP/Nextcloud
	 * stringy-bool coercion for an irreversible action.
	 *
	 * @param string $appUuid Parent Application UUID (path param)
	 *
	 * @return JSONResponse 200 + `{deleted, orphanedResources}`, or an error envelope
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function destroy(string $appUuid): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'unauthenticated'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$application = $this->loadApplication(uuid: $appUuid);
			if ($application === null) {
				return new JSONResponse(
					data: ['error' => 'not_found', 'detail' => 'Application ' . $appUuid . ' not found'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$this->assertOwner(application: $application, user: $user);

			$orphaned = $this->deletionService->deleteApplication(
				appUuid: $appUuid,
				appSlug: (string)($application['slug'] ?? ''),
				deleteData: $this->wantsDataPurge()
			);

			return new JSONResponse(
				data: ['deleted' => true, 'orphanedResources' => $orphaned],
				statusCode: Http::STATUS_OK
			);
		} catch (InsufficientPermissionException $e) {
			return new JSONResponse(
				data: ['error' => 'forbidden', 'detail' => $e->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: delete failed for {uuid}: {message}',
				['uuid' => $appUuid, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return new JSONResponse(
				data: ['error' => 'internal_error', 'detail' => $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end destroy()

	/**
	 * Resolve the destructive data-purge opt-in from the request, safe-by-default.
	 *
	 * The `deleteData` flag is read raw here rather than as a typed `bool`
	 * method argument, so it does NOT inherit PHP/Nextcloud stringy-bool
	 * coercion (where any non-empty, non-'false' string casts to true) for an
	 * IRREVERSIBLE action. Only an explicit affirmative — the string '1'/'true'
	 * or a literal true/1 from a JSON body — opts into the purge; every other,
	 * ambiguous, empty, or absent value preserves the data. So a hand-crafted
	 * `?deleteData=no` / `off` / `preview` can never trigger a wipe. The
	 * frontend's `deleteData: 1|0` contract maps cleanly onto this.
	 *
	 * @return bool True only when the caller explicitly opted into a data purge.
	 */
	private function wantsDataPurge(): bool {
		$raw = $this->request->getParam('deleteData', false);
		if (is_string($raw) === true) {
			$raw = strtolower(trim($raw));
		}

		return ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true');
	}//end wantsDataPurge()

	/**
	 * Flip an Application's status after an owner-only RBAC check.
	 *
	 * @param string $appUuid The Application UUID.
	 * @param string $status Target status (`published` or `draft`).
	 *
	 * @return JSONResponse
	 */
	private function setStatus(string $appUuid, string $status): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				data: ['error' => 'unauthenticated'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$application = $this->loadApplication(uuid: $appUuid);
			if ($application === null) {
				return new JSONResponse(
					data: ['error' => 'not_found', 'detail' => 'Application ' . $appUuid . ' not found'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$this->assertOwner(application: $application, user: $user);

			// Strip the OR-managed metadata envelope before writing back — the
			// save path expects the bare object data + the uuid (mirrors how
			// ApplicationCreationService updates the Application).
			unset($application['@self']);
			$application['status'] = $status;
			$saved = $this->objectService->saveObject(
				object: $application,
				register: VersionPromotionService::REGISTER_SLUG,
				schema: VersionPromotionService::APPLICATION_SCHEMA,
				uuid: $appUuid
			);

			// On go-live, onboard the app to the credential broker when its
			// manifest declares credentials[]. Best-effort + never-throw — a
			// broker/Doriath hiccup must not fail the publish (see registrar).
			if ($status === self::STATUS_PUBLISHED) {
				$slug = (string)($application['slug'] ?? '');
				if ($slug !== '') {
					$this->credentialRegistrar->onPublish(slug: $slug, caller: $user);
					$this->ensureBuiltAppRoute(slug: $slug, applicationUuid: $appUuid);
				}
			}

			return new JSONResponse(
				data: [
					'status' => $status,
					'application' => $this->normaliseObject(object: $saved),
				],
				statusCode: Http::STATUS_OK
			);
		} catch (InsufficientPermissionException $e) {
			return new JSONResponse(
				data: ['error' => 'forbidden', 'detail' => $e->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: publish/unpublish failed for {uuid}: {message}',
				['uuid' => $appUuid, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return new JSONResponse(
				data: ['error' => 'internal_error', 'detail' => $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end setStatus()

	/**
	 * Idempotently ensure a BuiltAppRoute index object exists for this slug.
	 *
	 * `ApplicationsController::getManifest` resolves a virtual-app slug to its
	 * Application UUID through this object — without it a published app is
	 * still unreachable by slug (404 `not_found` from the manifest/builder
	 * endpoints) even though its status says published. Only the app-creation
	 * WIZARD created this object (at creation time, atomically with going
	 * live); any app that starts as a draft and is published later — via the
	 * local-template, remote-registry, or GitHub-shop install paths — never
	 * got one. This closes that gap at the one place every publish path goes
	 * through: the explicit publish action itself.
	 *
	 * Best-effort + never-throw, matching {@see credentialRegistrar->onPublish()}
	 * immediately above this call — a routing hiccup must not fail the publish
	 * itself; the app is simply not reachable by slug until a retry succeeds.
	 *
	 * @param string $slug The application's kebab-case slug.
	 * @param string $applicationUuid The Application UUID the route should point at.
	 *
	 * @return void
	 */
	private function ensureBuiltAppRoute(string $slug, string $applicationUuid): void {
		try {
			$existing = $this->objectService->find(
				id: $slug,
				register: VersionPromotionService::REGISTER_SLUG,
				schema: 'built-app-route'
			);
			$existingData = $this->normaliseObject(object: $existing);
			$existingTarget = (string)($existingData['applicationUuid'] ?? '');
			if ($existingTarget === $applicationUuid) {
				return;
			}
		} catch (Throwable $e) {
			// No existing route for this slug — fall through to create one.
		}

		try {
			$this->objectService->saveObject(
				object: ['slug' => $slug, 'applicationUuid' => $applicationUuid],
				register: VersionPromotionService::REGISTER_SLUG,
				schema: 'built-app-route'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: built-app-route upsert failed on publish for slug {slug}: {message}',
				['slug' => $slug, 'message' => $e->getMessage(), 'exception' => $e]
			);
		}
	}//end ensureBuiltAppRoute()

	/**
	 * Load the Application object by UUID.
	 *
	 * OpenRegister's `ObjectService::find()` documents `@throws Exception If
	 * the object is not found` — it does NOT return null on a miss. So the
	 * `$entity === null` branch below could never be the thing that made this
	 * method return null, and every caller's `if ($application === null)`
	 * 404 branch was unreachable: a missing Application escaped as an
	 * exception and surfaced as a 500 `internal_error` from the outer
	 * `catch (Throwable)` instead of the intended 404.
	 *
	 * Catching here is what makes the declared `?array` contract true. The
	 * cause is logged at debug level so a genuine provisioning fault (no
	 * `buildiq` register at all) is still traceable rather than silently
	 * flattened into "not found".
	 *
	 * @param string $uuid Application UUID.
	 *
	 * @return array<string,mixed>|null Normalised application array, or null when absent.
	 */
	private function loadApplication(string $uuid): ?array {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: VersionPromotionService::REGISTER_SLUG,
				schema: VersionPromotionService::APPLICATION_SCHEMA
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: Application {uuid} could not be loaded: {message}',
				['uuid' => $uuid, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return null;
		}//end try

		if ($entity === null) {
			return null;
		}

		return $this->normaliseObject(object: $entity);
	}//end loadApplication()

	/**
	 * Assert the caller holds the `owners` role on the Application (admin bypass allowed).
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param IUser $user The calling user.
	 *
	 * @return void
	 *
	 * @throws InsufficientPermissionException When the caller is not an owner/admin.
	 */
	private function assertOwner(array $application, IUser $user): void {
		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			$permissions = [];
		}

		$userGroups = $this->permissionResolver->resolveUserGroups($user);
		$allowed = $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $user,
			userGroups: $userGroups,
			allowAdminBypass: true,
			roles: self::PUBLISH_ROLES
		);

		if ($allowed === true) {
			return;
		}

		throw new InsufficientPermissionException(
			message: 'User ' . $user->getUID() . ' is not an owner of Application '
				. (string)($application['slug'] ?? ($application['id'] ?? '?'))
		);
	}//end assertOwner()

	/**
	 * Coerce an OR result entry (ObjectEntity or array) to an associative array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseObject(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseObject()
}//end class
