<?php

/**
 * Buildiq ExportJobPipeline
 *
 * The work of one export run: the ZIP, the optional GitHub push and the
 * succeed transition. RunExportJob owns the job lifecycle around it.
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
use OCA\Buildiq\Kernel\Values\ExportContents;
use OCA\Buildiq\Kernel\Values\ExportTarget;
use OCA\Buildiq\Kernel\Values\BrokerAccess;
use OCA\Buildiq\Kernel\Values\PushTarget;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs the export of one ExportJob record and marks it succeeded.
 *
 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-7.1
 */
final class ExportJobPipeline {
	/**
	 * Constructor.
	 *
	 * @param ExportServiceInterface $exportService File-generation pipeline.
	 * @param ExportJobServiceInterface $exportJobService Job orchestration helper.
	 * @param GitHubPushServiceInterface $githubPushService GitHub delivery target.
	 * @param LoggerInterface $logger Logger.
	 * @param ExportAppContentBundlerInterface|null $contentBundler Finds the application and version the job names.
	 *                                                     Null exports the bare scaffold.
	 */
	public function __construct(
		private readonly ExportServiceInterface $exportService,
		private readonly ExportJobServiceInterface $exportJobService,
		private readonly GitHubPushServiceInterface $githubPushService,
		private readonly LoggerInterface $logger,
		private readonly ?ExportAppContentBundlerInterface $contentBundler,
	) {
	}//end __construct()

	/**
	 * Run the inner pipeline (ZIP + optional GitHub push) + drive the
	 * succeed transition. Any thrown error escapes to the caller,
	 * RunExportJob::run(), which fails the job.
	 *
	 * @param string $jobUuid Job UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
	 * @spec openspec/changes/archive/2026-10-07-data-registers-runtime/tasks.md#task-4.3
	 */
	public function execute(string $jobUuid): void {
		// Load the queued ExportJob record so we have the real application
		// identity — uuid, version slug, and slug (= appId). The job was
		// persisted by ExportJobService::queue() before this background job
		// was dispatched, so it must exist in OR.
		$job = $this->exportJobService->loadJob(jobUuid: $jobUuid);
		if ($job === null) {
			throw new RuntimeException(
				sprintf('Buildiq RunExportJob: could not load ExportJob record for UUID %s', $jobUuid)
			);
		}

		$applicationUuid = (string)($job['applicationUuid'] ?? '');
		$applicationVersion = (string)($job['applicationVersion'] ?? '0.1.0');
		$applicationSlug = (string)($job['applicationSlug'] ?? 'exported-app');
		$license = (string)($job['license'] ?? 'EUPL-1.2');

		$dataRegisters = $this->listField(job: $job, key: 'dataRegisters');
		$flows = $this->listField(job: $job, key: 'flows');

		if ($applicationUuid === '') {
			throw new RuntimeException(
				sprintf('Buildiq RunExportJob: ExportJob %s has an empty applicationUuid', $jobUuid)
			);
		}

		$target = new ExportTarget(
			applicationUuid: $applicationUuid,
			versionSlug: $applicationVersion,
			jobUuid: $jobUuid,
			// The application's own slug IS the agent lookup: agents carry
			// `applicationSlug`, so there is no agent binding to pass.
			applicationSlug: $applicationSlug
		);
		$source = $this->resolveSource(job: $job, target: $target);

		$context = $this->nameFromSource(source: $source, context: [
			'appId' => $applicationSlug,
			'appNamespace' => $this->slugToNamespace(slug: $applicationSlug),
			'appName' => $this->slugToLabel(slug: $applicationSlug),
			'appVersion' => $applicationVersion,
			'authorName' => 'Buildiq Citizen Developer',
			'authorEmail' => 'dev@conduction.nl',
			'license' => $license,
		]);

		$this->exportService->generateAppZip(
			target: $target,
			contents: new ExportContents(
				context: $context,
				dataRegisters: $dataRegisters,
				flows: $flows,
				source: $source
			),
			includeServerBundle: ($job['includeServerBundle'] ?? false) === true,
			mobileBundleId: $this->mobileBundleId(job: $job)
		);

		// Name what could not be resolved on the job itself. A skip that only
		// reaches the log reaches nobody: the operator reads the finished job.
		$skipped = $this->exportService->lastSkipped();

		$pushResult = $this->maybePush(jobUuid: $jobUuid, job: $job);

		$extra = $this->buildSuccessFields(jobUuid: $jobUuid, pushResult: $pushResult);
		if ($skipped !== []) {
			$extra['skipped'] = $skipped;
		}

		$extra = array_merge($extra, $this->contentLog());

		$this->exportJobService->transitionJob(jobUuid: $jobUuid, action: 'succeed', extraFields: $extra);
		$this->logger->info('Buildiq export succeeded', ['jobUuid' => $jobUuid]);
	}//end execute()

	/**
	 * An array field of the job record, or [] when it is absent or not an array.
	 *
	 * @param array<string, mixed> $job The ExportJob record.
	 * @param string               $key The field name.
	 *
	 * @return array<mixed>
	 */
	private function listField(array $job, string $key): array {
		if (is_array($job[$key] ?? null) === true) {
			return $job[$key];
		}

		return [];
	}//end listField()

