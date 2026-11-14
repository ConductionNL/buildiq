<?php

/**
 * Contract of ExportJobService
 *
 * The public surface of ExportJobService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of ExportJobService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportJobServiceInterface {

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
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
	 */
	public function loadJob(string $jobUuid): ?array;

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
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-33
	 * @spec openspec/changes/archive/2026-10-07-data-registers-runtime/tasks.md#task-4.3
	 */
	public function queue(
		string $applicationSlug,
		array $payload,
		?string $requestedBy = null,
	): string;

	/**
	 * Resolve a download path for the given ExportJob UUID.
	 *
	 * @param string $uuid ExportJob UUID.
	 *
	 * @return array{path:string,expired:bool}|null Resolution result.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
	 */
	public function resolveDownload(string $uuid): ?array;

	/**
	 * Run a queued export now, in this request, instead of waiting for cron.
	 *
	 * The job is only run when it is still in the job list: that is the
	 * claim. It is removed from the list before it runs, so cron cannot pick
	 * it up a second time, and a job cron already took is not in the list.
	 *
	 * @param string $jobUuid ExportJob UUID.
	 *
	 * @return bool True when this call ran the job, false when it was no longer queued.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-is-asynchronous-via-nextcloud-s-ijob
	 */
	public function runNow(string $jobUuid): bool;

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
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-38
	 */
	public function transitionJob(
		string $jobUuid,
		string $action,
		array $extraFields = [],
	): bool;
}//end interface
