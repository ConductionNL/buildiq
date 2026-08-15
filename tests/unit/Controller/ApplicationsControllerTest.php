<?php

/**
 * Unit tests for ApplicationsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
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

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ApplicationsController;
use OCA\OpenBuild\Service\ManifestResolverService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationsController::getManifest, including the RBAC
 * permissions check introduced by the openbuild-rbac change.
 */
class ApplicationsControllerTest extends TestCase {
	/**
	 * Mock OR ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock OR audit-trail mapper.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper&MockObject $auditTrailMapper;

	/**
	 * Mock manifest resolver — stubbed in {@see buildController()} so
	 * `filterManifestForCaller()` / `resolveCallerPermissionsForDisplay()`
	 * (spec `runtime-group-scoped-access`) behave as pass-through / empty by
	 * default, matching this file's existing tests which exercise the RBAC
	 * gate, not the group-scoped filter (covered separately in
	 * `ManifestResolverServicePermissionFilterTest`).
	 *
	 * @var ManifestResolverService&MockObject
	 */
	private ManifestResolverService&MockObject $manifestResolver;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->manifestResolver = $this->createMock(ManifestResolverService::class);
		$this->manifestResolver->method('filterManifestForCaller')->willReturnArgument(0);
		$this->manifestResolver->method('resolveCallerPermissionsForDisplay')->willReturn([]);
	}//end setUp()

	/**
	 * Build the controller with a default user/group fixture.
	 *
	 * @param string $uid Caller UID
	 * @param array<int, string> $callerGroups Group IDs the caller belongs to
	 * @param bool $isAdmin Whether the caller is in the `admin` group
	 *
	 * @return ApplicationsController
	 */
	private function buildController(string $uid = 'bob', array $callerGroups = [], bool $isAdmin = false): ApplicationsController {
		$request = $this->createMock(IRequest::class);

		$registerEntity = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$registerEntity->method('getId')->willReturn(926);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($registerEntity);

		$schemaEntity = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$schemaEntity->method('getId')->willReturn(1635);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schemaEntity);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		$groupMocks = [];
		foreach ($callerGroups as $gid) {
			$g = $this->createMock(IGroup::class);
			$g->method('getGID')->willReturn($gid);
			$groupMocks[] = $g;
		}
		$this->groupManager->method('getUserGroups')->with($user)->willReturn($groupMocks);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static function (string $callerUid, string $gid) use ($uid, $isAdmin): bool {
				return $callerUid === $uid && $gid === 'admin' && $isAdmin === true;
			}
		);

		$permissionResolver = new PermissionResolver($this->groupManager, $this->createMock(\Psr\Log\LoggerInterface::class));

		return new ApplicationsController(
			request: $request,
			logger: $this->logger,
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			manifestResolver: $this->manifestResolver,
			permissionResolver: $permissionResolver,
			channelApplier: $this->createMock(\OCA\OpenBuild\Service\AppChannelApplier::class),
			auditTrailMapper: $this->auditTrailMapper,
		);
	}//end buildController()

	/**
	 * Wire the OR mocks to return a route to an Application carrying the
	 * given permissions block (and an empty manifest object for happy
	 * paths that need a 200).
	 *
	 * @param array<string, mixed> $permissions The Application's permissions block
	 *
	 * @return void
	 */
	private function wireApplication(array $permissions): void {
		$manifest = [
			'version' => '1.0.0',
			'menu' => [],
			'pages' => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
		];
		$this->objectService->method('searchObjects')
			->willReturn([['applicationUuid' => 'abc-123']]);

		// OR's ObjectService::find() returns an ObjectEntity (or null); the
		// controller normalises it via jsonSerialize().
		$applicationEntity = $this->createMock(ObjectEntity::class);
		$applicationEntity->method('jsonSerialize')->willReturn([
			'manifest' => $manifest,
			'permissions' => $permissions,
		]);
		$this->objectService->method('find')->willReturn($applicationEntity);
	}//end wireApplication()

	/**
	 * Variant of wireApplication that also sets the Application's authoritative
	 * display `name` and the (possibly divergent) manifest-blob `name` — used by
	 * the getManifest name-authority tests (#32 Fix B).
	 *
	 * @param string $appName The Application entity's authoritative display name.
	 * @param string|null $manifestName The stored manifest blob's own name (null = absent).
	 *
	 * @return void
	 */
	private function wireApplicationWithName(string $appName, ?string $manifestName): void {
		$manifest = [
			'version' => '1.0.0',
			'menu' => [],
			'pages' => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
		];
		if ($manifestName !== null) {
			$manifest['name'] = $manifestName;
		}

		$this->objectService->method('searchObjects')
			->willReturn([['applicationUuid' => 'abc-123']]);

		$applicationEntity = $this->createMock(ObjectEntity::class);
		$applicationEntity->method('jsonSerialize')->willReturn([
			'name' => $appName,
			'manifest' => $manifest,
			'permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => []],
		]);
		$this->objectService->method('find')->willReturn($applicationEntity);
	}//end wireApplicationWithName()

	/**
	 * Happy path — slug resolves to a published Application; manifest is returned unwrapped
	 * to a caller whose group is in `permissions.viewers`.
	 *
	 * @return void
	 */
	public function testGetManifestReturnsManifestForViewer(): void {
		$controller = $this->buildController(uid: 'bob', callerGroups: ['team-alpha']);
		$this->wireApplication(permissions: ['owners' => [], 'editors' => [], 'viewers' => ['team-alpha']]);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertIsArray($result->getData());
		self::assertArrayHasKey('pages', $result->getData());
	}//end testGetManifestReturnsManifestForViewer()

	/**
	 * Owner role passes the RBAC check.
	 *
	 * @return void
	 */
	public function testGetManifestPassesForOwner(): void {
		$controller = $this->buildController(uid: 'alice', callerGroups: ['team-alpha']);
		$this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
	}//end testGetManifestPassesForOwner()

	/**
	 * Editor role passes the RBAC check.
	 *
	 * @return void
	 */
	public function testGetManifestPassesForEditor(): void {
		$controller = $this->buildController(uid: 'carol', callerGroups: ['team-beta']);
		$this->wireApplication(permissions: ['owners' => [], 'editors' => ['team-beta'], 'viewers' => []]);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
	}//end testGetManifestPassesForEditor()

	/**
	 * Caller with no role intersection gets 403 (NOT 404) — REQ-OBRBAC-002.
	 *
	 * @return void
	 */
	public function testGetManifestReturns403ForNoRole(): void {
		$controller = $this->buildController(uid: 'eve', callerGroups: ['stranger']);
		$this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
		$data = $result->getData();
		self::assertSame('forbidden', $data['error']);
		self::assertSame('openbuild.rbac.no_role', $data['code']);
		// The 403 body MUST NOT leak any manifest payload (REQ-OBRBAC-002).
		self::assertArrayNotHasKey('manifest', $data);
		self::assertArrayNotHasKey('pages', $data);
		self::assertArrayNotHasKey('name', $data);
	}//end testGetManifestReturns403ForNoRole()

	/**
	 * Empty `permissions` array still produces a 403 — no group means no role.
	 *
	 * @return void
	 */
	public function testGetManifestReturns403WhenPermissionsEmpty(): void {
		$controller = $this->buildController(uid: 'eve', callerGroups: ['stranger']);
		$this->wireApplication(permissions: ['owners' => [], 'editors' => [], 'viewers' => []]);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
	}//end testGetManifestReturns403WhenPermissionsEmpty()

	/**
	 * Admin bypass — a caller in the `admin` group passes even without a role,
	 * and the bypass is logged for audit (REQ-OBRBAC-006).
	 *
	 * @return void
	 */
	public function testGetManifestAdminBypassWritesAudit(): void {
		$controller = $this->buildController(uid: 'sysadmin', callerGroups: ['admin'], isAdmin: true);
		$this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

		$this->logger->expects(self::atLeastOnce())
			->method('info')
			->with(
				self::stringContains('rbac.admin_bypass'),
				self::callback(static function (array $ctx): bool {
					return ($ctx['event'] ?? null) === 'rbac.admin_bypass'
						&& ($ctx['actor'] ?? null) === 'sysadmin'
						&& ($ctx['slug'] ?? null) === 'hello-world';
				})
			);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
	}//end testGetManifestAdminBypassWritesAudit()

	/**
	 * Unknown slug → 404 with not_found error code (preserved from spec #1).
	 *
	 * @return void
	 */
	public function testGetManifestReturns404WhenSlugUnknown(): void {
		$controller = $this->buildController();
		$this->objectService->method('searchObjects')->willReturn([]);

		$result = $controller->getManifest(slug: 'no-such-app');

		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		self::assertSame('not_found', $data['error']);
	}//end testGetManifestReturns404WhenSlugUnknown()

	/**
	 * Inconsistent state — route exists but no applicationUuid → 500.
	 *
	 * @return void
	 */
	public function testGetManifestReturns500WhenRouteMissingApplicationUuid(): void {
		$controller = $this->buildController();
		$this->objectService->method('searchObjects')
			->willReturn([['slug' => 'hello-world']]);

		$this->logger->expects(self::atLeastOnce())->method('warning');

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
		$data = $result->getData();
		self::assertSame('inconsistent_state', $data['error']);
	}//end testGetManifestReturns500WhenRouteMissingApplicationUuid()

	/**
	 * listMine returns only Applications the caller has a role on
	 * (REQ-OBRBAC-002 / REQ-OBR-007). Non-role rows are filtered out
	 * server-side; the caller never sees their `permissions` or
	 * `manifest` payloads.
	 *
	 * @return void
	 */
	public function testListMineFiltersToRoledApplications(): void {
		$controller = $this->buildController(uid: 'bob', callerGroups: ['team-alpha']);

		$alpha = [
			'uuid' => 'app-alpha',
			'slug' => 'alpha',
			'name' => 'Alpha',
			'manifest' => ['version' => '1.0.0'],
			'permissions' => ['owners' => [], 'editors' => [], 'viewers' => ['team-alpha']],
		];
		$beta = [
			'uuid' => 'app-beta',
			'slug' => 'beta',
			'name' => 'Beta',
			'manifest' => ['version' => '1.0.0'],
			'permissions' => ['owners' => ['team-omega'], 'editors' => [], 'viewers' => []],
		];

		$this->objectService->method('searchObjects')->willReturn([$alpha, $beta]);

		$result = $controller->listMine();

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		self::assertCount(1, $data);
		self::assertSame('app-alpha', $data[0]['uuid']);
	}//end testListMineFiltersToRoledApplications()

	/**
	 * listMine returns the full unfiltered list to a Nextcloud admin
	 * and records a single audit event for the bypass (REQ-OBRBAC-006).
	 *
	 * @return void
	 */
	public function testListMineAdminBypassReturnsAllAndAudits(): void {
		$controller = $this->buildController(uid: 'sysadmin', callerGroups: ['admin'], isAdmin: true);

		$alpha = [
			'uuid' => 'app-alpha',
			'slug' => 'alpha',
			'permissions' => ['owners' => [], 'editors' => [], 'viewers' => ['team-alpha']],
		];
		$beta = [
			'uuid' => 'app-beta',
			'slug' => 'beta',
			'permissions' => ['owners' => ['team-omega'], 'editors' => [], 'viewers' => []],
		];

		$this->objectService->method('searchObjects')->willReturn([$alpha, $beta]);

		$this->logger->expects(self::atLeastOnce())
			->method('info')
			->with(
				self::stringContains('rbac.admin_bypass'),
				self::callback(static function (array $ctx): bool {
					return ($ctx['actor'] ?? null) === 'sysadmin'
						&& ($ctx['count'] ?? null) === 2;
				})
			);

		$result = $controller->listMine();

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertCount(2, $result->getData());
	}//end testListMineAdminBypassReturnsAllAndAudits()

	/**
	 * Admin bypass on the manifest endpoint writes an entry to the OR
	 * audit trail (REQ-OBRBAC-006), not just the PSR logger. The
	 * Application is passed in as an ObjectEntity so the mapper can be
	 * called.
	 *
	 * @return void
	 */
	public function testGetManifestAdminBypassWritesOrAuditTrail(): void {
		$controller = $this->buildController(uid: 'sysadmin', callerGroups: ['admin'], isAdmin: true);

		// Use an ObjectEntity-like stub so the controller reaches the
		// AuditTrailMapper write path (the array-only fixture used by
		// wireApplication() exercises the fallback PSR-log branch).
		$manifest = [
			'version' => '1.0.0',
			'menu' => [],
			'pages' => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
		];

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn([
			'manifest' => $manifest,
			'permissions' => ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []],
		]);

		$this->objectService->method('searchObjects')
			->willReturn([['applicationUuid' => 'abc-123']]);
		$this->objectService->method('find')->willReturn($entity);

		$this->auditTrailMapper->expects(self::once())
			->method('createAuditTrailEntry')
			->with(
				self::identicalTo($entity),
				self::equalTo('rbac.admin_bypass'),
				self::callback(static function (array $ctx): bool {
					return ($ctx['actor'] ?? null) === 'sysadmin'
						&& ($ctx['slug'] ?? null) === 'hello-world';
				})
			);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
	}//end testGetManifestAdminBypassWritesOrAuditTrail()

	/**
	 * admin-settings-owner-gating: a caller whose group intersects
	 * `permissions.owners` gets `runtime.user.isOwner === true` on the
	 * served manifest.
	 *
	 * @return void
	 */
	public function testGetManifestSetsIsOwnerTrueForOwner(): void {
		$controller = $this->buildController(uid: 'alice', callerGroups: ['team-alpha']);
		$this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		self::assertArrayHasKey('runtime', $data);
		self::assertTrue($data['runtime']['user']['isOwner']);
	}//end testGetManifestSetsIsOwnerTrueForOwner()

	/**
	 * admin-settings-owner-gating: a caller with a role (e.g. viewer) but NOT
	 * in `permissions.owners` gets `runtime.user.isOwner === false`.
	 *
	 * @return void
	 */
	public function testGetManifestSetsIsOwnerFalseForNonOwner(): void {
		$controller = $this->buildController(uid: 'bob', callerGroups: ['team-alpha']);
		$this->wireApplication(permissions: ['owners' => ['team-omega'], 'editors' => [], 'viewers' => ['team-alpha']]);

		$result = $controller->getManifest(slug: 'hello-world');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		self::assertFalse($data['runtime']['user']['isOwner']);
	}//end testGetManifestSetsIsOwnerFalseForNonOwner()

	/**
	 * admin-settings-owner-gating: an NC super-admin who is NOT in
	 * `permissions.owners` still gets `isOwner === false` — the whole point
	 * of the gate is that super-admin ≠ app-owner and there is NO
	 * super-admin fallback on the owner signal (unlike the read-gate's
	 * separate, audited admin bypass in {@see requirePermission()}).
	 *
	 * @return void
	 */
	public function testGetManifestSetsIsOwnerFalseForSuperAdminNotInOwners(): void {
		$controller = $this->buildController(uid: 'sysadmin', callerGroups: ['admin'], isAdmin: true);
		$this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

		$result = $controller->getManifest(slug: 'hello-world');

		// The admin bypass still lets the request through (read-gate, RBAC OK)...
		self::assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		// ...but the owner SIGNAL must remain false — no super-admin fallback.
		self::assertFalse($data['runtime']['user']['isOwner']);
	}//end testGetManifestSetsIsOwnerFalseForSuperAdminNotInOwners()

	/**
	 * admin-settings-owner-gating: when no Application context is resolvable
	 * (edge case — e.g. the legacy application-array-only fallback branch),
	 * the owner signal degrades to `false` and the endpoint does not fatal.
	 *
	 * This exercises the inconsistent-state 500 branch (no applicationUuid)
	 * as the closest reachable "no Application context" path through the
	 * public controller surface; the unit-level guarantee that
	 * {@see ApplicationsController::injectOwnerSignal()} never fatals on a
	 * null Application array is the behaviour under test.
	 *
	 * @return void
	 */
	public function testGetManifestOwnerSignalDegradesGracefullyWithoutApplicationContext(): void {
		$controller = $this->buildController(uid: 'bob', callerGroups: []);

		$reflection = new \ReflectionClass($controller);
		$method = $reflection->getMethod('injectOwnerSignal');
		$method->setAccessible(true);

		$manifest = ['version' => '1.0.0', 'pages' => []];

		$result = $method->invoke($controller, $manifest, null, null);

		self::assertIsArray($result);
		self::assertFalse($result['runtime']['user']['isOwner']);
	}//end testGetManifestOwnerSignalDegradesGracefullyWithoutApplicationContext()

	/**
	 * #32 Fix B (openbuild-runtime REQ-OBR-001): a stale, lower-cased manifest
	 * blob `name` MUST be overridden by the Application's authoritative cased
	 * `name`, so the runtime top-bar shows "Pet Store", not the raw slug.
	 *
	 * @return void
	 */
	public function testGetManifestOverridesStaleBlobNameWithApplicationName(): void {
		$controller = $this->buildController(uid: 'bob');
		$this->wireApplicationWithName(appName: 'Pet Store', manifestName: 'pet-store');

		$result = $controller->getManifest(slug: 'pet-store');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame('Pet Store', $result->getData()['name']);
	}//end testGetManifestOverridesStaleBlobNameWithApplicationName()

	/**
	 * #32 Fix B: when the manifest blob has no `name` at all, getManifest still
	 * supplies the Application's authoritative name (rather than leaving it
	 * absent, which caused the runtime to fall back to the raw slug).
	 *
	 * @return void
	 */
	public function testGetManifestSuppliesNameWhenBlobHasNone(): void {
		$controller = $this->buildController(uid: 'bob');
		$this->wireApplicationWithName(appName: 'Pet Store', manifestName: null);

		$result = $controller->getManifest(slug: 'pet-store');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertArrayHasKey('name', $result->getData());
		self::assertSame('Pet Store', $result->getData()['name']);
	}//end testGetManifestSuppliesNameWhenBlobHasNone()

	/**
	 * #32 Fix B: an already-consistent name (Application name == blob name) is
	 * returned unchanged.
	 *
	 * @return void
	 */
	public function testGetManifestKeepsConsistentName(): void {
		$controller = $this->buildController(uid: 'bob');
		$this->wireApplicationWithName(appName: 'Pet Store', manifestName: 'Pet Store');

		$result = $controller->getManifest(slug: 'pet-store');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		self::assertSame('Pet Store', $result->getData()['name']);
	}//end testGetManifestKeepsConsistentName()
}//end class
