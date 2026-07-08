<?php

/**
 * OpenBuild RunExportJob background job
 *
 * Picks up a queued ExportJob and walks it through running →
 * succeeded|failed. Honours the no-auto-retry rule (memory: crashes →
 * needs-input).
 *
 * @category BackgroundJob
 * @package  OCA\OpenBuild\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-34
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\BackgroundJob;

use OCA\OpenBuild\Service\ExportJobService;
use OCA\OpenBuild\Service\ExportService;
use OCA\OpenBuild\Service\GitHubPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Background job that runs a single ExportJob to completion.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-7.1
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
     */
    protected function run($argument): void
    {
        $jobUuid = $this->extractJobUuid(argument: $argument);
        if ($jobUuid === '') {
            $this->logger->error('OpenBuild RunExportJob: missing jobUuid argument');
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
                'OpenBuild export failed',
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
     * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.3
     */
    private function executePipeline(string $jobUuid): void
    {
        // Load the queued ExportJob record so we have the real application
        // identity — uuid, version slug, and slug (= appId). The job was
        // persisted by ExportJobService::queue() before this background job
        // was dispatched, so it must exist in OR.
        $job = $this->exportJobService->loadJob(jobUuid: $jobUuid);
        if ($job === null) {
            throw new RuntimeException(
                sprintf('OpenBuild RunExportJob: could not load ExportJob record for UUID %s', $jobUuid)
            );
        }

        $applicationUuid    = (string) ($job['applicationUuid'] ?? '');
        $applicationVersion = (string) ($job['applicationVersion'] ?? '0.1.0');
        $applicationSlug    = (string) ($job['applicationSlug'] ?? 'exported-app');
        $license            = (string) ($job['license'] ?? 'EUPL-1.2');

        $dataRegisters = [];
        if (is_array($job['dataRegisters'] ?? null) === true) {
            $dataRegisters = $job['dataRegisters'];
        }

        if ($applicationUuid === '') {
            throw new RuntimeException(
                sprintf('OpenBuild RunExportJob: ExportJob %s has an empty applicationUuid', $jobUuid)
            );
        }

        $context = [
            'appId'        => $applicationSlug,
            'appNamespace' => $this->slugToNamespace(slug: $applicationSlug),
            'appName'      => $this->slugToLabel(slug: $applicationSlug),
            'appVersion'   => $applicationVersion,
            'authorName'   => 'OpenBuild Citizen Developer',
            'authorEmail'  => 'dev@conduction.nl',
            'license'      => $license,
        ];

        $this->exportService->generateAppZip(
            applicationUuid: $applicationUuid,
            versionSlug: $applicationVersion,
            context: $context,
            jobUuid: $jobUuid,
            dataRegisters: $dataRegisters
        );

        $pushResult = $this->maybePush(jobUuid: $jobUuid, job: $job);

        $extra = $this->buildSuccessFields(jobUuid: $jobUuid, pushResult: $pushResult);

        $this->exportJobService->transitionJob(jobUuid: $jobUuid, action: 'succeed', extraFields: $extra);
        $this->logger->info('OpenBuild export succeeded', ['jobUuid' => $jobUuid]);
    }//end executePipeline()

    /**
     * Convert a kebab-case app slug to a PascalCase PHP namespace segment.
     *
     * E.g. `my-virtual-app` → `MyVirtualApp`.
     *
     * @param string $slug The application slug.
     *
     * @return string PascalCase namespace.
     */
    private function slugToNamespace(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
    }//end slugToNamespace()

    /**
     * Convert a kebab-case app slug to a human-readable label.
     *
     * E.g. `my-virtual-app` → `My Virtual App`.
     *
     * @param string $slug The application slug.
     *
     * @return string Human-readable label.
     */
    private function slugToLabel(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }//end slugToLabel()

    /**
     * Fetch the PAT once and push to GitHub if one was supplied.
     *
     * The generated tree lives in the exporter's work scratch dir keyed by
     * job UUID (see ExportService::prepareScratchDir); we hand that to the
     * GitHub push service so it can blob/tree/commit each file.
     *
     * @param string              $jobUuid Job UUID.
     * @param array<string,mixed> $job     Loaded ExportJob record.
     *
     * @return array{repoUrl?:string,pullRequestUrl?:string}|null
     *
     * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
     */
    private function maybePush(string $jobUuid, array $job): ?array
    {
        if ((string) ($job['target'] ?? 'zip') !== 'github') {
            return null;
        }

        $pat = $this->exportJobService->fetchPat(jobUuid: $jobUuid);
        if ($pat === null || $pat === '') {
            return null;
        }

        $treeDir = $this->exportService->scratchTreeDir(jobUuid: $jobUuid);

        return $this->githubPushService->push(
            jobUuid: $jobUuid,
            treeDir: $treeDir,
            pat: $pat,
            org: (string) ($job['githubOrg'] ?? ''),
            repo: (string) ($job['githubRepo'] ?? ''),
            visibility: (string) ($job['githubVisibility'] ?? 'private')
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
            'downloadUrl' => '/index.php/apps/openbuild/api/exports/'.$jobUuid.'/download',
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
