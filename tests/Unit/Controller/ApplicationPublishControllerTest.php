<?php

/**
 * Unit tests for ApplicationPublishController.
 *
 * Covers the explicit publish / unpublish action that flips Application.status
 * (which gates the app-menu entry in AppNavigationService):
 *   - publish() sets status=published, saves, returns 200
 *   - unpublish() sets status=draft, returns 200
 *   - the OR-managed @self envelope is stripped before saving
 *   - 401 when unauthenticated
 *   - 404 when the application is not found
 *   - 403 when the caller is not an owner
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

use OCA\OpenBuild\Controller\ApplicationPublishController;
use OCA\OpenBuild\Service\ApplicationDeletionService;
use OCA\OpenBuild\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * Tests for ApplicationPublishController.
 */
class ApplicationPublishControllerTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var ApplicationDeletionService&MockObject
	 */
	private ApplicationDeletionService&MockObject $deletionService;

	/**
	 * @var VirtualAppCredentialRegistrar&MockObject
	 */
	private VirtualAppCredentialRegistrar&MockObject $credentialRegistrar;

	/**
	 * Controller under test.
	 */
	private ApplicationPublishController $controller;

	/**
	 * Set up shared mocks + SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('getUserGroupIds')->willReturn([]);

		$permissionResolver = new PermissionResolver($this->groupManager, $this->createMock(LoggerInterface::class));
		$this->deletionService = $this->createMock(ApplicationDeletionService::class);
		$this->credentialRegistrar = $this->createMock(VirtualAppCredentialRegistrar::class);

		$this->controller = new ApplicationPublishController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			userSession: $this->userSession,
			permissionResolver: $permissionResolver,
			deletionService: $this->deletionService,
			credentialRegistrar: $this->credentialRegistrar,
		);
	}//end setUp()

	/**
	 * Build an ObjectEntity whose serialisers return the given payload.
	 *
	 * @param array<string,mixed> $payload The object data.
	 *
	 * @return ObjectEntity
	 */
	private function buildEntity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string,mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}

			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return $this->payload;
			}
		};

		$entity->payload = $payload;
		return $entity;
	}//end buildEntity()

	/**
	 * Mock a signed-in user with the given UID.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signInAs()

	/**
	 * publish() flips status to published, strips @self, and returns 200.
	 *
	 * @return void
	 */
	public function testPublishSetsStatusPublished(): void {
		$this->signInAs(uid: 'alice');
		$app = $this->buildEntity(payload: [
			'id' => 'u-app',
			'slug' => 'demo',
			'name' => 'Demo',
			'status' => 'draft',
			'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
			'@self' => ['id' => 'u-app'],
		]);
		$this->objectService->method('find')->willReturn($app);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (array $object) use (&$captured) {
				$captured = $object;
				return $this->buildEntity(payload: $object);
			});

		$response = $this->controller->publish('u-app');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('published', $response->getData()['status']);
		self::assertSame('published', $captured['status']);
		self::assertArrayNotHasKey('@self', $captured, '@self must be stripped before saving');
	}//end testPublishSetsStatusPublished()

	/**
	 * publish() triggers credential-broker onboarding for the app's slug.
	 *
	 * @return void
	 */
	public function testPublishTriggersCredentialOnboarding(): void {
		$this->signInAs(uid: 'alice');
		$app = $this->buildEntity(payload: [
			'id' => 'u-app',
			'slug' => 'spectr',
			'name' => 'Spectr',
			'status' => 'draft',
			'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
		]);
		$this->objectService->method('find')->willReturn($app);
		$this->objectService->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntity => $this->buildEntity(payload: $object)
		);

		$this->credentialRegistrar->expects($this->once())
			->method('onPublish')
			->with('spectr', $this->isInstanceOf(IUser::class));

		$response = $this->controller->publish('u-app');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPublishTriggersCredentialOnboarding()

	/**
	 * unpublish() flips status back to draft and returns 200 — and never onboards credentials.
	 *
	 * @return void
	 */
	public function testUnpublishSetsStatusDraft(): void {
		$this->signInAs(uid: 'alice');
		$app = $this->buildEntity(payload: [
			'id' => 'u-app',
			'slug' => 'demo',
			'name' => 'Demo',
			'status' => 'published',
			'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
		]);
		$this->objectService->method('find')->willReturn($app);
		$this->objectService->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntity => $this->buildEntity(payload: $object)
		);

		// Unpublish is not a go-live event — onboarding must not fire.
		$this->credentialRegistrar->expects($this->never())->method('onPublish');

		$response = $this->controller->unpublish('u-app');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('draft', $response->getData()['status']);
	}//end testUnpublishSetsStatusDraft()

	/**
	 * 401 when no user is signed in.
	 *
	 * @return void
	 */
	public function testPublishUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->publish('u-app');
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testPublishUnauthenticated()

	/**
	 * 404 when the application does not exist.
	 *
	 * @return void
	 */
	public function testPublishNotFound(): void {
		$this->signInAs(uid: 'alice');
		$this->objectService->method('find')->willReturn(null);
		$response = $this->controller->publish('u-missing');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPublishNotFound()

	/**
	 * 403 when the caller is not an owner of the application.
	 *
	 * @return void
	 */
	public function testPublishForbiddenForNonOwner(): void {
		$this->signInAs(uid: 'mallory');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$app = $this->buildEntity(payload: [
			'id' => 'u-app',
			'slug' => 'demo',
			'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob'], 'viewers' => []],
		]);
		$this->objectService->method('find')->willReturn($app);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->publish('u-app');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPublishForbiddenForNonOwner()

	/**
	 * Arrange a signed-in owner ('alice') with a deletable app 'u-app'/'demo'.
	 *
	 * @return void
	 */
	private function arrangeOwnedDeletableApp(): void {
		$this->signInAs(uid: 'alice');
		$app = $this->buildEntity(payload: [
			'id' => 'u-app',
			'slug' => 'demo',
			'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
		]);
		$this->objectService->method('find')->willReturn($app);
	}//end arrangeOwnedDeletableApp()

	/**
	 * Stub the raw `deleteData` request param destroy() reads (safe-by-default
	 * parsing lives in the controller, not the dispatcher's bool coercion).
	 *
	 * @param mixed $raw The value getParam('deleteData', ...) should return.
	 *
	 * @return void
	 */
	private function requestDeleteData(mixed $raw): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($key === 'deleteData' ? $raw : $default)
		);
	}//end requestDeleteData()

	/**
	 * With no deleteData param the underlying data is preserved: the service is
	 * called with deleteData=false. Positional ->with() constrains all three
	 * args so the third is actually verified.
	 *
	 * @return void
	 */
	public function testDestroyPreservesDataByDefault(): void {
		$this->arrangeOwnedDeletableApp();
		$this->deletionService->expects($this->once())
			->method('deleteApplication')
			->with('u-app', 'demo', false)
			->willReturn([]);

		$response = $this->controller->destroy('u-app');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['deleted']);
	}//end testDestroyPreservesDataByDefault()

	/**
	 * An explicit deleteData=1 opts into a destructive data purge: the service
	 * is called with deleteData=true.
	 *
	 * @return void
	 */
	public function testDestroyPurgesDataWhenOptedIn(): void {
		$this->arrangeOwnedDeletableApp();
		$this->requestDeleteData('1');
		$this->deletionService->expects($this->once())
			->method('deleteApplication')
			->with('u-app', 'demo', true)
			->willReturn([]);

		$response = $this->controller->destroy('u-app');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['deleted']);
	}//end testDestroyPurgesDataWhenOptedIn()

	/**
	 * The literal string "false" MUST preserve data — a hand-crafted
	 * `?deleteData=false` is a reasonable-looking URL and must never wipe.
	 * (Guards against relying on PHP's stringy-bool coercion.)
	 *
	 * @return void
	 */
	public function testDestroyTreatsStringFalseAsPreserve(): void {
		$this->arrangeOwnedDeletableApp();
		$this->requestDeleteData('false');
		$this->deletionService->expects($this->once())
			->method('deleteApplication')
			->with('u-app', 'demo', false)
			->willReturn([]);

		$response = $this->controller->destroy('u-app');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDestroyTreatsStringFalseAsPreserve()

	/**
	 * Any ambiguous/unexpected value (e.g. "no") preserves data rather than
	 * purging — the safe default for an irreversible action.
	 *
	 * @return void
	 */
	public function testDestroyTreatsAmbiguousValueAsPreserve(): void {
		$this->arrangeOwnedDeletableApp();
		$this->requestDeleteData('no');
		$this->deletionService->expects($this->once())
			->method('deleteApplication')
			->with('u-app', 'demo', false)
			->willReturn([]);

		$response = $this->controller->destroy('u-app');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDestroyTreatsAmbiguousValueAsPreserve()

	/**
	 * The explicit affirmative string "true" opts into the purge.
	 *
	 * @return void
	 */
	public function testDestroyTreatsStringTrueAsPurge(): void {
		$this->arrangeOwnedDeletableApp();
		$this->requestDeleteData('true');
		$this->deletionService->expects($this->once())
			->method('deleteApplication')
			->with('u-app', 'demo', true)
			->willReturn([]);

		$response = $this->controller->destroy('u-app');
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testDestroyTreatsStringTrueAsPurge()

	/**
	 * destroy() is forbidden for a non-owner and never calls the service.
	 *
	 * @return void
	 */
	public function testDestroyForbiddenForNonOwner(): void {
		$this->signInAs(uid: 'mallory');
		$this->groupManager->method('isAdmin')->willReturn(false);
		$app = $this->buildEntity(payload: [
			'id' => 'u-app',
			'slug' => 'demo',
			'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
		]);
		$this->objectService->method('find')->willReturn($app);
		$this->deletionService->expects($this->never())->method('deleteApplication');

		$response = $this->controller->destroy('u-app');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testDestroyForbiddenForNonOwner()
}//end class
