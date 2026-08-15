<?php

/**
 * Unit tests for RuleExecutionLogCleanup.
 *
 * Covers REQ-BRE-013: records past the 90-day retention window are purged,
 * recent records are left untouched.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\BackgroundJob
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

namespace OCA\OpenBuild\Tests\Unit\BackgroundJob;

use OCA\OpenBuild\BackgroundJob\RuleExecutionLogCleanup;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for {@see RuleExecutionLogCleanup}.
 */
final class RuleExecutionLogCleanupTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The job under test.
	 *
	 * @var RuleExecutionLogCleanup
	 */
	private RuleExecutionLogCleanup $job;

	/**
	 * Wire mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->job = new RuleExecutionLogCleanup(
			$this->createMock(ITimeFactory::class),
			$this->objectService,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Invoke the protected run() method via reflection.
	 *
	 * @return void
	 */
	private function invokeRun(): void {
		$method = new ReflectionMethod($this->job, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);

	}//end invokeRun()

	/**
	 * Old records are purged; recent records are not.
	 *
	 * @return void
	 */
	public function testPurgesOnlyOldRecords(): void {
		$old = gmdate('Y-m-d\TH:i:s\Z', (time() - (200 * 86400)));
		$recent = gmdate('Y-m-d\TH:i:s\Z', (time() - (5 * 86400)));

		$this->objectService->method('findAll')->willReturn(
			[
				['id' => 'uuid-old', 'timestamp' => $old],
				['id' => 'uuid-recent', 'timestamp' => $recent],
			]
		);

		$deleted = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid) use (&$deleted): bool {
				$deleted[] = $uuid;
				return true;
			}
		);

		$this->invokeRun();

		$this->assertSame(['uuid-old'], $deleted);

	}//end testPurgesOnlyOldRecords()

	/**
	 * An empty result set deletes nothing.
	 *
	 * @return void
	 */
	public function testNoRecordsNoDeletes(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->expects($this->never())->method('deleteObject');
		$this->invokeRun();

	}//end testNoRecordsNoDeletes()
}//end class
