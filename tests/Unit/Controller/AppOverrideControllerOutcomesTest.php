<?php

/**
 * Characterisation tests for AppOverrideController's guard and failure outcomes.
 *
 * Pins, for every endpoint, the allowed and refused outcomes of the write
 * guard (400 invalid appId, 401 anonymous, 403 out of scope), the 422 delta
 * rejections and the 500 failure path with its exact log line, so the shared
 * guard and failure helpers cannot change what a caller sees.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Outcome goldens for AppOverrideController.
 */
class AppOverrideControllerOutcomesTest extends TestCase {

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
	 * Mock permission resolver.
	 *
	 * @var PermissionResolver&MockObject
	 */
	private PermissionResolver&MockObject $permissionResolver;

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
	 * The four write endpoints and the service method each one persists through.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function writeEndpoints(): array {
		return [
			'save'      => ['save', 'upsert'],
			'saveUser'  => ['saveUser', 'upsertUserDelta'],
			'clear'     => ['clear', 'delete'],
			'clearUser' => ['clearUser', 'deleteUserDelta'],
		];
	}//end writeEndpoints()

	/**
	 * A malformed appId is refused with 400 before the session is consulted.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $persist  The service method that must not run.
	 *
	 * @return void
	 */
	#[DataProvider('writeEndpoints')]
	public function testInvalidAppIdIsRefusedFirst(string $endpoint, string $persist): void {
		$this->userSession->expects(self::never())->method('getUser');
		$this->service->expects(self::never())->method($persist);

		$response = $this->controller()->$endpoint('Bad_Id');

		self::assertSame([400, ['error' => 'invalid_app_id']], $this->outcome(response: $response));
	}//end testInvalidAppIdIsRefusedFirst()

	/**
	 * An anonymous caller is refused with 401.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $persist  The service method that must not run.
	 *
	 * @return void
	 */
	#[DataProvider('writeEndpoints')]
	public function testAnonymousWriteIsRefused(string $endpoint, string $persist): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->expects(self::never())->method('isEnabledForUser');
		$this->service->expects(self::never())->method($persist);

		$response = $this->controller()->$endpoint('pipelinq');

