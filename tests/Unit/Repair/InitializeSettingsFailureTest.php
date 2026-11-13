<?php

/**
 * Failure-path tests for the InitializeSettings repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Repair\InitializeSettings
 */
class InitializeSettingsFailureTest extends TestCase {
	/**
	 * A throwing reload is reported to the output and logged, not rethrown.
	 *
	 * @return void
	 */
	public function testAThrowingReloadIsReportedAndLogged(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('reloadConfiguration')->willThrowException(new RuntimeException('import broke'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Buildiq initialization failed', ['exception' => 'import broke']);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Initializing Buildiq configuration...');
		$output->expects($this->once())->method('warning')->with('Could not auto-configure Buildiq: import broke');

		(new InitializeSettings($settings, $logger))->run($output);

	}//end testAThrowingReloadIsReportedAndLogged()

	/**
	 * A failed import without a message reports an unknown error.
	 *
	 * @return void
	 */
	public function testAFailedImportWithoutMessageReportsUnknownError(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('reloadConfiguration')->willReturn(['success' => false]);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Buildiq configuration import issue: unknown error');

		(new InitializeSettings($settings, $this->createMock(LoggerInterface::class)))->run($output);

	}//end testAFailedImportWithoutMessageReportsUnknownError()

	/**
	 * A successful import without a version reports it as unknown.
	 *
	 * @return void
	 */
	public function testASuccessfulImportWithoutVersionSaysUnknown(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('reloadConfiguration')->willReturn(['success' => true]);

		$said = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$said): void {
				$said[] = $message;
			}
		);
		$output->expects($this->never())->method('warning');

		(new InitializeSettings($settings, $this->createMock(LoggerInterface::class)))->run($output);

		$this->assertSame(
			['Initializing Buildiq configuration...', 'Buildiq configuration imported successfully (version: unknown)'],
			$said
		);

	}//end testASuccessfulImportWithoutVersionSaysUnknown()
}//end class
