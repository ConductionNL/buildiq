<?php

/**
 * OpenBuilt RunExportJob unit tests
 *
 * Covers the most security-critical surface in spec #9: the lifecycle
 * transitions through TransitionEngine, the ALWAYS-clear-PAT contract
 * in the finally block, no-auto-retry on failure, and the documented
 * idempotency guarantee for re-runs of the same job.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\BackgroundJob
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

namespace OCA\OpenBuilt\Tests\Unit\BackgroundJob;

use OCA\OpenBuilt\BackgroundJob\RunExportJob;
use OCA\OpenBuilt\Service\ExportJobService;
use OCA\OpenBuilt\Service\ExportService;
use OCA\OpenBuilt\Service\GitHubPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Tests for {@see RunExportJob} — lifecycle + PAT cleanup contract.
 */
final class RunExportJobTest extends TestCase
{
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
    protected function setUp(): void
    {
        parent::setUp();
        $this->time              = $this->createMock(ITimeFactory::class);
        $this->exportService     = $this->createMock(ExportService::class);
        $this->exportJobService  = $this->createMock(ExportJobService::class);
        $this->githubPushService = $this->createMock(GitHubPushService::class);
    }//end setUp()

    /**
     * Invoke the protected `run()` method via Reflection so tests don't
     * need the full Nextcloud cron harness.
     *
     * @param RunExportJob $job      Job under test.
     * @param mixed        $argument Argument payload (commonly ['jobUuid' => ...]).
     *
     * @return void
     */
    private function invokeRun(RunExportJob $job, $argument): void
    {
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
    private function buildJob(?\Psr\Log\LoggerInterface $logger=null): RunExportJob
    {
        return new RunExportJob(
            $this->time,
            $this->exportService,
            $this->exportJobService,
            $this->githubPushService,
            $logger ?? new NullLogger()
        );
    }//end buildJob()

    /**
     * Happy path: the job transitions queued → running → succeeded via
     * the declarative TransitionEngine (proxied through ExportJobService).
     *
     * Specifically asserts both `start` and `succeed` transitions fire —
     * any regression to direct status writes would break this.
     *
     * @return void
     */
    public function testRunTransitionsThroughRunningToSucceeded(): void
    {
        $jobUuid = 'job-success-uuid';

        $this->exportJobService
            ->expects(self::exactly(2))
            ->method('transitionJob')
            ->willReturnCallback(function (string $uuid, string $action, array $extra=[]) use ($jobUuid): bool {
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

        $this->exportJobService->method('fetchPat')->willReturn(null);

        $this->exportService
            ->expects(self::once())
            ->method('generateAppZip')
            ->willReturn('/tmp/openbuilt-exports/'.$jobUuid.'.zip');

        // GitHub push must NOT fire when no PAT is present (ZIP-only).
        $this->githubPushService->expects(self::never())->method('push');

        // Terminal-state clear MUST fire even on success.
        $this->exportJobService->expects(self::once())->method('clearPat')->with($jobUuid);

        $this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);
    }//end testRunTransitionsThroughRunningToSucceeded()

    /**
     * Failure path: when ExportService::generateAppZip throws, the job
     * transitions to `failed` (NOT auto-retries — memory rule: crashes
     * → needs-input), and the error message is merged onto the record.
     *
     * @return void
     */
    public function testRunTransitionsToFailedOnException(): void
    {
        $jobUuid = 'job-fail-uuid';

        $this->exportService
            ->method('generateAppZip')
            ->willThrowException(new \RuntimeException('disk full'));

        $sawFail = false;
        $this->exportJobService
            ->expects(self::exactly(2))
            ->method('transitionJob')
            ->willReturnCallback(function (string $uuid, string $action, array $extra=[]) use ($jobUuid, &$sawFail): bool {
                if ($action === 'fail') {
                    self::assertSame($jobUuid, $uuid);
                    self::assertArrayHasKey('errorMessage', $extra);
                    self::assertSame('disk full', $extra['errorMessage']);
                    $sawFail = true;
                }

                return true;
            });

        // PAT cleared even on failure.
        $this->exportJobService->expects(self::once())->method('clearPat')->with($jobUuid);

        $this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);

        self::assertTrue($sawFail, 'fail transition MUST be invoked on exception');
    }//end testRunTransitionsToFailedOnException()

    /**
     * The clearPat() call MUST fire on the success path — wired through
     * the `finally` block so it executes regardless of pipeline outcome.
     *
     * This is the security-critical PAT-leak guard: a regression here
     * would leave a long-lived PAT in ICredentialsManager after every
     * successful GitHub export.
     *
     * @return void
     */
    public function testClearPatAlwaysCalledOnSuccess(): void
    {
        $jobUuid = 'pat-cleanup-success';
        $this->exportService->method('generateAppZip')->willReturn('/tmp/x.zip');
        $this->exportJobService->method('fetchPat')->willReturn(null);
        $this->exportJobService->method('transitionJob')->willReturn(true);

        $this->exportJobService
            ->expects(self::once())
            ->method('clearPat')
            ->with(self::equalTo($jobUuid));

        $this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);
    }//end testClearPatAlwaysCalledOnSuccess()

