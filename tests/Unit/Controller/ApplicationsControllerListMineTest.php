<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for ApplicationsController::listMine().
 *
 * `listMine` is the per-caller Application list: it filters every Application
 * by role, strips the permissions roster from viewer-only entries, lets an
 * admin see the rest (and logs that it did), and attaches the resolved
 * production ApplicationVersion to each card as `productionVersionDetail`.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApplicationsController::listMine().
 *
 * @covers \OCA\Buildiq\Controller\ApplicationsController
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Service\CallerContext
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */
class ApplicationsControllerListMineTest extends TestCase {

	/**
	 * Register id the register mapper resolves.
	 *
	 * @var int
	 */
	private const REGISTER_ID = 11;

	/**
	 * Schema id of the built-app (Application) schema.
	 *
	 * @var int
	 */
	private const APP_SCHEMA_ID = 22;

	/**
	 * Schema id of the applicationVersion schema.
	 *
	 * @var int
	 */
	private const VERSION_SCHEMA_ID = 33;

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private $registerMapper;

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Whether the caller is in the admin group.
	 *
	 * @var bool
	 */
	private bool $isAdmin = false;

	/**
	 * Wire the collaborator mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => $gid === 'admin' && $this->isAdmin
		);

		$register = new Register();
		$register->setId(self::REGISTER_ID);
		$this->registerMapper->method('find')->willReturn($register);

		$this->schemaMapper->method('find')->willReturnCallback(
			static function (string $slug): Schema {
				$schema = new Schema();
				if ($slug === 'applicationVersion') {
					$schema->setId(self::VERSION_SCHEMA_ID);
				} else {
					$schema->setId(self::APP_SCHEMA_ID);
				}

				return $schema;
			}
		);

	}//end setUp()

	/**
	 * Build the controller under test with a real PermissionResolver.
	 *
	 * @return ApplicationsController
	 */
	private function controller(): ApplicationsController {
		return new ApplicationsController(
			request: $this->createMock(IRequest::class),
			logger: $this->logger,
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper,
				auditTrailMapper: null
			),
			caller: new CallerContext(
				userSession: $this->userSession,
				groupManager: $this->groupManager,
				permissionResolver: new PermissionResolver(
					$this->groupManager,
					$this->createMock(LoggerInterface::class)
				)
			),
			manifestResolver: $this->createMock(ManifestResolverService::class),
			channelApplier: $this->createMock(AppChannelApplier::class)
		);

	}//end controller()

	/**
	 * Wire an authenticated user into the session mock.
	 *
	 * @param string $uid The UID.
	 *
	 * @return void
	 */
	private function authenticate(string $uid = 'bob'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end authenticate()

	/**
	 * Answer searchObjects per schema: Applications for the built-app schema,
	 * versions for the applicationVersion schema. Records every query.
	 *
	 * @param mixed                     $applications Applications result.
	 * @param array<int,mixed>|\Throwable $versions   Versions result, or the exception to throw.
	 * @param array<int,array>          $queries      Collected queries.
	 *
	 * @return void
	 */
	private function answerSearches(mixed $applications, array|\Throwable $versions, array &$queries = []): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($applications, $versions, &$queries) {
				$queries[] = $query;
				if ($query['@self']['schema'] === self::VERSION_SCHEMA_ID) {
					if ($versions instanceof \Throwable) {
						throw $versions;
					}

					return $versions;
				}

				return $applications;
			}
		);

	}//end answerSearches()

	/**
	 * An anonymous caller is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsForbidden(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects(self::never())->method('searchObjects');

		$response = $this->controller()->listMine();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(
			['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'],
			$response->getData()
		);

	}//end testAnonymousCallerIsForbidden()

	/**
	 * Only Applications the caller holds a role on are listed; a viewer-only
	 * entry loses its permissions roster, an owner's keeps it.
	 *
	 * @return void
	 */
	public function testListsOnlyTheCallersApplicationsAndStripsViewerRosters(): void {
		$this->authenticate(uid: 'bob');
		$queries = [];
		$this->answerSearches(
			applications: [
				['id' => 'a1', 'slug' => 'owned', 'permissions' => ['owners' => ['user:bob']]],
				['id' => 'a2', 'slug' => 'viewed', 'permissions' => ['viewers' => ['user:bob'], 'owners' => ['user:alice']]],
				['id' => 'a3', 'slug' => 'foreign', 'permissions' => ['owners' => ['user:alice']]],
				[],
			],
			versions: [],
			queries: $queries
		);
		$this->logger->expects(self::never())->method('info');

		$response = $this->controller()->listMine();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				['id' => 'a1', 'slug' => 'owned', 'permissions' => ['owners' => ['user:bob']]],
				['id' => 'a2', 'slug' => 'viewed'],
			],
			$response->getData()
		);
		// No production version anywhere: the version lookup is skipped.
		self::assertSame(
			[['@self' => ['register' => self::REGISTER_ID, 'schema' => self::APP_SCHEMA_ID]]],
			$queries
		);

	}//end testListsOnlyTheCallersApplicationsAndStripsViewerRosters()

	/**
	 * An admin also sees Applications they hold no role on, and the bypass is
	 * logged once for the whole list.
	 *
	 * @return void
	 */
	public function testAdminSeesEveryApplicationAndTheBypassIsLogged(): void {
		$this->isAdmin = true;
		$this->authenticate(uid: 'root');
		$this->answerSearches(
			applications: [
				['id' => 'a1', 'permissions' => ['owners' => ['user:alice']]],
				['id' => 'a2', 'permissions' => []],
			],
			versions: []
		);
		$this->logger->expects(self::once())->method('info')->with(
			'Buildiq: rbac.admin_bypass exercised on Application list',
			self::callback(
				static fn (array $context): bool => $context['actor'] === 'root'
					&& $context['event'] === 'rbac.admin_bypass.list'
					&& $context['count'] === 2
			)
		);

		$response = $this->controller()->listMine();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				['id' => 'a1', 'permissions' => ['owners' => ['user:alice']]],
				['id' => 'a2', 'permissions' => []],
			],
			$response->getData()
		);

	}//end testAdminSeesEveryApplicationAndTheBypassIsLogged()

	/**
	 * A non-array search result yields an empty list.
	 *
	 * @return void
	 */
	public function testANonArraySearchResultYieldsAnEmptyList(): void {
		$this->authenticate();
		$this->answerSearches(applications: 0, versions: []);

		$response = $this->controller()->listMine();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData());

	}//end testANonArraySearchResultYieldsAnEmptyList()

	/**
	 * The production version of each card is resolved into
	 * `productionVersionDetail`; `productionVersion` itself stays the uuid,
	 * and draft, unknown or missing versions get no detail.
	 *
	 * @return void
	 */
	public function testAttachesTheResolvedProductionVersion(): void {
		$this->authenticate(uid: 'bob');
		$owner = ['owners' => ['user:bob']];
		$queries = [];
		$this->answerSearches(
			applications: [
				['id' => 'a1', 'productionVersion' => 'v-1', 'permissions' => $owner],
				['id' => 'a2', 'productionVersion' => 'v-2', 'permissions' => $owner],
				['id' => 'a3', 'productionVersion' => 'draft', 'permissions' => $owner],
				['id' => 'a4', 'productionVersion' => 'v-gone', 'permissions' => $owner],
				['id' => 'a5', 'permissions' => $owner],
			],
			versions: [
				['id' => 'v-1', 'slug' => 'one', 'name' => 'One', 'semver' => '1.0.0', 'status' => 'published', 'manifest' => ['x' => 1]],
				['uuid' => 'v-2', 'semver' => '2.0.0'],
				['id' => 'v-other', 'semver' => '9.9.9'],
				['slug' => 'no-id'],
				[],
			],
			queries: $queries
		);

		$response = $this->controller()->listMine();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['uuid' => 'v-1', 'slug' => 'one', 'name' => 'One', 'semver' => '1.0.0', 'status' => 'published'],
			$data[0]['productionVersionDetail']
		);
		self::assertSame('v-1', $data[0]['productionVersion']);
		self::assertSame(
			['uuid' => 'v-2', 'slug' => null, 'name' => null, 'semver' => '2.0.0', 'status' => null],
			$data[1]['productionVersionDetail']
		);
		self::assertArrayNotHasKey('productionVersionDetail', $data[2]);
		self::assertArrayNotHasKey('productionVersionDetail', $data[3]);
		self::assertArrayNotHasKey('productionVersionDetail', $data[4]);
		self::assertSame(
			['@self' => ['register' => self::REGISTER_ID, 'schema' => self::VERSION_SCHEMA_ID]],
			$queries[1]
		);

	}//end testAttachesTheResolvedProductionVersion()

	/**
	 * A failing version lookup leaves the list intact and logs a warning.
	 *
	 * @return void
	 */
	public function testAFailingVersionLookupKeepsTheListAndWarns(): void {
		$this->authenticate(uid: 'bob');
		$apps = [['id' => 'a1', 'productionVersion' => 'v-1', 'permissions' => ['owners' => ['user:bob']]]];
		$this->answerSearches(applications: $apps, versions: new RuntimeException('index down'));
		$this->logger->expects(self::once())->method('warning')->with(
			self::stringContains('index down')
		);

		$response = $this->controller()->listMine();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($apps, $response->getData());

	}//end testAFailingVersionLookupKeepsTheListAndWarns()

	/**
	 * A failing Application search is answered with a 500 and logged.
	 *
	 * @return void
	 */
	public function testAFailingApplicationSearchIsAnInternalError(): void {
		$this->authenticate();
		$this->objectService->method('searchObjects')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects(self::once())->method('error')->with(
			'Buildiq: listMine failed: boom',
			self::anything()
		);

		$response = $this->controller()->listMine();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(
			['error' => 'internal_error', 'message' => 'Failed to load applications'],
			$response->getData()
		);

	}//end testAFailingApplicationSearchIsAnInternalError()
}//end class
