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

        $githubOrg        = null;
        $githubRepo       = null;
        $githubVisibility = 'private';
        if (isset($payload['githubOrg']) === true) {
            $githubOrg = (string) $payload['githubOrg'];
        }

        if (isset($payload['githubRepo']) === true) {
            $githubRepo = (string) $payload['githubRepo'];
        }

        if (isset($payload['githubVisibility']) === true) {
            $githubVisibility = (string) $payload['githubVisibility'];
        }

        $job = [
            'uuid'               => $jobUuid,
            'applicationSlug'    => $applicationSlug,
            'applicationUuid'    => (string) ($payload['applicationUuid'] ?? ''),
            'applicationVersion' => (string) ($payload['applicationVersion'] ?? ''),
            'target'             => $target,
            'status'             => 'queued',
            'githubOrg'          => $githubOrg,
            'githubRepo'         => $githubRepo,
            'githubVisibility'   => $githubVisibility,
            'includeSeedData'    => (bool) ($payload['includeSeedData'] ?? false),
            'license'            => (string) ($payload['license'] ?? 'EUPL-1.2'),
            'log'                => [],
        ];

        if ($githubPat !== null && $githubPat !== '') {
            // Store PAT keyed by job UUID; never persist it in the OR record.
            $this->credentialsManager->store(
                Application::APP_ID,
                $this->credentialKey(jobUuid: $jobUuid),
                $githubPat
            );
        }

        $this->persistJob(job: $job);
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
     * NOTE: This persists the *initial* record only. Subsequent state
     * transitions MUST go through transitionJob() so OR's lifecycle engine
     * (TransitionEngine + ObjectTransitionedEvent + guards) is the source of
     * truth — direct status writes here would bypass the declarative
     * x-openregister-lifecycle on the exportJob schema.
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
     * Drive an ExportJob through its declarative lifecycle.
     *
     * Calls OR's TransitionEngine — which looks up the named transition
     * in `x-openregister-lifecycle`, validates the allowed `from` states,
     * runs guards, saves through ObjectService (so audit + events fire),
     * and dispatches ObjectTransitionedEvent.
     *
     * If OR's TransitionEngine isn't available on the installed version
     * (older OR releases), we log the gap and return false so the caller
     * can decide what to do; we never silently fall back to direct status
     * writes (that would defeat the entire declarative contract).
     *
     * @param string              $jobUuid     ExportJob UUID.
     * @param string              $action      Transition action name
     *                                         ('start', 'succeed', 'fail').
     * @param array<string,mixed> $extraFields Optional fields to merge
     *                                         alongside the transition
     *                                         (e.g. errorMessage on 'fail',
     *                                         downloadUrl on 'succeed').
     *
     * @return bool True when the transition fired, false when OR's
     *              lifecycle engine is not available (gap recorded).
     */
    public function transitionJob(
        string $jobUuid,
        string $action,
        array $extraFields=[],
    ): bool {
        $engineClass = 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine';

        if ($this->container->has($engineClass) === false) {
            // Documented gap: spec REQ-OBEX-006 calls for declarative
            // lifecycle; older OR builds without TransitionEngine cannot
            // honour it. Surface this so the issue is visible — never
            // silently write status directly.
            $this->logger->warning(
                'OpenBuilt export: OR TransitionEngine unavailable — '
                .'lifecycle transition "'.$action.'" SKIPPED on job '.$jobUuid.'. '
                .'Bump OpenRegister to >= the build that ships '
                .'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine.'
            );
            return false;
        }

        try {
            $engine = $this->container->get($engineClass);
            if (method_exists($engine, 'transition') === false) {
                $this->logger->warning(
                    'OpenBuilt export: OR TransitionEngine present but '
                    .'transition() method missing — likely API drift.'
                );
                return false;
            }

            $engine->transition($jobUuid, $action);

            // Side fields (errorMessage, downloadUrl, …) are NOT part of the
            // transition itself; merge them via the standard ObjectService
            // save path so they go through validation but do not race with
            // the lifecycle field.
            if ($extraFields !== []) {
                $this->mergeJobFields(jobUuid: $jobUuid, fields: $extraFields);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'OpenBuilt export: lifecycle transition "'.$action.'" failed on job '
                .$jobUuid.': '.$e->getMessage()
            );
            return false;
        }//end try
    }//end transitionJob()

    /**
     * Merge side-fields onto an existing ExportJob record via OR.
     *
     * @param string              $jobUuid Job UUID.
     * @param array<string,mixed> $fields  Fields to merge (errorMessage,
     *                                     downloadUrl, downloadExpiresAt, …).
     *
     * @return void
     */
    public function mergeJobFields(string $jobUuid, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        try {
            if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
                return;
            }

            $service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
            if (method_exists($service, 'find') === false || method_exists($service, 'saveObject') === false) {
                return;
            }

            // Positional call: $service is untyped at this point.
            $existing = $service->find($jobUuid);
            if ($existing === null) {
                return;
            }

            // Defensive merge: never let callers overwrite `status` here —
            // that field is owned by the lifecycle engine.
            unset($fields['status'], $fields['uuid']);

            if (method_exists($existing, 'getObject') === true) {
                $data           = $existing->getObject() ?? [];
                $merged         = array_merge($data, $fields);
                $merged['uuid'] = $jobUuid;
                $service->saveObject($merged);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'OpenBuilt export: mergeJobFields failed on job '.$jobUuid.': '.$e->getMessage()
            );
        }//end try
    }//end mergeJobFields()

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
        $value = $this->credentialsManager->retrieve(Application::APP_ID, $this->credentialKey(jobUuid: $jobUuid));
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
            $this->credentialsManager->delete(Application::APP_ID, $this->credentialKey(jobUuid: $jobUuid));
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
