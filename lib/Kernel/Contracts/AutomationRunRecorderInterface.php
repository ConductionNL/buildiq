<?php

/**
 * Contract of AutomationRunRecorder
 *
 * Records each run of a buildiq automation and reads the runs back.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of AutomationRunRecorder.
 *
 * A run is built in memory with start() and step(), and written once by
 * finish(). A waiting run (an approval) is written by finish() and completed
 * later by complete(). Writing never throws: a failed write is logged and the
 * automation goes on.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */
interface AutomationRunRecorderInterface {

	public const REGISTER_SLUG = 'buildiq';

	public const SCHEMA_SLUG = 'automation-run';

	/**
	 * How long a run is kept, in days.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Begin a run.
	 *
	 * @param array<string, mixed>       $automation The automation (id or uuid, versionUuid, provenance).
	 * @param string                     $trigger    The trigger type.
	 * @param array<string, string>|null $subject    {schema, uuid} of the object, or null.
	 * @param string                     $actor      The uid that caused the trigger, or system.
	 *
	 * @return array<string, mixed> The run, not yet written.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function start(array $automation, string $trigger, ?array $subject, string $actor): array;

	/**
	 * Add one action's outcome to a run.
	 *
	 * @param array<string, mixed> $run     The run.
	 * @param string               $action  The action, as the maker named it.
	 * @param string               $outcome succeeded, failed, skipped, waiting or in-openregister.
	 * @param string               $message What happened, for a failure the error.
	 *
	 * @return array<string, mixed> The run with the step.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function step(array $run, string $action, string $outcome, string $message = ''): array;

	/**
	 * Write the run with its outcome.
	 *
	 * @param array<string, mixed> $run     The run.
	 * @param string               $outcome succeeded, failed, skipped or waiting.
	 * @param string|null          $reason  For failed and skipped: one sentence saying why.
	 *
	 * @return string|null The run's uuid, or null when it could not be written.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function finish(array $run, string $outcome, ?string $reason = null): ?string;

	/**
	 * Complete a waiting run: add its further steps and set its outcome.
	 *
	 * @param string                                  $runUuid The waiting run.
	 * @param string                                  $outcome succeeded or failed.
	 * @param array<int, array{action: string, outcome: string, message?: string}> $steps The steps to add.
	 * @param string|null                             $reason  For failed: why.
	 *
	 * @return bool Whether the run was found and written.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function complete(string $runUuid, string $outcome, array $steps, ?string $reason = null): bool;

	/**
	 * The runs of one automation, newest first, with its summary.
	 *
	 * @param string                $automationUuid The automation.
	 * @param array<string, string> $filters        outcome, since and until (dates or date-times).
	 * @param int                   $limit          At most this many runs.
	 * @param int                   $offset         Skip this many.
	 *
	 * @return array{
	 *     results: list<array<string, mixed>>,
	 *     total: int,
	 *     summary: array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 * }
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function runsOf(string $automationUuid, array $filters = [], int $limit = 25, int $offset = 0): array;

	/**
	 * The last run of one automation.
	 *
	 * @param string $automationUuid The automation.
	 *
	 * @return array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function summaryOf(string $automationUuid): array;

	/**
	 * Delete the runs that started more than RETENTION_DAYS before now.
	 *
	 * @param int $now Unix time of the run of the prune job.
	 *
	 * @return int How many runs were deleted.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-old-runs-are-pruned-and-the-last-run-stays-req-bqrl-005
	 */
	public function prune(int $now): int;
}//end interface
