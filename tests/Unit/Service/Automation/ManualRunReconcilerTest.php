<?php

/**
 * Unit tests for ManualRunReconciler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Service\Automation\AutomationRecords;
use OCA\Buildiq\Service\Automation\FlowRunOutcome;
use OCA\Buildiq\Service\Automation\ManualRunReconciler;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A waiting manual run is completed from its flow run once that has ended.
 */
#[CoversClass(ManualRunReconciler::class)]
#[UsesClass(FlowRunOutcome::class)]
#[UsesClass(AutomationRecords::class)]
#[UsesClass(ObjectRecord::class)]
final class ManualRunReconcilerTest extends TestCase {

	/**
	 * @var AutomationRunRecorderInterface&MockObject
	 */
	private AutomationRunRecorderInterface&MockObject $runs;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * @var FlowRunMapper&MockObject
	 */
	private FlowRunMapper&MockObject $flowRuns;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Wire the reconciler over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->runs     = $this->createMock(originalClassName: AutomationRunRecorderInterface::class);
		$this->objects  = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->flowRuns = $this->createMock(originalClassName: FlowRunMapper::class);
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The reconciler under test.
	 *
	 * @return ManualRunReconciler
	 */
	private function reconciler(): ManualRunReconciler {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with(FlowRunMapper::class)->willReturn($this->flowRuns);
		return new ManualRunReconciler(container: $container, runs: $this->runs, objectService: $this->objects, logger: $this->logger);
	}//end reconciler()

	/**
	 * A flow run row.
	 *
	 * @param string      $status Its status.
	 * @param string|null $error  Its error.
	 * @param array       $log    Its log.
	 *
	 * @return FlowRun
	 */
	private static function flowRun(string $status, ?string $error = null, array $log = []): FlowRun {
		$run = new FlowRun();
		$run->setStatus($status);
		$run->setError($error);
		$run->setLog($log);
		return $run;
	}//end flowRun()

	/**
	 * The automation object with its actions.
	 *
	 * @return ObjectEntity
	 */
	private function automation(): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * What the entity serialises to.
			 *
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * The payload.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = ['@self' => ['id' => 'aut-1'], 'actions' => [['type' => 'object-op'], 'not an action']];
		return $entity;
	}//end automation()

	/**
	 * A finished flow run completes its run; one still running leaves its run
	 * waiting; the automation is read once.
	 *
	 * @return void
	 */
	public function testAFinishedFlowRunCompletesItsWaitingRun(): void {
		$this->runs->method('waitingOnFlowRuns')->with('aut-1')->willReturn([
			['id' => 'run-1', 'flowRun' => 'fr-1', 'outcome' => 'waiting'],
			['id' => 'run-2', 'flowRun' => 'fr-2', 'outcome' => 'waiting'],
		]);
		$this->flowRuns->method('findByUuid')->willReturnMap([
			['fr-1', self::flowRun(status: 'completed', log: [['transition' => 'step-1', 'status' => 'completed', 'durationMs' => 336]])],
			['fr-2', self::flowRun(status: 'running')],
		]);
		$this->objects->expects($this->once())->method('find')->willReturn($this->automation());
		$this->runs->expects($this->once())->method('completeFromFlowRun')
			->with(
				'run-1',
				[
					'outcome' => 'succeeded',
					'reason' => null,
					'durationMs' => 336,
					'steps' => [['action' => 'object-op', 'outcome' => 'succeeded', 'message' => '']],
				]
			)
			->willReturn(true);

		$this->assertSame(expected: 1, actual: $this->reconciler()->reconcile(automationUuid: 'aut-1'));
	}//end testAFinishedFlowRunCompletesItsWaitingRun()

	/**
	 * A failed flow run is recorded failed with its error; a flow run that no
	 * longer exists is recorded failed with that reason.
	 *
	 * @return void
	 */
	public function testAFailedOrVanishedFlowRunIsRecordedFailed(): void {
		$this->runs->method('waitingOnFlowRuns')->willReturn([
			['id' => 'run-1', 'flowRun' => 'fr-1'],
			['id' => 'run-2', 'flowRun' => 'fr-gone'],
		]);
		$this->flowRuns->method('findByUuid')->willReturnCallback(
			static fn (string $uuid): FlowRun => match ($uuid) {
				'fr-1' => self::flowRun(
					status: 'failed',
					error: 'An update matched no object.',
					log: [['transition' => 'step-1', 'status' => 'failed', 'error' => 'An update matched no object.']]
				),
				default => throw new DoesNotExistException('gone'),
			}
		);
		$this->objects->method('find')->willReturn($this->automation());
		$completed = [];
		$this->runs->method('completeFromFlowRun')->willReturnCallback(function (...$arguments) use (&$completed): bool {
			$completed[] = $arguments;
			return true;
		});

		$this->assertSame(expected: 2, actual: $this->reconciler()->reconcile(automationUuid: 'aut-1'));
		$failed = ['action' => 'object-op', 'outcome' => 'failed', 'message' => 'An update matched no object.'];
		$this->assertSame(expected: 'run-1', actual: $completed[0][0]);
		$this->assertSame(expected: 'An update matched no object.', actual: $completed[0][1]['reason']);
		$this->assertSame(expected: [$failed], actual: $completed[0][1]['steps']);
		$this->assertSame(
			expected: ['run-2', ['outcome' => 'failed', 'reason' => ManualRunReconciler::GONE, 'durationMs' => null, 'steps' => []]],
			actual: $completed[1]
		);
	}//end testAFailedOrVanishedFlowRunIsRecordedFailed()

	/**
	 * Nothing waits: no flow run is read. A store that cannot be read, or a
	 * flow run read that fails, is logged and completes nothing.
	 *
	 * @return void
	 */
	public function testNothingWaitingOrUnreadableCompletesNothing(): void {
		$this->runs->method('waitingOnFlowRuns')->willReturnOnConsecutiveCalls(
			[],
			$this->throwException(exception: new RuntimeException('down')),
			[['id' => 'run-1', 'flowRun' => 'fr-1']]
		);
		$this->flowRuns->expects($this->once())->method('findByUuid')->willThrowException(new RuntimeException('db'));
		$this->runs->expects($this->never())->method('completeFromFlowRun');
		$this->logger->expects($this->exactly(count: 2))->method('error');

		$reconciler = $this->reconciler();
		$this->assertSame(expected: 0, actual: $reconciler->reconcile(automationUuid: 'aut-1'));
		$this->assertSame(expected: 0, actual: $reconciler->reconcile(automationUuid: 'aut-1'));
		$this->assertSame(expected: 0, actual: $reconciler->reconcile(automationUuid: 'aut-1'));
	}//end testNothingWaitingOrUnreadableCompletesNothing()
}//end class
