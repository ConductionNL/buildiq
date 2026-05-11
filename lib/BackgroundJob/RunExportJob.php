<?php

/**
 * OpenBuilt RunExportJob background job
 *
 * Picks up a queued ExportJob and walks it through running →
 * succeeded|failed. Honours the no-auto-retry rule (memory: crashes →
 * needs-input).
 *
 * @category BackgroundJob
 * @package  OCA\OpenBuilt\BackgroundJob
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

namespace OCA\OpenBuilt\BackgroundJob;

use OCA\OpenBuilt\Service\ExportJobService;
use OCA\OpenBuilt\Service\ExportService;
use OCA\OpenBuilt\Service\GitHubPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that runs a single ExportJob to completion.
 */
class RunExportJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory      $time              Time factory (Nextcloud-injectable).
     * @param ExportService     $exportService     File-generation pipeline.
     * @param ExportJobService  $exportJobService  Job orchestration helper.
     * @param GitHubPushService $githubPushService GitHub delivery target.
     * @param LoggerInterface   $logger            Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private ExportService $exportService,
        private ExportJobService $exportJobService,
        private GitHubPushService $githubPushService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the job.
     *
     * NEVER auto-retries — failures escalate via the ExportJob's
     * status=failed + errorMessage. The PAT is fetched once at the GitHub
     * phase and deleted from ICredentialsManager on every terminal state.
     *
     * @param mixed $argument Job argument injected by Nextcloud:
     *                        ['jobUuid' => string].
     *
     * @return void
     */
    protected function run($argument): void
    {
        $jobUuid = $this->extractJobUuid(argument: $argument);
        if ($jobUuid === '') {
            $this->logger->error('OpenBuilt RunExportJob: missing jobUuid argument');
            return;
        }

        // Lifecycle transition: queued → running (declarative, via OR
        // TransitionEngine). The schema's `x-openregister-lifecycle.transitions`
        // entry named "start" drives this; we never write `status` directly.
        $this->exportJobService->transitionJob(jobUuid: $jobUuid, action: 'start');

        try {
            $this->executePipeline(jobUuid: $jobUuid);
        } catch (\Throwable $e) {
            // No-auto-retry: fire the declarative 'fail' transition, merge
            // an errorMessage onto the record, and leave it for the user
            // (memory: crashes → needs-input).
            $this->logger->error(
                'OpenBuilt export failed',
                ['jobUuid' => $jobUuid, 'error' => $e->getMessage()]
            );
            $this->exportJobService->transitionJob(
                jobUuid: $jobUuid,
                action: 'fail',
                extraFields: ['errorMessage' => $e->getMessage()]
            );
        } finally {
            // Always clear the PAT — both success and failure are terminal.
            $this->exportJobService->clearPat(jobUuid: $jobUuid);
        }//end try
    }//end run()

    /**
     * Pull the job UUID from the Nextcloud job argument.
     *
     * @param mixed $argument Job argument.
     *
     * @return string Job UUID, '' when missing/malformed.
     */
    private function extractJobUuid($argument): string
    {
        if (is_array($argument) === true && isset($argument['jobUuid']) === true) {
            return (string) $argument['jobUuid'];
        }

        return '';
    }//end extractJobUuid()

    /**
     * Run the inner pipeline (ZIP + optional GitHub push) + drive the
     * succeed transition. Any thrown error escapes to run()'s catch block.
     *
     * @param string $jobUuid Job UUID.
     *
     * @return void
     */
    private function executePipeline(string $jobUuid): void
    {
        $context = [
            'appId'        => 'exported-app',
            'appNamespace' => 'ExportedApp',
            'appName'      => 'Exported App',
            'appVersion'   => '0.1.0',
            'authorName'   => 'OpenBuilt Citizen Developer',
            'authorEmail'  => 'dev@conduction.nl',
            'license'      => 'EUPL-1.2',
        ];

        $zipPath = $this->exportService->generateAppZip(
            applicationUuid: $jobUuid,
            versionSlug: '0.1.0',
            context: $context,
            jobUuid: $jobUuid
        );

        $pushResult = $this->maybePush(jobUuid: $jobUuid, zipPath: $zipPath);

        $extra = $this->buildSuccessFields(jobUuid: $jobUuid, pushResult: $pushResult);

        $this->exportJobService->transitionJob(jobUuid: $jobUuid, action: 'succeed', extraFields: $extra);
        $this->logger->info('OpenBuilt export succeeded', ['jobUuid' => $jobUuid]);
    }//end executePipeline()

    /**
     * Fetch the PAT once and push to GitHub if one was supplied.
     *
     * @param string $jobUuid Job UUID.
     * @param string $zipPath Path to the generated ZIP.
     *
     * @return array{repoUrl?:string,pullRequestUrl?:string}|null
     */
    private function maybePush(string $jobUuid, string $zipPath): ?array
    {
        $pat = $this->exportJobService->fetchPat(jobUuid: $jobUuid);
        if ($pat === null || $pat === '') {
            return null;
        }

        return $this->githubPushService->push(
            jobUuid: $jobUuid,
            treeDir: dirname($zipPath).'/'.$jobUuid,
            pat: $pat
        );
    }//end maybePush()

    /**
     * Assemble the side-fields merged on a successful run.
     *
     * @param string                                             $jobUuid    Job UUID.
     * @param array{repoUrl?:string,pullRequestUrl?:string}|null $pushResult Result of maybePush().
     *
     * @return array<string,mixed>
     */
    private function buildSuccessFields(string $jobUuid, ?array $pushResult): array
    {
        $extra = [
            'downloadUrl' => '/index.php/apps/openbuilt/api/exports/'.$jobUuid.'/download',
        ];

        if (is_array($pushResult) === false) {
            return $extra;
        }

        if (isset($pushResult['repoUrl']) === true && $pushResult['repoUrl'] !== '') {
            $extra['githubRepoUrl'] = $pushResult['repoUrl'];
        }

        if (isset($pushResult['pullRequestUrl']) === true && $pushResult['pullRequestUrl'] !== '') {
            $extra['githubPullRequestUrl'] = $pushResult['pullRequestUrl'];
        }

        return $extra;
    }//end buildSuccessFields()
}//end class
