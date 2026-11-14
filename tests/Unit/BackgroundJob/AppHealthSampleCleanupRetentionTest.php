<?php

/**
 * Unit tests for the retention rules of the app health sample cleanup: the
 * configured window clamped to 7..90 days, the exact query, the cutoff
 * boundary, rows without an id, and what a failing query or delete does.
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

use OCA\Buildiq\BackgroundJob\AppHealthSampleCleanup;
use OCA\Buildiq\Service\AppHealthSamples;
use OCA\Buildiq\Service\AppHealthService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Retention rules of AppHealthSampleCleanup.
 *
 * @covers \OCA\Buildiq\BackgroundJob\AppHealthSampleCleanup
 * @uses   \OCA\Buildiq\Service\AppHealthService
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class AppHealthSampleCleanupRetentionTest extends TestCase {

	/**
	 * The fixed "now" of every run: 2026-10-23T00:00:00Z.
	 *
	 * @var int
	 */
	private const NOW = 1792713600;

	/**
	 * The uuids deleteObject() was called with, in order.
	 *
	 * @var array<int,string>
	 */
	private array $deleted = [];

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The job over the given rows and configured retention.
	 *
	 * @param array<int,array<string,mixed>> $rows   The stored samples.
	 * @param int                            $days   The configured retention days.
	 * @param LoggerInterface|null           $logger The logger.
	 *
	 * @return AppHealthSampleCleanup The job.
	 */
	private function job(array $rows, int $days = 30, ?LoggerInterface $logger = null): AppHealthSampleCleanup {
		$this->deleted = [];
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('findAll')->willReturn($rows);
		$this->objects->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				if ($uuid === 'locked') {
					throw new RuntimeException('row locked');
				}

				$this->deleted[] = $uuid;
				return true;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->with('buildiq', 'health_retention_days', 30)->willReturn($days);

		return new AppHealthSampleCleanup(
			$this->createMock(ITimeFactory::class),
			$this->objects,
			new AppHealthService($this->objects, new AppHealthSamples(), new NullLogger()),
			$config,
			($logger ?? new NullLogger())
		);
	}//end job()

	/**
	 * A sample whose window starts exactly at the cutoff stays; one second earlier goes.
	 *
	 * @return void
	 */
	public function testTheCutoffItselfIsKept(): void {
		$count = $this->job(
			[
				['id' => 'at-cutoff', 'windowStart' => '2026-09-23T00:00:00+00:00'],
				['id' => 'before', 'windowStart' => '2026-09-22T23:59:59+00:00'],
			]
		)->purge(now: self::NOW);

		self::assertSame(['before'], $this->deleted);
		self::assertSame(1, $count);
	}//end testTheCutoffItselfIsKept()

	/**
	 * A retention below seven days is raised to seven.
	 *
	 * @return void
	 */
	public function testTheRetentionIsAtLeastSevenDays(): void {
		$this->job(
			[
				['id' => 'six-days', 'windowStart' => '2026-10-17T00:00:00'],
				['id' => 'eight-days', 'windowStart' => '2026-10-15T00:00:00'],
			],
			days: 1
		)->purge(now: self::NOW);

		self::assertSame(['eight-days'], $this->deleted);
	}//end testTheRetentionIsAtLeastSevenDays()

	/**
	 * A retention above ninety days is lowered to ninety.
	 *
	 * @return void
	 */
	public function testTheRetentionIsAtMostNinetyDays(): void {
		$this->job(
			[
				['id' => 'eighty-nine-days', 'windowStart' => '2026-07-26T00:00:00'],
				['id' => 'ninety-one-days', 'windowStart' => '2026-07-24T00:00:00'],
			],
			days: 365
		)->purge(now: self::NOW);

		self::assertSame(['ninety-one-days'], $this->deleted);
	}//end testTheRetentionIsAtMostNinetyDays()

	/**
	 * The sample query reads the health register and schema, a batch of 5000, outside RBAC and multitenancy.
	 *
	 * @return void
	 */
	public function testTheQueryReadsTheHealthSchemaInABatch(): void {
		$job = $this->job([]);
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'buildiq', 'schema' => 'app-health-sample'], 'limit' => 5000],
			false,
			false
		)->willReturn([]);

		self::assertSame(0, $job->purge(now: self::NOW));
	}//end testTheQueryReadsTheHealthSchemaInABatch()

	/**
	 * A row is deleted by its uuid when it has no id, and a row with neither is skipped.
	 *
	 * @return void
	 */
	public function testTheUuidStandsInForAMissingId(): void {
		$count = $this->job(
			[
				['uuid' => 'by-uuid', 'windowStart' => '2026-01-01T00:00:00'],
				['windowStart' => '2026-01-01T00:00:00'],
				['id' => 'no-window'],
			]
		)->purge(now: self::NOW);

		self::assertSame(['by-uuid', 'no-window'], $this->deleted);
		self::assertSame(2, $count);
	}//end testTheUuidStandsInForAMissingId()

	/**
	 * A failing query is logged as an error and deletes nothing.
	 *
	 * @return void
	 */
	public function testAFailingQueryDeletesNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Buildiq: app health cleanup query failed',
			['exception' => 'database gone']
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$objects->expects($this->never())->method('deleteObject');
		$job = new AppHealthSampleCleanup(
			$this->createMock(ITimeFactory::class),
			$objects,
			new AppHealthService($objects, new AppHealthSamples(), new NullLogger()),
			$this->createConfiguredMock(IAppConfig::class, ['getValueInt' => 30]),
			$logger
		);

		self::assertSame(0, $job->purge(now: self::NOW));
	}//end testAFailingQueryDeletesNothing()

	/**
	 * A sample that cannot be deleted is logged, not counted, and the rest still go.
	 *
	 * @return void
	 */
	public function testAFailingDeleteIsLoggedAndTheRestContinue(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Buildiq: an app health sample could not be deleted',
			['uuid' => 'locked', 'exception' => 'row locked']
		);

		$count = $this->job(
			[
				['id' => 'locked', 'windowStart' => '2026-01-01T00:00:00'],
				['id' => 'free', 'windowStart' => '2026-01-01T00:00:00'],
			],
			logger: $logger
		)->purge(now: self::NOW);

		self::assertSame(['free'], $this->deleted);
		self::assertSame(1, $count);
	}//end testAFailingDeleteIsLoggedAndTheRestContinue()
}//end class
