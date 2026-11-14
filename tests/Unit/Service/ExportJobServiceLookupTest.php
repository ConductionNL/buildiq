<?php

/**
 * Unit tests for the job lookup and failure paths of ExportJobService.
 *
 * Covers loadJob (entity, raw array and every "no job" answer), the
 * deterministic download location, and the transition paths that must report
 * false instead of writing the status field directly: no engine, an engine
 * without transition(), and a transition that throws.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ExportJobService;
use OCA\Buildiq\Service\JobOwnerImpersonator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ExportJobService lookups and transition failures.
 */
class ExportJobServiceLookupTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock impersonator.
	 *
	 * @var JobOwnerImpersonator&MockObject
	 */
	private JobOwnerImpersonator&MockObject $impersonator;

	/**
	 * Wire the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->impersonator = $this->createMock(originalClassName: JobOwnerImpersonator::class);
	}//end setUp()

	/**
	 * Build the service over a container that knows (or does not know) one service.
	 *
	 * @param object|null $service What the container hands out; null means it has nothing.
	 *
	 * @return ExportJobService
	 */
	private function serviceWith(?object $service): ExportJobService {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturn($service !== null);
		$container->method('get')->willReturn($service);

		return new ExportJobService(
			container: $container,
			jobList: $this->createMock(originalClassName: IJobList::class),
			logger: $this->logger,
			jobOwnerImpersonator: $this->impersonator
		);
	}//end serviceWith()

	/**
	 * An object service double whose find() answers with the given value or throws it.
	 *
	 * @param mixed $answer What find() returns, or a Throwable to throw.
	 *
	 * @return object
	 */
	private static function finder(mixed $answer): object {
		return new class($answer) {
			/**
			 * Store the answer.
			 *
			 * @param mixed $answer The answer.
			 */
			public function __construct(private mixed $answer) {
			}

			/**
			 * Return or throw the answer.
			 *
			 * @param string $uuid The job uuid.
			 *
			 * @return mixed
			 */
			public function find(string $uuid): mixed {
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};
	}//end finder()

	/**
	 * A job entity is read back as its object data.
	 *
	 * @return void
	 */
	public function testLoadJobReadsTheEntityData(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['status' => 'done', 'target' => 'zip']);

		$job = $this->serviceWith(service: self::finder(answer: $entity))->loadJob(jobUuid: 'job-1');

		self::assertSame(expected: ['status' => 'done', 'target' => 'zip'], actual: $job);
	}//end testLoadJobReadsTheEntityData()

	/**
	 * An object service that returns a plain array is accepted as is.
	 *
	 * @return void
	 */
	public function testLoadJobAcceptsAPlainArray(): void {
		$job = $this->serviceWith(service: self::finder(answer: ['status' => 'queued']))->loadJob(jobUuid: 'job-1');

		self::assertSame(expected: ['status' => 'queued'], actual: $job);
	}//end testLoadJobAcceptsAPlainArray()

	/**
	 * No object service, no job, or an unexpected answer all read as "no job".
	 *
	 * @return void
	 */
	public function testLoadJobReadsAbsentAnswersAsNull(): void {
		self::assertNull(actual: $this->serviceWith(service: null)->loadJob(jobUuid: 'job-1'));
		self::assertNull(actual: $this->serviceWith(service: self::finder(answer: null))->loadJob(jobUuid: 'job-1'));
		self::assertNull(actual: $this->serviceWith(service: self::finder(answer: 'job-1'))->loadJob(jobUuid: 'job-1'));
		self::assertNull(actual: $this->serviceWith(service: new \stdClass())->loadJob(jobUuid: 'job-1'));
	}//end testLoadJobReadsAbsentAnswersAsNull()

	/**
	 * A failing lookup is logged and reads as "no job".
	 *
	 * @return void
	 */
	public function testLoadJobLogsAFailingLookup(): void {
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains(string: 'loadJob failed for job job-1: db down'));

		$service = $this->serviceWith(service: self::finder(answer: new RuntimeException('db down')));

		self::assertNull(actual: $service->loadJob(jobUuid: 'job-1'));
	}//end testLoadJobLogsAFailingLookup()

	/**
	 * The download is found only at its deterministic location.
	 *
	 * @return void
	 */
	public function testResolveDownloadFindsOnlyAnExistingZip(): void {
		$uuid = 'test-' . bin2hex(random_bytes(6));
		$dir = sys_get_temp_dir() . '/buildiq-exports';
		if (is_dir($dir) === false) {
			mkdir($dir, 0700, true);
		}

		$service = $this->serviceWith(service: null);
		self::assertNull(actual: $service->resolveDownload(uuid: $uuid));

		$path = $dir . '/' . $uuid . '.zip';
		file_put_contents($path, 'PK');
		try {
			self::assertSame(
				expected: ['path' => $path, 'expired' => false],
				actual: $service->resolveDownload(uuid: $uuid)
			);
		} finally {
			unlink($path);
		}
	}//end testResolveDownloadFindsOnlyAnExistingZip()

	/**
	 * Without a transition engine the status is never written and the gap is logged.
	 *
	 * @return void
	 */
	public function testTransitionWithoutAnEngineIsSkipped(): void {
		$this->impersonator->expects(self::never())->method('runAsOwner');
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains(string: 'lifecycle transition "start" SKIPPED on job job-1'));

		self::assertFalse(condition: $this->serviceWith(service: null)->transitionJob(jobUuid: 'job-1', action: 'start'));
	}//end testTransitionWithoutAnEngineIsSkipped()

	/**
	 * An engine without transition() is reported as API drift.
	 *
	 * @return void
	 */
	public function testTransitionWithAnIncompatibleEngineIsSkipped(): void {
		$this->impersonator->expects(self::never())->method('runAsOwner');
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains(string: 'likely API drift'));

		self::assertFalse(
			condition: $this->serviceWith(service: new \stdClass())->transitionJob(jobUuid: 'job-1', action: 'start')
		);
	}//end testTransitionWithAnIncompatibleEngineIsSkipped()

	/**
	 * A transition that throws is logged and reported as false.
	 *
	 * @return void
	 */
	public function testAFailingTransitionReportsFalse(): void {
		$engine = new class {
			/**
			 * Refuse the transition.
			 *
			 * @param string $uuid The job uuid.
			 * @param string $action The action.
			 *
			 * @return void
			 */
			public function transition(string $uuid, string $action): void {
				throw new RuntimeException('guard refused');
			}
		};
		$this->impersonator->method('runAsOwner')
			->willReturnCallback(static fn (string $objectId, callable $work) => $work());
		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains(string: 'lifecycle transition "fail" failed on job job-1: guard refused'));

		self::assertFalse(condition: $this->serviceWith(service: $engine)->transitionJob(jobUuid: 'job-1', action: 'fail'));
	}//end testAFailingTransitionReportsFalse()

	/**
	 * Merging no fields touches nothing.
	 *
	 * @return void
	 */
	public function testMergingNoFieldsIsANoOp(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$service = new ExportJobService(
			container: $container,
			jobList: $this->createMock(originalClassName: IJobList::class),
			logger: $this->logger,
			jobOwnerImpersonator: $this->impersonator
		);

		$service->mergeJobFields(jobUuid: 'job-1', fields: []);
	}//end testMergingNoFieldsIsANoOp()
}//end class
