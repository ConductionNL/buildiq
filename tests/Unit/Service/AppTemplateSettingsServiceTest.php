<?php

/**
 * Unit tests for the app template's SettingsService.
 *
 * The template is not on buildiq's autoloader (it is copied into a new app
 * under that app's namespace), so the subject and its Application are loaded
 * from their files.
 *
 * Covers the settings read and write under the template's app id, the admin
 * flag, and every outcome of importing the shipped register configuration.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

require_once __DIR__ . '/../../../lib/Resources/template/lib/AppInfo/Application.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Service/SettingsService.php';

use OCA\AppTemplate\AppInfo\Application;
use OCA\AppTemplate\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the template's SettingsService.
 */
#[CoversClass(SettingsService::class)]
#[UsesClass(Application::class)]
final class AppTemplateSettingsServiceTest extends TestCase {
	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock app manager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up mocks and the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig    = $this->createMock(IAppConfig::class);
		$this->appManager   = $this->createMock(IAppManager::class);
		$this->container    = $this->createMock(ContainerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession  = $this->createMock(IUserSession::class);
		$this->logger       = $this->createMock(LoggerInterface::class);

		$this->service = new SettingsService(
			appConfig: $this->appConfig,
			appManager: $this->appManager,
			container: $this->container,
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Log in a user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function loggedIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end loggedIn()

	/**
	 * A configuration service double whose importFromApp() records its call and answers $result.
	 *
	 * @param array<string,mixed>|RuntimeException $result What the import answers or throws.
	 * @param array<string,mixed>                  $calls  Filled with the named arguments of the call.
	 *
	 * @return object
	 */
	private static function configurationService(array|RuntimeException $result, array &$calls): object {
		return new class ($result, $calls) {
			/**
			 * Build the double.
			 *
			 * @param array<string,mixed>|RuntimeException $result What the import answers or throws.
			 * @param array<string,mixed>                  $calls  Filled with the call's arguments.
			 */
			public function __construct(
				private readonly array|RuntimeException $result,
				private array &$calls,
			) {
			}

			/**
			 * Record the import and answer.
			 *
			 * @param string              $appId   The app id.
			 * @param array<string,mixed> $data    The configuration.
			 * @param string              $version The version.
			 * @param bool                $force   Whether to force.
			 *
			 * @return array<string,mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->calls = ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force];
				if ($this->result instanceof RuntimeException) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}//end configurationService()

	/**
	 * OpenRegister counts as available exactly when it is installed.
	 *
	 * @return void
	 */
	public function testOpenRegisterAvailabilityFollowsTheAppManager(): void {
		$this->appManager->expects(self::once())->method('isInstalled')->with('openregister')->willReturn(true);

		self::assertTrue(condition: $this->service->isOpenRegisterAvailable());
	}//end testOpenRegisterAvailabilityFollowsTheAppManager()

	/**
	 * The settings read the register under the template's app id and flag an admin.
	 *
	 * @return void
	 */
	public function testSettingsForAnAdmin(): void {
		$this->appConfig->expects(self::once())
			->method('getValueString')
			->with('app-template', 'register', '')
			->willReturn('reg-1');
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->loggedIn(uid: 'admin');
		$this->groupManager->expects(self::once())->method('isAdmin')->with('admin')->willReturn(true);

		self::assertSame(
			expected: ['register' => 'reg-1', 'openregisters' => true, 'isAdmin' => true],
			actual: $this->service->getSettings()
		);
	}//end testSettingsForAnAdmin()

	/**
	 * Without a logged-in user the admin flag is false and the group manager is not asked.
	 *
	 * @return void
	 */
	public function testSettingsWithoutAUserAreNotAdmin(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->userSession->method('getUser')->willReturn(null);
		$this->groupManager->expects(self::never())->method('isAdmin');

		self::assertSame(
			expected: ['register' => '', 'openregisters' => false, 'isAdmin' => false],
			actual: $this->service->getSettings()
		);
	}//end testSettingsWithoutAUserAreNotAdmin()

	/**
	 * An update writes only the known keys, as strings, and answers the settings after it.
	 *
	 * @return void
	 */
	public function testUpdateWritesOnlyKnownKeys(): void {
		$this->appConfig->expects(self::once())
			->method('setValueString')
			->with('app-template', 'register', '42');
		$this->appConfig->method('getValueString')->willReturn('42');
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->loggedIn(uid: 'bob');
		$this->groupManager->method('isAdmin')->willReturn(false);

		$result = $this->service->updateSettings(data: ['register' => 42, 'unknown' => 'x']);

		self::assertSame(expected: ['register' => '42', 'openregisters' => true, 'isAdmin' => false], actual: $result);
	}//end testUpdateWritesOnlyKnownKeys()

	/**
	 * An update without known keys writes nothing.
	 *
	 * @return void
	 */
	public function testUpdateWithoutKnownKeysWritesNothing(): void {
		$this->appConfig->expects(self::never())->method('setValueString');
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appManager->method('isInstalled')->willReturn(false);

		$this->service->updateSettings(data: ['other' => 'x']);
	}//end testUpdateWithoutKnownKeysWritesNothing()

	/**
	 * Without OpenRegister the import is skipped with a warning.
	 *
	 * @return void
	 */
	public function testLoadConfigurationWithoutOpenRegister(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->container->expects(self::never())->method('get');
		$this->logger->expects(self::once())->method('warning');

		self::assertSame(
			expected: ['success' => false, 'message' => 'OpenRegister is not installed or enabled.'],
			actual: $this->service->loadConfiguration()
		);
	}//end testLoadConfigurationWithoutOpenRegister()

	/**
	 * The shipped register file is imported under the template's app id, at its own version,
	 * passing the force flag, and the imported version is answered.
	 *
	 * @return void
	 */
	public function testLoadConfigurationImportsTheShippedRegister(): void {
		$calls = [];
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->expects(self::once())
			->method('get')
			->with('OCA\OpenRegister\Service\ConfigurationService')
			->willReturn(self::configurationService(result: ['version' => '0.1.0'], calls: $calls));

		$result = $this->service->loadConfiguration(force: true);

		$shipped = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Resources/template/lib/Settings/app_template_register.json'), true);
		self::assertSame(expected: ['success' => true, 'message' => 'Configuration imported successfully.', 'version' => '0.1.0'], actual: $result);
		self::assertSame(expected: 'app-template', actual: $calls['appId']);
		self::assertSame(expected: $shipped, actual: $calls['data']);
		self::assertSame(expected: $shipped['info']['version'], actual: $calls['version']);
		self::assertTrue(condition: $calls['force']);
	}//end testLoadConfigurationImportsTheShippedRegister()

	/**
	 * An import result without a version answers "unknown".
	 *
	 * @return void
	 */
	public function testImportedVersionDefaultsToUnknown(): void {
		$calls = [];
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->method('get')->willReturn(self::configurationService(result: ['registers' => 1], calls: $calls));

		$result = $this->service->loadConfiguration();

		self::assertSame(expected: 'unknown', actual: $result['version']);
		self::assertFalse(condition: $calls['force']);
	}//end testImportedVersionDefaultsToUnknown()

	/**
	 * An empty import result is a failure.
	 *
	 * @return void
	 */
	public function testEmptyImportResultIsAFailure(): void {
		$calls = [];
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->method('get')->willReturn(self::configurationService(result: [], calls: $calls));

		self::assertSame(
			expected: ['success' => false, 'message' => 'Import returned an empty result.'],
			actual: $this->service->loadConfiguration()
		);
	}//end testEmptyImportResultIsAFailure()

	/**
	 * A failing import answers its message and is logged as an error.
	 *
	 * @return void
	 */
	public function testFailingImportAnswersItsMessage(): void {
		$calls = [];
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->method('get')->willReturn(self::configurationService(result: new RuntimeException(message: 'schema clash'), calls: $calls));
		$this->logger->expects(self::once())
			->method('error')
			->with('AppTemplate: configuration import failed', ['exception' => 'schema clash']);

		self::assertSame(
			expected: ['success' => false, 'message' => 'schema clash'],
			actual: $this->service->loadConfiguration()
		);
	}//end testFailingImportAnswersItsMessage()
}//end class
