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
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
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
		$this->runs     = $this->createMock(AutomationRunRecorderInterface::class);
		$this->objects  = $this->createMock(ObjectServiceInterface::class);
		$this->flowRuns = $this->createMock(FlowRunMapper::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The reconciler under test.
	 *
	 * @return ManualRunReconciler
	 */
	private function reconciler(): ManualRunReconciler {
		$container = $this->createMock(ContainerInterface::class);
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
			public array $payload = [];

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
		$this->runs->expects($this->once())->method('complete')
			->with(
				'run-1',
				'succeeded',
				[['action' => 'object-op', 'outcome' => 'succeeded', 'message' => '']],
				null,
				336
			)
			->willReturn(true);

		$this->assertSame(1, $this->reconciler()->reconcile(automationUuid: 'aut-1'));
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
				'fr-1' => self::flowRun(status: 'failed', error: 'An update matched no object.', log: [['transition' => 'step-1', 'status' => 'failed', 'error' => 'An update matched no object.']]),
				default => throw new DoesNotExistException('gone'),
			}
		);
		$this->objects->method('find')->willReturn($this->automation());
		$completed = [];
		$this->runs->method('complete')->willReturnCallback(function (...$arguments) use (&$completed): bool {
			$completed[] = $arguments;
			return true;
		});

		$this->assertSame(2, $this->reconciler()->reconcile(automationUuid: 'aut-1'));
		$this->assertSame(['run-1', 'failed', [['action' => 'object-op', 'outcome' => 'failed', 'message' => 'An update matched no object.']], 'An update matched no object.'], array_slice($completed[0], 0, 4));
		$this->assertSame(['run-2', 'failed', [], ManualRunReconciler::GONE, null], $completed[1]);
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
			$this->throwException(new RuntimeException('down')),
			[['id' => 'run-1', 'flowRun' => 'fr-1']]
		);
		$this->flowRuns->expects($this->once())->method('findByUuid')->willThrowException(new RuntimeException('db'));
		$this->runs->expects($this->never())->method('complete');
		$this->logger->expects($this->exactly(2))->method('error');

		$reconciler = $this->reconciler();
		$this->assertSame(0, $reconciler->reconcile(automationUuid: 'aut-1'));
		$this->assertSame(0, $reconciler->reconcile(automationUuid: 'aut-1'));
		$this->assertSame(0, $reconciler->reconcile(automationUuid: 'aut-1'));
	}//end testNothingWaitingOrUnreadableCompletesNothing()
}//end class
