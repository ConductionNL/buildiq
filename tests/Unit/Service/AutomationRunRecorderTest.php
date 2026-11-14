<?php

/**
 * Unit tests for AutomationRunRecorder.
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
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationRunPage;
use OCA\Buildiq\Service\AutomationRunRecorder;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One record per run, with its steps and outcome, never thrown, read back newest first and pruned after 90 days.
 */
#[CoversClass(AutomationRunRecorder::class)]
#[UsesClass(AutomationRunPage::class)]
#[UsesClass(ObjectRecord::class)]
final class AutomationRunRecorderTest extends TestCase {

	/**
	 * 2026-11-14T10:00:00Z.
	 */
	private const NOW = 1794650400;

	private const AUTOMATION = ['id' => 'aut-1', 'versionUuid' => 'v-1', 'provenance' => ['compiledHash' => 'sha256:abc']];

	/**
	 * OpenRegister objects.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The current time the clock answers.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An object entity serialising to this payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * The recorder under test.
	 *
	 * @return AutomationRunRecorder
	 */
	private function recorder(): AutomationRunRecorder {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		return new AutomationRunRecorder($this->objects, $time, $this->logger);
	}//end recorder()

	/**
	 * A manual run whose second action fails is written once, failed, with both steps and its duration, and no payload.
	 *
	 * @return void
	 */
	public function testAFailingActionIsRecordedWithItsSteps(): void {
		$calls = [];
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(function (...$arguments) use (&$calls): ObjectEntity {
			$calls[] = $arguments;
			return $this->entity(['@self' => ['id' => 'run-1']]);
		});

		$recorder = $this->recorder();
		$run      = $recorder->start(automation: self::AUTOMATION, trigger: 'manual', subject: ['schema' => 'ticket', 'uuid' => 'T-1'], actor: 'anne');
		$run      = $recorder->step(run: $run, action: 'Notify the team', outcome: 'succeeded');
		$run      = $recorder->step(run: $run, action: 'Call the webhook', outcome: 'failed', message: 'Webhook answered 500');
		$this->now += 2;

		$this->assertSame('run-1', $recorder->finish(run: $run, outcome: 'failed', reason: 'Call the webhook failed.'));
		$this->assertSame([
			'automation' => 'aut-1',
			'versionUuid' => 'v-1',
			'compiledHash' => 'sha256:abc',
			'trigger' => 'manual',
			'subject' => ['schema' => 'ticket', 'uuid' => 'T-1'],
			'startedAt' => '2026-11-14T10:00:00+00:00',
			'steps' => [
				['action' => 'Notify the team', 'outcome' => 'succeeded', 'message' => ''],
				['action' => 'Call the webhook', 'outcome' => 'failed', 'message' => 'Webhook answered 500'],
			],
			'actor' => 'anne',
			'outcome' => 'failed',
			'durationMs' => 2000,
			'reason' => 'Call the webhook failed.',
		], $calls[0][0]);
		// Written as the system, in the run log schema, as a new record.
		$this->assertSame(['buildiq', 'automation-run', null, false, false], [$calls[0][2], $calls[0][3], $calls[0][4], $calls[0][5], $calls[0][6]]);
	}//end testAFailingActionIsRecordedWithItsSteps()

	/**
	 * A skipped run carries its reason; an unknown trigger reads as manual, a missing subject as none and no actor as system.
	 *
	 * @return void
	 */
	public function testASkippedRunCarriesItsReason(): void {
		$saved = null;
		$this->objects->method('saveObject')->willReturnCallback(function (array $object) use (&$saved): ObjectEntity {
			$saved = $object;
			return $this->entity(['uuid' => 'run-2']);
		});

		$recorder = $this->recorder();
		$uuid     = $recorder->finish(run: $recorder->start(automation: ['uuid' => 'aut-2'], trigger: 'weird', subject: ['schema' => 'x'], actor: ''), outcome: 'skipped', reason: 'The condition was false: prioriteit is laag.');

		$this->assertSame('run-2', $uuid);
		$this->assertSame(['aut-2', 'manual', null, 'system', 'skipped', 'The condition was false: prioriteit is laag.', []], [$saved['automation'], $saved['trigger'], $saved['subject'], $saved['actor'], $saved['outcome'], $saved['reason'], $saved['steps']]);
	}//end testASkippedRunCarriesItsReason()

	/**
	 * A failing write is logged at error, names the automation, and is not thrown.
	 *
	 * @return void
	 */
	public function testAFailingWriteDoesNotThrow(): void {
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('register gone'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('automation aut-1'));

		$recorder = $this->recorder();
		$this->assertNull($recorder->finish(run: $recorder->start(automation: self::AUTOMATION, trigger: 'manual', subject: null, actor: 'anne'), outcome: 'succeeded'));
	}//end testAFailingWriteDoesNotThrow()

