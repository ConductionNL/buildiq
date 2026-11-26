<?php

/**
 * Buildiq AutomationOutcomeRuns
 *
 * Turns what OpenRegister recorded for an automation's notifications and
 * lifecycle actions into runs of the run log, and pages them together with
 * the runs buildiq recorded itself.
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
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;

/**
 * The runs OpenRegister executed for an automation (logic-automation-run-log, design D4).
 *
 * Each outcome record is one run with one step. Nothing is invented: an
 * automation OpenRegister recorded nothing for answers the run log's own page,
 * unchanged. Without a reader (no OpenRegister outcome records) it is only the
 * run log.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */
final class AutomationOutcomeRuns {

	/**
	 * Where a run read from OpenRegister comes from.
	 */
	public const SOURCE = 'openregister';

	/**
	 * The step of a notification run.
	 */
	public const NOTIFICATION_STEP = 'send-notification';

	/**
	 * The step of a lifecycle action run.
	 */
	public const LIFECYCLE_STEP = 'lifecycle-action';

	/**
	 * The most recorded runs merged with OpenRegister's (the recorder's own read bound).
	 */
	public const RECORDED_LIMIT = 1000;

	/**
	 * The action types OpenRegister executes and records on a non-manual trigger.
	 */
	private const RECORDED_IN_OPENREGISTER = ['send-notification', 'webhook', 'object-op'];

	/**
	 * OpenRegister's outcomes in the run log's terms.
	 */
	private const OUTCOMES = [
		'sent' => 'succeeded',
		'done' => 'succeeded',
		'partial' => 'failed',
		'failed' => 'failed',
		'skipped' => 'skipped',
	];

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterAutomationOutcomeReader|null $reader Reads OpenRegister's outcome records; null reads none.
	 * @param AutomationOutcomeKeys                    $keys   Builds and matches the outcome keys.
	 * @param AutomationRunPage                        $page   Orders, filters and summarises runs.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
	 */
	public function __construct(
		private readonly ?OpenRegisterAutomationOutcomeReader $reader = null,
		private readonly AutomationOutcomeKeys $keys = new AutomationOutcomeKeys(),
		private readonly AutomationRunPage $page = new AutomationRunPage(),
	) {
	}//end __construct()

	/**
	 * The runs OpenRegister recorded for the automation, newest first.
	 *
	 * OpenRegister is asked only when the automation has an action it executes:
	 * a notification, a webhook or an object operation on a trigger other than
	 * manual. Outcomes of another automation whose key merely shares the prefix
	 * are left out.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-notifications-runs-come-from-openregisters-record
	 */
	public function runsFor(array $automation): array {
		$key = $this->keys->forAutomation(automation: $automation);
		if ($this->reader === null || $key === '' || self::executesInOpenRegister(automation: $automation) === false) {
			return [];
		}

		$runs = [];
		foreach ($this->reader->outcomes(keyPrefix: $key) as $outcome) {
			if ($this->keys->belongsTo(key: (string)($outcome['key'] ?? ''), automationKey: $key) === false) {
				continue;
			}

			$runs[] = self::runOf(automation: $automation, outcome: $outcome);
		}

		return $this->page->newestFirst(runs: $runs);
	}//end runsFor()

	/**
	 * One page of an automation's runs: those the run log recorded and those OpenRegister recorded.
	 *
	 * @param AutomationRunRecorderInterface $recorder   The run log.
	 * @param array<string, mixed>           $automation The automation.
	 * @param array{uuid: string, filters: array<string, string>, limit: int, offset: int} $query The page asked for.
	 *
	 * @return array{
	 *     results: list<array<string, mixed>>,
	 *     total: int,
	 *     summary: array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 * }
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-notifications-runs-come-from-openregisters-record
	 */
	public function pageFor(AutomationRunRecorderInterface $recorder, array $automation, array $query): array {
		$outcomeRuns = $this->runsFor(automation: $automation);
		if ($outcomeRuns === []) {
			return $recorder->runsOf(automationUuid: $query['uuid'], filters: $query['filters'], limit: $query['limit'], offset: $query['offset']);
		}

		return $this->pageOf(
			runs: array_merge($recorder->runsOf(automationUuid: $query['uuid'], limit: self::RECORDED_LIMIT)['results'], $outcomeRuns),
			query: $query
		);
	}//end pageFor()

