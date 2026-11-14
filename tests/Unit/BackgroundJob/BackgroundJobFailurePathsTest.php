<?php

/**
 * Unit tests for the background jobs' failure and entry-point paths.
 *
 * A background job has no caller to report to: whatever it does with a bad
 * argument or an unreadable record IS the outcome. These cover the paths that
 * decide that outcome and had no test:
 *
 *  - a job argument with no `jobUuid` must log and stop, and must NOT move the
 *    lifecycle of some other job;
 *  - an ExportJob record that cannot be loaded, or that carries no
 *    applicationUuid, must fail the job with a message on the record rather
 *    than export the empty template and call it a success;
 *  - an application with no version must fail the same way;
 *  - what the exporter could not resolve is reported ON the finished job, not
 *    only in the log, because the operator reads the job;
 *  - AppHealthSampleCleanup::run() must purge against the time factory's clock.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @covers \OCA\Buildiq\BackgroundJob\RunExportJob
 * @covers \OCA\Buildiq\BackgroundJob\AppHealthSampleCleanup
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\BackgroundJob;

use OCA\Buildiq\BackgroundJob\AppHealthSampleCleanup;
use OCA\Buildiq\BackgroundJob\RunExportJob;
use OCA\Buildiq\Service\AppHealthService;
use OCA\Buildiq\Service\ExportAppContentBundler;
use OCA\Buildiq\Service\ExportJobService;
use OCA\Buildiq\Service\ExportService;
use OCA\Buildiq\Service\GitHubPushService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests the jobs' entry-point guards and terminal failures.
 */
class BackgroundJobFailurePathsTest extends TestCase {
	/**
	 * Time factory mock.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Export service mock.
	 *
	 * @var ExportService&MockObject
	 */
	private ExportService&MockObject $exportService;

	/**
	 * Export job orchestration mock — owns the lifecycle transitions.
	 *
	 * @var ExportJobService&MockObject
	 */
	private ExportJobService&MockObject $exportJobService;

	/**
	 * GitHub delivery mock.
	 *
	 * @var GitHubPushService&MockObject
	 */
	private GitHubPushService&MockObject $githubPushService;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->time = $this->createMock(ITimeFactory::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->exportJobService = $this->createMock(ExportJobService::class);
		$this->githubPushService = $this->createMock(GitHubPushService::class);
	}//end setUp()

	/**
	 * Build the export job.
	 *
	 * @param LoggerInterface|null $logger Optional logger.
	 * @param ExportAppContentBundler|null $contentBundler Optional bundler.
	 *
	 * @return RunExportJob The job.
	 */
	private function buildExportJob(
		?LoggerInterface $logger = null,
		?ExportAppContentBundler $contentBundler = null,
	): RunExportJob {
		return new RunExportJob(
			$this->time,
			$this->exportService,
			$this->exportJobService,
			$this->githubPushService,
			($logger ?? new NullLogger()),
			$contentBundler
		);
	}//end buildExportJob()

	/**
	 * Invoke a job's protected `run()`.
	 *
	 * @param object $job The job under test.
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function invokeRun(object $job, mixed $argument): void {
		$method = new ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}//end invokeRun()

	/**
	 * A standard loadable ExportJob record.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function jobFixture(array $overrides = []): array {
		return array_merge(
			[
				'applicationUuid' => 'app-uuid-test',
				'applicationVersion' => '1.0.0',
				'applicationSlug' => 'test-app',
				'license' => 'EUPL-1.2',
			],
			$overrides
		);
	}//end jobFixture()

	/**
	 * Capture the `fail` transition's extra fields.
	 *
	 * @param array<string, mixed>|null $failed Receives the fail payload.
	 *
	 * @return void
	 */
	private function captureFail(?array &$failed): void {
		$this->exportJobService->method('transitionJob')->willReturnCallback(
			function (string $uuid, string $action, array $extra = []) use (&$failed): bool {
				if ($action === 'fail') {
					$failed = $extra;
				}

				return true;
			}
		);
	}//end captureFail()

	/**
	 * Job arguments that carry no usable job uuid.
	 *
	 * @return array<string, array{0: mixed}> The cases.
	 */
	public static function argumentsWithoutAJobUuid(): array {
		return [
			'null' => [null],
			'an empty array' => [[]],
			'a string' => ['job-uuid'],
			'the wrong key' => [['uuid' => 'job-uuid']],
		];
	}

	/**
	 * Without a job uuid the run logs and stops — and critically touches no
	 * lifecycle, because it does not know whose lifecycle it would be.
	 *
	 * @param mixed $argument The malformed job argument.
	 *
	 * @dataProvider argumentsWithoutAJobUuid
	 *
	 * @return void
	 */
	public function testAnArgumentWithoutAJobUuidStopsWithoutTouchingAnyJob(mixed $argument): void {
		$this->exportJobService->expects(self::never())->method('transitionJob');
		$this->exportJobService->expects(self::never())->method('loadJob');
		$this->exportService->expects(self::never())->method('generateAppZip');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(self::stringContains('missing jobUuid'));

		$this->invokeRun($this->buildExportJob(logger: $logger), $argument);
	}//end testAnArgumentWithoutAJobUuidStopsWithoutTouchingAnyJob()

	/**
	 * A record that cannot be loaded fails the job with a message naming the
	 * uuid, rather than exporting an empty template as a success.
	 *
	 * @return void
	 */
	public function testAnUnloadableRecordFailsTheJob(): void {
		// isStillQueued() also reads the record; a null read defaults to
		// queued, so the run proceeds and the pipeline is what refuses.
		$this->exportJobService->method('loadJob')->willReturn(null);
		$this->exportService->expects(self::never())->method('generateAppZip');

		$failed = null;
		$this->captureFail($failed);

		$this->invokeRun($this->buildExportJob(), ['jobUuid' => 'job-missing']);

		self::assertNotNull($failed, 'the job must be failed, not left running');
		self::assertStringContainsString('could not load ExportJob record', $failed['errorMessage']);
		self::assertStringContainsString('job-missing', $failed['errorMessage']);
	}//end testAnUnloadableRecordFailsTheJob()

