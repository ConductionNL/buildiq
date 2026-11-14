<?php

/**
 * Buildiq AutomationRunPage
 *
 * Orders, filters and summarises the runs of one automation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The page of runs the runs endpoint answers (logic-automation-run-log, design D3).
 *
 * Pure: no storage and no clock. Runs are compared on their start time in
 * UTC, so a run written with another offset still sorts and filters right.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
 */
final class AutomationRunPage {

	/**
	 * The runs, newest first.
	 *
	 * @param list<array<string, mixed>> $runs The runs.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function newestFirst(array $runs): array {
		usort($runs, fn (array $one, array $two): int => strcmp($this->startOf(run: $two), $this->startOf(run: $one)));
		return $runs;
	}//end newestFirst()

	/**
	 * The runs that pass the outcome and period filters, in their order.
	 *
	 * @param list<array<string, mixed>> $runs    The runs.
	 * @param array<string, string>      $filters outcome, since, until.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function select(array $runs, array $filters): array {
		$outcome = (string)($filters['outcome'] ?? '');
		$since   = $this->utc(value: (string)($filters['since'] ?? ''));
		$until   = $this->utc(value: (string)($filters['until'] ?? ''));

		return array_values(array_filter(
			$runs,
			function (array $run) use ($outcome, $since, $until): bool {
				$started = $this->startOf(run: $run);
				return ($outcome === '' || ($run['outcome'] ?? null) === $outcome)
					&& ($since === null || $started >= $since)
					&& ($until === null || $started <= $until);
			}
		));
	}//end select()

	/**
	 * The summary of runs sorted newest first: the last run, and its reason when it failed.
	 *
	 * @param list<array<string, mixed>> $runs The runs, newest first.
	 *
	 * @return array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function summary(array $runs): array {
		$last = ($runs[0] ?? null);
		if ($last === null) {
			return ['lastRunAt' => null, 'lastOutcome' => null, 'lastError' => null];
		}

		$error = null;
		if (($last['outcome'] ?? null) === 'failed') {
			$error = (string)($last['reason'] ?? '');
		}

		return ['lastRunAt' => (string)($last['startedAt'] ?? ''), 'lastOutcome' => (string)($last['outcome'] ?? ''), 'lastError' => $error];
	}//end summary()

	/**
	 * When a run started, as a sortable UTC string; '' when it does not parse.
	 *
	 * @param array<string, mixed> $run The run.
	 *
	 * @return string Y-m-d\TH:i:s in UTC, or ''.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function startOf(array $run): string {
		return ($this->utc(value: (string)($run['startedAt'] ?? '')) ?? '');
	}//end startOf()

	/**
	 * A date or date-time as a sortable UTC string, or null when it does not parse.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function utc(string $value): ?string {
		if ($value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
		} catch (Throwable) {
			return null;
		}
	}//end utc()
}//end class
