<?php

/**
 * Unit tests for what the rule execution log cleanup reads, skips and logs:
 * the exact query, rows without a timestamp or id, a failing query, a failing
 * delete, and the summary line.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\BackgroundJob;

use OCA\Buildiq\BackgroundJob\RuleExecutionLogCleanup;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Reading, skipping and logging of RuleExecutionLogCleanup.
 *
 * @covers \OCA\Buildiq\BackgroundJob\RuleExecutionLogCleanup
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class RuleExecutionLogCleanupLoggingTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Run the job once.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$job    = new RuleExecutionLogCleanup($this->createMock(ITimeFactory::class), $this->objects, $this->logger);
		$method = new ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * A timestamp well past the 90-day retention.
	 *
	 * @return string The timestamp.
	 */
	private function old(): string {
		return gmdate('Y-m-d\TH:i:s\Z', (time() - (120 * 86400)));
	}//end old()

	/**
	 * The query asks for the rule execution logs of the buildiq register.
	 *
	 * @return void
	 */
	public function testTheQueryReadsTheExecutionLogs(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'buildiq', 'schema' => 'rule-execution-log']]
		)->willReturn([]);
		$this->logger->expects($this->never())->method('info');

		$this->runJob();
	}//end testTheQueryReadsTheExecutionLogs()

	/**
	 * Rows without a timestamp or an id are skipped; the uuid stands in for a missing id.
	 *
	 * @return void
	 */
	public function testRowsWithoutTimestampOrIdAreSkipped(): void {
		$this->objects->method('findAll')->willReturn(
			[
				['id' => 'no-timestamp'],
				['timestamp' => $this->old()],
				['uuid' => 'by-uuid', 'timestamp' => $this->old()],
			]
		);
		$this->objects->expects($this->once())->method('deleteObject')->with('by-uuid')->willReturn(true);
		$this->logger->expects($this->once())->method('info')
			->with('Buildiq cleanup: purged 1 expired rule-execution log(s)');

		$this->runJob();
	}//end testRowsWithoutTimestampOrIdAreSkipped()

	/**
	 * A failing query is logged as an error and nothing is deleted.
	 *
	 * @return void
	 */
	public function testAFailingQueryIsLogged(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$this->objects->expects($this->never())->method('deleteObject');
		$this->logger->expects($this->once())->method('error')
			->with('Buildiq: RuleExecutionLog cleanup query failed', ['exception' => 'database gone']);

		$this->runJob();
	}//end testAFailingQueryIsLogged()

	/**
	 * A failing delete is logged as a warning, the rest still go, and only those are counted.
	 *
	 * @return void
	 */
	public function testAFailingDeleteIsLoggedAndNotCounted(): void {
		$this->objects->method('findAll')->willReturn(
			[
				['id' => 'locked', 'timestamp' => $this->old()],
				['id' => 'a', 'timestamp' => $this->old()],
				['id' => 'b', 'timestamp' => $this->old()],
			]
		);
		$this->objects->method('deleteObject')->willReturnCallback(
			static function (string $uuid): bool {
				if ($uuid === 'locked') {
					throw new RuntimeException('row locked');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('Buildiq: failed to purge RuleExecutionLog record', ['uuid' => 'locked', 'exception' => 'row locked']);
		$this->logger->expects($this->once())->method('info')
			->with('Buildiq cleanup: purged 2 expired rule-execution log(s)');

		$this->runJob();
	}//end testAFailingDeleteIsLoggedAndNotCounted()
}//end class
