<?php

/**
 * Unit tests for the app template's InitializeSettings repair step.
 *
 * The subject is app-template scaffold under lib/Resources/template/, which is
 * not on buildiq's autoloader (it is copied into a new app under that app's
 * namespace), so it is loaded from its file.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
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

namespace OCA\Buildiq\Tests\Unit\Repair;

require_once __DIR__ . '/../../../lib/Resources/template/lib/AppInfo/Application.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Service/SettingsService.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Service/FlowSeedService.php';
require_once __DIR__ . '/../../../lib/Resources/template/lib/Repair/InitializeSettings.php';

use OCA\AppTemplate\Repair\InitializeSettings;
use OCA\AppTemplate\Service\FlowSeedService;
use OCA\AppTemplate\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the template's InitializeSettings repair step.
 *
 * Out of a Nextcloud container `\OCP\Server::get()` cannot resolve the flow
 * mapper, so every run here also exercises the seeding-failure branch, which
 * must warn and carry on to the settings import.
 */
#[CoversClass(InitializeSettings::class)]
final class AppTemplateInitializeSettingsTest extends TestCase {
	/**
	 * Mock settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Mock flow seeder.
	 *
	 * @var FlowSeedService&MockObject
	 */
	private FlowSeedService&MockObject $flowSeedService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Info lines written to the output.
	 *
	 * @var list<string>
	 */
	private array $infos = [];

	/**
	 * Warning lines written to the output.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Subject.
	 *
	 * @var InitializeSettings
	 */
	private InitializeSettings $step;

	/**
	 * Wire mocks and record what the step writes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->flowSeedService = $this->createMock(FlowSeedService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->output->method('info')->willReturnCallback(function (string $message): void {
			$this->infos[] = $message;
		});
		$this->output->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});
		$this->step = new InitializeSettings(
			settingsService: $this->settingsService,
			flowSeedService: $this->flowSeedService,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * The repair step names itself.
	 *
	 * @return void
	 */
	public function testGetName(): void {
		$this->assertSame(
			'Initialize AppTemplate register and schemas via ConfigurationService',
			$this->step->getName()
		);
	}//end testGetName()

	/**
	 * Without OpenRegister the import is skipped with a warning and a log line.
	 *
	 * @return void
	 */
	public function testSkipsImportWhenOpenRegisterIsUnavailable(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(false);
		$this->settingsService->expects($this->never())->method('loadConfiguration');
		$this->flowSeedService->expects($this->never())->method('seed');
		$logged = [];
		$this->logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);
		$this->logger->expects($this->never())->method('error');

		$this->step->run($this->output);

		$this->assertSame(['Initializing AppTemplate configuration...'], $this->infos);
		$this->assertCount(2, $this->warnings);
		$this->assertStringStartsWith('Could not seed shipped flows: ', $this->warnings[0]);
		$this->assertSame('OpenRegister is not installed or enabled. Skipping auto-configuration.', $this->warnings[1]);
		$this->assertStringStartsWith('AppTemplate: flow seeding failed: ', $logged[0]);
		$this->assertSame('AppTemplate: OpenRegister not available, skipping register initialization', $logged[1]);
	}//end testSkipsImportWhenOpenRegisterIsUnavailable()

	/**
	 * A successful import is reported with its version, forced.
	 *
	 * @return void
	 */
	public function testReportsSuccessfulImportWithVersion(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->expects($this->once())
			->method('loadConfiguration')
			->with(true)
			->willReturn(['success' => true, 'version' => '1.4.0']);

		$this->step->run($this->output);

		$this->assertSame(
			['Initializing AppTemplate configuration...', 'AppTemplate configuration imported successfully (version: 1.4.0)'],
			$this->infos
		);
		$this->assertCount(1, $this->warnings);
	}//end testReportsSuccessfulImportWithVersion()

	/**
	 * A successful import without a version reports "unknown".
	 *
	 * @return void
	 */
	public function testReportsUnknownVersionWhenMissing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->method('loadConfiguration')->willReturn(['success' => true]);

		$this->step->run($this->output);

		$this->assertSame('AppTemplate configuration imported successfully (version: unknown)', $this->infos[1]);
	}//end testReportsUnknownVersionWhenMissing()

	/**
	 * A failed import is a warning carrying its message.
	 *
	 * @return void
	 */
	public function testWarnsWithMessageWhenImportFails(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->method('loadConfiguration')->willReturn(['success' => false, 'message' => 'schema clash']);
		$this->logger->expects($this->never())->method('error');

		$this->step->run($this->output);

		$this->assertSame(['Initializing AppTemplate configuration...'], $this->infos);
		$this->assertSame('AppTemplate configuration import issue: schema clash', $this->warnings[1]);
	}//end testWarnsWithMessageWhenImportFails()

	/**
	 * A failed import without a message reports "unknown error".
	 *
	 * @return void
	 */
	public function testWarnsUnknownErrorWhenMessageMissing(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->method('loadConfiguration')->willReturn(['success' => false]);

		$this->step->run($this->output);

		$this->assertSame('AppTemplate configuration import issue: unknown error', $this->warnings[1]);
	}//end testWarnsUnknownErrorWhenMessageMissing()

	/**
	 * A throwing import is caught, warned about and logged as an error.
	 *
	 * @return void
	 */
	public function testCatchesImportException(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->method('loadConfiguration')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())
			->method('error')
			->with('AppTemplate initialization failed', ['exception' => 'boom']);

		$this->step->run($this->output);

		$this->assertSame('Could not auto-configure AppTemplate: boom', $this->warnings[1]);
	}//end testCatchesImportException()

	/**
	 * A seeding failure is logged as a warning and does not stop the import.
	 *
	 * @return void
	 */
	public function testSeedingFailureIsLoggedAndImportStillRuns(): void {
		$this->settingsService->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settingsService->expects($this->once())->method('loadConfiguration')->willReturn(['success' => true]);
		$this->logger->expects($this->once())->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->assertStringStartsWith('AppTemplate: flow seeding failed: ', $message);
			}
		);

		$this->step->run($this->output);

		$this->assertCount(1, $this->warnings);
		$this->assertStringStartsWith('Could not seed shipped flows: ', $this->warnings[0]);
	}//end testSeedingFailureIsLoggedAndImportStillRuns()
}//end class
