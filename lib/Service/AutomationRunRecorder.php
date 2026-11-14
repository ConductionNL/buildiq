<?php

/**
 * Buildiq AutomationRunRecorder
 *
 * Writes one automation-run record per run of a buildiq automation, and reads
 * the runs back for the runs endpoint and the prune job.
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
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The run log of buildiq's automations (logic-automation-run-log, design D1 to D3 and D5).
 *
 * Writes and reads run as the system (`_rbac: false`): the schema is
 * admin-only on OpenRegister's object API, a run is written while whoever
 * triggered the automation is signed in, and the runs endpoint has already
 * applied the automation's own rules before it reads. No input payload is
 * stored. A failed write is logged at error and never thrown.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */
class AutomationRunRecorder implements AutomationRunRecorderInterface {

	/**
	 * The most runs one read considers.
	 */
	private const READ_LIMIT = 1000;

	/**
	 * The trigger values the schema accepts; anything else is recorded as manual.
	 */
	private const TRIGGERS = ['object-created', 'object-updated', 'object-deleted', 'lifecycle-transition', 'schedule', 'manual'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister objects.
	 * @param ITimeFactory           $time          The clock.
	 * @param LoggerInterface        $logger        Logs a failed write.
	 * @param AutomationRunPage      $page          Orders, filters and summarises runs.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly AutomationRunPage $page = new AutomationRunPage(),
	) {
	}//end __construct()

	/**
	 * Begin a run.
	 *
	 * @param array<string, mixed>       $automation The automation.
	 * @param string                     $trigger    The trigger type.
	 * @param array<string, string>|null $subject    {schema, uuid}, or null.
	 * @param string                     $actor      The uid, or system.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function start(array $automation, string $trigger, ?array $subject, string $actor): array {
		$provenance = $automation['provenance'] ?? [];
		if (is_array($provenance) === false) {
			$provenance = [];
		}

		if (in_array($trigger, self::TRIGGERS, true) === false) {
			$trigger = 'manual';
		}

		return [
			'automation' => (string)($automation['id'] ?? ($automation['uuid'] ?? '')),
			'versionUuid' => (string)($automation['versionUuid'] ?? ''),
			'compiledHash' => (string)($provenance['compiledHash'] ?? ''),
			'trigger' => $trigger,
			'subject' => self::subjectOf(subject: $subject),
			'startedAt' => $this->now()->format(DATE_ATOM),
			'steps' => [],
			'actor' => self::actorOf(actor: $actor),
		];
	}//end start()

	/**
	 * Add one action's outcome.
	 *
	 * @param array<string, mixed> $run     The run.
	 * @param string               $action  The action.
	 * @param string               $outcome Its outcome.
	 * @param string               $message What happened.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function step(array $run, string $action, string $outcome, string $message = ''): array {
		$run['steps'][] = ['action' => $action, 'outcome' => $outcome, 'message' => $message];
		return $run;
	}//end step()

	/**
	 * Write the run.
	 *
	 * @param array<string, mixed> $run     The run.
	 * @param string               $outcome The outcome.
	 * @param string|null          $reason  Why, for failed and skipped.
	 *
	 * @return string|null The uuid, or null.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function finish(array $run, string $outcome, ?string $reason = null): ?string {
		$record = $run + ['steps' => []];
		$record['outcome']    = $outcome;
		$record['durationMs'] = $this->durationSince(startedAt: (string)($run['startedAt'] ?? ''));
		if ($reason !== null && $reason !== '') {
			$record['reason'] = $reason;
		}

		return $this->write(record: $record, uuid: null);
	}//end finish()

	/**
	 * Complete a waiting run.
	 *
	 * @param string                                                       $runUuid The waiting run.
	 * @param string                                                       $outcome The outcome.
	 * @param array<int, array{action: string, outcome: string, message?: string}> $steps The steps to add.
	 * @param string|null                                                  $reason  Why, for failed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function complete(string $runUuid, string $outcome, array $steps, ?string $reason = null): bool {
		$run = $this->read(uuid: $runUuid);
		if ($run === null || ($run['outcome'] ?? null) !== 'waiting') {
			$this->logger->warning('Buildiq: no waiting automation run ' . $runUuid . ' to complete');
			return false;
		}

		foreach ($steps as $step) {
			$run = $this->step(
				run: $run,
				action: (string)($step['action'] ?? ''),
				outcome: (string)($step['outcome'] ?? ''),
				message: (string)($step['message'] ?? '')
			);
		}

		$run['outcome']    = $outcome;
		$run['durationMs'] = $this->durationSince(startedAt: (string)($run['startedAt'] ?? ''));
		if ($reason !== null && $reason !== '') {
			$run['reason'] = $reason;
		}

		return $this->write(record: $run, uuid: $runUuid) !== null;
	}//end complete()

	/**
	 * The runs of one automation, newest first.
	 *
	 * @param string                $automationUuid The automation.
	 * @param array<string, string> $filters        outcome, since, until.
	 * @param int                   $limit          Page size.
	 * @param int                   $offset         Page start.
	 *
	 * @return array{
	 *     results: list<array<string, mixed>>,
	 *     total: int,
	 *     summary: array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 * }
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function runsOf(string $automationUuid, array $filters = [], int $limit = 25, int $offset = 0): array {
		$runs     = $this->allRunsOf(automationUuid: $automationUuid);
		$summary  = $this->page->summary(runs: $runs);
		$selected = $this->page->select(runs: $runs, filters: $filters);

		return [
			'results' => array_slice($selected, max(0, $offset), max(0, $limit)),
			'total' => count($selected),
			'summary' => $summary,
		];
	}//end runsOf()

	/**
	 * The last run of one automation.
	 *
	 * @param string $automationUuid The automation.
	 *
	 * @return array{lastRunAt: string|null, lastOutcome: string|null, lastError: string|null}
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
	 */
	public function summaryOf(string $automationUuid): array {
		return $this->page->summary(runs: $this->allRunsOf(automationUuid: $automationUuid));
	}//end summaryOf()