	/**
	 * The last run of an automation, over the run log's runs and OpenRegister's.
	 *
	 * @param AutomationRunRecorderInterface $recorder   The run log.
	 * @param array<string, mixed>           $automation The automation.
	 * @param string                         $uuid       The automation uuid.
	 *
	 * @return array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-notifications-runs-come-from-openregisters-record
	 */
	public function summaryFor(AutomationRunRecorderInterface $recorder, array $automation, string $uuid): array {
		$outcomeRuns = $this->runsFor(automation: $automation);
		if ($outcomeRuns === []) {
			return $recorder->summaryOf(automationUuid: $uuid);
		}

		$runs = array_merge($recorder->runsOf(automationUuid: $uuid, limit: self::RECORDED_LIMIT)['results'], $outcomeRuns);

		return $this->page->summary(runs: $this->page->newestFirst(runs: $runs));
	}//end summaryFor()

	/**
	 * One page of runs from both sources, newest first, filtered, with the summary of all.
	 *
	 * @param list<array<string, mixed>>                                         $runs  The runs of both sources.
	 * @param array{filters: array<string, string>, limit: int, offset: int} $query The page asked for.
	 *
	 * @return array{
	 *     results: list<array<string, mixed>>,
	 *     total: int,
	 *     summary: array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 * }
	 */
	private function pageOf(array $runs, array $query): array {
		$ordered  = $this->page->newestFirst(runs: $runs);
		$selected = $this->page->select(runs: $ordered, filters: $query['filters']);

		return [
			'results' => array_slice($selected, max(0, $query['offset']), max(0, $query['limit'])),
			'total' => count($selected),
			'summary' => $this->page->summary(runs: $ordered),
		];
	}//end pageOf()

	/**
	 * Whether OpenRegister executes and records an action of the automation.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return bool
	 */
	private static function executesInOpenRegister(array $automation): bool {
		$trigger = $automation['trigger'] ?? [];
		if (is_array($trigger) === false || (string)($trigger['type'] ?? 'manual') === 'manual') {
			return false;
		}

		$actions = $automation['actions'] ?? [];
		if (is_array($actions) === false) {
			return false;
		}

		foreach ($actions as $action) {
			if (is_array($action) === true && in_array((string)($action['type'] ?? ''), self::RECORDED_IN_OPENREGISTER, true) === true) {
				return true;
			}
		}

		return false;
	}//end executesInOpenRegister()

	/**
	 * One outcome as a run with one step.
	 *
	 * The subject keeps the automation's schema slug, so the dialog links the
	 * object the same way as for a run buildiq recorded. A notification's step
	 * carries how many people it reached.
	 *
	 * @param array<string, mixed> $automation The automation.
	 * @param array<string, mixed> $outcome    The outcome.
	 *
	 * @return array<string, mixed>
	 */
	private static function runOf(array $automation, array $outcome): array {
		$subject = $outcome['subject'] ?? [];
		if (is_array($subject) === false) {
			$subject = [];
		}

		$trigger    = (array)($automation['trigger'] ?? []);
		$kind       = (string)($outcome['kind'] ?? '');
		$stored     = (string)($outcome['outcome'] ?? '');
		$result     = (self::OUTCOMES[$stored] ?? $stored);
		$reason     = trim((string)($outcome['reason'] ?? ''));
		$objectId   = (string)($subject['id'] ?? '');
		$recordedAt = (string)($outcome['at'] ?? '');

		$step = ['action' => self::LIFECYCLE_STEP, 'outcome' => $result, 'message' => $reason];
		if ($kind === 'notification') {
			$step['action']     = self::NOTIFICATION_STEP;
			$step['recipients'] = (int)($outcome['recipients'] ?? 0);
		}

		$run = [
			'id' => self::SOURCE . ':' . sha1($kind . '|' . (string)($outcome['key'] ?? '') . '|' . $recordedAt . '|' . $objectId),
			'source' => self::SOURCE,
			'automation' => (string)($automation['id'] ?? ($automation['uuid'] ?? '')),
			'trigger' => (string)($trigger['type'] ?? ''),
			'subject' => ['schema' => (string)($trigger['schema'] ?? ''), 'uuid' => $objectId],
			'startedAt' => $recordedAt,
			'durationMs' => null,
			'outcome' => $result,
			'steps' => [$step],
		];
		if ($reason !== '' && $result !== 'succeeded') {
			$run['reason'] = $reason;
		}

		return $run;
	}//end runOf()
}//end class
