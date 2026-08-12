<?php

/**
 * OpenBuild ApplicationVersionsController
 *
 * REST surface for the versioned-app model (ADR-002 / spec
 * `application-versions`). Exposes CRUD over ApplicationVersion rows
 * scoped to a parent Application slug, plus the strategy-aware delete
 * endpoint defined in spec REQ-OBV-108.
 *
 * Endpoints (registered in appinfo/routes.php):
 *
 *   - GET    /api/applications/{slug}/versions
 *   - GET    /api/applications/{slug}/versions/{versionSlug}
 *   - POST   /api/applications/{slug}/versions
 *   - PUT    /api/applications/{slug}/versions/{versionSlug}
 *   - DELETE /api/applications/{slug}/versions/{versionSlug}?strategy=...
 *
 * All endpoints carry `#[NoAdminRequired]` per spec REQ-OBV-107 — the
 * parent Application's `permissions` RBAC block (owners/editors for
 * write, viewers for read) is enforced server-side here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-24
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-47
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller serving the ApplicationVersion CRUD + strategy-delete surface.
 */
class ApplicationVersionsController extends Controller {
	/**
	 * Nextcloud admin group identifier used as the RBAC bypass anchor.
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * WF2: explicit allowlist of fields the caller may mutate via PUT.
	 *
	 * Any key sent by the client that is NOT in this list is silently
	 * dropped before the array_merge, preventing future schema additions
	 * (e.g. `lifecycleOverride`) from becoming unintended write channels.
	 * Immutable fields (`application`, `id`, `uuid`, `@self`) are
	 * enforced separately below the merge.
	 *
	 * @var array<int,string>
	 */
	private const MUTABLE_FIELDS = [
		'name',
		'slug',
		'manifest',
		'register',
		'semver',
		'status',
		'promotesTo',
	];

	/**
	 * Roles that grant write access to ApplicationVersion rows.
	 *
	 * @var array<int,string>
	 */
	private const WRITE_ROLES = ['owners', 'editors'];

	/**
	 * Roles that grant read access to ApplicationVersion rows.
	 *
	 * @var array<int,string>
	 */
	private const READ_ROLES = ['owners', 'editors', 'viewers'];

	/**
	 * Audit-event identifier emitted to the OR audit trail when an admin
	 * bypasses the per-Application permissions check (REQ-OBRBAC-006).
	 */
	private const EVENT_ADMIN_BYPASS = 'rbac.admin_bypass';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param ObjectService $objectService OpenRegister object service
	 * @param RegisterMapper $registerMapper Resolves register slugs
	 * @param SchemaMapper $schemaMapper Resolves schema slugs
	 * @param IUserSession $userSession Current Nextcloud user session
	 * @param IGroupManager $groupManager Group membership resolver
	 * @param ApplicationVersionService $versionService Owner of the imperative logic
	 * @param AuditTrailMapper|null $auditTrailMapper Optional OR audit-trail writer (null until OR loaded)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly ObjectService $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ApplicationVersionService $versionService,
		private readonly ?AuditTrailMapper $auditTrailMapper = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List ApplicationVersions for the named Application (spec REQ-OBV-107).
	 *
	 * @param string $appSlug Parent Application slug
	 *
	 * @return JSONResponse Versions array on 200, error envelope on miss
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-24
	 */
	#[NoAdminRequired]
	public function index(string $appSlug): JSONResponse {
		$authError = $this->requireRole(slug: $appSlug, roles: self::READ_ROLES);
		if ($authError !== null) {
			return $authError;
		}

		try {
			$application = $this->loadApplication(slug: $appSlug);
			if ($application === null) {
				return $this->errorResponse(code: 'not_found', detail: 'Application ' . $appSlug . ' not found', status: Http::STATUS_NOT_FOUND);
			}

			$applicationUuid = (string)($application['id'] ?? $application['uuid'] ?? '');

			$ids = $this->resolveRegisterAndSchema(schemaSlug: ApplicationVersionService::APPLICATION_VERSION_SCHEMA);
			if ($ids === null) {
				return $this->errorResponse(code: 'not_found', detail: 'Application ' . $appSlug . ' not found', status: Http::STATUS_NOT_FOUND);
			}

			[$registerId, $schemaId] = $ids;

			// OR's searchObjects doesn't reliably filter by relation-string equality
			// on the `application` field (the value is stored both inline AND in
			// @self.relations, and the matcher matches neither shape consistently).
			// Fetch every ApplicationVersion row and filter client-side by the
			// parent Application UUID. Cheap — we expect ~3 rows per app.
			$rows = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
				]
			);