		self::assertSame([401, ['error' => 'unauthenticated']], $this->outcome(response: $response));
	}//end testAnonymousWriteIsRefused()

	/**
	 * A caller outside Buildiq's group restriction is refused with 403.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $persist  The service method that must not run.
	 *
	 * @return void
	 */
	#[DataProvider('writeEndpoints')]
	public function testOutOfScopeWriteIsRefused(string $endpoint, string $persist): void {
		$this->signIn(enabled: false);
		$this->service->expects(self::never())->method($persist);

		$response = $this->controller()->$endpoint('pipelinq');

		self::assertSame([403, ['error' => 'forbidden']], $this->outcome(response: $response));
	}//end testOutOfScopeWriteIsRefused()

	/**
	 * An in-scope caller passes the guard and reaches the store.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $persist  The service method that must run.
	 *
	 * @return void
	 */
	#[DataProvider('writeEndpoints')]
	public function testInScopeWriteIsAllowed(string $endpoint, string $persist): void {
		$this->signIn(enabled: true);
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('validateDeltaShape')->willReturn([]);
		$this->service->expects(self::once())->method($persist);

		$response = $this->controller()->$endpoint('pipelinq');

		self::assertSame(200, $response->getStatus());
	}//end testInScopeWriteIsAllowed()

	/**
	 * Both delta writes refuse a list-shaped body with 422.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function deltaWrites(): array {
		return [
			'save'     => ['save', 'upsert'],
			'saveUser' => ['saveUser', 'upsertUserDelta'],
		];
	}//end deltaWrites()

	/**
	 * A list-shaped body is refused with the invalid_delta detail.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $persist  The service method that must not run.
	 *
	 * @return void
	 */
	#[DataProvider('deltaWrites')]
	public function testListBodyIsUnprocessable(string $endpoint, string $persist): void {
		$this->signIn(enabled: true);
		$this->request->method('getParams')->willReturn(['a', 'b']);
		$this->service->expects(self::never())->method($persist);

		$response = $this->controller()->$endpoint('pipelinq');

		$detail = ['error' => 'invalid_delta', 'detail' => 'request body must be a keyed delta object'];
		self::assertSame([422, $detail], $this->outcome(response: $response));
	}//end testListBodyIsUnprocessable()

	/**
	 * Shape violations are refused with 422 and listed.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $persist  The service method that must not run.
	 *
	 * @return void
	 */
	#[DataProvider('deltaWrites')]
	public function testShapeViolationIsUnprocessable(string $endpoint, string $persist): void {
		$this->signIn(enabled: true);
		$this->request->method('getParams')->willReturn(['pages' => 'x']);
		$this->service->method('validateDeltaShape')->willReturn(['pages must be a list']);
		$this->service->expects(self::never())->method($persist);

		$response = $this->controller()->$endpoint('pipelinq');

		$body = ['error' => 'invalid_delta', 'violations' => ['pages must be a list']];
		self::assertSame([422, $body], $this->outcome(response: $response));
	}//end testShapeViolationIsUnprocessable()

	/**
	 * An app-blanking delta is refused with 422.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $persist  The service method that must not run.
	 *
	 * @return void
	 */
	#[DataProvider('deltaWrites')]
	public function testBlankingDeltaIsUnprocessable(string $endpoint, string $persist): void {
		$this->signIn(enabled: true);
		$this->request->method('getParams')->willReturn(['pages' => []]);
		$this->service->method('validateDeltaShape')->willReturn([]);
		$this->service->method('wouldBlankApp')->willReturn(true);
		$this->service->expects(self::never())->method($persist);

		$response = $this->controller()->$endpoint('pipelinq');

		self::assertSame(422, $response->getStatus());
		self::assertSame('app_blanking_delta', $this->outcome(response: $response)[1]['error']);
	}//end testBlankingDeltaIsUnprocessable()

	/**
	 * A refused per-user override surfaces as 403 with the service's message.
	 *
	 * @return void
	 */
	public function testUserOverridesNotAllowedIsForbidden(): void {
		$this->signIn(enabled: true);
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('validateDeltaShape')->willReturn([]);
		$this->service->method('upsertUserDelta')->willThrowException(new RuntimeException('flag off'));
		$this->logger->expects(self::never())->method('error');

		$response = $this->controller()->saveUser(appId: 'pipelinq');

		$body = ['error' => 'user_overrides_not_allowed', 'detail' => 'flag off'];
		self::assertSame([403, $body], $this->outcome(response: $response));
	}//end testUserOverridesNotAllowedIsForbidden()

	/**
	 * Every endpoint, the store call that fails, and the log line it writes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function failingEndpoints(): array {
		return [
			'get'       => ['get', 'resolveLayeredDelta', 'Buildiq: AppOverride get failed for appId pipelinq: boom'],
			'getUser'   => ['getUser', 'getUserDelta', 'Buildiq: user-delta get failed for appId pipelinq: boom'],
			'saveUser'  => ['saveUser', 'upsertUserDelta', 'Buildiq: user-delta save failed for appId pipelinq: boom'],
			'clearUser' => ['clearUser', 'deleteUserDelta', 'Buildiq: user-delta clear failed for appId pipelinq: boom'],
			'list'      => ['listUserOverrides', 'listUserDeltas', 'Buildiq: user-delta list failed for appId pipelinq: boom'],
			'save'      => ['save', 'upsert', 'Buildiq: AppOverride save failed for appId pipelinq: boom'],
			'clear'     => ['clear', 'delete', 'Buildiq: AppOverride clear failed for appId pipelinq: boom'],
		];
	}//end failingEndpoints()

	/**
	 * A store failure is logged once with the exception and answered with 500.
	 *
	 * @param string $endpoint The controller method.
	 * @param string $failing  The service method that throws.
	 * @param string $message  The exact log message.
	 *
	 * @return void
	 */
	#[DataProvider('failingEndpoints')]
	public function testStoreFailureIsLoggedAndAnswered(string $endpoint, string $failing, string $message): void {
		$this->signInAsMaintainer();
		$failure = new \LogicException('boom');
		$this->service->method($failing)->willThrowException($failure);
		$this->logger->expects(self::once())->method('error')->with($message, ['exception' => $failure]);

		$response = $this->controller()->$endpoint('pipelinq');

		self::assertSame([500, ['error' => 'internal_error']], $this->outcome(response: $response));
	}//end testStoreFailureIsLoggedAndAnswered()

	/**
	 * Sign in a user with every gate open, for the failure-path goldens.
	 *
	 * @return void
	 */
	private function signInAsMaintainer(): void {
		$this->signIn(enabled: true);
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('validateDeltaShape')->willReturn([]);
		$this->service->method('findHybridApplication')->willReturn(['permissions' => ['owners' => ['alice']]]);
		$this->permissionResolver->method('resolveUserGroups')->willReturn([]);
		$this->permissionResolver->method('matchesCaller')->willReturn(true);
	}//end signInAsMaintainer()

	/**
	 * Sign in 'alice' with Buildiq enabled or disabled for her.
	 *
	 * @param bool $enabled Whether Buildiq is enabled for the user.
	 *
	 * @return void
	 */
	private function signIn(bool $enabled): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')->willReturn($enabled);
	}//end signIn()

	/**
	 * Reduce a response to its status and decoded body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), json_decode((string)json_encode($response->getData()), true)];
	}//end outcome()

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
}//end class