	/**
	 * Delete the runs older than the retention.
	 *
	 * @param int $now Unix time.
	 *
	 * @return int How many were deleted.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-old-runs-are-pruned-and-the-last-run-stays-req-bqrl-005
	 */
	public function prune(int $now): int {
		$cutoff  = gmdate('Y-m-d\TH:i:s', $now - (self::RETENTION_DAYS * 86400));
		$deleted = 0;
		foreach ($this->rows(filters: []) as $run) {
			$uuid    = (string)($run['id'] ?? '');
			$started = $this->page->startOf(run: $run);
			if ($uuid === '' || $started === '' || $started >= $cutoff) {
				continue;
			}

			try {
				$this->objectService->deleteObject(uuid: $uuid, register: self::REGISTER_SLUG, schema: self::SCHEMA_SLUG, _rbac: false, _multitenancy: false);
				$deleted++;
			} catch (Throwable $e) {
				$this->logger->warning('Buildiq: an automation run could not be deleted', ['uuid' => $uuid, 'exception' => $e->getMessage()]);
			}
		}

		return $deleted;
	}//end prune()

	/**
	 * Every run of one automation, newest first.
	 *
	 * @param string $automationUuid The automation.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function allRunsOf(string $automationUuid): array {
		if ($automationUuid === '') {
			return [];
		}

		return $this->page->newestFirst(runs: array_values(array_filter(
			$this->rows(filters: ['automation' => $automationUuid]),
			static fn (array $run): bool => ($run['automation'] ?? null) === $automationUuid
		)));
	}//end allRunsOf()

	/**
	 * The run rows matching the filters, as arrays with their id.
	 *
	 * @param array<string, string> $filters Property filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(array $filters): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER_SLUG, 'schema' => self::SCHEMA_SLUG] + $filters, 'limit' => self::READ_LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: automation runs could not be read', ['exception' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach (($found['results'] ?? $found) as $entry) {
			$rows[] = self::runOf(entry: $entry);
		}

		return array_values(array_filter($rows, static fn (array $row): bool => $row !== []));
	}//end rows()

	/**
	 * One run by uuid.
	 *
	 * @param string $uuid The run.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $uuid): ?array {
		try {
			$entry = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: self::SCHEMA_SLUG, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		$run = self::runOf(entry: $entry);
		if ($run === []) {
			return null;
		}

		return $run;
	}//end read()

	/**
	 * Save a run as the system.
	 *
	 * @param array<string, mixed> $record The run.
	 * @param string|null          $uuid   The run to replace, or null.
	 *
	 * @return string|null The uuid, or null when the write failed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private function write(array $record, ?string $uuid): ?string {
		unset($record['id'], $record['@self']);
		try {
			$saved = $this->objectService->saveObject(
				object: $record,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);

			return ObjectRecord::uuid(record: ObjectRecord::castArray(object: $saved)) ?? $uuid;
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: the run of automation ' . (string)($record['automation'] ?? '') . ' could not be recorded: ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}
	}//end write()

	/**
	 * A stored run as an array with its id, or [] when it is not one.
	 *
	 * @param mixed $entry What OpenRegister returned.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	private static function runOf(mixed $entry): array {
		$data = ObjectRecord::castArray(object: $entry);
		if ($data === []) {
			return [];
		}

		$id = (ObjectRecord::uuid(record: $data) ?? ($data['id'] ?? ''));
		unset($data['@self']);
		$data['id'] = (string)$id;

		return $data;
	}//end runOf()


	/**
	 * Who caused a run: the uid, or system when there is none.
	 *
	 * @param string $actor The uid.
	 *
	 * @return string
	 */
	private static function actorOf(string $actor): string {
		if ($actor === '') {
			return 'system';
		}

		return $actor;
	}//end actorOf()




	/**
	 * The subject as stored: {schema, uuid}, or null.
	 *
	 * @param array<string, string>|null $subject The subject.
	 *
	 * @return array{schema: string, uuid: string}|null
	 */
	private static function subjectOf(?array $subject): ?array {
		if ($subject === null || (string)($subject['uuid'] ?? '') === '') {
			return null;
		}

		return ['schema' => (string)($subject['schema'] ?? ''), 'uuid' => (string)$subject['uuid']];
	}//end subjectOf()

	/**
	 * Milliseconds from the start until now.
	 *
	 * @param string $startedAt The start.
	 *
	 * @return int
	 */
	private function durationSince(string $startedAt): int {
		$start = $this->page->utc(value: $startedAt);
		if ($start === null) {
			return 0;
		}

		$elapsed = $this->now()->getTimestamp() - (new DateTimeImmutable($start, new DateTimeZone('UTC')))->getTimestamp();
		return max(0, $elapsed * 1000);
	}//end durationSince()

	/**
	 * Now, from the clock.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
	}//end now()
}//end class
