<?php

/**
 * Unit tests for AutomationRunHistory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-notifications-runs-come-from-openregisters-record
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Service\AutomationRunHistory;
use OCA\Buildiq\Service\OpenRegisterAutomationOutcomeReader;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister's outcome records become runs; nothing is read or invented for an automation OpenRegister does not execute.
 */
final class AutomationRunHistoryTest extends TestCase {

	/**
	 * A notification automation on object creation.
	 *
	 * @var array<string, mixed>
	 */
	private const NOTIFYING = [
		'id' => '7f3c',
		'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
		'actions' => [['type' => 'send-notification']],
	];

	/**
	 * A sent notification for object obj-1.
	 *
	 * @var array<string, mixed>
	 */
	private const SENT = [
		'kind' => 'notification',
		'key' => 'aut-7f3c-1',
		'at' => '2026-11-24T09:30:00+00:00',
		'subject' => ['register' => '7', 'schema' => '12', 'id' => 'obj-1'],
		'outcome' => 'sent',
		'reason' => null,
		'recipients' => 2,
	];

	/**
	 * A failed notification for object obj-2, a day later.
	 *
	 * @var array<string, mixed>
	 */
	private const FAILED = [
		'kind' => 'notification',
		'key' => 'aut-7f3c-1',
		'at' => '2026-11-25T10:00:00+00:00',
		'subject' => ['register' => '7', 'schema' => '12', 'id' => 'obj-2'],
		'outcome' => 'failed',
		'reason' => 'mail server down',
		'recipients' => 0,
	];

	/**
	 * A reader answering these outcomes, recording the prefixes it was asked for.
	 *
	 * @param list<array<string, mixed>> $outcomes The outcomes.
	 * @param list<string>               $asked    The prefixes asked for (by reference).
	 *
	 * @return OpenRegisterAutomationOutcomeReader
	 */
	private function reader(array $outcomes, array &$asked = []): OpenRegisterAutomationOutcomeReader {
		$reader = $this->createMock(originalClassName: OpenRegisterAutomationOutcomeReader::class);
		$reader->method('outcomes')->willReturnCallback(
			static function (string $keyPrefix) use ($outcomes, &$asked): array {
				$asked[] = $keyPrefix;
				return $outcomes;
			}
		);
		return $reader;
	}//end reader()

	/**
	 * A run log holding one succeeded run of 20 November.
	 *
	 * @return AutomationRunRecorderInterface
	 */
	private function recorder(): AutomationRunRecorderInterface {
		$recorder = $this->createMock(originalClassName: AutomationRunRecorderInterface::class);
		$recorder->method('runsOf')->willReturn(
			['results' => [['id' => 'r1', 'startedAt' => '2026-11-20T10:00:00+00:00', 'outcome' => 'succeeded']], 'total' => 1, 'summary' => []]
		);
		return $recorder;
	}//end recorder()

	/**
	 * A sent notification is a succeeded run with one step and its recipients; a failed one carries its reason.
	 *
	 * @return void
	 */
	public function testANotificationOutcomeIsARun(): void {
		$asked = [];
		$runs  = new AutomationRunHistory(recorder: $this->recorder(), reader: $this->reader(outcomes: [self::SENT, self::FAILED], asked: $asked));

		$result = $runs->runsFor(automation: self::NOTIFYING);

		$this->assertSame(expected: ['aut-7f3c'], actual: $asked);
		$this->assertCount(expectedCount: 2, haystack: $result);
		$this->assertSame(expected: 'failed', actual: $result[0]['outcome']);
		$this->assertSame(expected: 'mail server down', actual: $result[0]['reason']);
		$this->assertSame(expected: 'succeeded', actual: $result[1]['outcome']);
		$this->assertArrayNotHasKey(key: 'reason', array: $result[1]);
		$this->assertSame(expected: 'openregister', actual: $result[1]['source']);
		$this->assertSame(expected: '7f3c', actual: $result[1]['automation']);
		$this->assertSame(expected: 'object-created', actual: $result[1]['trigger']);
		$this->assertSame(expected: ['schema' => 'permit', 'uuid' => 'obj-1'], actual: $result[1]['subject']);
		$this->assertSame(expected: '2026-11-24T09:30:00+00:00', actual: $result[1]['startedAt']);
		$this->assertNull(actual: $result[1]['durationMs']);
		$this->assertSame(
			expected: [['action' => 'send-notification', 'outcome' => 'succeeded', 'message' => '', 'recipients' => 2]],
			actual: $result[1]['steps']
		);
		$this->assertStringStartsWith(prefix: 'openregister:', string: $result[1]['id']);
		$this->assertNotSame(expected: $result[0]['id'], actual: $result[1]['id']);
	}//end testANotificationOutcomeIsARun()

