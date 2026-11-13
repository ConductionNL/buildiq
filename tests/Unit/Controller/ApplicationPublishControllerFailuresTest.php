<?php

/**
 * Failure-path tests for ApplicationPublishController.
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

use OCA\Buildiq\Controller\ApplicationPublishController;
use OCA\Buildiq\Service\ApplicationDeletionService;
use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\ManifestFormDestinations;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * @covers \OCA\Buildiq\Controller\ApplicationPublishController
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Exception\InsufficientPermissionException
 * @uses   \OCA\Buildiq\Exception\VersionPromotionException
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class ApplicationPublishControllerFailuresTest extends TestCase {
	/**
	 * The owned application every test starts from.
	 *
	 * @var array<string, mixed>
	 */
	private const APP = [
		'id' => 'u-app',
		'slug' => 'demo',
		'status' => 'draft',
		'permissions' => ['owners' => ['user:alice']],
	];

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var ApplicationDeletionService&MockObject
	 */
	private ApplicationDeletionService&MockObject $deletionService;

	/**
	 * @var VirtualAppCredentialRegistrar&MockObject
	 */
	private VirtualAppCredentialRegistrar&MockObject $registrar;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->deletionService = $this->createMock(ApplicationDeletionService::class);
		$this->registrar = $this->createMock(VirtualAppCredentialRegistrar::class);

	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param ManifestResolverService|null  $manifests    Manifest resolver.
	 * @param ManifestFormDestinations|null $destinations Destination checker.
	 *
	 * @return ApplicationPublishController
	 */
	private function controller(?ManifestResolverService $manifests = null, ?ManifestFormDestinations $destinations = null): ApplicationPublishController {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		return new ApplicationPublishController(
			request: $this->createMock(IRequest::class),
			logger: $this->logger,
			objectService: $this->objectService,
			userSession: $this->userSession,
			permissionResolver: new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class)),
			deletionService: $this->deletionService,
			credentialRegistrar: $this->registrar,
			manifests: $manifests,
			formDestinations: $destinations,
		);

	}//end controller()

	/**
	 * Sign in as a user.
	 *
	 * @param string $uid User id.
	 *
	 * @return void
	 */
	private function signInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end signInAs()

	/**
	 * An entity serialising to the payload.
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
	 * Answer find(): the application by uuid, the route by slug.
	 *
	 * @param array<string, mixed>|null $application Application payload, null = missing.
	 * @param mixed                     $route       Route payload, or 'throw'.
	 *
	 * @return void
	 */
	private function rows(?array $application, mixed $route = 'throw'): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($application, $route): ?ObjectEntity {
				if ($schema === 'built-app-route') {
					if ($route === 'throw') {
						throw new RuntimeException('no route');
					}

					return $this->entity(payload: $route);
				}

				if ($application === null) {
					return null;
				}

				return $this->entity(payload: $application);
			}
		);

	}//end rows()

	/**
	 * Destroy refuses an anonymous caller with 401.
	 *
	 * @return void
	 */
	public function testDestroyUnauthenticatedIs401(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->deletionService->expects($this->never())->method('deleteApplication');

		$response = $this->controller()->destroy('u-app');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'unauthenticated'], $response->getData());

	}//end testDestroyUnauthenticatedIs401()

	/**
	 * Destroy of an unknown application is 404.
	 *
	 * @return void
	 */
	public function testDestroyOfAnUnknownApplicationIs404(): void {
		$this->signInAs(uid: 'alice');
		$this->rows(application: null);
		$this->deletionService->expects($this->never())->method('deleteApplication');

		$response = $this->controller()->destroy('u-app');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'Application u-app not found'], $response->getData());

	}//end testDestroyOfAnUnknownApplicationIs404()

	/**
	 * A failing deletion is logged and answered 500 with the reason.
	 *
	 * @return void
	 */
	public function testAFailingDeletionIs500(): void {
		$this->signInAs(uid: 'alice');
		$this->rows(application: self::APP);
		$this->deletionService->method('deleteApplication')->willThrowException(new RuntimeException('register busy'));
		$this->logger->expects($this->once())->method('error')
			->with(
				'Buildiq: delete failed for {uuid}: {message}',
				$this->callback(static fn (array $c): bool => $c['uuid'] === 'u-app' && $c['message'] === 'register busy')
			);

		$response = $this->controller()->destroy('u-app');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal_error', 'detail' => 'register busy'], $response->getData());

	}//end testAFailingDeletionIs500()

	/**
	 * An unreadable application is treated as missing (404) and logged at debug.
	 *
	 * @return void
	 */
	public function testAnUnreadableApplicationIs404(): void {
		$this->signInAs(uid: 'alice');
		$this->objectService->method('find')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects($this->once())->method('debug')
			->with('Buildiq: Application {uuid} could not be loaded: {message}', $this->anything());

		$response = $this->controller()->publish('u-app');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnUnreadableApplicationIs404()

	/**
	 * Non-array permissions mean no owner: publish is forbidden.
	 *
	 * @return void
	 */
	public function testNonArrayPermissionsAreForbidden(): void {
		$this->signInAs(uid: 'alice');
		$this->rows(application: ['permissions' => 'alice'] + self::APP);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->publish('u-app');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);

	}//end testNonArrayPermissionsAreForbidden()

	/**
	 * When the resolver has no manifest, the application's own manifest is checked.
	 *
	 * @return void
	 */
	public function testTheStoredManifestIsCheckedWhenNoneResolves(): void {
		$this->signInAs(uid: 'alice');
		$stored = ['pages' => [['id' => 'p']]];
		$this->rows(application: ['manifest' => $stored] + self::APP);

		$resolver = $this->createMock(ManifestResolverService::class);
		$resolver->method('resolve')->willReturn(null);
		$destinations = $this->createMock(ManifestFormDestinations::class);
		$destinations->expects($this->once())->method('findings')->with($stored)->willReturn([['code' => 'x']]);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller(manifests: $resolver, destinations: $destinations)->publish('u-app');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			'This app cannot be published: its forms have 1 point(s) their destination refuses. Each one is marked on its field in the page designer.',
			$response->getData()['message']
		);

	}//end testTheStoredManifestIsCheckedWhenNoneResolves()

	/**
	 * Without any manifest there is nothing to check and publish proceeds.
	 *
	 * @return void
	 */
	public function testNoManifestAtAllPublishes(): void {
		$this->signInAs(uid: 'alice');
		$this->rows(application: ['slug' => ''] + self::APP);

		$resolver = $this->createMock(ManifestResolverService::class);
		$resolver->expects($this->never())->method('resolve');
		$destinations = $this->createMock(ManifestFormDestinations::class);
		$destinations->expects($this->never())->method('findings');
		$this->registrar->expects($this->never())->method('onPublish');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($this->entity(payload: ['status' => 'published']));

		$response = $this->controller(manifests: $resolver, destinations: $destinations)->publish('u-app');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'published', 'application' => ['status' => 'published']], $response->getData());

	}//end testNoManifestAtAllPublishes()

	/**
	 * A route already pointing at this application is not rewritten.
	 *
	 * @return void
	 */
	public function testAnExistingRouteIsKept(): void {
		$this->signInAs(uid: 'alice');
		$this->rows(application: self::APP, route: ['slug' => 'demo', 'applicationUuid' => 'u-app']);
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($this->entity(payload: []));

		$this->assertSame(Http::STATUS_OK, $this->controller()->publish('u-app')->getStatus());

	}//end testAnExistingRouteIsKept()

	/**
	 * A failing route write is logged but publish still succeeds.
	 *
	 * @return void
	 */
	public function testAFailingRouteWriteDoesNotFailThePublish(): void {
		$this->signInAs(uid: 'alice');
		$this->rows(application: self::APP, route: ['slug' => 'demo', 'applicationUuid' => 'someone-else']);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null): ObjectEntity {
				if ($schema === 'built-app-route') {
					throw new RuntimeException('route refused');
				}

				return $this->entity(payload: $object);
			}
		);
		$this->logger->expects($this->once())->method('error')
			->with(
				'Buildiq: built-app-route upsert failed on publish for slug {slug}: {message}',
				$this->callback(static fn (array $c): bool => $c['slug'] === 'demo' && $c['message'] === 'route refused')
			);

		$response = $this->controller()->publish('u-app');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('published', $response->getData()['status']);

	}//end testAFailingRouteWriteDoesNotFailThePublish()

	/**
	 * A failing status save is logged and answered 500.
	 *
	 * @return void
	 */
	public function testAFailingStatusSaveIs500(): void {
		$this->signInAs(uid: 'alice');
		$this->rows(application: self::APP);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked row'));
		$this->registrar->expects($this->never())->method('onPublish');
		$this->logger->expects($this->once())->method('error')
			->with('Buildiq: publish/unpublish failed for {uuid}: {message}', $this->anything());

		$response = $this->controller()->unpublish('u-app');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal_error', 'detail' => 'locked row'], $response->getData());

	}//end testAFailingStatusSaveIs500()
}//end class
