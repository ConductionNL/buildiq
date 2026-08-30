<?php

/**
 * Unit tests for AppOverrideController auth posture.
 *
 * Covers the buildiq-inline-edit-persistence change (spec
 * app-override-persistence): anonymous write rejected (401), out-of-scope
 * write forbidden (403), in-scope write allowed (2xx), GET returns the stored
 * delta or an empty object, malformed body 422, app-blanking delta 422,
 * idempotent clear.
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

use OCA\Buildiq\Controller\AppOverrideController;
use OCA\Buildiq\Service\AppOverrideService;
use OCA\Buildiq\Service\PermissionResolver;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppOverrideController.
 */
class AppOverrideControllerTest extends TestCase {
	/**
	 * Mock HTTP request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock override service.
	 *
	 * @var AppOverrideService&MockObject
	 */
	private AppOverrideService&MockObject $service;

	/**
	 * Mock permission resolver (maintainer gate for listUserOverrides).
	 *
	 * @var PermissionResolver&MockObject
	 */
	private PermissionResolver&MockObject $permissionResolver;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = $this->createMock(AppOverrideService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->permissionResolver = $this->createMock(PermissionResolver::class);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return AppOverrideController
	 */
	private function controller(): AppOverrideController {
		return new AppOverrideController(
			request: $this->request,
			logger: $this->logger,
			appOverrideService: $this->service,
			userSession: $this->userSession,
			appManager: $this->appManager,
			permissionResolver: $this->permissionResolver
		);

	}//end controller()

	/**
	 * Build a mock IUser with the given UID.
	 *
	 * @param string $uid The user UID.
	 *
	 * @return IUser&MockObject
	 */
	private function mockUser(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end mockUser()

	/**
	 * Anonymous PUT is rejected with 401 and persists nothing.
	 *
	 * @return void
	 */
	public function testAnonymousSaveIsRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method('upsert');

		$response = $this->controller()->save(appId: 'pipelinq');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousSaveIsRejected()

	/**
	 * A logged-in user outside Buildiq's group-restriction is forbidden (403).
	 *
	 * @return void
	 */
	public function testOutOfScopeSaveIsForbidden(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('carol'));
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->service->expects(self::never())->method('upsert');

		$response = $this->controller()->save(appId: 'pipelinq');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testOutOfScopeSaveIsForbidden()

	/**
	 * An in-scope user saving a valid delta succeeds and records updatedBy.
	 *
	 * @return void
	 */
	public function testInScopeSaveIsAllowed(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->request->method('getParams')->willReturn(['pages' => [['id' => 'home', 'title' => 'X']]]);
		$this->request->method('getParam')->willReturn(null);

		$this->service->method('validateDeltaShape')->willReturn([]);
		$this->service->method('wouldBlankApp')->willReturn(false);
		$this->service->expects(self::once())
			->method('upsert')
			->willReturn(['appId' => 'pipelinq', 'updatedBy' => 'alice', 'updatedAt' => '2026-06-18T00:00:00+00:00']);

		$response = $this->controller()->save(appId: 'pipelinq');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame('alice', $data['updatedBy']);

	}//end testInScopeSaveIsAllowed()

	/**
	 * A malformed (list-shaped) body is rejected 422 and persists nothing.
	 *
	 * @return void
	 */
	public function testMalformedBodyIsUnprocessable(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		// A list-shaped params bag → readDeltaBody returns null.
		$this->request->method('getParams')->willReturn(['a', 'b']);
		$this->request->method('getParam')->willReturn(null);
		$this->service->expects(self::never())->method('upsert');

		$response = $this->controller()->save(appId: 'pipelinq');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testMalformedBodyIsUnprocessable()

	/**
	 * An app-blanking delta is rejected 422 and persists nothing.
	 *
	 * @return void
	 */
	public function testAppBlankingDeltaIsUnprocessable(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->request->method('getParams')->willReturn(['pages' => [['id' => 'home', '$op' => 'remove']], 'menu' => []]);
		$this->request->method('getParam')->willReturn(null);
		$this->service->method('validateDeltaShape')->willReturn([]);
		$this->service->method('wouldBlankApp')->willReturn(true);
		$this->service->expects(self::never())->method('upsert');

		$response = $this->controller()->save(appId: 'pipelinq');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testAppBlankingDeltaIsUnprocessable()

	/**
	 * GET with no override returns 200 with an empty object.
	 *
	 * @return void
	 */
	public function testGetReturnsEmptyObjectWhenNoOverride(): void {
		$this->service->method('findByAppId')->willReturn(null);

		$response = $this->controller()->get(appId: 'somefleetapp');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertEquals((object)[], $response->getData());

	}//end testGetReturnsEmptyObjectWhenNoOverride()

	/**
	 * GET returns the stored manifestDelta unchanged.
	 *
	 * @return void
	 */
	public function testGetReturnsStoredDelta(): void {
		// The default GET now returns the LAYERED resolution (admin ⊕ the
		// caller's own user delta) via resolveLayeredDelta — the loader
		// contract is unchanged (layered-versioned-app-deltas). `?scope=admin`
		// would instead read the raw admin delta via findByAppId.
		$delta = ['pages' => [['id' => 'home', 'title' => 'Renamed']]];
		$this->service->method('resolveLayeredDelta')->willReturn(['appId' => 'opencatalogi', 'manifestDelta' => $delta]);

		$response = $this->controller()->get(appId: 'opencatalogi');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertEquals((object)$delta, $response->getData());

	}//end testGetReturnsStoredDelta()

	/**
	 * Anonymous DELETE is rejected with 401.
	 *
	 * @return void
	 */
	public function testAnonymousClearIsRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method('delete');

		$response = $this->controller()->clear(appId: 'opencatalogi');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousClearIsRejected()

	/**
	 * In-scope DELETE clears the override and returns 200.
	 *
	 * @return void
	 */
	public function testInScopeClearSucceeds(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->service->expects(self::once())->method('delete')->with('opencatalogi');

		$response = $this->controller()->clear(appId: 'opencatalogi');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testInScopeClearSucceeds()

	/**
	 * An invalid (non-kebab) appId is rejected 400 before any service call.
	 *
	 * @return void
	 */
	public function testInvalidAppIdIsBadRequest(): void {
		$response = $this->controller()->get(appId: 'Not_Valid');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testInvalidAppIdIsBadRequest()

	/**
	 * A non-maintainer is forbidden (403) from listing all users' overrides —
	 * the no-admin-idor boundary. The service is never queried.
	 *
	 * @return void
	 */
	public function testListUserOverridesForbiddenForNonMaintainer(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('bob'));
		$this->service->method('findHybridApplication')->willReturn(
			['slug' => 'pipelinq', 'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []]]
		);
		$this->permissionResolver->method('resolveUserGroups')->willReturn([]);
		$this->permissionResolver->method('matchesCaller')->willReturn(false);
		$this->service->expects(self::never())->method('listUserDeltas');

		$response = $this->controller()->listUserOverrides(appId: 'pipelinq');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testListUserOverridesForbiddenForNonMaintainer()

	/**
	 * An owner/editor (or admin) may list all users' overrides (200).
	 *
	 * @return void
	 */
	public function testListUserOverridesAllowedForMaintainer(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->service->method('findHybridApplication')->willReturn(
			['slug' => 'pipelinq', 'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []]]
		);
		$this->permissionResolver->method('resolveUserGroups')->willReturn([]);
		$this->permissionResolver->method('matchesCaller')->willReturn(true);
		$this->service->expects(self::once())->method('listUserDeltas')->willReturn(
			[['owner' => 'carol', 'versionUuid' => 'v1', 'semver' => '0.1.0', 'status' => 'draft', 'updatedAt' => null]]
		);

		$response = $this->controller()->listUserOverrides(appId: 'pipelinq');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertSame(1, $data['total']);
		self::assertSame('carol', $data['overrides'][0]['owner']);

	}//end testListUserOverridesAllowedForMaintainer()

	/**
	 * saveUser: anonymous callers are rejected and nothing is persisted.
	 *
	 * Wire-contract test for the per-user override endpoints (gate-25). These
	 * are separate methods from the app-scoped save/get/clear above and had no
	 * coverage of their own.
	 *
	 * @return void
	 */
	public function testAnonymousSaveUserIsRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method('upsertUserDelta');

		$response = $this->controller()->saveUser(appId: 'pipelinq');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousSaveUserIsRejected()

	/**
	 * saveUser: an out-of-scope user is forbidden and nothing is persisted.
	 *
	 * @return void
	 */
	public function testOutOfScopeSaveUserIsForbidden(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('carol'));
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->service->expects(self::never())->method('upsertUserDelta');

		$response = $this->controller()->saveUser(appId: 'pipelinq');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testOutOfScopeSaveUserIsForbidden()

	/**
	 * saveUser: an in-scope user's delta is stored against their OWN uid.
	 *
	 * The owner in the response is the session uid — this endpoint is
	 * owner-scoped and must not accept an owner from the request body.
	 *
	 * @return void
	 */
	public function testInScopeSaveUserIsAllowedAndOwnerScoped(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->request->method('getParams')->willReturn(['pages' => [['id' => 'home', 'title' => 'X']]]);
		$this->request->method('getParam')->willReturn(null);

		$this->service->method('validateDeltaShape')->willReturn([]);
		$this->service->method('wouldBlankApp')->willReturn(false);
		$this->service->expects(self::once())
			->method('upsertUserDelta')
			->willReturn(['owner' => 'alice', 'updatedAt' => '2026-08-09T00:00:00+00:00', 'versionUuid' => null]);

		$response = $this->controller()->saveUser(appId: 'pipelinq');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('alice', $response->getData()['owner']);

	}//end testInScopeSaveUserIsAllowedAndOwnerScoped()

	/**
	 * saveUser: an invalid app id is a 400 before any auth or persistence.
	 *
	 * @return void
	 */
	public function testSaveUserRejectsInvalidAppId(): void {
		$this->service->expects(self::never())->method('upsertUserDelta');

		$response = $this->controller()->saveUser(appId: 'Not A Valid Id!');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSaveUserRejectsInvalidAppId()

	/**
	 * getUser: anonymous callers get 401 and the service is never consulted.
	 *
	 * @return void
	 */
	public function testAnonymousGetUserIsRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method('getUserDelta');

		$response = $this->controller()->getUser(appId: 'pipelinq');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousGetUserIsRejected()

	/**
	 * getUser: a logged-in user reads their own stored delta.
	 *
	 * @return void
	 */
	public function testGetUserReturnsOwnDelta(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->service->expects(self::once())
			->method('getUserDelta')
			->willReturn(['delta' => ['pages' => []], 'owner' => 'alice']);

		$response = $this->controller()->getUser(appId: 'pipelinq');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testGetUserReturnsOwnDelta()

	/**
	 * clearUser: anonymous callers are rejected and nothing is deleted.
	 *
	 * @return void
	 */
	public function testAnonymousClearUserIsRejected(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->service->expects(self::never())->method('deleteUserDelta');

		$response = $this->controller()->clearUser(appId: 'pipelinq');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnonymousClearUserIsRejected()

	/**
	 * clearUser: an in-scope user clears their own delta and gets a success.
	 *
	 * @return void
	 */
	public function testInScopeClearUserSucceeds(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->service->expects(self::once())->method('deleteUserDelta');

		$response = $this->controller()->clearUser(appId: 'pipelinq');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testInScopeClearUserSucceeds()
}//end class