			$rowsList = [];
			if (is_array($rows) === true) {
				$rowsList = $rows;
			}

			$normalised = [];
			foreach ($rowsList as $row) {
				$normalisedRow = $this->normaliseObject(object: $row);
				$rowAppUuid = (string)($normalisedRow['application'] ?? '');
				if ($rowAppUuid !== $applicationUuid) {
					continue;
				}

				$normalised[] = $normalisedRow;
			}

			return new JSONResponse(data: $normalised, statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenBuild: ApplicationVersionsController::index failed for slug ' . $appSlug . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return $this->errorResponse(code: 'internal_error', detail: 'Failed to load versions');
		}//end try
	}//end index()

	/**
	 * Fetch a single ApplicationVersion by version slug (spec REQ-OBV-107).
	 *
	 * @param string $appSlug Parent Application slug
	 * @param string $versionSlug ApplicationVersion slug
	 *
	 * @return JSONResponse The version on 200, error envelope on miss
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-24
	 */
	#[NoAdminRequired]
	public function show(string $appSlug, string $versionSlug): JSONResponse {
		$authError = $this->requireRole(slug: $appSlug, roles: self::READ_ROLES);
		if ($authError !== null) {
			return $authError;
		}

		$version = $this->findVersionForApplication(slug: $appSlug, versionSlug: $versionSlug);
		if ($version === null) {
			return $this->errorResponse(code: 'not_found', detail: $versionSlug, status: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $version, statusCode: Http::STATUS_OK);
	}//end show()

	/**
	 * Create an ApplicationVersion under the named Application (spec REQ-OBV-107 / REQ-OBV-102).
	 *
	 * @param string $appSlug Parent Application slug
	 *
	 * @return JSONResponse 201 with the created version, or error envelope
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-24
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function create(string $appSlug): JSONResponse {
		$authError = $this->requireRole(slug: $appSlug, roles: self::WRITE_ROLES);
		if ($authError !== null) {
			return $authError;
		}

		try {
			$application = $this->loadApplication(slug: $appSlug);
			if ($application === null) {
				return $this->errorResponse(code: 'not_found', detail: 'Application ' . $appSlug . ' not found', status: Http::STATUS_NOT_FOUND);
			}

			$applicationUuid = (string)($application['id'] ?? $application['uuid'] ?? '');
			$payload = $this->collectPayload();

			// Strip any client-supplied UUID — OR mints its own on create.
			unset($payload['id'], $payload['uuid'], $payload['@self']);
			// Honour the back-reference even if the client forgot to send it.
			$payload['application'] = $applicationUuid;

			// Manifest-only versioning (REQ-OBV-107, design Decision 2): when no
			// `register` is supplied, inherit the current production version's
			// register so the new version SHARES production's data — no
			// per-version register is minted. An explicitly-supplied register is
			// honoured unchanged (the wizard / promotion paths keep that option).
			$suppliedRegister = (string)($payload['register'] ?? '');
			if ($suppliedRegister === '') {
				$inheritedRegister = $this->resolveProductionRegister(application: $application);
				if ($inheritedRegister === null) {
					return $this->errorResponse(
						code: 'no_register_to_inherit',
						detail: 'Application ' . $appSlug . ' has no production version to inherit a register from; supply an explicit `register`.',
						status: Http::STATUS_UNPROCESSABLE_ENTITY
					);
				}

				$payload['register'] = $inheritedRegister;
			}

			$payload = $this->versionService->onSave(current: null, next: $payload);

			$promotesTo = (string)($payload['promotesTo'] ?? '');
			if ($promotesTo !== '') {
				// Cycle guard requires a uuid; for a brand-new row use a stable
				// placeholder string that cannot occur in OR's actual UUID space.
				$this->versionService->guardNoCycle(
					currentUuid: '__pending_create__',
					proposedTargetUuid: $promotesTo
				);
			}

			$created = $this->objectService->saveObject(
				object: $payload,
				register: ApplicationVersionService::REGISTER_SLUG,
				schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
			);

			return new JSONResponse(
				data: $this->normaliseObject(object: $created),
				statusCode: Http::STATUS_CREATED
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenBuild: ApplicationVersionsController::create failed for slug ' . $appSlug . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return $this->errorResponse(
				code: 'create_failed',
				detail: $e->getMessage(),
				status: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}//end try
	}//end create()

	/**
	 * Update an ApplicationVersion (spec REQ-OBV-103 / REQ-OBV-104 / REQ-OBV-107).
	 *
	 * @param string $appSlug Parent Application slug
	 * @param string $versionSlug ApplicationVersion slug
	 *
	 * @return JSONResponse 200 with the updated version, or error envelope
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-24
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $appSlug, string $versionSlug): JSONResponse {
		$authError = $this->requireRole(slug: $appSlug, roles: self::WRITE_ROLES);
		if ($authError !== null) {
			return $authError;
		}

		try {
			$current = $this->findVersionForApplication(slug: $appSlug, versionSlug: $versionSlug);
			if ($current === null) {
				return $this->errorResponse(code: 'not_found', detail: $versionSlug, status: Http::STATUS_NOT_FOUND);
			}

			$currentUuid = (string)($current['id'] ?? $current['uuid'] ?? '');

			// WF2: strip caller input to the explicit MUTABLE_FIELDS allowlist
			// before merging, so new schema properties added in future cannot
			// become unintended write channels via implicit array_merge.
			$clientInput = array_intersect_key($this->collectPayload(), array_flip(self::MUTABLE_FIELDS));
			$payload = array_merge($current, $clientInput);
			unset($payload['@self']);

			// Preserve immutable fields.
			$payload['application'] = $current['application'] ?? null;
			$payload['id'] = $currentUuid;

			// Cycle guard on cross-row.
			$proposedPromotesTo = $payload['promotesTo'] ?? null;
			if (is_string($proposedPromotesTo) === true) {
				$cycleTarget = $proposedPromotesTo;
				if ($cycleTarget === '') {
					$cycleTarget = null;
				}

				$this->versionService->guardNoCycle(
					currentUuid: $currentUuid,
					proposedTargetUuid: $cycleTarget
				);
			}

			$payload = $this->versionService->onSave(current: $current, next: $payload);

			// Acquire an optimistic lock before the read-modify-write to prevent
			// concurrent UI / MCP writes silently losing each other's changes (issue #159).
			$locked = false;
			if (method_exists($this->objectService, 'lockObject') === true && $currentUuid !== '') {
				try {
					$this->objectService->lockObject(
						identifier: $currentUuid,
						process: 'openbuild.controller-update',
						duration: 15
					);
					$locked = true;
				} catch (Throwable $lockError) {
					return $this->errorResponse(
						code: 'version_locked',
						detail: 'Version ' . $versionSlug . ' is currently locked by another writer. Retry after a moment.',
						status: Http::STATUS_CONFLICT
					);
				}
			}

			try {
				$updated = $this->objectService->saveObject(
					object: $payload,
					register: ApplicationVersionService::REGISTER_SLUG,
					schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
					uuid: $currentUuid
				);
			} finally {
				if ($locked === true && method_exists($this->objectService, 'unlockObject') === true) {
					try {
						$this->objectService->unlockObject(identifier: $currentUuid);
					} catch (Throwable $unlockErr) {
						$this->logger->warning(
							'OpenBuild: failed to release update lock on ' . $currentUuid . ': ' . $unlockErr->getMessage()
						);
					}
				}
			}

			return new JSONResponse(
				data: $this->normaliseObject(object: $updated),
				statusCode: Http::STATUS_OK
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenBuild: ApplicationVersionsController::update failed for slug ' . $appSlug . '/' . $versionSlug . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return $this->errorResponse(
				code: 'update_failed',
				detail: $e->getMessage(),
				status: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}//end try
	}//end update()

	/**
	 * Delete an ApplicationVersion using the requested strategy (spec REQ-OBV-108).
	 *
	 * Accepts the `strategy` query parameter (`delete-now |
	 * orphan-grace | keep-register`). Missing/unknown values yield 400.
	 * Attempts to delete the parent Application's production version
	 * yield 422.
	 *
	 * @param string $appSlug Parent Application slug
	 * @param string $versionSlug ApplicationVersion slug
	 *
	 * @return JSONResponse 204 on success, error envelope otherwise
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function destroy(string $appSlug, string $versionSlug): JSONResponse {
		$authError = $this->requireRole(slug: $appSlug, roles: self::WRITE_ROLES);
		if ($authError !== null) {
			return $authError;
		}

		$strategy = (string)$this->request->getParam('strategy', '');
		if ($strategy === '') {
			return $this->errorResponse(
				code: 'missing_strategy',
				detail: 'Query parameter `strategy` is required (delete-now | orphan-grace | keep-register).',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$current = $this->findVersionForApplication(slug: $appSlug, versionSlug: $versionSlug);
			if ($current === null) {
				return $this->errorResponse(code: 'not_found', detail: $versionSlug, status: Http::STATUS_NOT_FOUND);
			}

			$currentUuid = (string)($current['id'] ?? $current['uuid'] ?? '');

			$this->versionService->deleteVersion(versionUuid: $currentUuid, strategy: $strategy);

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (Throwable $e) {
			$this->logger->info(
				'OpenBuild: ApplicationVersionsController::destroy refused for slug ' . $appSlug . '/' . $versionSlug . ': ' . $e->getMessage()
			);

			$message = $e->getMessage();
			$status = Http::STATUS_UNPROCESSABLE_ENTITY;
			$code = 'delete_failed';
			if (str_contains($message, 'Unknown deletion strategy') === true) {
				$status = Http::STATUS_BAD_REQUEST;
				$code = 'invalid_strategy';
			}

			return $this->errorResponse(code: $code, detail: $message, status: $status);
		}//end try
	}//end destroy()

	/**
	 * Release a version: set-as-production + publish + demote previous production.
	 *
	 * Owner-only with NO admin bypass (REQ-OBV-110 scenario 4): a Nextcloud admin
	 * who is not an owner of this Application cannot release. Delegates the
	 * cross-row mutation (publish chosen + move productionVersion pointer + archive
	 * previous production) to {@see ApplicationVersionService::releaseVersion()}.
	 *
	 * @param string $appSlug Parent Application slug
	 * @param string $versionSlug ApplicationVersion slug to release
	 *
	 * @return JSONResponse 200 with the release result, or an error envelope
	 *
	 * @spec openspec/changes/version-lifecycle-and-switcher/specs/application-versions/spec.md
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function release(string $appSlug, string $versionSlug): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		$application = $this->loadApplication(slug: $appSlug);
		if ($application === null) {
			return $this->errorResponse(
				code: 'not_found',
				detail: 'Application ' . $appSlug . ' not found',
				status: Http::STATUS_NOT_FOUND
			);
		}

		if ($this->isOwnerStrict(application: $application, user: $user) === false) {
			return $this->errorResponse(code: 'openbuild.rbac.not_owner', status: Http::STATUS_FORBIDDEN);
		}

		try {
			$version = $this->findVersionRowBySlug(application: $application, versionSlug: $versionSlug);
			if ($version === null) {
				return $this->errorResponse(code: 'not_found', detail: $versionSlug, status: Http::STATUS_NOT_FOUND);
			}

			$applicationUuid = (string)($application['id'] ?? $application['uuid'] ?? '');
			$versionUuid = (string)($version['id'] ?? $version['uuid'] ?? '');

			$result = $this->versionService->releaseVersion(
				applicationUuid: $applicationUuid,
				versionUuid: $versionUuid
			);

			return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->info(
				'OpenBuild: ApplicationVersionsController::release refused for slug ' . $appSlug . '/' . $versionSlug . ': ' . $e->getMessage()
			);
			return $this->errorResponse(
				code: 'release_failed',
				detail: $e->getMessage(),
				status: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}//end try
	}//end release()

	/**
	 * Resolve the register of the Application's current production version.
	 *
	 * Handles both the UUID-string and the inline-embedded-object shapes of
	 * `Application.productionVersion`. Returns null when there is no production
	 * version or it carries no register — the caller turns that into a 422.
	 *
	 * @param array<string,mixed> $application Normalised Application data
	 *
	 * @return string|null The production version's register slug, or null
	 */
	private function resolveProductionRegister(array $application): ?string {
		$productionVersion = ($application['productionVersion'] ?? null);

		if (is_array($productionVersion) === true) {
			$register = (string)($productionVersion['register'] ?? '');
			if ($register !== '') {
				return $register;
			}

			return null;
		}

		$versionUuid = (string)($productionVersion ?? '');
		if ($versionUuid === '') {
			return null;
		}

		try {
			$version = $this->objectService->find(
				id: $versionUuid,
				register: ApplicationVersionService::REGISTER_SLUG,
				schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($version === null) {
			return null;
		}

		$register = (string)($this->normaliseObject(object: $version)['register'] ?? '');
		if ($register !== '') {
			return $register;
		}

		return null;
	}//end resolveProductionRegister()

	/**
	 * Resolve the OpenBuild register + one of its schemas to the numeric IDs
	 * OR's `searchObjects` expects in `@self`.
	 *
	 * Both mapper find() calls THROW DoesNotExistException when absent — they do
	 * not return null, so uncaught they turn a #[NoAdminRequired] endpoint into a
	 * framework 500. Translated in ONE place so the four call sites cannot drift.
	 *
	 * @param string $schemaSlug Schema slug to resolve alongside the register.
	 *
	 * @return array{0:int,1:int}|null `[registerId, schemaId]`, null when absent.
	 */
	private function resolveRegisterAndSchema(string $schemaSlug): ?array {
		try {
			return [
				$this->registerMapper->find(
					ApplicationVersionService::REGISTER_SLUG,
					_multitenancy: false
				)->getId(),
				$this->schemaMapper->find($schemaSlug, _multitenancy: false)->getId(),
			];
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenBuild: could not resolve register/schema {schema}: {message}',
				['schema' => $schemaSlug, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return null;
		}//end try
	}//end resolveRegisterAndSchema()

	/**
	 * Find a version row by slug, scoped to the parent Application (robust).
	 *
	 * Mirrors {@see index()}'s client-side filter (OR relation-equality filters
	 * are unreliable on some installs), so release resolves the version reliably.
	 *
	 * @param array<string,mixed> $application Normalised Application data
	 * @param string $versionSlug The version slug to find
	 *
	 * @return array<string,mixed>|null The version row, or null on miss
	 */
	private function findVersionRowBySlug(array $application, string $versionSlug): ?array {
		$applicationUuid = (string)($application['id'] ?? $application['uuid'] ?? '');

		$ids = $this->resolveRegisterAndSchema(schemaSlug: ApplicationVersionService::APPLICATION_VERSION_SCHEMA);
		if ($ids === null) {
			return null;
		}

		[$registerId, $schemaId] = $ids;

		$rows = $this->objectService->searchObjects(
			query: ['@self' => ['register' => $registerId, 'schema' => $schemaId]]
		);
		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			$normalised = $this->normaliseObject(object: $row);
			if ((string)($normalised['application'] ?? '') !== $applicationUuid) {
				continue;
			}

			if ((string)($normalised['slug'] ?? '') === $versionSlug) {
				return $normalised;
			}
		}

		return null;
	}//end findVersionRowBySlug()

	/**
	 * Owner-only check with NO admin bypass (REQ-OBV-110).
	 *
	 * Unlike {@see requireRole()} (which grants an audited admin bypass), the
	 * release operation requires an explicit `owners` grant — Nextcloud admin
	 * power does not auto-authorise it.
	 *
	 * @param array<string,mixed> $application Normalised Application data
	 * @param IUser $user The calling user
	 *
	 * @return bool True only when the caller is an owner principal
	 */
	private function isOwnerStrict(array $application, IUser $user): bool {
		$authorised = $this->collectAuthorisedPrincipals(application: $application, roles: ['owners']);
		if (in_array($user->getUID(), $authorised['users'], true) === true) {
			return true;
		}

		return (count(array_intersect($this->getUserGroupIds(user: $user), $authorised['groups'])) > 0);
	}//end isOwnerStrict()

	/**
	 * Resolve the parent Application by slug, returning a normalised array.
	 *
	 * @param string $slug Parent Application slug
	 *
	 * @return array<string,mixed>|null Application record or null when missing
	 */
	private function loadApplication(string $slug): ?array {
		$ids = $this->resolveRegisterAndSchema(schemaSlug: ApplicationVersionService::APPLICATION_SCHEMA);
		if ($ids === null) {
			return null;
		}

		[$registerId, $schemaId] = $ids;

		$rows = $this->objectService->searchObjects(
			query: [
				'@self' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
				'slug' => $slug,
			]
		);

		if (is_array($rows) === false || $rows === []) {
			return null;
		}

		return $this->normaliseObject(object: $rows[0]);
	}//end loadApplication()

	/**
	 * Resolve an ApplicationVersion by version slug, scoped to the parent Application.
	 *
	 * Returns null when either the Application or the version is missing,
	 * or when the version's `application` relation does not back-reference
	 * this Application (IDOR-safe).
	 *
	 * @param string $slug Parent Application slug
	 * @param string $versionSlug ApplicationVersion slug
	 *
	 * @return array<string,mixed>|null Version record or null on miss
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-24
	 */
	private function findVersionForApplication(string $slug, string $versionSlug): ?array {
		$application = $this->loadApplication(slug: $slug);
		if ($application === null) {
			return null;
		}

		$applicationUuid = (string)($application['id'] ?? $application['uuid'] ?? '');

		$ids = $this->resolveRegisterAndSchema(schemaSlug: ApplicationVersionService::APPLICATION_VERSION_SCHEMA);
		if ($ids === null) {
			return null;
		}

		[$registerId, $schemaId] = $ids;

		$rows = $this->objectService->searchObjects(
			query: [
				'@self' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
				'slug' => $versionSlug,
				'application' => $applicationUuid,
			]
		);

		if (is_array($rows) === false || $rows === []) {
			return null;
		}

		return $this->normaliseObject(object: $rows[0]);
	}//end findVersionForApplication()

	/**
	 * Verify the current user has any of the named roles on the parent Application.
	 *
	 * Admin callers pass via an audited bypass that writes both to the OR
	 * per-object audit trail (REQ-OBRBAC-006, issue #162) and to the PSR log
	 * so the permission-history panel shows version-level admin operations.
	 *
	 * @param string $slug Parent Application slug
	 * @param array<int,string> $roles List of role names (`owners`, `editors`, `viewers`)
	 *
	 * @return JSONResponse|null Null on allow, 401/403/404 envelope on deny
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-47
	 */
	private function requireRole(string $slug, array $roles): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		$application = $this->loadApplication(slug: $slug);
		if ($application === null) {
			return $this->errorResponse(
				code: 'not_found',
				detail: 'Application ' . $slug . ' not found',
				status: Http::STATUS_NOT_FOUND
			);
		}

		if ($this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === true) {
			$this->recordAdminBypass(slug: $slug, actor: $user->getUID());
			return null;
		}

		$authorised = $this->collectAuthorisedPrincipals(application: $application, roles: $roles);
		if (in_array($user->getUID(), $authorised['users'], true) === true) {
			return null;
		}

		if (count(array_intersect($this->getUserGroupIds(user: $user), $authorised['groups'])) > 0) {
			return null;
		}

		return $this->errorResponse(
			code: 'openbuild.rbac.no_role',
			status: Http::STATUS_FORBIDDEN
		);
	}//end requireRole()

	/**
	 * Record an admin-bypass event in the OR audit trail and the PSR log.
	 *
	 * Mirrors ApplicationsController::recordAdminBypass so that version-level
	 * admin bypasses surface in the permission-history panel (REQ-OBRBAC-006,
	 * issue #162). Falls back to PSR-only when the audit mapper is unavailable.
	 *
	 * @param string $slug The slug used in the audit envelope
	 * @param string $actor The bypassing user's UID
	 *
	 * @return void
	 */
	private function recordAdminBypass(string $slug, string $actor): void {
		$timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$context = [
			'event' => self::EVENT_ADMIN_BYPASS,
			'actor' => $actor,
			'slug' => $slug,
			'timestamp' => $timestamp,
			'surface' => 'ApplicationVersionsController',
		];

		if ($this->auditTrailMapper !== null) {
			try {
				// Attempt to find the Application entity for the audit-trail key.
				$appEntity = null;
				try {
					$registerId = $this->registerMapper->find(
						ApplicationVersionService::REGISTER_SLUG,
						_multitenancy: false
					)->getId();
					$schemaId = $this->schemaMapper->find(
						ApplicationVersionService::APPLICATION_SCHEMA,
						_multitenancy: false
					)->getId();
					$rows = $this->objectService->searchObjects(
						query: ['@self' => ['register' => $registerId, 'schema' => $schemaId], 'slug' => $slug]
					);
					if (is_array($rows) === true && $rows !== [] && $rows[0] instanceof ObjectEntity) {
						$appEntity = $rows[0];
					}
				} catch (Throwable $_e) {
					// Entity lookup failure must not abort the bypass audit.
				}

				if ($appEntity instanceof ObjectEntity) {
					$this->auditTrailMapper->createAuditTrailEntry(
						object: $appEntity,
						action: self::EVENT_ADMIN_BYPASS,
						context: $context
					);
					$this->logger->info('OpenBuild: rbac.admin_bypass exercised', $context);
					return;
				}
			} catch (Throwable $e) {
				// WF3: audit-trail write failure is a COMPLIANCE gap, not a
				// routine warning. Emit at CRITICAL so ops alerting picks it up.
				// Per REQ-OBRBAC-007 the OR audit trail is the system of record
				// for admin-bypass events; silent fallback defeats forensic review.
				$this->logger->critical(
					'OpenBuild: failed to record admin bypass in OR audit trail — COMPLIANCE GAP; bypass event lost from system of record',
					array_merge($context, ['exception' => $e->getMessage()])
				);
			}//end try
		}//end if

		$this->logger->info('OpenBuild: rbac.admin_bypass exercised', $context);
	}//end recordAdminBypass()

	/**
	 * Flatten the named role buckets into user / group principal lists.
	 *
	 * Mirrors ApplicationsController::collectAuthorisedGroups but accepts
	 * a role filter so read endpoints can include viewers while write
	 * endpoints exclude them.
	 *
	 * @param array<string,mixed> $application The Application data
	 * @param array<int,string> $roles Role names to include
	 *
	 * @return array{users: array<int,string>, groups: array<int,string>}
	 */
	private function collectAuthorisedPrincipals(array $application, array $roles): array {
		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			return ['users' => [], 'groups' => []];
		}

		$userSet = [];
		$groupSet = [];
		foreach ($roles as $role) {
			$bucket = ($permissions[$role] ?? []);
			if (is_array($bucket) === false) {
				continue;
			}

			$this->absorbPrincipalBucket(bucket: $bucket, userSet: $userSet, groupSet: $groupSet);
		}

		return [
			'users' => array_keys($userSet),
			'groups' => array_keys($groupSet),
		];
	}//end collectAuthorisedPrincipals()

	/**
	 * Classify a permission-role bucket into user-UID and group-GID sets.
	 *
	 * @param array<int,mixed> $bucket The raw bucket (owners/editors/viewers entries)
	 * @param array<string,bool> $userSet Accumulating UID set (passed by reference)
	 * @param array<string,bool> $groupSet Accumulating GID set (passed by reference)
	 *
	 * @return void
	 */
	private function absorbPrincipalBucket(array $bucket, array &$userSet, array &$groupSet): void {
		foreach ($bucket as $principal) {
			if (is_string($principal) === false || $principal === '') {
				continue;
			}

			if (str_starts_with($principal, 'user:') === true) {
				$uid = substr($principal, 5);
				if ($uid !== '') {
					$userSet[$uid] = true;
				}

				continue;
			}

			$gid = $principal;
			if (str_starts_with($principal, 'group:') === true) {
				$gid = substr($principal, 6);
			}

			if ($gid !== '') {
				$groupSet[$gid] = true;
			}
		}//end foreach
	}//end absorbPrincipalBucket()

	/**
	 * Read the current user's group GIDs.
	 *
	 * @param IUser $user The Nextcloud user
	 *
	 * @return array<int,string>
	 */
	private function getUserGroupIds(IUser $user): array {
		$groups = $this->groupManager->getUserGroups($user);
		$ids = [];
		foreach ($groups as $group) {
			$ids[] = $group->getGID();
		}

		return $ids;
	}//end getUserGroupIds()

	/**
	 * Read the JSON / form payload from the current request.
	 *
	 * @return array<string,mixed>
	 */
	private function collectPayload(): array {
		$params = $this->request->getParams();
		unset($params['_route']);
		return $params;
	}//end collectPayload()

	/**
	 * Build a uniform error envelope.
	 *
	 * @param string $code Error code
	 * @param string|null $detail Optional detail message
	 * @param int $status HTTP status code
	 *
	 * @return JSONResponse
	 */
	private function errorResponse(string $code, ?string $detail = null, int $status = Http::STATUS_BAD_REQUEST): JSONResponse {
		$body = ['error' => $code];
		if ($detail !== null) {
			$body['detail'] = $detail;
		}

		return new JSONResponse(data: $body, statusCode: $status);
	}//end errorResponse()

	/**
	 * Coerce an OR result entry to a plain associative array.
	 *
	 * @param mixed $object The OR object/result entry
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
