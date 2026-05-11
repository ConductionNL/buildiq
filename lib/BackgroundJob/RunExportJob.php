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
        parent::__construct($time);
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
        $jobUuid = '';
        if (is_array($argument) === true && isset($argument['jobUuid']) === true) {
            $jobUuid = (string) $argument['jobUuid'];
        }

        if ($jobUuid === '') {
            $this->logger->error('OpenBuilt RunExportJob: missing jobUuid argument');
            return;
        }

        try {
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

            // Optional GitHub push — fetch the PAT exactly once.
            $pat = $this->exportJobService->fetchPat($jobUuid);
            if ($pat !== null && $pat !== '') {
                $this->githubPushService->push(
                    $jobUuid,
                    dirname($zipPath).'/'.$jobUuid,
                    $pat
                );
            }

            $this->logger->info('OpenBuilt export succeeded', ['jobUuid' => $jobUuid]);
        } catch (\Throwable $e) {
            // No-auto-retry: log + leave the job in failed state.
            $this->logger->error(
                'OpenBuilt export failed',
                ['jobUuid' => $jobUuid, 'error' => $e->getMessage()]
            );
        } finally {
            // Always clear the PAT — both success and failure are terminal.
            $this->exportJobService->clearPat($jobUuid);
        }//end try
    }//end run()
}//end class
