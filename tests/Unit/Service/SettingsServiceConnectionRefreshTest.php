<?php

/**
 * SettingsService connection-refresh tests.
 *
 * `updateSettings()` is the one writer of the template store keys: the admin
 * form and the setup wizard both reach it. If it stops handing the written
 * keys over, integriq keeps an old store status after an admin fixed the URL.
 * These tests assert the keys it hands over are exactly the ones it wrote.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-002-a-store-settings-save-asks-integriq-to-look-again
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Buildiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for the refresh SettingsService asks for after a save.
 *
 * @covers \OCA\Buildiq\Service\SettingsService
 */
class SettingsServiceConnectionRefreshTest extends TestCase {

	/**
	 * Every key list handed to the reporter.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $refreshes = [];

	/**
	 * The service, with or without a recording reporter.
	 *
	 * @param bool $withReporter Whether to wire the reporter.
	 *
	 * @return SettingsService
	 */
	private function service(bool $withReporter = true): SettingsService {
		$reporter = null;
		if ($withReporter === true) {
			/** @var ConnectionReporter&MockObject $reporter */
			$reporter = $this->getMockBuilder(className: ConnectionReporter::class)
				->disableOriginalConstructor()
				->onlyMethods(['refreshFromSave'])
				->getMock();
			$reporter->method('refreshFromSave')->willReturnCallback(
				function (array $savedKeys): array {
					$this->refreshes[] = $savedKeys;
					return [];
				}
			);
		}

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new SettingsService(
			appConfig: $appConfig,
			appManager: $appManager,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: new NullLogger(),
			connectionReporter: $reporter,
		);
	}//end service()

	/**
	 * A save hands over the keys it wrote, the token included.
	 *
	 * @return void
	 */
	public function testASaveHandsOverTheKeysItWrote(): void {
		$this->service()->updateSettings(
			[
				'registry_url'   => 'https://store.example.nl',
				'registry_token' => 's3cret',
				'not_a_key'      => 'ignored',
			]
		);

		$this->assertSame(expected: [['registry_url', 'registry_token']], actual: $this->refreshes);
	}//end testASaveHandsOverTheKeysItWrote()

	/**
	 * An empty token is not written, so it is not handed over either.
	 *
	 * The form sends an empty token to mean "keep the current one".
	 *
	 * @return void
	 */
	public function testAnEmptyTokenIsNotHandedOver(): void {
		$this->service()->updateSettings(['register' => 'buildiq', 'registry_token' => '']);

		$this->assertSame(expected: [['register']], actual: $this->refreshes);
	}//end testAnEmptyTokenIsNotHandedOver()

	/**
	 * The result is the same with and without the reporter.
	 *
	 * @return void
	 */
	public function testTheResultIsTheSameWithAndWithoutTheReporter(): void {
		$data = ['registry_url' => 'https://store.example.nl'];

		$this->assertSame(
			expected: $this->service(withReporter: false)->updateSettings($data),
			actual: $this->service()->updateSettings($data)
		);
	}//end testTheResultIsTheSameWithAndWithoutTheReporter()

	/**
	 * The container factory hands the reporter in.
	 *
	 * `Application::register()` builds SettingsService by hand, and the
	 * reporter argument is optional. A factory that leaves it out makes every
	 * save a silent no-op in production while every test above stays green.
	 *
	 * @return void
	 */
	public function testTheContainerFactoryHandsTheReporterIn(): void {
		$application = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');

		$factory = substr($application, (int) strpos($application, 'static fn ($c): SettingsService => new SettingsService('), 900);
		$this->assertStringContainsString(needle: 'connectionReporter: $c->get(ConnectionReporter::class)', haystack: $factory);
	}//end testTheContainerFactoryHandsTheReporterIn()
}//end class
