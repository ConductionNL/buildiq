<?php

/**
 * Unit tests for AutomationOutcomeRuns.
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

use OCA\Buildiq\Kernel\Contracts\AutomationOutcomeReaderInterface;
use OCA\Buildiq\Service\AutomationOutcomeRuns;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister's outcome records become runs; nothing is read or invented for an automation OpenRegister does not execute.
 */
final class AutomationOutcomeRunsTest extends TestCase {

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
	 * A reader answering these outcomes, recording the prefixes it was asked for.
	 *
	 * @param list<array<string, mixed>> $outcomes The outcomes.
	 * @param list<string>               $asked    The prefixes asked for (by reference).
	 *
	 * @return AutomationOutcomeReaderInterface
	 */
	private function reader(array $outcomes, array &$asked = []): AutomationOutcomeReaderInterface {
		$reader = $this->createMock(AutomationOutcomeReaderInterface::class);
		$reader->method('outcomes')->willReturnCallback(static function (string $keyPrefix) use ($outcomes, &$asked): array {
			$asked[] = $keyPrefix;
			return $outcomes;
		});
		return $reader;
	}//end reader()

	/**
	 * A sent notification is a succeeded run with one step and its recipients; a failed one carries its reason.
	 *
	 * @return void
	 */
	public function testANotificationOutcomeIsARun(): void {
		$asked = [];
		$runs  = new AutomationOutcomeRuns(reader: $this->reader(outcomes: [
			['kind' => 'notification', 'key' => 'aut-7f3c-1', 'at' => '2026-11-24T09:30:00+00:00', 'subject' => ['register' => '7', 'schema' => '12', 'id' => 'obj-1'], 'outcome' => 'sent', 'reason' => null, 'recipients' => 2],
			['kind' => 'notification', 'key' => 'aut-7f3c-1', 'at' => '2026-11-25T10:00:00+00:00', 'subject' => ['register' => '7', 'schema' => '12', 'id' => 'obj-2'], 'outcome' => 'failed', 'reason' => 'mail server down', 'recipients' => 0],
		], asked: $asked));

		$result = $runs->runsFor(automation: self::NOTIFYING);

		$this->assertSame(['aut-7f3c'], $asked);
		$this->assertCount(2, $result);
		$this->assertSame('failed', $result[0]['outcome']);
		$this->assertSame('mail server down', $result[0]['reason']);
		$this->assertSame('succeeded', $result[1]['outcome']);
		$this->assertArrayNotHasKey('reason', $result[1]);
		$this->assertSame('openregister', $result[1]['source']);
		$this->assertSame('7f3c', $result[1]['automation']);
		$this->assertSame('object-created', $result[1]['trigger']);
		$this->assertSame(['schema' => 'permit', 'uuid' => 'obj-1'], $result[1]['subject']);
		$this->assertSame('2026-11-24T09:30:00+00:00', $result[1]['startedAt']);
		$this->assertNull($result[1]['durationMs']);
		$this->assertSame([['action' => 'send-notification', 'outcome' => 'succeeded', 'message' => '', 'recipients' => 2]], $result[1]['steps']);
		$this->assertStringStartsWith('openregister:', $result[1]['id']);
		$this->assertNotSame($result[0]['id'], $result[1]['id']);
	}//end testANotificationOutcomeIsARun()

	/**
	 * A lifecycle action that was done succeeded; partial is a failure; skipped stays skipped.
	 *
	 * @return void
	 */
	public function testLifecycleOutcomesAreMapped(): void {
		$automation = ['id' => '7f3c', 'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit'], 'actions' => [['type' => 'webhook']]];
		$runs       = new AutomationOutcomeRuns(reader: $this->reader(outcomes: [
			['kind' => 'lifecycle', 'key' => 'aut-7f3c', 'at' => '2026-11-24T09:00:00+00:00', 'subject' => ['id' => 'a'], 'outcome' => 'done', 'reason' => null],
			['kind' => 'lifecycle', 'key' => 'aut-7f3c', 'at' => '2026-11-24T08:00:00+00:00', 'subject' => ['id' => 'b'], 'outcome' => 'partial', 'reason' => 'one of two'],
			['kind' => 'lifecycle', 'key' => 'aut-7f3c', 'at' => '2026-11-24T07:00:00+00:00', 'subject' => ['id' => 'c'], 'outcome' => 'skipped', 'reason' => 'condition is false'],
		]));

