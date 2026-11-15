<?php

/**
 * Lookup and fallback tests for VersionPromotionController.
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

use OCA\Buildiq\Controller\VersionPromotionController;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\VersionPromotionService;
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
 * @covers \OCA\Buildiq\Controller\VersionPromotionController
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Exception\InsufficientPermissionException
 * @uses   \OCA\Buildiq\Exception\VersionPromotionException
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class VersionPromotionControllerLookupTest extends TestCase {
	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var VersionPromotionService&MockObject
	 */
	private VersionPromotionService&MockObject $promotionService;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Set up the doubles with a signed-in owner `olga`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->promotionService = $this->createMock(VersionPromotionService::class);
		$this->request = $this->createMock(IRequest::class);

	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return VersionPromotionController
	 */
	private function controller(): VersionPromotionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('olga');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);

		return new VersionPromotionController(
			request: $this->request,
			logger: $this->logger,
			objectService: $this->objectService,
			userSession: $session,
			promotionService: $this->promotionService,
			permissionResolver: new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class)),
		);

	}//end controller()

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
	 * Answer find() per schema: the application or the version (null = missing,
	 * 'throw' = unreadable).
	 *
	 * @param mixed $application Application payload, null or 'throw'.
	 * @param mixed $version     Version payload, null or 'throw'.
	 *
	 * @return void
	 */
	private function rows(mixed $application, mixed $version): void {
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null) use ($application, $version): ?ObjectEntity {
				$answer = $version;
				if ($schema === VersionPromotionService::APPLICATION_SCHEMA) {
					$answer = $application;
				}

				if ($answer === 'throw') {
					throw new RuntimeException('lookup failed for ' . $id);
				}

				if ($answer === null) {
					return null;
				}

				return $this->entity(payload: $answer);
			}
		);

	}//end rows()

	/**
	 * An unreadable application is logged at debug and answered 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableApplicationIs404(): void {
		$this->rows(application: 'throw', version: null);
		$this->promotionService->expects($this->never())->method('promote');
		$this->logger->expects($this->once())->method('debug')
			->with(
				'Buildiq: Application {uuid} could not be loaded: {message}',
				$this->callback(static fn (array $c): bool => $c['uuid'] === 'app-1' && $c['message'] === 'lookup failed for app-1')
			);

		$response = $this->controller()->promote('app-1', 'v-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'Application app-1 not found'], $response->getData());

	}//end testAnUnreadableApplicationIs404()

	/**
	 * A missing version is 404 naming the version.
	 *
	 * @return void
	 */
	public function testAMissingVersionIs404(): void {
		$this->rows(application: ['id' => 'app-1', 'permissions' => ['owners' => ['user:olga']]], version: null);
		$this->promotionService->expects($this->never())->method('promote');

		$response = $this->controller()->promote('app-1', 'v-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'ApplicationVersion v-1 not found'], $response->getData());

	}//end testAMissingVersionIs404()

	/**
	 * An unreadable version is logged at debug and answered 404.
	 *
	 * @return void
	 */
	public function testAnUnreadableVersionIs404(): void {
		$this->rows(application: ['id' => 'app-1'], version: 'throw');
		$this->logger->expects($this->once())->method('debug')
			->with('Buildiq: ApplicationVersion {uuid} could not be loaded: {message}', $this->anything());

		$response = $this->controller()->promote('app-1', 'v-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found', 'detail' => 'ApplicationVersion v-1 not found'], $response->getData());

	}//end testAnUnreadableVersionIs404()

	/**
	 * An application without a permissions block refuses everyone with 403.
	 *
	 * @return void
	 */
	public function testNonArrayPermissionsRefuseEveryone(): void {
		$this->rows(application: ['id' => 'app-1', 'permissions' => 'open'], version: ['id' => 'v-1', 'application' => 'app-1']);
		$this->promotionService->expects($this->never())->method('promote');

		$response = $this->controller()->promote('app-1', 'v-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Application has no permissions block; no caller may promote.', $response->getData()['detail']);

	}//end testNonArrayPermissionsRefuseEveryone()

	/**
	 * The posted strategy reaches the service with the source row.
	 *
	 * @return void
	 */
	public function testThePostedStrategyReachesTheService(): void {
		$source = ['id' => 'v-1', 'application' => 'app-1'];
		$this->rows(application: ['id' => 'app-1', 'permissions' => ['editors' => ['user:olga']]], version: $source);
		$this->request->method('getParam')->with('strategy', '')->willReturn('overwrite');
		$this->promotionService->expects($this->once())->method('promote')
			->with($source, 'overwrite')
			->willReturn(['id' => 'v-target']);

		$response = $this->controller()->promote('app-1', 'v-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'v-target'], $response->getData());

	}//end testThePostedStrategyReachesTheService()

	/**
	 * An unexpected failure is logged and answered internal_error (500).
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIs500(): void {
		$this->rows(application: ['id' => 'app-1', 'permissions' => ['owners' => ['user:olga']]], version: ['id' => 'v-1', 'application' => 'app-1']);
		$this->promotionService->method('promote')->willThrowException(new RuntimeException('disk full'));
		$this->logger->expects($this->once())->method('error')
			->with('Buildiq: VersionPromotionController::promote unexpected failure: disk full', $this->anything());

		$response = $this->controller()->promote('app-1', 'v-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal_error', 'detail' => 'disk full'], $response->getData());

	}//end testAnUnexpectedFailureIs500()
}//end class