	/**
	 * A lifecycle action that was done succeeded; partial is a failure; skipped stays skipped.
	 *
	 * @return void
	 */
	public function testLifecycleOutcomesAreMapped(): void {
		$automation = ['id' => '7f3c', 'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit'], 'actions' => [['type' => 'webhook']]];
		$lifecycle  = ['kind' => 'lifecycle', 'key' => 'aut-7f3c'];
		$runs       = new AutomationRunHistory(
			recorder: $this->recorder(),
			reader: $this->reader(
				outcomes: [
					$lifecycle + ['at' => '2026-11-24T09:00:00+00:00', 'subject' => ['id' => 'a'], 'outcome' => 'done', 'reason' => null],
					$lifecycle + ['at' => '2026-11-24T08:00:00+00:00', 'subject' => ['id' => 'b'], 'outcome' => 'partial', 'reason' => 'one of two'],
					$lifecycle + ['at' => '2026-11-24T07:00:00+00:00', 'subject' => ['id' => 'c'], 'outcome' => 'skipped', 'reason' => 'condition is false'],
				]
			)
		);

		$result = $runs->runsFor(automation: $automation);

		$this->assertSame(expected: ['succeeded', 'failed', 'skipped'], actual: array_column($result, 'outcome'));
		$this->assertSame(expected: [['action' => 'lifecycle-action', 'outcome' => 'failed', 'message' => 'one of two']], actual: $result[1]['steps']);
		$this->assertSame(expected: 'condition is false', actual: $result[2]['reason']);
	}//end testLifecycleOutcomesAreMapped()

	/**
	 * An outcome of another automation whose uuid merely shares the prefix is left out.
	 *
	 * @return void
	 */
	public function testAnotherAutomationsOutcomeIsLeftOut(): void {
		$runs = new AutomationRunHistory(recorder: $this->recorder(), reader: $this->reader(outcomes: [['key' => 'aut-7f3cd-1'] + self::SENT]));

		$this->assertSame(expected: [], actual: $runs->runsFor(automation: self::NOTIFYING));
	}//end testAnotherAutomationsOutcomeIsLeftOut()

	/**
	 * A manual automation, one without an action OpenRegister executes, one without an id, and no reader are never looked up.
	 *
	 * @return void
	 */
	public function testOpenRegisterIsAskedOnlyForWhatItExecutes(): void {
		$asked  = [];
		$runs   = new AutomationRunHistory(recorder: $this->recorder(), reader: $this->reader(outcomes: [self::SENT], asked: $asked));
		$notify = [['type' => 'send-notification']];

		$this->assertSame(expected: [], actual: $runs->runsFor(automation: ['id' => 'm', 'trigger' => ['type' => 'manual'], 'actions' => $notify]));
		$this->assertSame(
			expected: [],
			actual: $runs->runsFor(automation: ['id' => 'g', 'trigger' => ['type' => 'object-created'], 'actions' => [['type' => 'generate-document']]])
		);
		$this->assertSame(expected: [], actual: $runs->runsFor(automation: ['trigger' => ['type' => 'object-created'], 'actions' => $notify]));
		$this->assertSame(expected: [], actual: $runs->runsFor(automation: ['id' => 'b', 'trigger' => 'broken', 'actions' => 'broken']));
		$this->assertSame(expected: [], actual: $asked);
		$this->assertSame(expected: [], actual: (new AutomationRunHistory(recorder: $this->recorder()))->runsFor(automation: self::NOTIFYING));
	}//end testOpenRegisterIsAskedOnlyForWhatItExecutes()

	/**
	 * Recorded and OpenRegister runs are paged together, newest first, filtered, with the summary of both.
	 *
	 * @return void
	 */
	public function testThePageMergesBothSources(): void {
		$runs = new AutomationRunHistory(recorder: $this->recorder(), reader: $this->reader(outcomes: [self::FAILED, self::SENT]));

		$query = ['uuid' => '7f3c', 'filters' => [], 'limit' => 2, 'offset' => 0];
		$all   = $runs->pageFor(automation: self::NOTIFYING, query: $query);
		$this->assertSame(expected: ['2026-11-25T10:00:00+00:00', '2026-11-24T09:30:00+00:00'], actual: array_column($all['results'], 'startedAt'));
		$this->assertSame(expected: 3, actual: $all['total']);
		$this->assertSame(
			expected: ['lastRunAt' => '2026-11-25T10:00:00+00:00', 'lastOutcome' => 'failed', 'lastError' => 'mail server down'],
			actual: $all['summary']
		);

		$query     = ['uuid' => '7f3c', 'filters' => ['outcome' => 'succeeded'], 'limit' => 10, 'offset' => 1];
		$succeeded = $runs->pageFor(automation: self::NOTIFYING, query: $query);
		$this->assertSame(expected: ['r1'], actual: array_column($succeeded['results'], 'id'));
		$this->assertSame(expected: 2, actual: $succeeded['total']);
	}//end testThePageMergesBothSources()

	/**
	 * Without an OpenRegister record the run log's own page and summary are answered unchanged.
	 *
	 * @return void
	 */
	public function testWithoutARecordTheRunLogAnswersUnchanged(): void {
		$page     = ['results' => [['id' => 'r1']], 'total' => 1, 'summary' => ['lastRunAt' => 'x', 'lastOutcome' => 'failed', 'lastError' => 'boom']];
		$recorder = $this->createMock(originalClassName: AutomationRunRecorderInterface::class);
		$recorder->expects($this->once())->method('runsOf')->with('7f3c', ['outcome' => 'failed'], 25, 5)->willReturn($page);
		$recorder->expects($this->once())->method('summaryOf')->with('7f3c')->willReturn($page['summary']);
		$runs = new AutomationRunHistory(recorder: $recorder, reader: $this->reader(outcomes: []));

		$query = ['uuid' => '7f3c', 'filters' => ['outcome' => 'failed'], 'limit' => 25, 'offset' => 5];
		$this->assertSame(expected: $page, actual: $runs->pageFor(automation: self::NOTIFYING, query: $query));
		$this->assertSame(expected: $page['summary'], actual: $runs->summaryFor(automation: self::NOTIFYING, uuid: '7f3c'));
	}//end testWithoutARecordTheRunLogAnswersUnchanged()

	/**
	 * The last run counts OpenRegister's record when it is newer than the run log's.
	 *
	 * @return void
	 */
	public function testTheSummaryCountsOpenRegistersRecord(): void {
		$runs = new AutomationRunHistory(recorder: $this->recorder(), reader: $this->reader(outcomes: [self::SENT]));

		$this->assertSame(
			expected: ['lastRunAt' => '2026-11-24T09:30:00+00:00', 'lastOutcome' => 'succeeded', 'lastError' => null],
			actual: $runs->summaryFor(automation: self::NOTIFYING, uuid: '7f3c')
		);
	}//end testTheSummaryCountsOpenRegistersRecord()
}//end class