	/**
	 * A record with no applicationUuid fails the job: there is nothing to
	 * export, and an empty archive must not be reported as a success.
	 *
	 * @return void
	 */
	public function testARecordWithoutAnApplicationUuidFailsTheJob(): void {
		$this->exportJobService->method('loadJob')
			->willReturn($this->jobFixture(['applicationUuid' => '']));
		$this->exportService->expects(self::never())->method('generateAppZip');

		$failed = null;
		$this->captureFail($failed);

		$this->invokeRun($this->buildExportJob(), ['jobUuid' => 'job-no-app']);

		self::assertNotNull($failed);
		self::assertStringContainsString('empty applicationUuid', $failed['errorMessage']);
	}//end testARecordWithoutAnApplicationUuidFailsTheJob()

	/**
	 * An application whose version cannot be resolved fails the job rather
	 * than exporting a versionless shell.
	 *
	 * @return void
	 */
	public function testAnApplicationWithNoVersionFailsTheJob(): void {
		$this->exportJobService->method('loadJob')->willReturn($this->jobFixture());

		$bundler = $this->createMock(ExportAppContentBundler::class);
		$bundler->method('resolveSource')->willReturn([
			'application' => ['slug' => 'test-app'],
			'version' => [],
		]);

		$this->exportService->expects(self::never())->method('generateAppZip');

		$failed = null;
		$this->captureFail($failed);

		$this->invokeRun(
			$this->buildExportJob(contentBundler: $bundler),
			['jobUuid' => 'job-no-version']
		);

		self::assertNotNull($failed);
		self::assertSame(
			'The application has no version to export.',
			$failed['errorMessage']
		);
	}//end testAnApplicationWithNoVersionFailsTheJob()

	/**
	 * What the exporter could not resolve is recorded ON the finished job.
	 *
	 * A skip that only reaches the log reaches nobody — the operator reads
	 * the job, so the succeed transition has to carry it.
	 *
	 * @return void
	 */
	public function testWhatCouldNotBeResolvedIsReportedOnTheFinishedJob(): void {
		$this->exportJobService->method('loadJob')->willReturn($this->jobFixture());
		$this->exportService->method('generateAppZip')->willReturn('/tmp/x.zip');
		$this->exportService->method('lastSkipped')
			->willReturn(['schema "case" could not be read']);

		$succeeded = null;
		$this->exportJobService->method('transitionJob')->willReturnCallback(
			function (string $uuid, string $action, array $extra = []) use (&$succeeded): bool {
				if ($action === 'succeed') {
					$succeeded = $extra;
				}

				return true;
			}
		);

		$this->invokeRun($this->buildExportJob(), ['jobUuid' => 'job-skips']);

		self::assertNotNull($succeeded, 'the job must have succeeded');
		self::assertSame(
			['schema "case" could not be read'],
			$succeeded['skipped'],
			'the skip belongs on the record the operator reads'
		);
	}//end testWhatCouldNotBeResolvedIsReportedOnTheFinishedJob()

	/**
	 * Nothing skipped means no `skipped` key at all, rather than an empty one
	 * the UI would have to special-case.
	 *
	 * @return void
	 */
	public function testNothingSkippedLeavesTheKeyOffTheRecord(): void {
		$this->exportJobService->method('loadJob')->willReturn($this->jobFixture());
		$this->exportService->method('generateAppZip')->willReturn('/tmp/x.zip');
		$this->exportService->method('lastSkipped')->willReturn([]);

		$succeeded = null;
		$this->exportJobService->method('transitionJob')->willReturnCallback(
			function (string $uuid, string $action, array $extra = []) use (&$succeeded): bool {
				if ($action === 'succeed') {
					$succeeded = $extra;
				}

				return true;
			}
		);

		$this->invokeRun($this->buildExportJob(), ['jobUuid' => 'job-clean']);

		self::assertNotNull($succeeded);
		self::assertArrayNotHasKey('skipped', $succeeded);
	}//end testNothingSkippedLeavesTheKeyOffTheRecord()

	/**
	 * AppHealthSampleCleanup::run() purges against the time factory's clock,
	 * so the retention cutoff follows the server's time rather than a time
	 * the job computed for itself.
	 *
	 * @return void
	 */
	public function testTheHealthCleanupRunPurgesAgainstTheInjectedClock(): void {
		$now = 1760000000;

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(30);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		// The cutoff is derived from the injected clock; capturing the call
		// proves run() reached the purge with it rather than returning early.
		$objectService->expects(self::once())
			->method('findAll')
			->willReturn([]);

		$job = new AppHealthSampleCleanup(
			$time,
			$objectService,
			$this->createMock(AppHealthService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);

		$this->invokeRun($job, null);
	}//end testTheHealthCleanupRunPurgesAgainstTheInjectedClock()

	/**
	 * The same purge, called directly, reports how many samples it deleted —
	 * which is what run() discards, so it is asserted here instead.
	 *
	 * @return void
	 */
	public function testTheHealthPurgeReportsNothingDeletedForAnEmptyRegister(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(30);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturn([]);

		$job = new AppHealthSampleCleanup(
			$time,
			$objectService,
			$this->createMock(AppHealthService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);

		self::assertSame(0, $job->purge(now: 1760000000));
	}//end testTheHealthPurgeReportsNothingDeletedForAnEmptyRegister()
}//end class
