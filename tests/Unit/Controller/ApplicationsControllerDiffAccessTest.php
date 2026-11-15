<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Access goldens for ApplicationsController::diffVersions().
 *
 * The diff returns two manifests of one Application, so it runs the same
 * slug resolution and role gate as getManifest(). These pin the gate's refused
 * and allowed outcomes on the diff endpoint: an anonymous caller, a caller with
 * no role, a viewer, and an administrator using the audited bypass.
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
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Refused and allowed outcomes of the diff endpoint's role gate.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */
class ApplicationsControllerDiffAccessTest extends TestCase {

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

	/**
	 * User session mock.
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
	 * Build the collaborator mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
	}//end setUp()

	/**
	 * An anonymous caller is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects(self::never())->method('searchObjects');

		$response = $this->diff();

		self::assertSame([401, ['error' => 'unauthenticated']], $this->outcome(response: $response));
	}//end testAnonymousCallerIsRefused()

	/**
	 * A caller without a role on the Application is refused, and no version is read.
	 *
	 * @return void
	 */
	public function testCallerWithoutARoleIsRefused(): void {
		$this->resolvable(caller: 'carol', admin: false);
		$this->objectService->expects(self::once())->method('find')->willReturn($this->application());

		$response = $this->diff();

		self::assertSame([403, ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role']], $this->outcome(response: $response));
	}//end testCallerWithoutARoleIsRefused()

	/**
	 * A viewer may read the diff.
	 *
	 * @return void
	 */
	public function testViewerIsAllowed(): void {
		$this->resolvable(caller: 'vera', admin: false);
		$this->objectService->method('find')->willReturn($this->application());

		self::assertSame(200, $this->diff()->getStatus());
	}//end testViewerIsAllowed()

	/**
	 * An administrator without a role passes through the audited bypass.
	 *
	 * @return void
	 */
	public function testAdministratorPassesThroughTheBypass(): void {
		$this->resolvable(caller: 'root', admin: true);
		$this->objectService->method('find')->willReturn($this->application());

		self::assertSame(200, $this->diff()->getStatus());
	}//end testAdministratorPassesThroughTheBypass()

	/**
	 * Diff the draft against itself, which reads no version.
	 *
	 * @return JSONResponse The response.
	 */
	private function diff(): JSONResponse {
		return $this->controller()->diffVersions(slug: 'hello-world', from: 'draft', to: 'draft');
	}//end diff()

	/**
	 * Sign a caller in and make the slug resolve through the route index.
	 *
	 * @param string $caller The caller's UID.
	 * @param bool   $admin  Whether the caller is in the admin group.
	 *
	 * @return void
	 */
	private function resolvable(string $caller, bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn($admin);
		$this->groupManager->method('isInGroup')->willReturn($admin);
		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(1);
		$this->registerMapper->method('find')->willReturn($register);
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(2);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->method('searchObjects')->willReturn(
			[$this->entity(payload: ['slug' => 'hello-world', 'applicationUuid' => 'app-uuid-1'])]
		);
	}//end resolvable()

	/**
	 * The Application: bob owns it, vera views it.
	 *
	 * @return ObjectEntity The Application entity.
	 */
	private function application(): ObjectEntity {
		return $this->entity(payload: [
			'id' => 'app-uuid-1',
			'slug' => 'hello-world',
			'permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => ['user:vera']],
		]);
	}//end application()

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
	 * Reduce a response to its status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	/**
	 * Build the controller under test.
	 *
	 * @return ApplicationsController
	 */
	private function controller(): ApplicationsController {
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
				permissionResolver: new PermissionResolver($this->groupManager, $logger)
			),
			manifestResolver: $this->createMock(ManifestResolverService::class),
			channelApplier: $this->createMock(AppChannelApplier::class)
		);
	}//end controller()
}//end class
