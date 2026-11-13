<?php

/**
 * Unit tests for the repair step that flags the registry token as sensitive.
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

use OCA\Buildiq\Repair\FlagRegistryTokenSensitive;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for FlagRegistryTokenSensitive.
 *
 * @covers \OCA\Buildiq\Repair\FlagRegistryTokenSensitive
 */
final class FlagRegistryTokenSensitiveTest extends TestCase {

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

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
	 * Step under test.
	 *
	 * @var FlagRegistryTokenSensitive
	 */
	private FlagRegistryTokenSensitive $step;

	/**
	 * Wire mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);
		$this->step = new FlagRegistryTokenSensitive(appConfig: $this->appConfig, logger: $this->logger);
	}//end setUp()

	/**
	 * Without a stored token nothing is flagged.
	 *
	 * @return void
	 */
	public function testWithoutATokenNothingIsFlagged(): void {
		$this->appConfig->expects($this->once())->method('getValueString')
			->with('buildiq', 'registry_token', '')
			->willReturn('');
		$this->appConfig->expects($this->never())->method('updateSensitive');
		$this->output->expects($this->once())->method('info')->with('No Buildiq registry token configured; nothing to flag.');

		$this->step->run(output: $this->output);
	}//end testWithoutATokenNothingIsFlagged()

	/**
	 * A stored token is flagged sensitive in place.
	 *
	 * @return void
	 */
	public function testAStoredTokenIsFlaggedSensitive(): void {
		$this->appConfig->method('getValueString')->willReturn('stored-value');
		$this->appConfig->expects($this->once())->method('updateSensitive')
			->with('buildiq', 'registry_token', true)
			->willReturn(true);
		$this->output->expects($this->once())->method('info')->with('Buildiq registry token flagged as sensitive.');
		$this->output->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('warning');

		$this->step->run(output: $this->output);
	}//end testAStoredTokenIsFlaggedSensitive()

	/**
	 * A failing flag is reported and logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsReportedNotThrown(): void {
		$this->appConfig->method('getValueString')->willReturn('stored-value');
		$this->appConfig->method('updateSensitive')->willThrowException(new RuntimeException('locked'));
		$this->output->expects($this->never())->method('info');
		$this->output->expects($this->once())->method('warning')->with('Could not flag the Buildiq registry token: locked');
		$this->logger->expects($this->once())->method('warning')
			->with('Buildiq: could not flag registry_token as sensitive', ['exception' => 'locked']);

		$this->step->run(output: $this->output);
	}//end testAFailureIsReportedNotThrown()
}//end class
