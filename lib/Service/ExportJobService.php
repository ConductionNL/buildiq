<?php

/**
 * Buildiq Export Job Service
 *
 * Orchestration helper between the HTTP controller and the OR-backed
 * ExportJob record + the imperative ExportService pipeline.
 *
 * It used to take custody of the user's GitHub Personal Access Token: Decision 3
 * had it stored under ICredentialsManager keyed by job UUID and deleted on every
 * terminal state. That is gone. The record now carries only `githubCredentialId`
 * — a UUID pointing at a credential in OpenRegister's broker — so there is no
 * Buildiq-held secret left to store, to forget to delete, or to leak.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-38
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\BackgroundJob\IJobList;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bridges the ExportsController to the OR ExportJob record + RunExportJob.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
 */
class ExportJobService {
	/**
	 * OR register slug hosting the ExportJob schema (#104).
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * OR schema SLUG for the ExportJob record (#104). NOTE: this is
	 * NOT the same as the schema's JSON key (`exportJob` in
	 * openbuild_register.json) — the declared `slug` field is
	 * kebab-cased to `export-job`. `saveObject()`/`find()` resolve
	 * register/schema by SLUG, so the kebab-case form is the one that
	 * MUST be used here (and everywhere else a caller addresses this
	 * schema, e.g. src/manifest.json's Exports page and
	 * ExportJobsList.vue's OR REST fetch).
	 */
	public const EXPORT_JOB_SCHEMA = 'export-job';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Container — used to lazily fetch OR
	 *                                      services.
	 * @param IJobList $jobList Background job list.
	 * @param LoggerInterface $logger Logger.
	 * @param JobOwnerImpersonator $jobOwnerImpersonator Impersonates the ExportJob owner for the
	 *                                                   duration of a lifecycle transition (#105) —
	 *                                                   background jobs run with no HTTP session.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private JobOwnerImpersonator $jobOwnerImpersonator,
	) {
	}//end __construct()

	/**
	 * Create an ExportJob record in OR and schedule the background job.
	 *
	 * No secret is accepted or stored here. A GitHub export carries only
	 * `githubCredentialId` — a broker credential UUID — plus `requestedBy`, the UID
	 * the session-less background job hands the broker as the claimed owner.
	 *
	 * @param string $applicationSlug Source Application slug.
	 * @param array<string,mixed> $payload Sanitised payload.
	 * @param string|null $requestedBy UID of the queueing user.
	 *
	 * @return string Job UUID (UUIDv4).
	 *
	 * @throws \InvalidArgumentException When required fields are missing.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.3
	 */
	public function queue(
		string $applicationSlug,
		array $payload,
		?string $requestedBy = null,
	): string {
		$jobUuid = $this->uuid4();
		$target = (string)($payload['target'] ?? 'zip');

		$githubOrg = null;
		$githubRepo = null;
		$githubVisibility = 'private';
		if (isset($payload['githubOrg']) === true) {
			$githubOrg = (string)$payload['githubOrg'];
		}

		if (isset($payload['githubRepo']) === true) {
			$githubRepo = (string)$payload['githubRepo'];
		}

		if (isset($payload['githubVisibility']) === true) {
			$githubVisibility = (string)$payload['githubVisibility'];
		}

		$job = [
			'uuid' => $jobUuid,
			'applicationSlug' => $applicationSlug,
			'applicationUuid' => (string)($payload['applicationUuid'] ?? ''),
			'applicationVersion' => (string)($payload['applicationVersion'] ?? ''),
			'target' => $target,
			'status' => 'queued',
			'githubOrg' => $githubOrg,
			'githubRepo' => $githubRepo,
			'githubVisibility' => $githubVisibility,
			// A broker credential UUID, not a token — safe on the record by construction.
			'githubCredentialId' => (string)($payload['githubCredentialId'] ?? ''),
			'requestedBy' => (string)($requestedBy ?? ''),
			'includeSeedData' => (bool)($payload['includeSeedData'] ?? false),
			'dataRegisters' => $this->sanitiseDataRegisters(raw: $payload['dataRegisters'] ?? []),
			'flows' => $this->sanitiseFlows(raw: $payload['flows'] ?? []),
			'license' => (string)($payload['license'] ?? 'EUPL-1.2'),
			'log' => [],
		];

		$this->persistJob(job: $job);
		$this->jobList->add(
			\OCA\Buildiq\BackgroundJob\RunExportJob::class,
			['jobUuid' => $jobUuid]
		);

		return $jobUuid;
	}//end queue()

