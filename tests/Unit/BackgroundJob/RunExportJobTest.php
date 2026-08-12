<?php

/**
 * OpenBuild RunExportJob unit tests
 *
 * Covers the most security-critical surface in spec #9: the lifecycle
 * transitions through TransitionEngine, the ALWAYS-clear-PAT contract
 * in the finally block, no-auto-retry on failure, and the documented
 * idempotency guarantee for re-runs of the same job.
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
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\BackgroundJob;

use OCA\OpenBuild\BackgroundJob\RunExportJob;
use OCA\OpenBuild\Service\ExportJobService;
use OCA\OpenBuild\Service\ExportService;
use OCA\OpenBuild\Service\GitHubPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Tests for {@see RunExportJob} — lifecycle + PAT cleanup contract.
 */
final class RunExportJobTest extends TestCase {
	/**
	 * Time factory mock (required by the QueuedJob base class).
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Export pipeline mock.
	 *
	 * @var ExportService&MockObject
	 */
	private ExportService&MockObject $exportService;

	/**
	 * Orchestration helper mock — owns transitions + PAT plumbing.
	 *
	 * @var ExportJobService&MockObject
	 */
	private ExportJobService&MockObject $exportJobService;

	/**
	 * GitHub delivery target mock.
	 *
	 * @var GitHubPushService&MockObject
	 */
	private GitHubPushService&MockObject $githubPushService;

