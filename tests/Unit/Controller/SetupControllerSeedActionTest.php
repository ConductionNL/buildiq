<?php

/**
 * Tests for SetupController's seed-templates action, unknown actions and the
 * admin gate.
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

use OCA\Buildiq\Controller\SetupController;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\DemoDataService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\SettingsService;
use OCA\Buildiq\Service\SetupServices;
use OCA\Buildiq\Service\TemplateSeedService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Controller\SetupController
 * @uses   \OCA\Buildiq\Service\CallerContext
 * @uses   \OCA\Buildiq\Service\SetupServices
 */
class SetupControllerSeedActionTest extends TestCase {
	/**
	 * @var TemplateSeedService&MockObject
	 */
	private TemplateSeedService&MockObject $seedService;

	/**
	 * @var DemoDataService&MockObject
	 */
	private DemoDataService&MockObject $demoData;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seedService = $this->createMock(TemplateSeedService::class);
		$this->demoData = $this->createMock(DemoDataService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the controller for a caller.
	 *
	 * @param string|null          $uid     Signed-in user, or null for anonymous.
	 * @param bool                 $isAdmin Whether that user is an admin.
	 * @param array<string, mixed> $params  Request parameters.
	 *
	 * @return SetupController
	 */
	private function controller(?string $uid = 'root', bool $isAdmin = true, array $params = []): SetupController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(
			static fn (string $who): bool => $who === $uid && $isAdmin
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		return new SetupController(
			request: $request,
			logger: $this->logger,
			appConfig: $this->createMock(IAppConfig::class),
			caller: new CallerContext(
				userSession: $userSession,
				groupManager: $groupManager,
				permissionResolver: $this->createMock(PermissionResolver::class)
			),
			setup: new SetupServices(
				demoDataService: $this->demoData,
				settings: $this->createMock(SettingsService::class),
				seedService: $this->seedService
			)
		);

	}//end controller()

	/**
	 * An anonymous caller is refused 401 on every endpoint.
	 *
	 * @return void
	 */
	public function testAnonymousCallersAre401(): void {
		$this->seedService->expects($this->never())->method('seed');
		$controller = $this->controller(uid: null);

		foreach ([$controller->status(), $controller->saveConfig(), $controller->runAction('seed-templates')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'unauthenticated'], $response->getData());
		}

	}//end testAnonymousCallersAre401()

	/**
	 * A signed-in non-admin is refused 403 on every endpoint.
	 *
	 * @return void
	 */
	public function testNonAdminsAre403(): void {
		$this->seedService->expects($this->never())->method('seed');
		$controller = $this->controller(uid: 'bob', isAdmin: false);

		foreach ([$controller->status(), $controller->saveConfig(), $controller->runAction('seed-templates')] as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			$this->assertSame(
				['error' => 'forbidden', 'message' => 'Setup requires Nextcloud admin privileges.'],
				$response->getData()
			);
		}

	}//end testNonAdminsAre403()

	/**
	 * An unknown action id is 404 and seeds nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownActionIs404(): void {
		$this->seedService->expects($this->never())->method('seed');

		$response = $this->controller()->runAction('format-disk');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'Unknown setup action: format-disk'], $response->getData());

	}//end testAnUnknownActionIs404()

	/**
	 * A clean seed reports its counts and passes the detail through.
	 *
	 * @return void
	 */
	public function testACleanSeedReportsItsCounts(): void {
		$result = ['seeded' => 2, 'updated' => 1, 'skipped' => 4, 'errors' => []];
		$this->seedService->expects($this->once())->method('seed')->willReturn($result);

		$response = $this->controller()->runAction('seed-templates');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'success' => true,
				'message' => 'Seeded 2 template(s), updated 1, skipped 4 already present.',
				'detail' => $result,
			],
			$response->getData()
		);

	}//end testACleanSeedReportsItsCounts()

	/**
	 * A seed with errors is 422 and lists every error.
	 *
	 * @return void
	 */
	public function testASeedWithErrorsIs422(): void {
		$result = ['seeded' => 1, 'updated' => 0, 'skipped' => 0, 'errors' => ['bad A', 'bad B']];
		$this->seedService->method('seed')->willReturn($result);

		$response = $this->controller()->runAction('seed-templates');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			[
				'success' => false,
				'message' => 'Seeded 1 template(s) with errors: bad A; bad B',
				'detail' => $result,
			],
			$response->getData()
		);

	}//end testASeedWithErrorsIs422()

	/**
	 * A throwing seed is logged and answered 500 without leaking the reason.
	 *
	 * @return void
	 */
	public function testAThrowingSeedIs500WithoutTheReason(): void {
		$this->seedService->method('seed')->willThrowException(new RuntimeException('db password wrong'));
		$this->logger->expects($this->once())->method('error')
			->with('Buildiq: setup seed-templates action failed', ['exception' => 'db password wrong']);

		$response = $this->controller()->runAction('seed-templates');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(
			['success' => false, 'message' => 'Template seeding failed unexpectedly.'],
			$response->getData()
		);

	}//end testAThrowingSeedIs500WithoutTheReason()

	/**
	 * A non-scalar posted dataset names nothing and is refused as "that".
	 *
	 * @return void
	 */
	public function testANonScalarPostedDatasetIsRefused(): void {
		$this->demoData->method('listChoices')->willReturn([['id' => 'demo'], ['id' => 'none']]);
		$this->demoData->expects($this->never())->method('install');

		$response = $this->controller(params: ['dataset' => ['demo']])->runAction('load-demo-data');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['success' => false, 'message' => 'No dataset is called "that".'], $response->getData());

	}//end testANonScalarPostedDatasetIsRefused()
}//end class
