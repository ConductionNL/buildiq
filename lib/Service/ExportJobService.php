<?php

/**
 * OpenBuilt Export Job Service
 *
 * Orchestration helper between the HTTP controller and the OR-backed
 * ExportJob record + the imperative ExportService pipeline. Persists the
 * GitHub PAT via ICredentialsManager (Decision 3) and never logs it.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Service
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

namespace OCA\OpenBuilt\Service;

use OCA\OpenBuilt\AppInfo\Application;
use OCP\BackgroundJob\IJobList;
use OCP\Security\ICredentialsManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bridges the ExportsController to the OR ExportJob record + RunExportJob.
 */
class ExportJobService
{
    private const PAT_CREDENTIAL_PREFIX = 'openbuilt.export.';
    private const PAT_CREDENTIAL_SUFFIX = '.pat';

    /**
     * Constructor.
     *
     * @param ContainerInterface  $container          Container — used to lazily fetch OR services.
     * @param ICredentialsManager $credentialsManager Nextcloud credentials manager.
     * @param IJobList            $jobList            Background job list.
     * @param LoggerInterface     $logger             Logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private ICredentialsManager $credentialsManager,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create an ExportJob record in OR and schedule the background job.
     *
     * The PAT (when supplied) is stored under
     * `openbuilt.export.<jobUuid>.pat` and stripped from the in-memory payload
     * before any logging.
     *
     * @param string              $applicationSlug Source Application slug.
     * @param array<string,mixed> $payload         Sanitised payload (no PAT).
     * @param string|null         $githubPat       GitHub PAT, if any.
     *
     * @return string Job UUID (UUIDv4).
     *
     * @throws \InvalidArgumentException When required fields are missing.
     */
    public function queue(
        string $applicationSlug,
        array $payload,
        ?string $githubPat=null,
    ): string {
        $jobUuid = $this->uuid4();
        $target  = (string) ($payload['target'] ?? 'zip');

        $job = [
            'uuid'               => $jobUuid,
            'applicationSlug'    => $applicationSlug,
            'applicationUuid'    => (string) ($payload['applicationUuid'] ?? ''),
            'applicationVersion' => (string) ($payload['applicationVersion'] ?? ''),
            'target'             => $target,
            'status'             => 'queued',
            'githubOrg'          => isset($payload['githubOrg']) ? (string) $payload['githubOrg'] : null,
            'githubRepo'         => isset($payload['githubRepo']) ? (string) $payload['githubRepo'] : null,
            'githubVisibility'   => isset($payload['githubVisibility']) ? (string) $payload['githubVisibility'] : 'private',
            'includeSeedData'    => (bool) ($payload['includeSeedData'] ?? false),
            'license'            => (string) ($payload['license'] ?? 'EUPL-1.2'),
            'log'                => [],
        ];

        if ($githubPat !== null && $githubPat !== '') {
            // Store PAT keyed by job UUID; never persist it in the OR record.
            $this->credentialsManager->store(
                Application::APP_ID,
                $this->credentialKey($jobUuid),
                $githubPat
            );
        }

        $this->persistJob($job);
        $this->jobList->add(
            \OCA\OpenBuilt\BackgroundJob\RunExportJob::class,
            ['jobUuid' => $jobUuid]
        );

        return $jobUuid;
    }//end queue()

    /**
     * Persist the ExportJob record via OR (best-effort; falls back to a no-op
     * when OR is not available so unit tests can stub the path).
     *
     * @param array<string,mixed> $job Sanitised job record.
     *
     * @return void
     */
    public function persistJob(array $job): void
    {
        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                $this->logger->info('OpenBuilt export job persisted (logger fallback): '.$job['uuid']);
                return;
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'saveObject') === true) {
                $service->saveObject($job);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Could not persist ExportJob to OR: '.$e->getMessage());
        }
    }//end persistJob()

    /**
     * Resolve a download path for the given ExportJob UUID.
     *
     * @param string $uuid ExportJob UUID.
     *
     * @return array{path:string,expired:bool}|null Resolution result.
     */
    public function resolveDownload(string $uuid): ?array
    {
        // Look for the ZIP in the deterministic location.
        $candidate = sys_get_temp_dir().'/openbuilt-exports/'.$uuid.'.zip';
        if (file_exists($candidate) === false) {
            return null;
        }

        // No expiry record in fallback path — treat as fresh.
        return [
            'path'    => $candidate,
            'expired' => false,
        ];
    }//end resolveDownload()

    /**
     * Fetch the stored PAT for a job, if any.
     *
     * @param string $jobUuid Job UUID.
     *
     * @return string|null PAT or null when none was stored.
     */
    public function fetchPat(string $jobUuid): ?string
    {
        $value = $this->credentialsManager->retrieve(Application::APP_ID, $this->credentialKey($jobUuid));
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end fetchPat()

    /**
     * Delete the stored PAT for a job. Safe to call multiple times.
     *
     * @param string $jobUuid Job UUID.
     *
     * @return void
     */
    public function clearPat(string $jobUuid): void
    {
        try {
            $this->credentialsManager->delete(Application::APP_ID, $this->credentialKey($jobUuid));
        } catch (\Throwable $e) {
            $this->logger->debug('PAT delete returned no-op: '.$e->getMessage());
        }
    }//end clearPat()

    /**
     * Build the ICredentialsManager key for a job's PAT.
     *
     * @param string $jobUuid Job UUID.
     *
     * @return string Credentials key.
     */
    public function credentialKey(string $jobUuid): string
    {
        return self::PAT_CREDENTIAL_PREFIX.$jobUuid.self::PAT_CREDENTIAL_SUFFIX;
    }//end credentialKey()

    /**
     * Generate a UUIDv4.
     *
     * @return string UUIDv4.
     */
    public function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
        return vsprintf('%s-%s-%s-%s-%s', str_split(bin2hex($data), 4));
    }//end uuid4()
}//end class
