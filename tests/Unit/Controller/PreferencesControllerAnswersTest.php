<?php

/**
 * Golden tests for the exact answers of PreferencesController.
 *
 * Pins, for both endpoints, the refusal bodies (anonymous caller, unsafe key)
 * and the namespaced, sanitised config key a preference is stored under, so the
 * shared admission cannot change what a caller sees or which value is touched.
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

use OCA\Buildiq\Controller\PreferencesController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins the preference endpoints' answers.
 */
class PreferencesControllerAnswersTest extends TestCase {
	/**
	 * Config double.
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig&MockObject $config;

	/**
	 * Set up the config double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
	}//end setUp()

	/**
	 * Both endpoints, called with the given key.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		return ['get' => ['getPreference'], 'set' => ['setPreference']];
	}//end endpoints()

	/**
	 * An anonymous caller is told so, and no value is touched.
	 *
	 * @param string $endpoint The controller method.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testAnonymousCallerIsRefused(string $endpoint): void {
		$this->config->expects(self::never())->method(self::anything());

		$response = $this->controller(uid: null)->$endpoint('theme');

		self::assertSame([401, ['message' => 'Not logged in']], $this->outcome(response: $response));
	}//end testAnonymousCallerIsRefused()

	/**
	 * A key with nothing safe in it is refused, and no value is touched.
	 *
	 * @param string $endpoint The controller method.
	 *
	 * @return void
	 */
	#[DataProvider('endpoints')]
	public function testUnsafeKeyIsRefused(string $endpoint): void {
		$this->config->expects(self::never())->method(self::anything());

		$response = $this->controller(uid: 'ann')->$endpoint('!!/..');

		self::assertSame([400, ['message' => 'Invalid key']], $this->outcome(response: $response));
	}//end testUnsafeKeyIsRefused()

	/**
	 * A key is lowercased, stripped to its safe characters and namespaced, for reads and writes.
	 *
	 * @return void
	 */
	public function testKeysAreSanitisedAndNamespaced(): void {
		$this->config->expects(self::once())->method('getUserValue')->with('ann', 'buildiq', 'pref_mytheme', '')->willReturn('dark');
		$this->config->expects(self::once())->method('setUserValue')->with('ann', 'buildiq', 'pref_mytheme', 'light');
		$this->config->expects(self::once())->method('deleteUserValue')->with('ann', 'buildiq', 'pref_mytheme');
		$controller = $this->controller(uid: 'ann');

		self::assertSame([200, ['value' => 'dark']], $this->outcome(response: $controller->getPreference(key: 'My Theme!')));
		self::assertSame([200, ['value' => 'light']], $this->outcome(response: $controller->setPreference(key: 'My Theme!', value: 'light')));
		self::assertSame([200, ['value' => null]], $this->outcome(response: $controller->setPreference(key: 'My Theme!')));
	}//end testKeysAreSanitisedAndNamespaced()

	/**
	 * Reduce a response to status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0: int, 1: mixed}
	 */
	private function outcome(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end outcome()

	/**
	 * The controller, with the given user signed in (or nobody).
	 *
	 * @param string|null $uid The signed-in user, or null.
	 *
	 * @return PreferencesController
	 */
	private function controller(?string $uid): PreferencesController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return new PreferencesController(request: $this->createMock(IRequest::class), config: $this->config, userSession: $session);
	}//end controller()
}//end class
