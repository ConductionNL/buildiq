<?php

/**
 * Unit tests for ApplicationsController::diffVersions (spec #6 openbuild-versioning).
 *
 * Pins the three contract points called out in design.md §Diff endpoint:
 *   - 200 returns `{ from, to }` with manifest + version + publishedAt for both
 *   - 404 when either UUID is unknown (or — same thing — its applicationUuid
 *     does not match the slug-resolved Application; gate-7 IDOR closure)
 *   - 404 when slug → BuiltAppRoute lookup misses (org-scope rejection)
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationsController::diffVersions.
 */
class ApplicationsControllerDiffTest extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var ApplicationsController
	 */
	private ApplicationsController $controller;

	/**
	 * Mock OR ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);

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

		// diffVersions() now enforces RBAC (C5 fix). Use an admin user so the
		// admin-bypass path grants access for tests that cover diff logic.
		// A dedicated deny test (testDiffVersionsReturns403WhenCallerHasNoRole)
		// exercises the non-admin / no-permissions path separately.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('diff-tester');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		// Admin user: isInGroup('diff-tester', 'admin') → true.
		$groupManager->method('isInGroup')->willReturn(true);

		$permissionResolver = new PermissionResolver($groupManager, $this->logger);

		$this->controller = new ApplicationsController(
			request: $request,
			logger: $this->logger,
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			userSession: $userSession,
			groupManager: $groupManager,
			manifestResolver: $this->createMock(ManifestResolverService::class),
			permissionResolver: $permissionResolver,
			channelApplier: $this->createMock(\OCA\OpenBuild\Service\AppChannelApplier::class),
		);
	}//end setUp()

	/**
	 * Wrap a plain array into an ObjectEntity-like test double.
	 *
	 * OR's ObjectService::find() returns an ObjectEntity (or null); the
	 * controller normalises the result via jsonSerialize().
	 *
	 * @param array<string, mixed> $payload Serialised object payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * Happy path: both UUIDs resolve to valid ApplicationVersion rows whose
	 * applicationUuid matches the slug-resolved Application; we get a 200
	 * with both blobs.
	 *
	 * @return void
	 */
	public function testDiffVersionsReturns200WithBothBlobs(): void {
		$route = ['applicationUuid' => 'app-uuid-1'];
		$application = [
			'@self' => ['id' => 'app-uuid-1'],
			'manifest' => ['version' => '1.1.0', 'pages' => []],
			'version' => '1.1.0',
		];
		$oldVersion = [
			'@self' => ['id' => 'snap-old'],
			'applicationUuid' => 'app-uuid-1',
			'version' => '1.0.0',
			'manifest' => ['version' => '1.0.0', 'pages' => []],
			'publishedAt' => '2026-05-01T10:00:00Z',
		];
		$newVersion = [
			'@self' => ['id' => 'snap-new'],
			'applicationUuid' => 'app-uuid-1',
			'version' => '1.1.0',
			'manifest' => ['version' => '1.1.0', 'pages' => [['id' => 'extra']]],
			'publishedAt' => '2026-05-05T10:00:00Z',
		];

		$this->objectService->method('searchObjects')->willReturn([$route]);
		$this->objectService->method('find')
			->willReturnCallback(function (...$args) use ($application, $oldVersion, $newVersion) {
				$id = $args['id'] ?? $args[0];
				if ($id === 'app-uuid-1') {
					return $this->entity($application);
				}
				if ($id === 'snap-old') {
					return $this->entity($oldVersion);
				}
				if ($id === 'snap-new') {
					return $this->entity($newVersion);
				}
				return null;
			});

		$result = $this->controller->diffVersions(slug: 'hello-world', from: 'snap-old', to: 'snap-new');

		self::assertSame(Http::STATUS_OK, $result->getStatus());
		$data = $result->getData();
		self::assertArrayHasKey('from', $data);
		self::assertArrayHasKey('to', $data);
		self::assertSame(['version' => '1.0.0', 'pages' => []], $data['from']['manifest']);
		self::assertSame('1.0.0', $data['from']['version']);
		self::assertSame('2026-05-01T10:00:00Z', $data['from']['publishedAt']);
		self::assertSame('1.1.0', $data['to']['version']);
		self::assertSame([['id' => 'extra']], $data['to']['manifest']['pages']);
	}//end testDiffVersionsReturns200WithBothBlobs()

	/**
	 * Unknown UUIDs (or — same surface — UUIDs that point at a snapshot belonging
	 * to a DIFFERENT Application) must return 404. This is the gate-7 IDOR-safe
	 * path: we never leak the existence of a snapshot from another org's app.
	 *
	 * @return void
	 */
	public function testDiffVersionsReturns404WhenVersionUuidUnknown(): void {
		$route = ['applicationUuid' => 'app-uuid-1'];
		$application = [
			'@self' => ['id' => 'app-uuid-1'],
			'manifest' => ['version' => '1.0.0'],
			'version' => '1.0.0',
		];

		$this->objectService->method('searchObjects')->willReturn([$route]);
		$this->objectService->method('find')
			->willReturnCallback(function (...$args) use ($application) {
				$id = $args['id'] ?? $args[0];
				if ($id === 'app-uuid-1') {
					return $this->entity($application);
				}
				// Both snapshot lookups return null → 404 from the resolver.
				return null;
			});

		$result = $this->controller->diffVersions(slug: 'hello-world', from: 'snap-missing', to: 'snap-also-missing');

		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		self::assertSame('not_found', $data['error']);
	}//end testDiffVersionsReturns404WhenVersionUuidUnknown()

	/**
	 * Snapshot whose applicationUuid is for a DIFFERENT Application → 404.
	 *
	 * This is the explicit IDOR-safe cross-app check on the resolver — a
	 * snapshot row that belongs to a different parent must surface as a miss
	 * (same status code as "unknown UUID") so we never leak existence.
	 *
	 * @return void
	 */
	public function testDiffVersionsReturns404WhenSnapshotIsForDifferentApplication(): void {
		$route = ['applicationUuid' => 'app-uuid-1'];
		$application = [
			'@self' => ['id' => 'app-uuid-1'],
			'manifest' => ['version' => '1.0.0'],
		];
		$foreignSnap = [
			'@self' => ['id' => 'snap-foreign'],
			// Snapshot belongs to a different Application.
			'applicationUuid' => 'app-uuid-2',
			'manifest' => ['leaked' => true],
			'version' => '9.9.9',
		];

		$this->objectService->method('searchObjects')->willReturn([$route]);
		$this->objectService->method('find')
			->willReturnCallback(function (...$args) use ($application, $foreignSnap) {
				$id = $args['id'] ?? $args[0];
				if ($id === 'app-uuid-1') {
					return $this->entity($application);
				}
				if ($id === 'snap-foreign') {
					return $this->entity($foreignSnap);
				}
				return null;
			});

		// `from = draft` is fine (resolves on the current Application);
		// `to = snap-foreign` is the IDOR attempt.
		$result = $this->controller->diffVersions(slug: 'hello-world', from: 'draft', to: 'snap-foreign');

		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		self::assertSame('not_found', $data['error']);
		// Verify the leaked manifest is NOT in the response body anywhere.
		self::assertStringNotContainsString('leaked', json_encode($data));
	}//end testDiffVersionsReturns404WhenSnapshotIsForDifferentApplication()

	/**
	 * Unknown slug — no BuiltAppRoute row → 404. The gate-7 org-scope rejection
	 * path; we never even reach the snapshot resolver.
	 *
	 * @return void
	 */
	public function testDiffVersionsReturns404WhenSlugUnknown(): void {
		$this->objectService->method('searchObjects')->willReturn([]);

		$result = $this->controller->diffVersions(slug: 'no-such-app', from: 'snap-a', to: 'snap-b');

		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		$data = $result->getData();
		self::assertSame('not_found', $data['error']);
	}//end testDiffVersionsReturns404WhenSlugUnknown()

	/**
	 * C5 RBAC gate: a non-admin caller with no role on the Application must
	 * receive 403, not the manifest diff data.
	 *
	 * @return void
	 */
	public function testDiffVersionsReturns403WhenCallerHasNoRole(): void {
		// Build a controller where the caller is NOT in the admin group and has
		// no entry in the Application's permissions block.
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

		$outsider = $this->createMock(IUser::class);
		$outsider->method('getUID')->willReturn('outsider');
		$outsiderSession = $this->createMock(IUserSession::class);
		$outsiderSession->method('getUser')->willReturn($outsider);

		$noAdminGroupManager = $this->createMock(IGroupManager::class);
		$noAdminGroupManager->method('getUserGroups')->willReturn([]);
		// outsider is NOT an admin.
		$noAdminGroupManager->method('isInGroup')->willReturn(false);

		$objectService = $this->createMock(ObjectService::class);

		$route = ['applicationUuid' => 'app-uuid-1'];
		$application = [
			'@self' => ['id' => 'app-uuid-1'],
			'manifest' => ['version' => '1.0.0'],
			'version' => '1.0.0',
			'permissions' => [
				'owners' => ['user:owner-only'],
				'editors' => [],
				'viewers' => [],
			],
		];
		$objectService->method('searchObjects')->willReturn([$route]);
		$objectService->method('find')
			->willReturnCallback(function (...$args) use ($application) {
				$id = $args['id'] ?? $args[0];
				if ($id === 'app-uuid-1') {
					$entity = $this->createMock(ObjectEntity::class);
					$entity->method('jsonSerialize')->willReturn($application);
					return $entity;
				}
				return null;
			});

		$noAdminPermissionResolver = new PermissionResolver($noAdminGroupManager, $this->createMock(LoggerInterface::class));

		$controller = new ApplicationsController(
			request: $request,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			userSession: $outsiderSession,
			groupManager: $noAdminGroupManager,
			manifestResolver: $this->createMock(ManifestResolverService::class),
			permissionResolver: $noAdminPermissionResolver,
			channelApplier: $this->createMock(\OCA\OpenBuild\Service\AppChannelApplier::class),
		);

		$result = $controller->diffVersions(slug: 'hello-world', from: 'draft', to: 'draft');

		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
		$data = $result->getData();
		self::assertSame('openbuild.rbac.no_role', $data['code']);
	}//end testDiffVersionsReturns403WhenCallerHasNoRole()
}//end class
