<?php

/**
 * Unit tests for InitializeSettings repair step.
 *
 * Covers REQ-OBS-004 (repair step bootstraps configuration on install/upgrade).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
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

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\InitializeSettings;
use OCA\Buildiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see InitializeSettings}.
 */
final class InitializeSettingsTest extends TestCase {

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->output = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * Build the SUT.
	 *
	 * @return InitializeSettings
	 */
	private function step(): InitializeSettings {
		return new InitializeSettings(
			settingsService: $this->settingsService,
			logger: new NullLogger(),
		);
	}//end step()

	/**
	 * REQ-OBS-004 — getName() returns a non-empty human-readable string.
	 *
	 * @return void
	 */
	public function testGetNameReturnsNonEmptyString(): void {
		self::assertNotEmpty($this->step()->getName());
	}//end testGetNameReturnsNonEmptyString()

	/**
	 * REQ-OBS-004 — run() skips import and emits warning when OpenRegister is absent.
	 *
	 * @return void
	 */
	public function testRunWarnsWhenOpenRegisterAbsent(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(false);
		$this->settingsService->expects(self::never())->method('reloadConfiguration');

		$this->output->expects(self::once())->method('warning');

		$this->step()->run($this->output);
	}//end testRunWarnsWhenOpenRegisterAbsent()

	/**
	 * REQ-OBS-004 — run() calls reloadConfiguration() and emits info on success.
	 *
	 * @return void
	 */
	public function testRunCallsReloadConfigurationOnSuccess(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->expects(self::once())
			->method('reloadConfiguration')
			->willReturn(['success' => true, 'version' => '1.0.0', 'message' => 'ok']);

		$this->output->expects(self::atLeastOnce())->method('info');

		$this->step()->run($this->output);
	}//end testRunCallsReloadConfigurationOnSuccess()

	/**
	 * REQ-OBS-004 — run() emits warning when import reports failure.
	 *
	 * @return void
	 */
	public function testRunWarnsWhenImportReportsFailure(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->method('reloadConfiguration')->willReturn(
			[
				'success' => false,
				'message' => 'Import returned an empty result.',
			]
		);

		$this->output->expects(self::once())->method('warning');

		$this->step()->run($this->output);
	}//end testRunWarnsWhenImportReportsFailure()
}//end class