	/**
	 * Find the application and version the job names.
	 *
	 * An export that cannot read its application would produce the empty
	 * template and call it a success, so that fails the job instead.
	 *
	 * @param array<string,mixed> $job The loaded ExportJob record.
	 * @param ExportTarget $target The application and the semver on the job.
	 *
	 * @return array{application: array<string,mixed>, version: array<string,mixed>}|null Null without a bundler.
	 *
	 * @throws RuntimeException When the application or its version cannot be found.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-targets-a-specific-application-version
	 */
	private function resolveSource(array $job, ExportTarget $target): ?array {
		if ($this->contentBundler === null) {
			return null;
		}

		$source = $this->contentBundler->resolveSource(
			applicationUuid: $target->applicationUuid,
			semver: $target->versionSlug,
			versionSlug: (string)($job['applicationVersionSlug'] ?? '')
		);

		if ($source === null) {
			throw new RuntimeException('The application to export could not be read.');
		}

		if ($source['version'] === []) {
			throw new RuntimeException('The application has no version to export.');
		}

		$source['includeSeedData'] = (($job['includeSeedData'] ?? false) === true);

		return $source;
	}//end resolveSource()

	/**
	 * Name the exported app after the application rather than its slug.
	 *
	 * @param array<string,mixed>|null $source From resolveSource().
	 * @param array<string,string> $context The placeholder context so far.
	 *
	 * @return array<string,string> The context with the application's name and description.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-exported-tree-shape-conforms-to-the-nextcloud-app-template-baseline
	 */
	private function nameFromSource(?array $source, array $context): array {
		$application = (array)($source['application'] ?? []);

		$name = trim((string)($application['name'] ?? ''));
		if ($name !== '') {
			$context['appName'] = $name;
		}

		$description = trim((string)($application['description'] ?? ''));
		if ($description !== '') {
			$context['appDescription'] = $description;
		}

		return $context;
	}//end nameFromSource()

	/**
	 * The job log line saying what went into the archive.
	 *
	 * @return array<string,array<int,string>> `['log' => [line]]`, or [] when nothing was bundled.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-is-asynchronous-via-nextcloud-s-ijob
	 */
	private function contentLog(): array {
		$content = $this->exportService->lastContent();
		if ($content === null) {
			return [];
		}

		return [
			'log' => [
				sprintf(
					'Exported %d pages, %d menu items, %d schemas and %d records.',
					$content['pages'],
					$content['menu'],
					$content['schemas'],
					$content['records']
				),
			],
		];
	}//end contentLog()

	/**
	 * Convert a kebab-case app slug to a PascalCase PHP namespace segment.
	 *
	 * E.g. `my-virtual-app` → `MyVirtualApp`.
	 *
	 * @param string $slug The application slug.
	 *
	 * @return string PascalCase namespace.
	 */
	private function slugToNamespace(string $slug): string {
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
	private function slugToLabel(string $slug): string {
		return ucwords(str_replace('-', ' ', $slug));
	}//end slugToLabel()

	/**
	 * Push to GitHub through the credential broker, if this is a GitHub export.
	 *
	 * The generated tree lives in the exporter's work scratch dir keyed by
	 * job UUID (see ExportService::prepareScratchDir); we hand that to the
	 * GitHub push service so it can blob/tree/commit each file.
	 *
	 * `requestedBy` travels on the record because this is a cron-driven job with no
	 * HTTP session, and the broker's owner guard needs an identity to check the
	 * credential against. Without it there is nothing to authorise, so we fail the
	 * job rather than push unauthenticated.
	 *
	 * @param string $jobUuid Job UUID.
	 * @param array<string,mixed> $job Loaded ExportJob record.
	 *
	 * @return array{repoUrl?:string,pullRequestUrl?:string}|null
	 *
	 * @throws RuntimeException When a GitHub export has no broker credential.
	 *
	 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-6.2
	 */
	private function maybePush(string $jobUuid, array $job): ?array {
		if ((string)($job['target'] ?? 'zip') !== 'github') {
			return null;
		}

		$credentialId = (string)($job['githubCredentialId'] ?? '');
		if ($credentialId === '') {
			throw new RuntimeException(
				'Buildiq RunExportJob: a GitHub export needs a broker credential; '
				. 'ExportJob ' . $jobUuid . ' has none.'
			);
		}

		$requestedBy = (string)($job['requestedBy'] ?? '');
		if ($requestedBy === '') {
			$requestedBy = (string)($job['owner'] ?? '');
		}

		$actingUserId = null;
		if ($requestedBy !== '') {
			$actingUserId = $requestedBy;
		}

		$treeDir = $this->exportService->scratchTreeDir(jobUuid: $jobUuid);

		return $this->githubPushService->push(
			jobUuid: $jobUuid,
			treeDir: $treeDir,
			access: new BrokerAccess(credentialId: $credentialId, actingUserId: $actingUserId),
			target: new PushTarget(
				org: (string)($job['githubOrg'] ?? ''),
				repo: (string)($job['githubRepo'] ?? ''),
				visibility: (string)($job['githubVisibility'] ?? 'private')
			)
		);
	}//end maybePush()

	/**
	 * Assemble the side-fields merged on a successful run.
	 *
	 * @param string $jobUuid Job UUID.
	 * @param array{repoUrl?:string,pullRequestUrl?:string}|null $pushResult Result of maybePush().
	 *
	 * @return array<string,mixed>
	 */
	private function buildSuccessFields(string $jobUuid, ?array $pushResult): array {
		$extra = [
			'downloadUrl' => '/index.php/apps/buildiq/api/exports/' . $jobUuid . '/download',
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

	/**
	 * The bundle id the mobile app project is written with: null when the job asks for no project, empty for the default.
	 *
	 * @param array<string,mixed> $job The export job.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-28-pages-installable-offline-and-mobile/tasks.md#T08
	 */
	private function mobileBundleId(array $job): ?string {
		if (($job['includeMobileProject'] ?? false) !== true) {
			return null;
		}

		return (string)($job['mobileBundleId'] ?? '');
	}//end mobileBundleId()

}//end class