	/**
	 * Normalise the submit request's `dataRegisters` choice onto the shape
	 * `{register: string, includeData: bool}` — mirrors the existing
	 * `includeSeedData` boolean-cast pattern above. Malformed entries (not
	 * an array, or missing/empty `register`) are dropped rather than
	 * rejected — no existence validation of the referenced register is
	 * performed here (matches the head spec's own Non-Goal for a dangling
	 * `Application.dataRegisters[].register` slug).
	 *
	 * @param mixed $raw The request payload's `dataRegisters` value.
	 *
	 * @return array<int,array{register:string,includeData:bool}>
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.3
	 */
	private function sanitiseDataRegisters(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$register = (string)($entry['register'] ?? '');
			if ($register === '') {
				continue;
			}

			$out[] = [
				'register' => $register,
				'includeData' => (bool)($entry['includeData'] ?? false),
			];
		}

		return $out;
	}//end sanitiseDataRegisters()

	/**
	 * Normalise the submit request's `flows` choice.
	 *
	 * Mirrors `sanitiseDataRegisters()`: same defensive shape, because this is
	 * the same untrusted request payload arriving by the same route.
	 *
	 * Only the UUID is kept. `label` is a builder-UI convenience and has no
	 * meaning to the exporter, which resolves the flow and writes the flow's
	 * own name into the bundle.
	 *
	 * No sibling `sanitiseAgents()` exists on purpose: agents carry
	 * `applicationSlug` and are found by asking which agents point at the
	 * application, so there is no agent choice in the payload to sanitise.
	 *
	 * @param mixed $raw The request payload's `flows` value.
	 *
	 * @return array<int, array{flow: string}> Normalised bindings.
	 */
	private function sanitiseFlows(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$flow = trim((string)($entry['flow'] ?? ''));
			if ($flow === '') {
				continue;
			}

			$out[] = ['flow' => $flow];
		}

		return $out;
	}//end sanitiseFlows()

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
	 * Fixes #104 (persist/load mismatch): the previous call omitted BOTH
	 * `register`/`schema` (so `ObjectService::saveObject()` relied on
	 * whatever register/schema context an EARLIER call in the same request
	 * left behind — e.g. `ExportsController::isAuthorisedForApplication()`'s
	 * `searchObjectsBySlug('buildiq', 'application', ...)` call re-anchors
	 * that ambient state to schema=`application`, which does not accept an
	 * ExportJob payload's shape) AND `uuid` (so `extractUuidAndNormalizeObject()`
	 * — which only recognises `@self.id`/`id`, not our own `uuid` data field —
	 * never saw it, and OR silently auto-generated its OWN identity for the
	 * row, disconnected from the `$jobUuid` returned to the caller and handed
	 * to the background job). Either gap alone means the record OR actually
	 * persists is unreachable by `loadJob($jobUuid)` afterwards — the
	 * background job's "could not load ExportJob record" failure.
	 *
	 * @param array<string,mixed> $job Sanitised job record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
	 */
	public function persistJob(array $job): void {
		try {
			// ADR-083 rule 1: the availability check spelled in the idiom the
			// fleet recognises. Not a behaviour change — NC's
			// `SimpleContainer::has()` IS `isset(...) || class_exists($id)`
			// (server/lib/private/AppFramework/Utility/SimpleContainer.php:50).
			if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
				$this->logger->info('Buildiq export job persisted (logger fallback): ' . $job['uuid']);
				return;
			}

			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			if (method_exists($service, 'saveObject') === true) {
				$explicitUuid = null;
				if (isset($job['uuid']) === true && is_string($job['uuid']) === true && $job['uuid'] !== '') {
					$explicitUuid = $job['uuid'];
				}

				$service->saveObject(
					$job,
					register: self::REGISTER_SLUG,
					schema: self::EXPORT_JOB_SCHEMA,
					uuid: $explicitUuid
				);
			}
		} catch (\Throwable $e) {
			// Loud, not lossy. This used to warn and return, so queue() went on to
			// schedule the background job and hand the caller a 202 with a job UUID for
			// a record that did not exist — the job then failed to load it and died, and
			// the user saw an export that had simply vanished. If we cannot record the
			// job, the submit has not succeeded and the caller must be told.
			$this->logger->error('Could not persist ExportJob to OR: ' . $e->getMessage());
			throw new RuntimeException('Could not record the export job: ' . $e->getMessage(), 0, $e);
		}//end try
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
	 * Background-job impersonation (#105): RunExportJob drives this
	 * transition from a Nextcloud QueuedJob, which runs with NO HTTP
	 * session. TransitionEngine::transition() resolves the acting user
	 * from IUserSession and gates the mutation on
	 * PermissionHandler::hasPermission('update', ...); the export-job
	 * schema's `authorization.update` is admin-only
	 * (lib/Settings/openbuild_register.json), and PermissionHandler fails
	 * closed for an anonymous (null) caller on write actions. Left
	 * unaddressed, EVERY export would fail its `start` transition with a
	 * permission denial before the pipeline even ran. {@see
	 * JobOwnerImpersonator} resolves the ExportJob's owner (stamped
	 * automatically by ObjectService::saveObject()'s
	 * applyOwnerAttribution() at queue() time, from the submitting user's
	 * real HTTP session) and impersonates them for the duration of the
	 * transition — PermissionHandler grants an object's owner full access
	 * to their own object regardless of the schema's group-based
	 * authorization, so this succeeds without relaxing admin-only
	 * authorization for any other caller. Mirrors hermiq's
	 * ScheduleService::runAgentAsOwner() impersonation pattern.
	 *
	 * @param string $jobUuid ExportJob UUID.
	 * @param string $action Transition action name
	 *                       ('start', 'succeed', 'fail').
	 * @param array<string,mixed> $extraFields Optional fields to merge
	 *                                         alongside the transition
	 *                                         (e.g. errorMessage on 'fail',
	 *                                         downloadUrl on 'succeed').
	 *
	 * @return bool True when the transition fired, false when OR's
	 *              lifecycle engine is not available (gap recorded).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-38
	 */
	public function transitionJob(
		string $jobUuid,
		string $action,
		array $extraFields = [],
	): bool {
		$engineClass = 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine';

		if ($this->container->has($engineClass) === false) {
			// Documented gap: spec REQ-OBEX-006 calls for declarative
			// lifecycle; older OR builds without TransitionEngine cannot
			// honour it. Surface this so the issue is visible — never
			// silently write status directly.
			$this->logger->warning(
				'Buildiq export: OR TransitionEngine unavailable — '
				. 'lifecycle transition "' . $action . '" SKIPPED on job ' . $jobUuid . '. '
				. 'Bump OpenRegister to >= the build that ships '
				. 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine.'
			);
			return false;
		}

		try {
			$engine = $this->container->get($engineClass);
			if (method_exists($engine, 'transition') === false) {
				$this->logger->warning(
					'Buildiq export: OR TransitionEngine present but '
					. 'transition() method missing — likely API drift.'
				);
				return false;
			}

			return $this->jobOwnerImpersonator->runAsOwner(
				objectId: $jobUuid,
				work: function () use ($engine, $jobUuid, $action, $extraFields): bool {
					$engine->transition($jobUuid, $action);

					// Side fields (errorMessage, downloadUrl, …) are NOT
					// part of the transition itself; merge them via the
					// standard ObjectService save path so they go through
					// validation but do not race with the lifecycle
					// field. Still impersonated here, so this write is
					// authorised the same way.
					if ($extraFields !== []) {
						$this->mergeJobFields(jobUuid: $jobUuid, fields: $extraFields);
					}

					return true;
				}
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq export: lifecycle transition "' . $action . '" failed on job '
				. $jobUuid . ': ' . $e->getMessage()
			);
			return false;
		}//end try
	}//end transitionJob()

	/**
	 * Merge side-fields onto an existing ExportJob record via OR.
	 *
	 * @param string $jobUuid Job UUID.
	 * @param array<string,mixed> $fields Fields to merge (errorMessage,
	 *                                    downloadUrl, downloadExpiresAt, …).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
	 */
	public function mergeJobFields(string $jobUuid, array $fields): void {
		if ($fields === []) {
			return;
		}

		try {
			// See persistJob() for why this is class_exists() rather than
			// container->has() — same question, ADR-083 rule 1 idiom.
			if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
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
				$data = $existing->getObject() ?? [];
				$merged = array_merge($data, $fields);
				$merged['uuid'] = $jobUuid;
				// #104: explicit register/schema/uuid — see persistJob()'s
				// docblock for why omitting these silently misfiles (or
				// outright drops) the write instead of updating this SAME
				// existing record.
				$service->saveObject(
					$merged,
					register: self::REGISTER_SLUG,
					schema: self::EXPORT_JOB_SCHEMA,
					uuid: $jobUuid
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Buildiq export: mergeJobFields failed on job ' . $jobUuid . ': ' . $e->getMessage()
			);
		}//end try
	}//end mergeJobFields()

	/**
	 * Resolve a download path for the given ExportJob UUID.
	 *
	 * @param string $uuid ExportJob UUID.
	 *
	 * @return array{path:string,expired:bool}|null Resolution result.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
	 */
	public function resolveDownload(string $uuid): ?array {
		// Look for the ZIP in the deterministic location.
		$candidate = sys_get_temp_dir() . '/buildiq-exports/' . $uuid . '.zip';
		if (file_exists($candidate) === false) {
			return null;
		}

		// No expiry record in fallback path — treat as fresh.
		return [
			'path' => $candidate,
			'expired' => false,
		];
	}//end resolveDownload()

	// The fetchPat()/clearPat()/credentialKey() trio was removed with the PAT itself.
	// There is no Buildiq-held GitHub secret any more, so there is nothing to fetch,
	// nothing to remember to delete on a terminal state, and no key to build.

	/**
	 * Load an ExportJob record from OR by its UUID.
	 *
	 * Returns the job data as an array, or null when the record cannot be
	 * found (OR unavailable, or unknown UUID). Callers should treat null
	 * as a fatal-for-this-run condition.
	 *
	 * @param string $jobUuid ExportJob UUID.
	 *
	 * @return array<string, mixed>|null Job data, or null on failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
	 */
	public function loadJob(string $jobUuid): ?array {
		try {
			if ($this->container->has('OCA\\OpenRegister\\Service\\ObjectService') === false) {
				return null;
			}

			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			if (method_exists($service, 'find') === false) {
				return null;
			}

			$object = $service->find($jobUuid);
			if ($object === null) {
				return null;
			}

			if (method_exists($object, 'getObject') === true) {
				$data = $object->getObject() ?? [];
				if (is_array($data) === true) {
					return $data;
				}

				return null;
			}

			// Some OR versions return the array directly.
			if (is_array($object) === true) {
				return $object;
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Buildiq ExportJobService: loadJob failed for job ' . $jobUuid . ': ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end loadJob()

	/**
	 * Generate a UUIDv4.
	 *
	 * Fixes #104: the previous implementation split the 32 hex digits into
	 * eight 4-char groups (`str_split($hex, 4)`) but `vsprintf()` only
	 * consumes the first FIVE of a format string's `%s` placeholders,
	 * silently discarding the remaining three groups — the returned string
	 * was five 4-char groups (20 hex digits) instead of the canonical
	 * 8-4-4-4-12 (32 hex digits). The version/variant nibble-setting above
	 * was already correct; only the grouping was malformed.
	 *
	 * @return string UUIDv4 in canonical 8-4-4-4-12 form.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
	 */
	public function uuid4(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
		$hex = bin2hex($data);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}//end uuid4()
}//end class