	/**
	 * Build mocks shared across every test.
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
	 * Invoke the protected `run()` method via Reflection so tests don't
	 * need the full Nextcloud cron harness.
	 *
	 * @param RunExportJob $job Job under test.
	 * @param mixed $argument Argument payload (commonly ['jobUuid' => ...]).
	 *
	 * @return void
	 */
	private function invokeRun(RunExportJob $job, $argument): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}//end invokeRun()

	/**
	 * Build the job with a custom logger so log-output assertions are
	 * possible. Default tests use NullLogger().
	 *
	 * @param \Psr\Log\LoggerInterface|null $logger Optional logger.
	 *
	 * @return RunExportJob
	 */
	private function buildJob(?\Psr\Log\LoggerInterface $logger = null): RunExportJob {
		return new RunExportJob(
			$this->time,
			$this->exportService,
			$this->exportJobService,
			$this->githubPushService,
			$logger ?? new NullLogger()
		);
	}//end buildJob()

	/**
	 * Standard ExportJob fixture returned by the loadJob mock.
	 *
	 * @param string $applicationUuid Optional application UUID override.
	 *
	 * @return array<string,mixed>
	 */
	private function jobFixture(string $applicationUuid = 'app-uuid-test'): array {
		return [
			'applicationUuid' => $applicationUuid,
			'applicationVersion' => '1.0.0',
			'applicationSlug' => 'test-app',
			'license' => 'EUPL-1.2',
		];
	}//end jobFixture()

	/**
	 * Happy path: the job transitions queued → running → succeeded via
	 * the declarative TransitionEngine (proxied through ExportJobService).
	 *
	 * Specifically asserts both `start` and `succeed` transitions fire —
	 * any regression to direct status writes would break this.
	 *
	 * @return void
	 */
	public function testRunTransitionsThroughRunningToSucceeded(): void {
		$jobUuid = 'job-success-uuid';

		$this->exportJobService->method('loadJob')->willReturn($this->jobFixture());

		$this->exportJobService
			->expects(self::exactly(2))
			->method('transitionJob')
			->willReturnCallback(function (string $uuid, string $action, array $extra = []) use ($jobUuid): bool {
				static $calls = 0;
				$calls++;
				if ($calls === 1) {
					self::assertSame($jobUuid, $uuid);
					self::assertSame('start', $action);
				} else {
					self::assertSame($jobUuid, $uuid);
					self::assertSame('succeed', $action);
					self::assertArrayHasKey('downloadUrl', $extra);
				}

				return true;
			});

		$this->exportService
			->expects(self::once())
			->method('generateAppZip')
			->willReturn('/tmp/openbuild-exports/' . $jobUuid . '.zip');

		// GitHub push must NOT fire for a ZIP-only job.
		$this->githubPushService->expects(self::never())->method('push');

		$this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);
	}//end testRunTransitionsThroughRunningToSucceeded()

	/**
	 * Failure path: when ExportService::generateAppZip throws, the job
	 * transitions to `failed` (NOT auto-retries — memory rule: crashes
	 * → needs-input), and the error message is merged onto the record.
	 *
	 * @return void
	 */
	public function testRunTransitionsToFailedOnException(): void {
		$jobUuid = 'job-fail-uuid';

		$this->exportJobService->method('loadJob')->willReturn($this->jobFixture());

		$this->exportService
			->method('generateAppZip')
			->willThrowException(new \RuntimeException('disk full'));

		$sawFail = false;
		$this->exportJobService
			->expects(self::exactly(2))
			->method('transitionJob')
			->willReturnCallback(function (string $uuid, string $action, array $extra = []) use ($jobUuid, &$sawFail): bool {
				if ($action === 'fail') {
					self::assertSame($jobUuid, $uuid);
					self::assertArrayHasKey('errorMessage', $extra);
					self::assertSame('disk full', $extra['errorMessage']);
					$sawFail = true;
				}

				return true;
			});

		$this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);

		self::assertTrue($sawFail, 'fail transition MUST be invoked on exception');
	}//end testRunTransitionsToFailedOnException()

	/**
	 * A GitHub export with no broker credential fails closed — it does NOT push.
	 *
	 * This replaces the old pair of `clearPat()` tests. Those guaranteed the PAT was
	 * deleted from ICredentialsManager on every terminal state, which mattered only
	 * because OpenBuild held a PAT at all. It no longer does, so there is nothing to
	 * clear; what matters now is that a job which cannot authenticate through the
	 * broker refuses to run rather than proceeding.
	 *
	 * @return void
	 */
	public function testGithubExportWithoutCredentialFailsClosed(): void {
		$jobUuid = 'github-no-credential';

		$job = $this->jobFixture();
		$job['target'] = 'github';
		$job['githubOrg'] = 'acme-co';
		$job['githubRepo'] = 'hello-world';
		$job['githubCredentialId'] = '';

		$this->exportJobService->method('loadJob')->willReturn($job);
		$this->exportService->method('generateAppZip')->willReturn('/tmp/x.zip');

		// The whole point: no push is attempted without a credential.
		$this->githubPushService->expects(self::never())->method('push');

		$sawFail = false;
		$this->exportJobService
			->method('transitionJob')
			->willReturnCallback(function (string $uuid, string $action, array $extra = []) use (&$sawFail): bool {
				if ($action === 'fail') {
					$sawFail = true;
					self::assertStringContainsString('broker credential', (string)$extra['errorMessage']);
				}

				return true;
			});

		$this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);

		self::assertTrue($sawFail, 'a GitHub export without a credential MUST fail');
	}//end testGithubExportWithoutCredentialFailsClosed()

	/**
	 * A GitHub export hands the push service the credential UUID and the queueing
	 * user's UID — never a token, which this process does not have.
	 *
	 * @return void
	 */
	public function testGithubExportPassesCredentialAndActingUserToPush(): void {
		$jobUuid = 'github-with-credential';

		$job = $this->jobFixture();
		$job['target'] = 'github';
		$job['githubOrg'] = 'acme-co';
		$job['githubRepo'] = 'hello-world';
		$job['githubCredentialId'] = 'cred-uuid-1234';
		$job['requestedBy'] = 'alice';

		$this->exportJobService->method('loadJob')->willReturn($job);
		$this->exportJobService->method('transitionJob')->willReturn(true);
		$this->exportService->method('generateAppZip')->willReturn('/tmp/x.zip');
		$this->exportService->method('scratchTreeDir')->willReturn('/tmp/tree');

		$this->githubPushService
			->expects(self::once())
			->method('push')
			->with(
				self::anything(),
				self::anything(),
				self::equalTo('cred-uuid-1234'),
				self::equalTo('acme-co'),
				self::equalTo('hello-world'),
				self::anything(),
				self::equalTo('alice')
			)
			->willReturn(
				[
					'repoUrl' => 'https://github.com/acme-co/hello-world',
					'pullRequestUrl' => 'https://github.com/acme-co/hello-world/pull/1',
				]
			);

		$this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);
	}//end testGithubExportPassesCredentialAndActingUserToPush()

	/**
	 * Re-running a job with the same UUID must invoke the pipeline with
	 * identical arguments — the path through generateAppZip is parameterised
	 * only by jobUuid + applicationUuid + version + context, so two runs
	 * produce equivalent calls. This pins idempotency at the job-orchestration
	 * layer (REQ-OBEX-008 byte-equivalence is the ExportService's contract;
	 * here we lock that the job itself doesn't inject any per-run entropy).
	 *
	 * @return void
	 */
	public function testRerunWithSameParamsProducesEquivalentInvocations(): void {
		$jobUuid = 'idempotent-rerun-uuid';

		$this->exportJobService->method('loadJob')->willReturn($this->jobFixture('app-uuid-idempotent'));

		$captured = [];
		$this->exportService
			->expects(self::exactly(2))
			->method('generateAppZip')
			->willReturnCallback(function (
				string $applicationUuid,
				string $versionSlug,
				array $context,
				string $jobUuidArg,
			) use (&$captured): string {
				$captured[] = [
					'applicationUuid' => $applicationUuid,
					'versionSlug' => $versionSlug,
					'context' => $context,
					'jobUuid' => $jobUuidArg,
				];

				return '/tmp/out.zip';
			});
		$this->exportJobService->method('transitionJob')->willReturn(true);

		$job = $this->buildJob();
		$this->invokeRun($job, ['jobUuid' => $jobUuid]);
		$this->invokeRun($job, ['jobUuid' => $jobUuid]);

		self::assertCount(2, $captured);
		self::assertSame($captured[0], $captured[1], 'Two invocations with the same jobUuid must produce identical arguments');
	}//end testRerunWithSameParamsProducesEquivalentInvocations()

	/**
	 * The loaded ExportJob's `dataRegisters` choice is forwarded verbatim
	 * into `ExportService::generateAppZip()` (data-registers-runtime task
	 * 4.3). Absent on the job record — defaults to `[]`, not omitted/null.
	 *
	 * @return void
	 */
	public function testForwardsDataRegistersFromLoadedJobIntoGenerateAppZip(): void {
		$jobUuid = 'job-data-registers-uuid';
		$dataRegisters = [
			['register' => 'spectr', 'includeData' => true],
			['register' => 'bag-adressen', 'includeData' => false],
		];

		$job = $this->jobFixture();
		$job['dataRegisters'] = $dataRegisters;
		$this->exportJobService->method('loadJob')->willReturn($job);
		$this->exportJobService->method('transitionJob')->willReturn(true);

		$captured = null;
		$this->exportService
			->expects(self::once())
			->method('generateAppZip')
			->willReturnCallback(function (...$args) use (&$captured): string {
				$captured = $args;
				return '/tmp/out.zip';
			});

		$this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);

		self::assertSame($dataRegisters, $captured[4] ?? null, 'dataRegisters must be forwarded as the 5th argument');
	}//end testForwardsDataRegistersFromLoadedJobIntoGenerateAppZip()

	/**
	 * A job record predating this property (no `dataRegisters` key at all)
	 * forwards `[]` — additive, backward compatible.
	 *
	 * @return void
	 */
	public function testForwardsEmptyDataRegistersWhenJobRecordPredatesTheProperty(): void {
		$jobUuid = 'job-no-data-registers-uuid';

		$this->exportJobService->method('loadJob')->willReturn($this->jobFixture());
		$this->exportJobService->method('transitionJob')->willReturn(true);

		$captured = null;
		$this->exportService
			->expects(self::once())
			->method('generateAppZip')
			->willReturnCallback(function (...$args) use (&$captured): string {
				$captured = $args;
				return '/tmp/out.zip';
			});

		$this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);

		self::assertSame([], $captured[4] ?? null);
	}//end testForwardsEmptyDataRegistersWhenJobRecordPredatesTheProperty()

	/**
	 * Nothing token-shaped may reach a log line.
	 *
	 * This used to inject a real PAT via `fetchPat()` and assert it never surfaced.
	 * The job no longer has a token to leak, so the test now drives a full GitHub run
	 * and asserts no GitHub-token-shaped string appears anywhere in the log — a guard
	 * that stays meaningful if someone reintroduces a secret on this path.
	 *
	 * @return void
	 */
	public function testNoTokenShapedStringIsEverLogged(): void {
		$jobUuid = 'github-log-scan';

		$captured = [];
		$logger = new class($captured) extends AbstractLogger {
			/**
			 * @var list<string>
			 */
			private array $sink;

			public function __construct(array &$captured) {
				$this->sink = &$captured;
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->sink[] = (string)$message . ' ' . json_encode($context);
			}
		};

		$job = $this->jobFixture();
		$job['target'] = 'github';
		$job['githubOrg'] = 'acme-co';
		$job['githubRepo'] = 'hello-world';
		$job['githubCredentialId'] = 'cred-uuid-1234';
		$job['requestedBy'] = 'alice';

		$this->exportJobService->method('loadJob')->willReturn($job);
		$this->exportService->method('generateAppZip')->willReturn('/tmp/out.zip');
		$this->exportService->method('scratchTreeDir')->willReturn('/tmp/tree');
		$this->exportJobService->method('transitionJob')->willReturn(true);
		$this->githubPushService
			->method('push')
			->willReturn(['repoUrl' => 'https://github.com/x/y', 'pullRequestUrl' => 'https://github.com/x/y/pull/1']);

		$this->invokeRun($this->buildJob($logger), ['jobUuid' => $jobUuid]);

		self::assertNotEmpty($captured, 'the run must emit at least one log line for this scan to mean anything');

		foreach ($captured as $line) {
			self::assertDoesNotMatchRegularExpression(
				'/gh[pousr]_[A-Za-z0-9]{10,}/',
				$line,
				'No GitHub token may ever appear in a log line — found in: ' . $line
			);
		}
	}//end testNoTokenShapedStringIsEverLogged()
}//end class
