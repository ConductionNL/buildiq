<?php

/**
 * Unit tests for ApplicationCreationController.
 *
 * Covers spec `openbuild-app-creation-wizard` REQ-OBWIZ-001, REQ-OBWIZ-007:
 *   - 201 on success with applicationUuid in body
 *   - 422 on validation failure (failedAtStep=validate)
 *   - 500 on rollback-complete failure
 *   - 500 on rollback-partial failure (orphanedResources in body)
 *   - 401 when caller is unauthenticated
 *   - 403 for an authenticated non-admin (issue #157)
 *   - the admin posture is declared at the MIDDLEWARE layer, not only in the body
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

use OCA\OpenBuild\Controller\ApplicationCreationController;
use OCA\OpenBuild\Exception\WizardCreationException;
use OCA\OpenBuild\Service\ApplicationCreationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for ApplicationCreationController.
 */
class ApplicationCreationControllerTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var ApplicationCreationService&MockObject
	 */
	private ApplicationCreationService&MockObject $creationService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Controller under test.
	 */
	private ApplicationCreationController $controller;

	/**
	 * Set up shared mocks + SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->creationService = $this->createMock(ApplicationCreationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->controller = new ApplicationCreationController(
			request: $this->request,
			logger: $this->logger,
			creationService: $this->creationService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
		);

		// Default: request returns basic params.
		$this->request->method('getParams')->willReturn([
			'name' => 'Test App',
			'slug' => 'test-app',
			'preset' => 'single',
		]);
	}//end setUp()

	/**
	 * Configure the user session to return an authenticated NC-admin user.
	 *
	 * Also wires groupManager::isAdmin to return true so the new admin gate
	 * in wizard() allows the request (issue #157).
	 *
	 * PHPUnit 10 does not allow re-configuring a mock method that was already
	 * stubbed in setUp(). Each test must call this helper explicitly when it
	 * requires an authenticated session. Tests that expect 401 must NOT call it.
	 *
	 * @return void
	 */
	private function authenticateAsAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
	}//end authenticateAsAdmin()

	// -------------------------------------------------------------------------
	// Auth posture — declared at the middleware layer, not only in the body
	// -------------------------------------------------------------------------

	/**
	 * `wizard()` provisions an OpenRegister Register, so it is admin-only
	 * (issue #157). `#[NoAdminRequired]` does not ADD a layer, it REMOVES one:
	 * it tells NC's SecurityMiddleware that any logged-in user may reach the
	 * method, leaving the in-body `isAdmin()` gate as the only thing in the
	 * way. The attribute must therefore declare the admin posture the body
	 * already enforces — the same correction SetupController got in #127.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function wizardDeclaresTheAdminPostureAtTheMiddlewareLayer(): void {
		$reflection = new ReflectionMethod(ApplicationCreationController::class, 'wizard');

		self::assertCount(
			0,
			$reflection->getAttributes(NoAdminRequired::class),
			'wizard() must not carry #[NoAdminRequired]: it disables the middleware admin check '
			. 'on an endpoint that provisions an OpenRegister Register.'
		);

		self::assertCount(
			1,
			$reflection->getAttributes(AuthorizedAdminSetting::class),
			'wizard() must declare #[AuthorizedAdminSetting] so the middleware enforces admin before dispatch.'
		);
	}//end wizardDeclaresTheAdminPostureAtTheMiddlewareLayer()

	// -------------------------------------------------------------------------
	// 401 Unauthenticated
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function wizardReturns401WhenNoUserSession(): void {
		// No authenticateAsAdmin() call here — userSession::getUser() returns null
		// by default for an unconfigured PHPUnit 10 mock, triggering the 401 branch.
		$response = $this->controller->wizard();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('unauthenticated', $response->getData()['error']);
	}//end wizardReturns401WhenNoUserSession()

	// -------------------------------------------------------------------------
	// 201 Success
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function wizardReturns201WithApplicationUuidOnSuccess(): void {
		$this->authenticateAsAdmin();

		$this->creationService->method('createApplication')
			->willReturn('app-uuid-001');

		$response = $this->controller->wizard();

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('app-uuid-001', $response->getData()['applicationUuid']);
	}//end wizardReturns201WithApplicationUuidOnSuccess()

	// -------------------------------------------------------------------------
	// 422 Validation failure
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function wizardReturns422OnValidationFailure(): void {
		$this->authenticateAsAdmin();

		$this->creationService->method('createApplication')
			->willThrowException(new WizardCreationException(
				errorCode: 'validation_error',
				failedAtStep: 'validate',
				message: 'Invalid slug.',
				rollbackStatus: 'none',
			));

		$response = $this->controller->wizard();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$data = $response->getData();
		self::assertSame('validation_error', $data['code']);
		self::assertSame('validate', $data['failedAtStep']);
		self::assertSame('none', $data['rollbackStatus']);
	}//end wizardReturns422OnValidationFailure()

	// -------------------------------------------------------------------------
	// 500 Rollback complete
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function wizardReturns500OnRollbackComplete(): void {
		$this->authenticateAsAdmin();

		$this->creationService->method('createApplication')
			->willThrowException(new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'register-provision-production',
				message: 'Register creation failed.',
				rollbackStatus: 'complete',
			));

		$response = $this->controller->wizard();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		self::assertSame('wizard_rollback', $data['code']);
		self::assertSame('complete', $data['rollbackStatus']);
		self::assertArrayNotHasKey('orphanedResources', $data);
	}//end wizardReturns500OnRollbackComplete()

	// -------------------------------------------------------------------------
	// 500 Rollback partial
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function wizardReturns500WithOrphanedResourcesOnRollbackPartial(): void {
		$this->authenticateAsAdmin();

		$this->creationService->method('createApplication')
			->willThrowException(new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'register-provision-staging',
				message: 'Register creation failed.',
				rollbackStatus: 'partial',
				orphanedResources: ['openbuild-test-app-development'],
			));

		$response = $this->controller->wizard();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		self::assertSame('partial', $data['rollbackStatus']);
		self::assertSame(['openbuild-test-app-development'], $data['orphanedResources']);
	}//end wizardReturns500WithOrphanedResourcesOnRollbackPartial()

	// -------------------------------------------------------------------------
	// 403 Non-admin forbidden (issue #157)
	// -------------------------------------------------------------------------

	/**
	 * Creating a virtual app provisions an OR Register, which is an
	 * admin-only operation (OR #1949). Non-admin authenticated users
	 * must receive 403 (issue #157).
	 *
	 * @test
	 *
	 * @return void
	 */
	public function wizardReturns403ForNonAdminAuthenticatedUser(): void {
		$nonAdminUser = $this->createMock(IUser::class);
		$nonAdminUser->method('getUID')->willReturn('regular-user');
		$this->userSession->method('getUser')->willReturn($nonAdminUser);
		$this->groupManager->method('isAdmin')->with('regular-user')->willReturn(false);

		$this->creationService->expects($this->never())->method('createApplication');

		$response = $this->controller->wizard();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('forbidden', $response->getData()['error']);
	}//end wizardReturns403ForNonAdminAuthenticatedUser()
}//end class
