<?php

/**
 * Tests for AppDemoRecordsController.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AppDemoRecordsController;
use OCA\Buildiq\Service\AppDemoRecordsService;
use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
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
 * Only an owner or editor fills or removes an app's demo records.
 *
 * @covers \OCA\Buildiq\Controller\AppDemoRecordsController
 * @uses   \OCA\Buildiq\Service\AppOrganisationGuard
 * @uses   \OCA\Buildiq\Service\CallerContext
 */
final class AppDemoRecordsControllerTest extends TestCase {

	/**
	 * The Application's uuid.
	 *
	 * @var string
	 */
	private const APP = 'a1b2c3d4-0000-4000-8000-000000000001';

	/**
	 * The app `meldingen`: `sanne` owns it, `joris` edits it.
	 *
	 * @var array<string, mixed>
	 */
	private const APPLICATION = [
		'slug' => 'meldingen',
		'organisationScope' => 'holder',
		'productionVersion' => 'v-1',
		'permissions' => ['owners' => ['user:sanne'], 'editors' => ['user:joris']],
		'demoRecords' => [['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'old-1']],
	];

	/**
	 * The demo records service.
	 *
	 * @var AppDemoRecordsService&MockObject
	 */
	private AppDemoRecordsService&MockObject $service;

	/**
	 * Wire the service to answer `meldingen` with register `meldingen`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(originalClassName: AppDemoRecordsService::class);
		$this->service->method('application')->willReturnCallback(
			static function (string $uuid): ?array {
				if ($uuid !== self::APP) {
					return null;
				}

				return self::APPLICATION;
			}
		);
	}//end setUp()

	/**
	 * The controller, signed in as `$uid` (or nobody).
	 *
	 * @param string|null $uid     The caller.
	 * @param bool        $visible Whether the organisation guard lets the caller see the app.
	 *
	 * @return AppDemoRecordsController
	 */
	private function controller(?string $uid, bool $visible = true): AppDemoRecordsController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$permissions = $this->createMock(originalClassName: PermissionResolver::class);
		$permissions->method('resolveUserGroups')->willReturn([]);
		$permissions->method('matchesCaller')->willReturnCallback(
			static function (array $permissions, IUser $caller, array $userGroups, ?bool $allowAdminBypass, array $roles): bool {
				foreach ($roles as $role) {
					if (in_array('user:' . $caller->getUID(), (array)($permissions[$role] ?? []), true) === true) {
						return true;
					}
				}

				return false;
			}
		);
		$guard = $this->createMock(originalClassName: AppOrganisationGuard::class);
		$guard->method('canSee')->willReturn($visible);

		return new AppDemoRecordsController(
			request: $this->createMock(originalClassName: IRequest::class),
			demoRecords: $this->service,
			caller: new CallerContext(
				userSession: $session,
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
				permissionResolver: $permissions,
				organisationGuard: $guard
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end controller()

	/**
	 * An editor fills the app with demo records from its production register.
	 *
	 * @return void
	 */
	public function testAnEditorFillsTheApp(): void {
		$this->service->expects($this->once())->method('fill')
			->with(self::APP, self::APPLICATION)
			->willReturn(['created' => 3, 'skipped' => [], 'failed' => 0, 'total' => 4]);

		$response = $this->controller(uid: 'joris')->fill(appUuid: self::APP);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['created' => 3, 'skipped' => [], 'failed' => 0, 'total' => 4], actual: $response->getData());
	}//end testAnEditorFillsTheApp()

	/**
	 * An owner removes the demo records.
	 *
	 * @return void
	 */
	public function testAnOwnerRemovesTheDemoRecords(): void {
		$this->service->expects($this->once())->method('remove')->with(self::APP, self::APPLICATION)->willReturn(['removed' => 1, 'failed' => 0]);

		$response = $this->controller(uid: 'sanne')->remove(appUuid: self::APP);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['removed' => 1, 'failed' => 0], actual: $response->getData());
	}//end testAnOwnerRemovesTheDemoRecords()

	/**
	 * The refusals: nobody signed in, an unknown or unseen app, and a caller without a role.
	 *
	 * @return array<string, array{0: string|null, 1: string, 2: bool, 3: int}>
	 */
	public static function refusals(): array {
		return [
			'anonymous' => [null, self::APP, true, Http::STATUS_UNAUTHORIZED],
			'unknown app' => ['sanne', 'b1b2c3d4-0000-4000-8000-000000000009', true, Http::STATUS_NOT_FOUND],
			'another organisation' => ['sanne', self::APP, false, Http::STATUS_NOT_FOUND],
			'no role' => ['eva', self::APP, true, Http::STATUS_FORBIDDEN],
		];
	}//end refusals()

	/**
	 * A refused caller neither fills nor removes anything.
	 *
	 * @param string|null $uid     The caller.
	 * @param string      $appUuid The app asked for.
	 * @param bool        $visible Whether the organisation guard shows it.
	 * @param int         $status  The answer.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testARefusedCallerChangesNothing(?string $uid, string $appUuid, bool $visible, int $status): void {
		$this->service->expects($this->never())->method('fill');
		$this->service->expects($this->never())->method('remove');
		$controller = $this->controller(uid: $uid, visible: $visible);

		$this->assertSame(expected: $status, actual: $controller->fill(appUuid: $appUuid)->getStatus());
		$this->assertSame(expected: $status, actual: $controller->remove(appUuid: $appUuid)->getStatus());
	}//end testARefusedCallerChangesNothing()

	/**
	 * An app without a production register cannot be filled.
	 *
	 * @return void
	 */
	public function testAnAppWithoutARegisterIsAConflict(): void {
		$this->service->method('fill')->willReturn(null);

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $this->controller(uid: 'sanne')->fill(appUuid: self::APP)->getStatus());
	}//end testAnAppWithoutARegisterIsAConflict()

	/**
	 * A failing fill or removal answers 500 without its message.
	 *
	 * @return void
	 */
	public function testAFailureAnswersAnInternalError(): void {
		$this->service->method('fill')->willThrowException(new RuntimeException('secret detail'));
		$this->service->method('remove')->willThrowException(new RuntimeException('secret detail'));
		$controller = $this->controller(uid: 'sanne');

		foreach ([$controller->fill(appUuid: self::APP), $controller->remove(appUuid: self::APP)] as $response) {
			$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
			$this->assertSame(expected: ['error' => 'internal_error'], actual: $response->getData());
		}
	}//end testAFailureAnswersAnInternalError()
}//end class
