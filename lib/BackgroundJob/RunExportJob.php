<?php

/**
 * Buildiq RunExportJob background job
 *
 * Picks up a queued ExportJob and walks it through running →
 * succeeded|failed. Honours the no-auto-retry rule (memory: crashes →
 * needs-input).
 *
 * @category BackgroundJob
 * @package  OCA\Buildiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-34
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\BackgroundJob;

use OCA\Buildiq\Kernel\Contracts\ExportAppContentBundlerInterface;
use OCA\Buildiq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ExportServiceInterface;
use OCA\Buildiq\Kernel\Contracts\GitHubPushServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that runs a single ExportJob to completion.
 *
 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-7.1
 */
class RunExportJob extends QueuedJob {
	/**
	 * The export work of one run.
	 *
	 * @var ExportJobPipeline
	 */
	private ExportJobPipeline $pipeline;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory (Nextcloud-injectable).
	 * @param ExportServiceInterface $exportService File-generation pipeline.
	 * @param ExportJobServiceInterface $exportJobService Job orchestration helper.
	 * @param GitHubPushServiceInterface $githubPushService GitHub delivery target.
	 * @param LoggerInterface $logger Logger.
	 * @param ExportAppContentBundlerInterface|null $contentBundler Finds the application and version the job names.
	 *                                                     Null exports the bare scaffold.
	 */
	public function __construct(
		ITimeFactory $time,
		private ExportServiceInterface $exportService,
		private ExportJobServiceInterface $exportJobService,
		private GitHubPushServiceInterface $githubPushService,
		private LoggerInterface $logger,
		private ?ExportAppContentBundlerInterface $contentBundler = null,
	) {
		parent::__construct(time: $time);
		$this->pipeline = new ExportJobPipeline(
			exportService: $this->exportService,
			exportJobService: $this->exportJobService,
			githubPushService: $this->githubPushService,
			logger: $this->logger,
			contentBundler: $this->contentBundler
		);
	}//end __construct()

	/**
	 * Execute the job.
	 *
	 * NEVER auto-retries — failures escalate via the ExportJob's
	 * status=failed + errorMessage. The GitHub phase holds no token: it hands the
	 * broker a credential UUID and the queueing user's UID, and the broker injects
	 * the secret. There is nothing to clear on a terminal state.
	 *
	 * @param mixed $argument Job argument injected by Nextcloud:
	 *                        ['jobUuid' => string].
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
	 */
	#[\Override]
	protected function run($argument): void {
		$jobUuid = $this->extractJobUuid(argument: $argument);
		if ($jobUuid === '') {
			$this->logger->error('Buildiq RunExportJob: missing jobUuid argument');
			return;
		}

		// The job can be started twice: by the export dialog right away and by
		// cron. Only a job that is still queued runs; a record without a status
		// predates the field and counts as queued.
		if ($this->isStillQueued(jobUuid: $jobUuid) === false) {
			$this->logger->info('Buildiq RunExportJob: job already picked up, skipping', ['jobUuid' => $jobUuid]);
			return;
		}

		// Lifecycle transition: queued → running (declarative, via OR
		// TransitionEngine). The schema's `x-openregister-lifecycle.transitions`
		// entry named "start" drives this; we never write `status` directly.
		$this->exportJobService->transitionJob(jobUuid: $jobUuid, action: 'start');

		try {
			$this->pipeline->execute(jobUuid: $jobUuid);
		} catch (\Throwable $e) {
			// No-auto-retry: fire the declarative 'fail' transition, merge
			// an errorMessage onto the record, and leave it for the user
			// (memory: crashes → needs-input).
			$this->logger->error(
				'Buildiq export failed',
				['jobUuid' => $jobUuid, 'error' => $e->getMessage()]
			);
			$this->exportJobService->transitionJob(
				jobUuid: $jobUuid,
				action: 'fail',
				extraFields: ['errorMessage' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Run the export for one job now, outside cron.
	 *
	 * Used when the export dialog starts a job right away. The caller removes
	 * the job from the job list first; run() still skips a job that is no
	 * longer queued.
	 *
	 * @param string $jobUuid Job UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-is-asynchronous-via-nextcloud-s-ijob
	 */
	public function runFor(string $jobUuid): void {
		$this->run(argument: ['jobUuid' => $jobUuid]);
	}//end runFor()

	/**
	 * Whether the job is still waiting to run.
	 *
	 * @param string $jobUuid Job UUID.
	 *
	 * @return bool False only when the record says it left the queued state.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-is-asynchronous-via-nextcloud-s-ijob
	 */
	private function isStillQueued(string $jobUuid): bool {
		$job = $this->exportJobService->loadJob(jobUuid: $jobUuid);
		$status = (string)($job['status'] ?? 'queued');

		return $status === 'queued';
	}//end isStillQueued()

	/**
	 * Pull the job UUID from the Nextcloud job argument.
	 *
	 * @param mixed $argument Job argument.
	 *
	 * @return string Job UUID, '' when missing/malformed.
	 */
	private function extractJobUuid($argument): string {
		if (is_array($argument) === true && isset($argument['jobUuid']) === true) {
			return (string)$argument['jobUuid'];
		}

		return '';
	}//end extractJobUuid()
}//end class