	/**
	 * A waiting run is completed: its steps are added, its outcome set, the same record replaced.
	 *
	 * @return void
	 */
	public function testAWaitingRunIsCompletedByTheDecision(): void {
		$found = [];
		$this->objects->method('find')->willReturnCallback(function (...$arguments) use (&$found): ObjectEntity {
			$found[] = $arguments;
			return $this->entity([
				'@self' => ['id' => 'run-3'],
				'automation' => 'aut-1',
				'startedAt' => '2026-11-14T09:00:00+00:00',
				'outcome' => 'waiting',
				'steps' => [['action' => 'approval requested', 'outcome' => 'waiting', 'message' => '']],
			]);
		});
		$calls = [];
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(function (...$arguments) use (&$calls): ObjectEntity {
			$calls[] = $arguments;
			return $this->entity(['@self' => ['id' => 'run-3']]);
		});

		$done = $this->recorder()->complete(runUuid: 'run-3', outcome: 'succeeded', steps: [
			['action' => 'approved by lead', 'outcome' => 'succeeded'],
			['action' => 'Generate the letter', 'outcome' => 'succeeded', 'message' => 'letter.pdf'],
		]);

		$this->assertTrue($done);
		$this->assertSame(['run-3', 'buildiq', 'automation-run', false, false], [$found[0][0], $found[0][3], $found[0][4], $found[0][5], $found[0][6]]);
		$record = $calls[0][0];
		$this->assertSame('succeeded', $record['outcome']);
		$this->assertSame(3600000, $record['durationMs']);
		$this->assertSame(['approval requested', 'approved by lead', 'Generate the letter'], array_column($record['steps'], 'action'));
		$this->assertArrayNotHasKey('id', $record);
		$this->assertSame('run-3', $calls[0][4]);
	}//end testAWaitingRunIsCompletedByTheDecision()

	/**
	 * A run that is not waiting, or missing, is not completed.
	 *
	 * @return void
	 */
	public function testOnlyAWaitingRunIsCompleted(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls($this->entity(['@self' => ['id' => 'r'], 'outcome' => 'succeeded']), $this->throwException(new RuntimeException('gone')));
		$this->objects->expects($this->never())->method('saveObject');

		$recorder = $this->recorder();
		$this->assertFalse($recorder->complete(runUuid: 'r', outcome: 'failed', steps: []));
		$this->assertFalse($recorder->complete(runUuid: 'gone', outcome: 'failed', steps: []));
	}//end testOnlyAWaitingRunIsCompleted()

	/**
	 * The runs of one automation come newest first, filtered, paged, with the summary of the newest.
	 *
	 * @return void
	 */
	public function testRunsAreReadNewestFirstWithTheSummary(): void {
		$this->objects->method('findAll')
			->with(['filters' => ['register' => 'buildiq', 'schema' => 'automation-run', 'automation' => 'aut-1'], 'limit' => 1000], false, false)
			->willReturn(['results' => [
				['@self' => ['id' => 'r1'], 'automation' => 'aut-1', 'startedAt' => '2026-11-12T10:00:00+00:00', 'outcome' => 'succeeded'],
				['@self' => ['id' => 'r3'], 'automation' => 'aut-1', 'startedAt' => '2026-11-14T09:00:00+01:00', 'outcome' => 'failed', 'reason' => 'Webhook answered 500'],
				['@self' => ['id' => 'other'], 'automation' => 'aut-9', 'startedAt' => '2026-11-14T09:30:00+00:00', 'outcome' => 'succeeded'],
				['@self' => ['id' => 'r2'], 'automation' => 'aut-1', 'startedAt' => '2026-11-13T10:00:00+00:00', 'outcome' => 'failed', 'reason' => 'old'],
			]]);

		$all = $this->recorder()->runsOf(automationUuid: 'aut-1');
		$this->assertSame(['r3', 'r2', 'r1'], array_column($all['results'], 'id'));
		$this->assertSame(3, $all['total']);
		$this->assertSame(['lastRunAt' => '2026-11-14T09:00:00+01:00', 'lastOutcome' => 'failed', 'lastError' => 'Webhook answered 500'], $all['summary']);

		$failed = $this->recorder()->runsOf(automationUuid: 'aut-1', filters: ['outcome' => 'failed'], limit: 1, offset: 1);
		$this->assertSame(['r2'], array_column($failed['results'], 'id'));
		$this->assertSame(2, $failed['total']);

		$since = $this->recorder()->runsOf(automationUuid: 'aut-1', filters: ['since' => '2026-11-13', 'until' => '2026-11-13T23:59:59Z']);
		$this->assertSame(['r2'], array_column($since['results'], 'id'));
	}//end testRunsAreReadNewestFirstWithTheSummary()

	/**
	 * An automation without runs, and an unreadable store, summarise to nothing.
	 *
	 * @return void
	 */
	public function testNoRunsSummariseToNothing(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));

		$this->assertSame(['lastRunAt' => null, 'lastOutcome' => null, 'lastError' => null], $this->recorder()->summaryOf(automationUuid: 'aut-1'));
		$this->assertSame([], $this->recorder()->runsOf(automationUuid: '')['results']);
	}//end testNoRunsSummariseToNothing()

	/**
	 * A run that started 91 days ago is deleted; one from yesterday stays.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-old-runs-are-pruned-and-the-last-run-stays-req-bqrl-005
	 *
	 * @return void
	 */
	public function testPruneDeletesRunsOlderThan90Days(): void {
		$this->objects->method('findAll')->willReturn([
			['@self' => ['id' => 'old'], 'automation' => 'aut-1', 'startedAt' => gmdate(DATE_ATOM, self::NOW - (91 * 86400))],
			['@self' => ['id' => 'new'], 'automation' => 'aut-1', 'startedAt' => gmdate(DATE_ATOM, self::NOW - 86400)],
			['@self' => ['id' => 'undated'], 'automation' => 'aut-1'],
		]);
		$this->objects->expects($this->once())->method('deleteObject')->with('old', 'buildiq', 'automation-run', false, false);

		$this->assertSame(1, $this->recorder()->prune(now: self::NOW));
	}//end testPruneDeletesRunsOlderThan90Days()
}//end class
