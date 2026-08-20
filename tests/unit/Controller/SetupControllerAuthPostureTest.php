<?php

/**
 * Auth-posture tests for SetupController.
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

use OCA\OpenBuild\Controller\SetupController;
use OCA\OpenBuild\Service\SettingsService;
use OCA\OpenBuild\Service\TemplateSeedService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The setup wizard writes app config (`registry_url`, the write-only
 * `registry_token`) and seeds OpenRegister ApplicationTemplate objects. It is
 * admin-only, and it has to say so at BOTH layers:
 *
 *  - the attribute, so NC's SecurityMiddleware refuses before dispatch, and
 *  - `requireAdmin()` in the body, as defence in depth.
 *
 * These methods previously carried `#[NoAdminRequired]`, which disables the
 * FIRST layer — leaving the body check as the only thing in the way. Hydra
 * gate-9 (semantic-auth) flags exactly that contradiction.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * No `@spec` anchor: the behaviour this asserts ("Admin-only idempotent
 * seed-templates action" / "Non-admin is rejected") lives only in the
 * `openbuild-first-time-setup` CHANGE delta, and an anchor into
 * `openspec/changes/**` dangles as soon as that change is archived. Promoting
 * it to `openspec/specs/` is the right fix and is filed separately — it pulls
 * seven scenarios under gate-19, which needs real e2e coverage rather than a
 * `@e2e exclude`.
 */
class SetupControllerAuthPostureTest extends TestCase {

	/**
	 * Every routed setup method declares the admin posture in its attributes,
	 * and none of them re-opens the middleware with `#[NoAdminRequired]`.
	 *
	 * @return void
	 */
	public function testEveryRoutedSetupMethodIsAdminOnlyAtTheMiddlewareLayer(): void {
		foreach (['status', 'saveConfig', 'runAction'] as $method) {
			$reflected = new ReflectionMethod(SetupController::class, $method);

			$this->assertCount(
				0,
				$reflected->getAttributes(NoAdminRequired::class),
				$method . '() must not carry #[NoAdminRequired]: it disables the middleware admin check '
				. 'on an endpoint that writes app config and seeds templates.'
			);

			$this->assertCount(
				1,
				$reflected->getAttributes(AuthorizedAdminSetting::class),
				$method . '() must declare #[AuthorizedAdminSetting] so the middleware enforces admin before dispatch.'
			);
		}

	}//end testEveryRoutedSetupMethodIsAdminOnlyAtTheMiddlewareLayer()

	/**
	 * The body gate holds independently of the attribute: a non-admin is
	 * refused 403 and NOTHING is seeded or written.
	 *
	 * @return void
	 */
	public function testNonAdminIsRefusedAndNothingIsSeededOrWritten(): void {
		$seedService = $this->createMock(TemplateSeedService::class);
		$seedService->expects($this->never())->method('seed');

		$settings = $this->createMock(SettingsService::class);
		$settings->expects($this->never())->method('updateSettings');

		$controller = $this->controller(
			seedService: $seedService,
			settings: $settings,
			isAdmin: false
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->status()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->saveConfig()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->runAction('seed-templates')->getStatus());

	}//end testNonAdminIsRefusedAndNothingIsSeededOrWritten()

	/**
	 * With no session at all the answer is 401, and again nothing runs.
	 *
	 * @return void
	 */
	public function testNoSessionIsRefusedAndNothingIsSeeded(): void {
		$seedService = $this->createMock(TemplateSeedService::class);
		$seedService->expects($this->never())->method('seed');

		$controller = $this->controller(
			seedService: $seedService,
			settings: $this->createMock(SettingsService::class),
			user: null
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->runAction('seed-templates')->getStatus());

	}//end testNoSessionIsRefusedAndNothingIsSeeded()

	/**
	 * Build a SetupController over doubles.
	 *
	 * @param TemplateSeedService $seedService The seeding service double.
	 * @param SettingsService $settings The settings service double.
	 * @param bool $isAdmin Whether the acting user is an admin.
	 * @param string|null $user UID of the acting user, null for no session.
	 *
	 * @return SetupController The controller under test.
	 */
	private function controller(
		TemplateSeedService $seedService,
		SettingsService $settings,
		bool $isAdmin = false,
		?string $user = 'bob',
	): SetupController {
		$userSession = $this->createMock(IUserSession::class);
		if ($user === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$actingUser = $this->createMock(IUser::class);
			$actingUser->method('getUID')->willReturn($user);
			$userSession->method('getUser')->willReturn($actingUser);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new SetupController(
			$this->createMock(IRequest::class),
			new NullLogger(),
			$this->createMock(IAppConfig::class),
			$userSession,
			$groupManager,
			$settings,
			$seedService
		);

	}//end controller()

}//end class
