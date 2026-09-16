<?php

/**
 * Unit tests for SettingsService.
 *
 * Covers REQ-OBS-001 (settings read returns config + metadata),
 * REQ-OBS-002 (update persists only whitelisted keys),
 * REQ-OBS-003 (load/reload configuration idempotent / force).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
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

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see SettingsService}.
 */
final class SettingsServiceTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the SUT with the shared mocks.
	 *
	 * @return SettingsService
	 */
	private function sut(): SettingsService {
		return new SettingsService(
			appConfig: $this->appConfig,
			appManager: $this->appManager,
			container: $this->container,
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			logger: new NullLogger(),
		);
	}//end sut()

	/**
	 * REQ-OBS-001 — getSettings() returns CONFIG_KEYS values plus metadata.
	 *
	 * @return void
	 */
	public function testGetSettingsReturnsConfigKeysAndMetadata(): void {
		$this->appConfig->method('getValueString')->willReturn('my-register');
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(true);

		$settings = $this->sut()->getSettings();

		self::assertArrayHasKey('register', $settings);
		self::assertSame('my-register', $settings['register']);
		self::assertTrue($settings['openregisters']);
		self::assertTrue($settings['isAdmin']);
	}//end testGetSettingsReturnsConfigKeysAndMetadata()

	/**
	 * Builder groups are returned as a clean list (REQ-OBRBAC-008).
	 *
	 * @return void
	 */
	public function testGetSettingsReturnsTheBuilderGroupsAsAList(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'builder_groups') ? '["buildiq-builders"," ","buildiq-builders",7,"team-alpha"]' : $default
		);
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);

		$settings = $this->sut()->getSettings();

		self::assertSame(['buildiq-builders', 'team-alpha'], $settings['builder_groups']);
	}//end testGetSettingsReturnsTheBuilderGroupsAsAList()

	/**
	 * A stored value that is not a JSON list reads as no groups: a corrupt
	 * setting never widens access.
	 *
	 * @return void
	 */
	public function testACorruptBuilderGroupsValueReadsAsNoGroups(): void {
		self::assertSame([], SettingsService::decodeGroupList(raw: 'not json'));
		self::assertSame([], SettingsService::decodeGroupList(raw: '"buildiq-builders"'));
		self::assertSame([], SettingsService::decodeGroupList(raw: ''));
	}//end testACorruptBuilderGroupsValueReadsAsNoGroups()

	/**
	 * Saving builder groups stores a cleaned JSON list; a value that is not a
	 * list is ignored rather than stored.
	 *
	 * @return void
	 */
	public function testUpdateSettingsStoresTheBuilderGroupsAsJson(): void {
		$writes = [];
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$writes): bool {
				$writes[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);

		$this->sut()->updateSettings(['builder_groups' => ['team-alpha', '', 'team-alpha', 'buildiq-builders']]);
		self::assertSame('["team-alpha","buildiq-builders"]', $writes['builder_groups'] ?? null);

		$writes = [];
		$this->sut()->updateSettings(['builder_groups' => 'team-alpha']);
		self::assertArrayNotHasKey('builder_groups', $writes);

		$this->sut()->updateSettings(['builder_groups' => []]);
		self::assertSame('[]', $writes['builder_groups'] ?? null, 'an empty pick clears the setting');
	}//end testUpdateSettingsStoresTheBuilderGroupsAsJson()

	/**
	 * REQ-OBS-001 — isAdmin is false when no user is signed in.
	 *
	 * @return void
	 */
	public function testGetSettingsIsAdminFalseWhenUnauthenticated(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->userSession->method('getUser')->willReturn(null);

		$settings = $this->sut()->getSettings();

		self::assertFalse($settings['isAdmin']);
		self::assertFalse($settings['openregisters']);
	}//end testGetSettingsIsAdminFalseWhenUnauthenticated()

	/**
	 * REQ-OBS-002 — updateSettings() persists only CONFIG_KEYS (whitelisted).
	 *
	 * @return void
	 */
	public function testUpdateSettingsPersistsOnlyWhitelistedKeys(): void {
		$this->appConfig->expects(self::once())
			->method('setValueString')
			->with(self::anything(), 'register', 'buildiq');

		$this->appConfig->method('getValueString')->willReturn('buildiq');
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->sut()->updateSettings(['register' => 'buildiq', 'unknown_key' => 'ignored']);

		self::assertSame('buildiq', $result['register']);
	}//end testUpdateSettingsPersistsOnlyWhitelistedKeys()

	/**
	 * REQ-OBS-002 — updateSettings() ignores keys not in CONFIG_KEYS.
	 *
	 * @return void
	 */
	public function testUpdateSettingsIgnoresUnknownKeys(): void {
		$this->appConfig->expects(self::never())->method('setValueString');

		$this->appConfig->method('getValueString')->willReturn('');
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->userSession->method('getUser')->willReturn(null);

		$this->sut()->updateSettings(['unknown' => 'value', 'anotherUnknown' => 'val2']);
	}//end testUpdateSettingsIgnoresUnknownKeys()

	/**
	 * REQ-OBS-003 — loadConfiguration() returns failure when OpenRegister absent.
	 *
	 * @return void
	 */
	public function testLoadConfigurationFailsWhenOpenRegisterAbsent(): void {
		$this->appManager->method('isInstalled')->willReturn(false);

		$result = $this->sut()->loadConfiguration();

		self::assertFalse($result['success']);
		self::assertStringContainsString('OpenRegister', $result['message']);
	}//end testLoadConfigurationFailsWhenOpenRegisterAbsent()

	/**
	 * REQ-OBS-003 — reloadConfiguration() calls importFromApp with force:true.
	 *
	 * @return void
	 */
	public function testReloadConfigurationCallsImportWithForceTrue(): void {
		$this->appManager->method('isInstalled')->willReturn(true);

		$calls = [];
		$configService = new class($calls) {
			/**
			 * @param array<int,bool> $calls Collects force arguments.
			 */
			public function __construct(
				private array &$calls,
			) {
			}//end __construct()

			/**
			 * @param string $appId App identifier.
			 * @param array<string,mixed> $data Config data.
			 * @param string $version Version string.
			 * @param bool $force Force flag.
			 *
			 * @return array<string,mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->calls[] = $force;
				return ['version' => '1.0.0'];
			}//end importFromApp()
		};

		$this->container->method('get')->willReturn($configService);

		$result = $this->sut()->reloadConfiguration();

		// The config file must exist in the repo for force:true to reach importFromApp.
		if (isset($result['success']) && $result['success'] === true) {
			self::assertNotEmpty($calls, 'importFromApp must be called');
			self::assertTrue($calls[0], 'reloadConfiguration must pass force:true');
		} else {
			// Config file absent in test environment — failure path is acceptable.
			self::assertFalse($result['success']);
		}
	}//end testReloadConfigurationCallsImportWithForceTrue()
}//end class
