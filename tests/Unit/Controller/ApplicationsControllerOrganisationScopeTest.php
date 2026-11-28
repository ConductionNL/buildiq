<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the organisation scope in ApplicationsController.
 *
 * Every slug door of the controller (manifest, web manifest, save, diff, copy)
 * goes through resolveApplicationBySlug(), and the builder's app list through
 * listMine(). Another organisation's scoped app answers as an unknown slug on
 * the first and is left out of the second, before any role and for admins too.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Organisation scope on the controller's slug and list doors.
 *
 * @covers \OCA\Buildiq\Controller\ApplicationsController
 * @uses   \OCA\Buildiq\Service\AppOrganisationGuard
 * @uses   \OCA\Buildiq\Service\AppOrganisationMembership
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Service\CallerContext
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class ApplicationsControllerOrganisationScopeTest extends TestCase {

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * The signed-in caller.
	 *
	 * @var IUser&MockObject
	 */
	private $user;

	/**
	 * Whether the caller is in the admin group.
	 *
	 * @var bool
	 */
	private bool $isAdmin = false;

	/**
	 * Wire the collaborator mocks and sign bob in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);
		$this->groupManager->method('isInGroup')->willReturnCallback(fn (): bool => $this->isAdmin);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('bob');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($this->user);
	}//end setUp()

	/**
	 * The owner of a scoped app who is not in its organisation gets the unknown-slug 404.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationsScopedAppIsNotFoundEvenForItsOwner(): void {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->expects(self::once())->method('canSee')
			->with(self::callback(static fn (array $app): bool => $app['slug'] === 'toezicht'), self::identicalTo($this->user))
			->willReturn(false);
		$this->resolvable(app: $this->app(scope: 'holder'));

		$response = $this->controller(guard: $guard)->webManifest(slug: 'toezicht');

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'not_found'], $response->getData());
	}//end testAnotherOrganisationsScopedAppIsNotFoundEvenForItsOwner()

	/**
	 * A member of the holder organisation opens the scoped app.
	 *
	 * @return void
	 */
	public function testAMemberOpensTheScopedApp(): void {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->method('canSee')->willReturn(true);
		$this->resolvable(app: $this->app(scope: 'holder'));

		$response = $this->controller(guard: $guard)->webManifest(slug: 'toezicht');

		self::assertSame(200, $response->getStatus());
		self::assertSame('Toezicht', $response->getData()['name']);
	}//end testAMemberOpensTheScopedApp()

	/**
	 * Without a guard a scoped app is not found and a shared app opens without asking.
	 *
	 * @return void
	 */
	public function testWithoutAGuardOnlyTheSharedAppOpens(): void {
		$this->resolvable(app: $this->app(scope: 'holder'));
		self::assertSame(404, $this->controller(guard: null)->webManifest(slug: 'toezicht')->getStatus());
	}//end testWithoutAGuardOnlyTheSharedAppOpens()

	/**
	 * A shared app never asks the guard.
	 *
	 * @return void
	 */
	public function testASharedAppDoesNotAskTheGuard(): void {
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->expects(self::never())->method('canSee');
		$this->resolvable(app: $this->app(scope: 'all'));

		self::assertSame(200, $this->controller(guard: $guard)->webManifest(slug: 'toezicht')->getStatus());
	}//end testASharedAppDoesNotAskTheGuard()

	/**
	 * The app list leaves out another organisation's scoped app, for an admin too,
	 * and keeps shared apps and the caller's own organisation's scoped apps.
	 *
	 * @return void
	 */
	public function testTheListLeavesOutOtherOrganisationsScopedAppsForAnAdminToo(): void {
		$this->isAdmin = true;
		$this->mapIds();
		$owned = ['permissions' => ['owners' => ['user:bob']]];
		$this->objectService->method('searchObjects')->willReturn([
			['id' => 'a1', 'slug' => 'shared'] + $owned,
			['id' => 'a2', 'slug' => 'ours', 'organisationScope' => 'holder'] + $owned,
			['id' => 'a3', 'slug' => 'theirs', 'organisationScope' => 'holder'] + $owned,
			['id' => 'a4', 'slug' => 'foreign-shared', 'organisationScope' => 'all', 'permissions' => ['owners' => ['user:alice']]],
		]);
		$guard = $this->createMock(AppOrganisationGuard::class);
		$guard->method('canSee')->willReturnCallback(static fn (array $app): bool => $app['slug'] === 'ours');

		$response = $this->controller(guard: $guard)->listMine();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['shared', 'ours', 'foreign-shared'], array_column($response->getData(), 'slug'));
	}//end testTheListLeavesOutOtherOrganisationsScopedAppsForAnAdminToo()

	/**
	 * Without a guard the list keeps only shared apps.
	 *
	 * @return void
	 */
	public function testWithoutAGuardTheListKeepsOnlySharedApps(): void {
		$this->mapIds();
		$owned = ['permissions' => ['owners' => ['user:bob']]];
		$this->objectService->method('searchObjects')->willReturn([
			['id' => 'a1', 'slug' => 'shared'] + $owned,
			['id' => 'a2', 'slug' => 'scoped', 'organisationScope' => 'holder'] + $owned,
			['id' => 'a3', 'slug' => 'odd', 'organisationScope' => 'unknown'] + $owned,
		]);

		self::assertSame(['shared'], array_column($this->controller(guard: null)->listMine()->getData(), 'slug'));
	}//end testWithoutAGuardTheListKeepsOnlySharedApps()

	/**
	 * The `toezicht` Application owned by bob.
	 *
	 * @param string $scope The organisation scope.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function app(string $scope): array {
		return [
			'id' => 'app-uuid-1',
			'slug' => 'toezicht',
			'name' => 'Toezicht',
			'organisationScope' => $scope,
			'permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => []],
			'@self' => ['organisation' => 'org-noord'],
		];
	}//end app()

	/**
	 * Register and schema mappers answering fixed ids.
	 *
	 * @return array{0: RegisterMapper, 1: SchemaMapper}
	 */
	private function mapIds(): array {
		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(1);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->registerMapper->method('find')->willReturn($register);
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(2);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturn($schema);
		return [$this->registerMapper, $this->schemaMapper];
	}//end mapIds()

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapper|null
	 */
	private ?RegisterMapper $registerMapper = null;

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapper|null
	 */
	private ?SchemaMapper $schemaMapper = null;

	/**
	 * Make the slug resolve to the given Application through its route.
	 *
	 * @param array<string, mixed> $app The Application payload.
	 *
	 * @return void
	 */
	private function resolvable(array $app): void {
		$this->mapIds();
		$this->objectService->method('searchObjects')->willReturn(
			[$this->entity(payload: ['slug' => 'toezicht', 'applicationUuid' => 'app-uuid-1'])]
		);
		$this->objectService->method('find')->willReturn($this->entity(payload: $app));
	}//end resolvable()

	/**
	 * Build an ObjectEntity serialising to the given payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {

			/**
			 * The payload this stand-in serialises to.
			 *
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * Serialise the payload.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * Build the controller under test.
	 *
	 * @param AppOrganisationGuard|null $guard The organisation guard.
	 *
	 * @return ApplicationsController
	 */
	private function controller(?AppOrganisationGuard $guard): ApplicationsController {
		$logger = $this->createMock(LoggerInterface::class);
		return new ApplicationsController(
			request: $this->createMock(IRequest::class),
			logger: $logger,
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper,
				auditTrailMapper: null
			),
			caller: new CallerContext(
				userSession: $this->userSession,
				groupManager: $this->groupManager,
				permissionResolver: new PermissionResolver($this->groupManager, $logger),
				organisationGuard: $guard
			),
			manifestResolver: $this->createMock(ManifestResolverService::class),
			channelApplier: $this->createMock(AppChannelApplier::class)
		);
	}//end controller()
}//end class
