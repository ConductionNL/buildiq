<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The offline worker of a virtual app (pages-installable-offline-and-mobile
 * T05): served under the run path with its scope capped there, only when the
 * instance allows offline, the caller may open the app and the app declares
 * an offline block; 404 otherwise.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\Offline\OfflineWorkerScript;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for ApplicationsController::offlineWorker().
 *
 * @covers \OCA\Buildiq\Controller\ApplicationsController
 * @uses \OCA\Buildiq\Service\RegisterStore
 * @uses \OCA\Buildiq\Service\CallerContext
 * @uses \OCA\Buildiq\Service\PermissionResolver
 * @uses \OCA\Buildiq\Service\Icon\SvgRasteriser
 * @uses \OCA\Buildiq\Service\Offline\OfflineWorkerScript
 */
class ApplicationsControllerOfflineWorkerTest extends TestCase {

	private $objectService;

	private $userSession;

	private $groupManager;

	private $registerMapper;

	private $schemaMapper;

	private $manifestResolver;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->manifestResolver = $this->createMock(ManifestResolverService::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
	}//end setUp()

	/**
	 * The route is for signed-in users, never public.
	 *
	 * @return void
	 */
	public function testTheRouteIsForSignedInUsers(): void {
		self::assertCount(1, (new ReflectionMethod(ApplicationsController::class, 'offlineWorker'))->getAttributes(NoAdminRequired::class));
	}//end testTheRouteIsForSignedInUsers()

	/**
	 * A viewer of an app that declares offline gets the stamped worker, scope capped at the run path.
	 *
	 * @return void
	 */
	public function testAViewerGetsTheWorkerScopedToTheRunPath(): void {
		$this->resolvable(caller: 'vera');
		$this->manifestResolver->expects(self::once())->method('resolve')
			->with('hello-world', null, self::isInstanceOf(IUser::class))
			->willReturn(['offline' => ['forms' => ['report']]]);

		$response = $this->controller(allowed: true, script: "self.CN_OFFLINE_BUNDLE_VERSION = \"v\";\n/* worker */")->offlineWorker(slug: 'hello-world');

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		self::assertSame("self.CN_OFFLINE_BUNDLE_VERSION = \"v\";\n/* worker */", $response->getData());
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('./', $headers['Service-Worker-Allowed']);
		self::assertSame('text/javascript; charset=utf-8', $headers['Content-Type']);
		self::assertSame('no-cache', $headers['Cache-Control']);
	}//end testAViewerGetsTheWorkerScopedToTheRunPath()

	/**
	 * While the instance does not allow offline, the worker is 404 and the app is not even looked up.
	 *
	 * @return void
	 */
	public function testWhileOfflineIsOffTheWorkerIs404(): void {
		$this->resolvable(caller: 'vera');
		$this->objectService->expects(self::never())->method('find');
		$this->manifestResolver->expects(self::never())->method('resolve');

		$response = $this->controller(allowed: false, script: 'x')->offlineWorker(slug: 'hello-world');

		self::assertSame([404, ['error' => 'not_found']], [$response->getStatus(), $response->getData()]);
	}//end testWhileOfflineIsOffTheWorkerIs404()

	/**
	 * Without a settings service the worker is 404 too.
	 *
	 * @return void
	 */
	public function testWithoutTheSettingTheWorkerIs404(): void {
		$this->resolvable(caller: 'vera');

		self::assertSame(404, $this->controller(allowed: null, script: 'x')->offlineWorker(slug: 'hello-world')->getStatus());
	}//end testWithoutTheSettingTheWorkerIs404()

	/**
	 * A user who may not open the app gets 404.
	 *
	 * @return void
	 */
	public function testAStrangerGets404(): void {
		$this->resolvable(caller: 'carol');
		$this->manifestResolver->expects(self::never())->method('resolve');

		self::assertSame(404, $this->controller(allowed: true, script: 'x')->offlineWorker(slug: 'hello-world')->getStatus());
	}//end testAStrangerGets404()

	/**
	 * An app without an offline block, or a build without the bundle, is 404.
	 *
	 * @return void
	 */
	public function testNoOfflineBlockOrNoBundleIs404(): void {
		$this->resolvable(caller: 'vera');
		$this->manifestResolver->method('resolve')->willReturnOnConsecutiveCalls(['pages' => []], ['offline' => ['forms' => ['r']]], null);

		self::assertSame(404, $this->controller(allowed: true, script: 'x')->offlineWorker(slug: 'hello-world')->getStatus());
		self::assertSame(404, $this->controller(allowed: true, script: null)->offlineWorker(slug: 'hello-world')->getStatus());
		self::assertSame(404, $this->controller(allowed: true, script: 'x')->offlineWorker(slug: 'hello-world')->getStatus());
	}//end testNoOfflineBlockOrNoBundleIs404()

	/**
	 * A failing lookup is 404, not a 500 with details.
	 *
	 * @return void
	 */
	public function testAFailingLookupIs404(): void {
		$this->resolvable(caller: 'vera');
		$this->manifestResolver->method('resolve')->willThrowException(new \RuntimeException('down'));

		self::assertSame([404, ['error' => 'not_found']], (function ($r) {
			return [$r->getStatus(), $r->getData()];
		})($this->controller(allowed: true, script: 'x')->offlineWorker(slug: 'hello-world')));
	}//end testAFailingLookupIs404()

	/**
	 * Make the slug resolve to the app, for this caller.
	 *
	 * @param string $caller The caller's uid.
	 *
	 * @return void
	 */
	private function resolvable(string $caller): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);
		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(1);
		$this->registerMapper->method('find')->willReturn($register);
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(2);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->method('searchObjects')->willReturn(
			[$this->entity(payload: ['slug' => 'hello-world', 'applicationUuid' => 'app-uuid-1'])]
		);
		$this->objectService->method('find')->willReturn($this->entity(payload: [
			'id' => 'app-uuid-1',
			'slug' => 'hello-world',
			'name' => 'Toezicht',
			'permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => ['user:vera']],
		]));
	}//end resolvable()

	/**
	 * An ObjectEntity serialising to this payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {

			public array $payload = [];

			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * The controller with this offline setting and worker bundle.
	 *
	 * @param bool|null   $allowed Whether offline is allowed; null for no settings service.
	 * @param string|null $script  What the worker script answers.
	 *
	 * @return ApplicationsController
	 */
	private function controller(?bool $allowed, ?string $script): ApplicationsController {
		$logger = $this->createMock(LoggerInterface::class);
		$settings = null;
		if ($allowed !== null) {
			$settings = $this->createMock(SettingsServiceInterface::class);
			$settings->method('offlineAppsAllowed')->willReturn($allowed);
		}

		$worker = $this->createMock(OfflineWorkerScript::class);
		$worker->method('script')->willReturn($script);

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
			manifestResolver: $this->manifestResolver,
			channelApplier: $this->createMock(AppChannelApplier::class),
			settings: $settings,
			workerScript: $worker
		);
	}//end controller()
}//end class
