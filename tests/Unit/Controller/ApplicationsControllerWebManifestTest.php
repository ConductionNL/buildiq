<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The web app manifest of a virtual app (pages-installable-offline-and-mobile
 * T01): served under the run path to whoever may open the app, by the same
 * slug resolution and role gate as getManifest(), and 404 to everyone else.
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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Psr\Log\LoggerInterface;

/**
 * The web app manifest of a virtual app follows the manifest endpoint's access rule.
 */
class ApplicationsControllerWebManifestTest extends TestCase {

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
	 * The route answers signed-in users who are not admins; the rule inside decides who gets the manifest.
	 *
	 * @return void
	 */
	public function testTheRouteIsForSignedInUsers(): void {
		self::assertCount(1, (new ReflectionMethod(ApplicationsController::class, 'webManifest'))->getAttributes(NoAdminRequired::class));
	}//end testTheRouteIsForSignedInUsers()

	/**
	 * A user who may open the app gets its manifest, starting and scoped at the run path it is served from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-makes-an-app-installable-req-bqom-001
	 */
	public function testAViewerGetsTheManifestScopedToTheRunPath(): void {
		$this->resolvable(caller: 'vera', admin: false);
		$this->objectService->method('find')->willReturn($this->application());

		$response = $this->controller()->webManifest(slug: 'hello-world');

		self::assertSame(200, $response->getStatus());
		// getHeaders() needs OC::$server in unit context; read the set header directly.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('application/manifest+json; charset=utf-8', $headers['Content-Type']);
		self::assertSame(
			[
				'id' => './',
				'name' => 'Toezicht',
				'short_name' => 'Toezicht',
				'start_url' => './',
				'scope' => './',
				'display' => 'standalone',
				'icons' => [['src' => '../../icons/hello-world.svg', 'type' => 'image/svg+xml', 'sizes' => 'any', 'purpose' => 'any']],
			],
			$response->getData()
		);
	}//end testAViewerGetsTheManifestScopedToTheRunPath()

	/**
	 * A signed-in user who may not open the app, and an anonymous caller, get 404 and no manifest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-makes-an-app-installable-req-bqom-001
	 */
	public function testAStrangerGets404(): void {
		$this->resolvable(caller: 'carol', admin: false);
		$this->objectService->method('find')->willReturn($this->application());

		$response = $this->controller()->webManifest(slug: 'hello-world');

		self::assertSame([404, ['error' => 'not_found']], [$response->getStatus(), $response->getData()]);
	}//end testAStrangerGets404()

	/**
	 * Without a session the answer is the same 404, so it does not tell a stranger the app exists.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerGets404(): void {
		$this->userSession->method('getUser')->willReturn(null);

		self::assertSame(404, $this->controller()->webManifest(slug: 'hello-world')->getStatus());
	}//end testAnAnonymousCallerGets404()

	/**
	 * A failing lookup is a 404 too, never a 500 that confirms the slug.
	 *
	 * @return void
	 */
	public function testAFailingLookupIs404(): void {
		$this->resolvable(caller: 'vera', admin: false);
		$this->objectService->method('find')->willThrowException(new \RuntimeException('down'));

		self::assertSame(404, $this->controller()->webManifest(slug: 'hello-world')->getStatus());
	}//end testAFailingLookupIs404()

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
			'name' => 'Toezicht',
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
