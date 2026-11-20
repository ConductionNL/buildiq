<?php

/**
 * "Allow script steps" in the admin settings, and its disable sweep.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\Automation\ScriptStepSweep;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The setting is off by default, is written as a boolean, and turning it off
 * (only then) runs the sweep.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */
final class SettingsServiceScriptStepTest extends TestCase {

	/**
	 * The service over an app config holding the setting, with a sweep expected the given number of times.
	 *
	 * @param bool        $stored  The stored setting.
	 * @param int         $sweeps  How often the sweep runs.
	 * @param array<bool> $written Receives the values written.
	 *
	 * @return SettingsService
	 */
	private function service(bool $stored, int $sweeps, array &$written): SettingsService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $default = false) use ($stored): bool {
				if ($key === ScriptStepSweep::SETTING) {
					return $stored;
				}

				return $default;
			}
		);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $value) use (&$written): bool {
				if ($key === ScriptStepSweep::SETTING) {
					$written[] = $value;
				}

				return true;
			}
		);
		$sweep = $this->createMock(originalClassName: ScriptStepSweep::class);
		$sweep->expects(self::exactly(count: $sweeps))->method('disableAll')->willReturn(['disabled' => 2, 'failed' => 0]);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($sweep);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new SettingsService(
			appConfig: $appConfig,
			appManager: $appManager,
			container: $container,
			caller: new CallerContext(
				userSession: $this->createMock(originalClassName: IUserSession::class),
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
				permissionResolver: $this->createMock(originalClassName: PermissionResolver::class)
			),
			logger: new NullLogger(),
		);
	}//end service()

	/**
	 * Turning the setting off disables the automations; the read shows it off.
	 *
	 * @return void
	 */
	public function testTurningScriptsOffRunsTheSweep(): void {
		$written  = [];
		$settings = $this->service(stored: true, sweeps: 1, written: $written)->updateSettings(data: [ScriptStepSweep::SETTING => false]);

		self::assertSame(expected: [false], actual: $written);
		self::assertArrayHasKey(key: ScriptStepSweep::SETTING, array: $settings);
	}//end testTurningScriptsOffRunsTheSweep()

	/**
	 * Turning it on, leaving it off, or a save without the key runs no sweep.
	 *
	 * @return void
	 */
	public function testOtherSavesRunNoSweep(): void {
		$written = [];
		$this->service(stored: false, sweeps: 0, written: $written)->updateSettings(data: [ScriptStepSweep::SETTING => 'true']);
		$this->service(stored: false, sweeps: 0, written: $written)->updateSettings(data: [ScriptStepSweep::SETTING => false]);
		$this->service(stored: true, sweeps: 0, written: $written)->updateSettings(data: ['registry_url' => 'https://example.org']);

		self::assertSame(expected: [true, false], actual: $written);
	}//end testOtherSavesRunNoSweep()

	/**
	 * The setting is off by default.
	 *
	 * @return void
	 */
	public function testTheSettingIsOffByDefault(): void {
		$written = [];
		$settings = $this->service(stored: false, sweeps: 0, written: $written)->getSettings();

		self::assertFalse(condition: $settings[ScriptStepSweep::SETTING]);
	}//end testTheSettingIsOffByDefault()
}//end class
