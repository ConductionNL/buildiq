<?php

/**
 * Unit tests for the "Allow code on pages" setting in SettingsService.
 *
 * Covers pages-custom-code T01 (REQ-BQCC-005): the instance setting is off by
 * default, reads as a boolean and is written as a boolean.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PageCodeGuard;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the page-code setting in {@see SettingsService}.
 */
final class SettingsServicePageCodeTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up the app config mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
	}//end setUp()

	/**
	 * Build the SUT.
	 *
	 * @return SettingsService
	 */
	private function sut(): SettingsService {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		return new SettingsService(
			appConfig: $this->appConfig,
			appManager: $appManager,
			container: $this->createMock(ContainerInterface::class),
			caller: new CallerContext(
				userSession: $userSession,
				groupManager: $this->createMock(IGroupManager::class),
				permissionResolver: $this->createMock(PermissionResolver::class)
			),
			logger: new NullLogger(),
		);
	}//end sut()

	/**
	 * The setting is read with a false default, as saveManifest() reads it,
	 * and handed to the admin form as a boolean.
	 *
	 * @return void
	 */
	public function testGetSettingsReadsTheSettingOffByDefault(): void {
		$this->appConfig->expects(self::once())
			->method('getValueBool')
			->with('buildiq', PageCodeGuard::SETTING, false)
			->willReturn(true);

		$settings = $this->sut()->getSettings();

		self::assertTrue($settings[PageCodeGuard::SETTING]);
	}//end testGetSettingsReadsTheSettingOffByDefault()

	/**
	 * Submitted values that mean on and off.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function submittedValues(): array {
		return [
			'json true'     => [true, true],
			'json false'    => [false, false],
			'string true'   => ['true', true],
			'string 1'      => ['1', true],
			'checkbox on'   => [' ON ', true],
			'string false'  => ['false', false],
			'string 0'      => ['0', false],
			'null'          => [null, false],
			'an array'      => [['true'], false],
		];
	}//end submittedValues()

	/**
	 * A submitted toggle is written as a boolean, and only the toggle is
	 * written when nothing else is submitted.
	 *
	 * @param mixed $submitted The submitted value.
	 * @param bool  $expected  The boolean written.
	 *
	 * @return void
	 */
	#[DataProvider('submittedValues')]
	public function testUpdateSettingsWritesTheSettingAsABoolean(mixed $submitted, bool $expected): void {
		$this->appConfig->expects(self::once())
			->method('setValueBool')
			->with('buildiq', PageCodeGuard::SETTING, $expected);
		$this->appConfig->expects(self::never())->method('setValueString');

		$this->sut()->updateSettings([PageCodeGuard::SETTING => $submitted]);
	}//end testUpdateSettingsWritesTheSettingAsABoolean()

	/**
	 * A save without the key leaves the setting as it is.
	 *
	 * @return void
	 */
	public function testUpdateSettingsWithoutTheKeyLeavesTheSettingAlone(): void {
		$this->appConfig->expects(self::never())->method('setValueBool');

		$this->sut()->updateSettings(['register' => 'buildiq']);
	}//end testUpdateSettingsWithoutTheKeyLeavesTheSettingAlone()
}//end class
