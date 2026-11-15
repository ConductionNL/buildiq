<?php

/**
 * Behaviour tests for ApplicationVersionsController.
 *
 * Pins the role gate (who may read and write a version), the create / update /
 * destroy / release outcomes and their HTTP codes, register inheritance on
 * create, update locking, and the admin-bypass audit record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
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

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationVersionsController;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Controller\ApplicationVersionsController
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class ApplicationVersionsControllerBehaviourTest extends TestCase {
	/**
	 * The application row every test resolves by default.
	 *
	 * @var array<string, mixed>
	 */
	private const APP = [
		'id' => 'app-uuid',
		'slug' => 'shop',
		'permissions' => [
			'owners' => ['user:olga', 'group:owners-grp'],
			'editors' => ['user:ed', 'editors-grp'],
			'viewers' => ['user:vic', '', 7],
		],
	];

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var ApplicationVersionService&MockObject
	 */
	private ApplicationVersionService&MockObject $versionService;

	/**
	 * The version rows the version-schema search returns.
	 *
	 * @var array<int, mixed>
	 */
	private array $versions = [];

	/**
	 * The application rows the slug search returns.
	 *
	 * @var array<int, mixed>
	 */
	private array $apps = [self::APP];

	/**
	 * Set up mocks: register 1, application schema 2, version schema 3.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->versionService = $this->createMock(ApplicationVersionService::class);

		$register = new Register();
		$register->setId(1);
		$this->registerMapper->method('find')->willReturn($register);

		$this->schemaMapper->method('find')->willReturnCallback(
			static function (string|int $slug): Schema {
				$schema = new Schema();
				$schema->setId($slug === ApplicationVersionService::APPLICATION_SCHEMA ? 2 : 3);
				return $schema;
			}
		);

		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query = []): array {
				if (($query['@self']['schema'] ?? null) === 2) {
					return $this->apps;
				}

				$rows = $this->versions;
				if (isset($query['slug']) === true) {
					$rows = array_values(
						array_filter(
							$rows,
							static fn ($row): bool => is_array($row) && ($row['slug'] ?? null) === $query['slug']
						)
					);
				}

				return $rows;
			}
		);

		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->request->method('getParams')->willReturn([]);

	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param AuditTrailMapper|null $audit Audit trail mapper, if any.
	 *
	 * @return ApplicationVersionsController
	 */
	private function controller(?AuditTrailMapper $audit = null): ApplicationVersionsController {
		return new ApplicationVersionsController(
			request: $this->request,
			logger: $this->logger,
			objectService: $this->objectService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			versionService: $this->versionService,
			auditTrailMapper: $audit,
		);

	}//end controller()

	/**
	 * Sign in a user with the given group ids.
	 *
	 * @param string             $uid    User id.
	 * @param array<int, string> $groups Group ids.
	 *
	 * @return void
	 */
	private function signIn(string $uid, array $groups = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $gid): bool => $gid === 'admin' && in_array('admin', $groups, true)
		);
		$this->groupManager->method('getUserGroups')->willReturn(
			array_map(
				function (string $gid): IGroup {
					$group = $this->createMock(IGroup::class);
					$group->method('getGID')->willReturn($gid);
					return $group;
				},
				$groups
			)
		);

	}//end signIn()

	/**
	 * Make the request carry the given body parameters.
	 *
	 * @param array<string, mixed> $params Parameters.
	 *
	 * @return void
	 */
	private function body(array $params): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturn($params);

	}//end body()

	/**
	 * An entity that serialises to the payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;

	}//end entity()

	/**
	 * Without a session every endpoint answers 401.
	 *
	 * @return void
	 */
	public function testAnonymousCallersGet401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$controller = $this->controller();
		foreach ([
			$controller->index('shop'),
			$controller->show('shop', 'v1'),
			$controller->create('shop'),
			$controller->update('shop', 'v1'),
			$controller->destroy('shop', 'v1'),
		] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'unauthenticated'], $response->getData());
		}

	}//end testAnonymousCallersGet401()

	/**
	 * An unknown application answers 404 before any role check.
	 *
	 * @return void
	 */
	public function testAnUnknownApplicationIs404(): void {
		$this->signIn(uid: 'olga');
		$this->apps = [];

		$response = $this->controller()->index('ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'Application ghost not found'], $response->getData());

	}//end testAnUnknownApplicationIs404()

	/**
	 * A user in no role bucket is refused with 403.
	 *
	 * @return void
	 */
	public function testAUserWithoutARoleIsForbidden(): void {
		$this->signIn(uid: 'mallory', groups: ['staff']);

		$response = $this->controller()->index('shop');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'buildiq.rbac.no_role'], $response->getData());

	}//end testAUserWithoutARoleIsForbidden()

	/**
	 * A viewer may read but not write.
	 *
	 * @return void
	 */
	public function testAViewerMayReadButNotWrite(): void {
		$this->signIn(uid: 'vic');
		$this->versions = [['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid']];

		$controller = $this->controller();
		$this->assertSame(Http::STATUS_OK, $controller->index('shop')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->create('shop')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->update('shop', 'v1')->getStatus());

	}//end testAViewerMayReadButNotWrite()

	/**
	 * Group principals, with or without the `group:` prefix, grant the role.
	 *
	 * @return void
	 */
	public function testGroupPrincipalsGrantTheRole(): void {
		$this->signIn(uid: 'someone', groups: ['editors-grp']);
		$this->versions = [['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid']];

		$response = $this->controller()->show('shop', 'v1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid'], $response->getData());

	}//end testGroupPrincipalsGrantTheRole()

	/**
	 * Non-array permissions grant nobody anything.
	 *
	 * @return void
	 */
	public function testNonArrayPermissionsGrantNothing(): void {
		$this->signIn(uid: 'olga');
		$this->apps = [['id' => 'app-uuid', 'slug' => 'shop', 'permissions' => 'everyone']];

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->index('shop')->getStatus());

	}//end testNonArrayPermissionsGrantNothing()

	/**
	 * An admin passes the gate and the bypass is logged when no audit trail is wired.
	 *
	 * @return void
	 */
	public function testAnAdminBypassIsLogged(): void {
		$this->signIn(uid: 'root', groups: ['admin']);

		$this->logger->expects($this->once())->method('info')
			->with(
				'Buildiq: rbac.admin_bypass exercised',
				$this->callback(
					static fn (array $c): bool => $c['event'] === 'rbac.admin_bypass'
						&& $c['actor'] === 'root'
						&& $c['slug'] === 'shop'
						&& $c['surface'] === 'ApplicationVersionsController'
				)
			);

		$this->assertSame(Http::STATUS_OK, $this->controller()->index('shop')->getStatus());

	}//end testAnAdminBypassIsLogged()

	/**
	 * With an audit trail and an entity row, the bypass is recorded on the application.
	 *
	 * @return void
	 */
	public function testAnAdminBypassIsRecordedInTheAuditTrail(): void {
		$this->signIn(uid: 'root', groups: ['admin']);
		$entity = $this->entity(payload: self::APP);
		$this->apps = [$entity];

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects($this->once())->method('recordObjectEvent')
			->with(
				$this->callback(
					static fn (ObjectAuditEvent $e): bool => $e->object === $entity
						&& $e->action === 'rbac.admin_bypass'
						&& $e->context['actor'] === 'root'
				)
			)
			->willReturn(new AuditTrail());

		$this->assertSame(Http::STATUS_OK, $this->controller(audit: $audit)->index('shop')->getStatus());

	}//end testAnAdminBypassIsRecordedInTheAuditTrail()

	/**
	 * A failing audit write is logged as critical and the admin still passes.
	 *
	 * @return void
	 */
	public function testAFailingAuditWriteIsCriticalButNotBlocking(): void {
		$this->signIn(uid: 'root', groups: ['admin']);
		$this->apps = [$this->entity(payload: self::APP)];

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willThrowException(new RuntimeException('audit down'));

		$this->logger->expects($this->once())->method('critical')
			->with(
				$this->stringContains('COMPLIANCE GAP'),
				$this->callback(static fn (array $c): bool => $c['exception'] === 'audit down' && $c['actor'] === 'root')
			);
		$this->logger->expects($this->once())->method('info')->with('Buildiq: rbac.admin_bypass exercised');

		$this->assertSame(Http::STATUS_OK, $this->controller(audit: $audit)->index('shop')->getStatus());

	}//end testAFailingAuditWriteIsCriticalButNotBlocking()

	/**
	 * Show answers 404 for an unknown version.
	 *
	 * @return void
	 */
	public function testShowOfAnUnknownVersionIs404(): void {
		$this->signIn(uid: 'vic');

		$response = $this->controller()->show('shop', 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'nope'], $response->getData());

	}//end testShowOfAnUnknownVersionIs404()

	/**
	 * Index failures are logged and answered as internal_error (400).
	 *
	 * @return void
	 */
	public function testIndexFailureIsInternalError(): void {
		$this->signIn(uid: 'vic');
		$this->versionService->expects($this->never())->method('onSave');
		$calls = 0;
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('searchObjects')->willReturnCallback(
			function () use (&$calls): array {
				$calls++;
				if ($calls >= 3) {
					throw new RuntimeException('search exploded');
				}

				return [self::APP];
			}
		);
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('ApplicationVersionsController::index failed for slug shop: search exploded'));

		$response = $this->controller()->index('shop');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'internal_error', 'detail' => 'Failed to load versions'], $response->getData());

	}//end testIndexFailureIsInternalError()

	/**
	 * Create strips identity fields, binds the application, keeps a supplied
	 * register, guards the promotion target and answers 201.
	 *
	 * @return void
	 */
	public function testCreateBindsTheApplicationAndAnswers201(): void {
		$this->signIn(uid: 'ed');
		$this->body(
			params: [
				'_route' => 'x',
				'id' => 'forged',
				'uuid' => 'forged',
				'@self' => ['owner' => 'x'],
				'slug' => 'v2',
				'register' => 'reg-explicit',
				'promotesTo' => 'v-prod',
			]
		);

		$expected = ['slug' => 'v2', 'register' => 'reg-explicit', 'promotesTo' => 'v-prod', 'application' => 'app-uuid'];
		$this->versionService->expects($this->once())->method('onSave')
			->with(null, $expected)
			->willReturnArgument(1);
		$this->versionService->expects($this->once())->method('guardNoCycle')->with('__pending_create__', 'v-prod');
		$this->objectService->expects($this->once())->method('saveObject')
			->with($expected, [], 'buildiq', 'applicationVersion')
			->willReturn($this->entity(payload: ['id' => 'v-new'] + $expected));

		$response = $this->controller()->create('shop');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('v-new', $response->getData()['id']);

	}//end testCreateBindsTheApplicationAndAnswers201()

	/**
	 * Without a register, create inherits the production version's register (inline row).
	 *
	 * @return void
	 */
	public function testCreateInheritsTheInlineProductionRegister(): void {
		$this->signIn(uid: 'ed');
		$this->apps = [self::APP + ['productionVersion' => ['register' => 'reg-prod']]];
		$this->body(params: ['slug' => 'v2']);

		$this->versionService->expects($this->once())->method('onSave')
			->with(null, ['slug' => 'v2', 'application' => 'app-uuid', 'register' => 'reg-prod'])
			->willReturnArgument(1);
		$this->versionService->expects($this->never())->method('guardNoCycle');
		$this->objectService->method('saveObject')->willReturn($this->entity(payload: ['id' => 'v-new']));

		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create('shop')->getStatus());

	}//end testCreateInheritsTheInlineProductionRegister()

	/**
	 * A production version referenced by uuid is looked up for its register.
	 *
	 * @return void
	 */
	public function testCreateInheritsTheRegisterOfAReferencedVersion(): void {
		$this->signIn(uid: 'ed');
		$this->apps = [self::APP + ['productionVersion' => 'v-prod']];
		$this->body(params: ['slug' => 'v2']);

		$this->objectService->expects($this->once())->method('find')
			->with('v-prod', [], false, 'buildiq', 'applicationVersion')
			->willReturn($this->entity(payload: ['register' => 'reg-from-row']));
		$this->versionService->expects($this->once())->method('onSave')
			->with(null, ['slug' => 'v2', 'application' => 'app-uuid', 'register' => 'reg-from-row'])
			->willReturnArgument(1);
		$this->objectService->method('saveObject')->willReturn($this->entity(payload: []));

		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create('shop')->getStatus());

	}//end testCreateInheritsTheRegisterOfAReferencedVersion()

	/**
	 * Every way of having no production register refuses create with 422.
	 *
	 * @return void
	 */
	public function testCreateWithoutAnInheritableRegisterIs422(): void {
		$this->signIn(uid: 'ed');
		$this->body(params: ['slug' => 'v2']);
		$this->objectService->expects($this->never())->method('saveObject');

		$cases = [
			'no production version' => [self::APP, null],
			'inline row without register' => [self::APP + ['productionVersion' => ['register' => '']], null],
			'referenced row missing' => [self::APP + ['productionVersion' => 'v-gone'], 'null'],
			'referenced row unreadable' => [self::APP + ['productionVersion' => 'v-err'], 'throw'],
			'referenced row without register' => [self::APP + ['productionVersion' => 'v-bare'], 'bare'],
		];

		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if ($id === 'v-err') {
					throw new RuntimeException('read failed');
				}

				if ($id === 'v-bare') {
					return $this->entity(payload: ['slug' => 'bare']);
				}

				return null;
			}
		);

		foreach ($cases as $label => [$app]) {
			$this->apps = [$app];
			$response = $this->controller()->create('shop');
			$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus(), $label);
			$this->assertSame('no_register_to_inherit', $response->getData()['error'], $label);
		}

	}//end testCreateWithoutAnInheritableRegisterIs422()

	/**
	 * A refused save surfaces as create_failed (422) with the reason.
	 *
	 * @return void
	 */
	public function testARefusedCreateIs422WithTheReason(): void {
		$this->signIn(uid: 'olga');
		$this->body(params: ['register' => 'r', 'promotesTo' => 'v-self']);
		$this->versionService->method('onSave')->willReturnArgument(1);
		$this->versionService->method('guardNoCycle')->willThrowException(new RuntimeException('cycle detected'));
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->create('shop');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'create_failed', 'detail' => 'cycle detected'], $response->getData());

	}//end testARefusedCreateIs422WithTheReason()

	/**
	 * Update merges only mutable fields, keeps identity, locks, saves and unlocks.
	 *
	 * @return void
	 */
	public function testUpdateMergesMutableFieldsUnderALock(): void {
		$this->signIn(uid: 'ed');
		$current = ['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid', 'status' => 'draft', '@self' => ['x' => 1]];
		$this->versions = [$current];
		$this->body(params: ['status' => 'review', 'application' => 'other-app', 'owner' => 'evil', 'promotesTo' => '']);

		$expected = ['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid', 'status' => 'review', 'promotesTo' => ''];
		$this->versionService->expects($this->once())->method('guardNoCycle')->with('v-1', null);
		$this->versionService->expects($this->once())->method('onSave')
			->with($current, $expected)
			->willReturnArgument(1);

		$order = [];
		$this->objectService->expects($this->once())->method('lockObject')
			->with('v-1', 'buildiq.controller-update', 15)
			->willReturnCallback(
				static function () use (&$order): array {
					$order[] = 'lock';
					return [];
				}
			);
		$this->objectService->expects($this->once())->method('saveObject')
			->with($expected, [], 'buildiq', 'applicationVersion', 'v-1')
			->willReturnCallback(
				function () use (&$order, $expected): ObjectEntity {
					$order[] = 'save';
					return $this->entity(payload: $expected);
				}
			);
		$this->objectService->expects($this->once())->method('unlockObject')->with('v-1')
			->willReturnCallback(
				static function () use (&$order): bool {
					$order[] = 'unlock';
					return true;
				}
			);

		$response = $this->controller()->update('shop', 'v1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($expected, $response->getData());
		$this->assertSame(['lock', 'save', 'unlock'], $order);

	}//end testUpdateMergesMutableFieldsUnderALock()

	/**
	 * Update of an unknown version answers 404 without saving.
	 *
	 * @return void
	 */
	public function testUpdateOfAnUnknownVersionIs404(): void {
		$this->signIn(uid: 'ed');
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->update('shop', 'nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'nope'], $response->getData());

	}//end testUpdateOfAnUnknownVersionIs404()

	/**
	 * A held lock answers 409 and nothing is saved.
	 *
	 * @return void
	 */
	public function testALockedVersionIs409(): void {
		$this->signIn(uid: 'ed');
		$this->versions = [['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid']];
		$this->versionService->method('onSave')->willReturnArgument(1);
		$this->objectService->method('lockObject')->willThrowException(new RuntimeException('held'));
		$this->objectService->expects($this->never())->method('saveObject');
		$this->objectService->expects($this->never())->method('unlockObject');

		$response = $this->controller()->update('shop', 'v1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'version_locked',
				'detail' => 'Version v1 is currently locked by another writer. Retry after a moment.',
			],
			$response->getData()
		);

	}//end testALockedVersionIs409()

	/**
	 * A failed save still releases the lock and answers update_failed (422);
	 * a failed unlock is only a warning.
	 *
	 * @return void
	 */
	public function testAFailedSaveReleasesTheLockAndIs422(): void {
		$this->signIn(uid: 'ed');
		$this->versions = [['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid']];
		$this->versionService->method('onSave')->willReturnArgument(1);
		$this->objectService->method('lockObject')->willReturn([]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('invalid manifest'));
		$this->objectService->expects($this->once())->method('unlockObject')->willThrowException(new RuntimeException('unlock lost'));

		$this->logger->expects($this->once())->method('warning')
			->with('Buildiq: failed to release update lock on v-1: unlock lost');

		$response = $this->controller()->update('shop', 'v1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'update_failed', 'detail' => 'invalid manifest'], $response->getData());

	}//end testAFailedSaveReleasesTheLockAndIs422()

	/**
	 * A version without an id is saved without locking.
	 *
	 * @return void
	 */
	public function testAVersionWithoutAnIdIsSavedUnlocked(): void {
		$this->signIn(uid: 'ed');
		$this->versions = [['slug' => 'v1', 'application' => 'app-uuid']];
		$this->versionService->method('onSave')->willReturnArgument(1);
		$this->versionService->expects($this->never())->method('guardNoCycle');
		$this->objectService->expects($this->never())->method('lockObject');
		$this->objectService->expects($this->never())->method('unlockObject');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($this->entity(payload: []));

		$this->assertSame(Http::STATUS_OK, $this->controller()->update('shop', 'v1')->getStatus());

	}//end testAVersionWithoutAnIdIsSavedUnlocked()

	/**
	 * Destroy demands a strategy.
	 *
	 * @return void
	 */
	public function testDestroyWithoutAStrategyIs400(): void {
		$this->signIn(uid: 'ed');
		$this->request->method('getParam')->with('strategy', '')->willReturn('');
		$this->versionService->expects($this->never())->method('deleteVersion');

		$response = $this->controller()->destroy('shop', 'v1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_strategy', $response->getData()['error']);

	}//end testDestroyWithoutAStrategyIs400()

	/**
	 * Destroy hands the version uuid and strategy to the service and answers 204.
	 *
	 * @return void
	 */
	public function testDestroyDeletesWithTheStrategy(): void {
		$this->signIn(uid: 'olga');
		$this->versions = [['uuid' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid']];
		$this->request->method('getParam')->willReturn('orphan-grace');
		$this->versionService->expects($this->once())->method('deleteVersion')->with('v-1', 'orphan-grace');

		$response = $this->controller()->destroy('shop', 'v1');

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
		$this->assertSame([], $response->getData());

	}//end testDestroyDeletesWithTheStrategy()

	/**
	 * Destroy of an unknown version is 404.
	 *
	 * @return void
	 */
	public function testDestroyOfAnUnknownVersionIs404(): void {
		$this->signIn(uid: 'olga');
		$this->request->method('getParam')->willReturn('delete-now');
		$this->versionService->expects($this->never())->method('deleteVersion');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->destroy('shop', 'v1')->getStatus());

	}//end testDestroyOfAnUnknownVersionIs404()

	/**
	 * An unknown strategy is a 400 invalid_strategy; any other refusal is 422.
	 *
	 * @return void
	 */
	public function testDestroyRefusalsMapToTheirCodes(): void {
		$this->signIn(uid: 'olga');
		$this->versions = [['id' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid']];
		$this->request->method('getParam')->willReturn('shred');
		$this->versionService->method('deleteVersion')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('Unknown deletion strategy: shred')),
			$this->throwException(new RuntimeException('Version is in production'))
		);

		$first = $this->controller()->destroy('shop', 'v1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $first->getStatus());
		$this->assertSame(['error' => 'invalid_strategy', 'detail' => 'Unknown deletion strategy: shred'], $first->getData());

		$second = $this->controller()->destroy('shop', 'v1');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $second->getStatus());
		$this->assertSame(['error' => 'delete_failed', 'detail' => 'Version is in production'], $second->getData());

	}//end testDestroyRefusalsMapToTheirCodes()

	/**
	 * Release is for owners only: an editor is refused, an owner group passes.
	 *
	 * @return void
	 */
	public function testReleaseIsOwnerOnly(): void {
		$this->signIn(uid: 'ed');
		$this->versionService->expects($this->never())->method('releaseVersion');
		$response = $this->controller()->release('shop', 'v1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'buildiq.rbac.not_owner'], $response->getData());

	}//end testReleaseIsOwnerOnly()

	/**
	 * An owner by group releases; an unknown version is 404; a refusal is 422.
	 *
	 * @return void
	 */
	public function testReleaseOutcomesForAnOwnerGroup(): void {
		$this->signIn(uid: 'someone', groups: ['owners-grp']);
		$this->versions = [
			['id' => 'v-other', 'slug' => 'v1', 'application' => 'other-app'],
			['uuid' => 'v-1', 'slug' => 'v1', 'application' => 'app-uuid'],
			'not-a-row',
		];
		$this->versionService->method('releaseVersion')->willReturnCallback(
			static function (string $app, string $version): array {
				if ($version !== 'v-1') {
					throw new RuntimeException('wrong row');
				}

				return ['productionVersion' => $version, 'application' => $app];
			}
		);

		$controller = $this->controller();
		$released = $controller->release('shop', 'v1');
		$this->assertSame(Http::STATUS_OK, $released->getStatus());
		$this->assertSame(['productionVersion' => 'v-1', 'application' => 'app-uuid'], $released->getData());

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->release('shop', 'v9')->getStatus());

		$this->versions = [['id' => 'v-2', 'slug' => 'v2', 'application' => 'app-uuid']];
		$refused = $controller->release('shop', 'v2');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['error' => 'release_failed', 'detail' => 'wrong row'], $refused->getData());

	}//end testReleaseOutcomesForAnOwnerGroup()

	/**
	 * Release of an unknown application is 404.
	 *
	 * @return void
	 */
	public function testReleaseOfAnUnknownApplicationIs404(): void {
		$this->signIn(uid: 'olga');
		$this->apps = [];

		$response = $this->controller()->release('ghost', 'v1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'Application ghost not found'], $response->getData());

	}//end testReleaseOfAnUnknownApplicationIs404()
}//end class