    /**
     * Symmetric guarantee on the failure path: clearPat() MUST still fire.
     *
     * Without this, a failed export leaves the PAT in ICredentialsManager
     * indefinitely — the exact security incident Decision 3 is designed to
     * prevent.
     *
     * @return void
     */
    public function testClearPatAlwaysCalledOnFailure(): void
    {
        $jobUuid = 'pat-cleanup-failure';

        $this->exportService
            ->method('generateAppZip')
            ->willThrowException(new \RuntimeException('boom'));
        $this->exportJobService->method('transitionJob')->willReturn(true);

        $this->exportJobService
            ->expects(self::once())
            ->method('clearPat')
            ->with(self::equalTo($jobUuid));

        $this->invokeRun($this->buildJob(), ['jobUuid' => $jobUuid]);
    }//end testClearPatAlwaysCalledOnFailure()

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
    public function testRerunWithSameParamsProducesEquivalentInvocations(): void
    {
        $jobUuid = 'idempotent-rerun-uuid';

        $captured = [];
        $this->exportService
            ->expects(self::exactly(2))
            ->method('generateAppZip')
            ->willReturnCallback(function (
                string $applicationUuid,
                string $versionSlug,
                array $context,
                string $jobUuidArg
            ) use (&$captured): string {
                $captured[] = [
                    'applicationUuid' => $applicationUuid,
                    'versionSlug'     => $versionSlug,
                    'context'         => $context,
                    'jobUuid'         => $jobUuidArg,
                ];

                return '/tmp/out.zip';
            });
        $this->exportJobService->method('fetchPat')->willReturn(null);
        $this->exportJobService->method('transitionJob')->willReturn(true);

        $job = $this->buildJob();
        $this->invokeRun($job, ['jobUuid' => $jobUuid]);
        $this->invokeRun($job, ['jobUuid' => $jobUuid]);

        self::assertCount(2, $captured);
        self::assertSame($captured[0], $captured[1], 'Two invocations with the same jobUuid must produce identical arguments');
    }//end testRerunWithSameParamsProducesEquivalentInvocations()

    /**
     * The PAT MUST NEVER appear in a log line. This test captures every
     * log line emitted during a run that fetches a PAT and dispatches a
     * push, then asserts the PAT marker is absent across all of them.
     *
     * Security-critical: even a debug-level log of the PAT defeats the
     * Decision 3 contract.
     *
     * @return void
     */
    public function testCredentialNeverLogged(): void
    {
        $jobUuid = 'pat-no-log-uuid';
        $pat     = 'ghp_marker_token_must_not_appear';

        $captured = [];
        $logger   = new class ($captured) extends AbstractLogger {
            /**
             * @var list<string>
             */
            private array $sink;

            public function __construct(array &$captured)
            {
                $this->sink = &$captured;
            }

            public function log($level, \Stringable|string $message, array $context=[]): void
            {
                $this->sink[] = (string) $message.' '.json_encode($context);
            }
        };

        $this->exportService->method('generateAppZip')->willReturn('/tmp/out.zip');
        $this->exportJobService->method('fetchPat')->willReturn($pat);
        $this->exportJobService->method('transitionJob')->willReturn(true);
        $this->githubPushService
            ->method('push')
            ->willReturn(['repoUrl' => 'https://github.com/x/y', 'pullRequestUrl' => 'https://github.com/x/y/pull/1']);

        $this->invokeRun($this->buildJob($logger), ['jobUuid' => $jobUuid]);

        foreach ($captured as $line) {
            self::assertStringNotContainsString(
                $pat,
                $line,
                'PAT must NEVER appear in any log line — found in: '.$line
            );
        }
    }//end testCredentialNeverLogged()
}//end class