		$result = $runs->runsFor(automation: $automation);

		$this->assertSame(['succeeded', 'failed', 'skipped'], array_column($result, 'outcome'));
		$this->assertSame([['action' => 'lifecycle-action', 'outcome' => 'failed', 'message' => 'one of two']], $result[1]['steps']);
		$this->assertSame('condition is false', $result[2]['reason']);
	}//end testLifecycleOutcomesAreMapped()

	/**
	 * An outcome of another automation whose uuid merely shares the prefix is left out.
	 *
	 * @return void
	 */
	public function testAnotherAutomationsOutcomeIsLeftOut(): void {
		$runs = new AutomationOutcomeRuns(reader: $this->reader(outcomes: [
			['kind' => 'notification', 'key' => 'aut-7f3cd-1', 'at' => '2026-11-24T09:30:00+00:00', 'subject' => ['id' => 'x'], 'outcome' => 'sent'],
		]));

		$this->assertSame([], $runs->runsFor(automation: self::NOTIFYING));
	}//end testAnotherAutomationsOutcomeIsLeftOut()

	/**
	 * A manual automation, one without an action OpenRegister executes, and one without an id are never looked up.
	 *
	 * @return void
	 */
	public function testOpenRegisterIsAskedOnlyForWhatItExecutes(): void {
		$asked = [];
		$runs  = new AutomationOutcomeRuns(reader: $this->reader(outcomes: [], asked: $asked));

		$this->assertSame([], $runs->runsFor(automation: ['id' => 'm'] + ['trigger' => ['type' => 'manual'], 'actions' => [['type' => 'send-notification']]]));
		$this->assertSame([], $runs->runsFor(automation: ['id' => 'g', 'trigger' => ['type' => 'object-created'], 'actions' => [['type' => 'generate-document']]]));
		$this->assertSame([], $runs->runsFor(automation: ['trigger' => ['type' => 'object-created'], 'actions' => [['type' => 'send-notification']]]));
		$this->assertSame([], $runs->runsFor(automation: ['id' => 'b', 'trigger' => 'broken', 'actions' => 'broken']));
		$this->assertSame([], $asked);
	}//end testOpenRegisterIsAskedOnlyForWhatItExecutes()

	/**
	 * Recorded and OpenRegister runs are paged together, newest first, filtered, with the summary of both.
	 *
	 * @return void
	 */
	public function testThePageMergesBothSources(): void {
		$runs     = new AutomationOutcomeRuns(reader: $this->reader(outcomes: []));
		$recorded = [
			['id' => 'r1', 'startedAt' => '2026-11-20T10:00:00+00:00', 'outcome' => 'succeeded'],
		];
		$outcomes = [
			['id' => 'o1', 'startedAt' => '2026-11-22T10:00:00+00:00', 'outcome' => 'failed', 'reason' => 'mail server down'],
			['id' => 'o2', 'startedAt' => '2026-11-18T10:00:00+00:00', 'outcome' => 'succeeded'],
		];

		$all = $runs->pageOf(recorded: $recorded, outcomeRuns: $outcomes, filters: [], limit: 2, offset: 0);
		$this->assertSame(['o1', 'r1'], array_column($all['results'], 'id'));
		$this->assertSame(3, $all['total']);
		$this->assertSame(['lastRunAt' => '2026-11-22T10:00:00+00:00', 'lastOutcome' => 'failed', 'lastError' => 'mail server down'], $all['summary']);

		$succeeded = $runs->pageOf(recorded: $recorded, outcomeRuns: $outcomes, filters: ['outcome' => 'succeeded'], limit: 10, offset: 1);
		$this->assertSame(['o2'], array_column($succeeded['results'], 'id'));
		$this->assertSame(2, $succeeded['total']);
	}//end testThePageMergesBothSources()
}//end class
