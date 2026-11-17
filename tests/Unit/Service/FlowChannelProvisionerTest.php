<?php

/**
 * Unit tests for FlowChannelProvisioner.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ChannelApplyReport;
use OCA\Buildiq\Service\FlowChannelProvisioner;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the flows channel: skipped without an application, flows created and bound once, existing bindings kept,
 * failures recorded per flow, the 512 bound and a rebind that fails after the flows exist.
 *
 * @covers \OCA\Buildiq\Service\FlowChannelProvisioner
 * @uses   \OCA\Buildiq\Service\ChannelApplyReport
 */
class FlowChannelProvisionerTest extends TestCase {

	private const APP_UUID = 'app-1';

	/**
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flowService;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flowService = $this->createMock(FlowService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * No flows: the channel is declared with zero and nothing is read.
	 *
	 * @return void
	 */
	public function testNoFlowsReadsNothing(): void {
		$this->objectService->expects($this->never())->method('find');
		$report = new ChannelApplyReport();

		$this->provisioner()->apply([], self::APP_UUID, $report);

		$channel = $report->toArray()['channels']['flows'];
		$this->assertSame(0, $channel['declared']);
		$this->assertSame(0, $channel['created']);
	}//end testNoFlowsReadsNothing()

	/**
	 * Without a local application the whole channel is skipped with its reason.
	 *
	 * @return void
	 */
	public function testNoApplicationContextSkipsTheChannel(): void {
		$this->flowService->expects($this->never())->method('save');
		foreach ([null, ''] as $uuid) {
			$report = new ChannelApplyReport();
			$this->provisioner()->apply(['f1' => ['name' => 'A'], 'f2' => ['name' => 'B']], $uuid, $report);

			$channel = $report->toArray()['channels']['flows'];
			$this->assertSame('no-local-application-context', $channel['reason']);
			$this->assertSame(2, $channel['skipped']);
		}
	}//end testNoApplicationContextSkipsTheChannel()

	/**
	 * An application that is absent, or whose lookup throws, skips the channel as not found.
	 *
	 * @return void
	 */
	public function testAnUnloadableApplicationSkipsTheChannel(): void {
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new RuntimeException('broker down'))
		);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('local application "app-1" could not be loaded: broker down'));
		$this->flowService->expects($this->never())->method('save');

		foreach (['absent', 'throws'] as $case) {
			$report = new ChannelApplyReport();
			$this->provisioner()->apply(['f1' => ['name' => 'A']], self::APP_UUID, $report);
			$this->assertSame('application-not-found', $report->toArray()['channels']['flows']['reason'], $case);
		}
	}//end testAnUnloadableApplicationSkipsTheChannel()

	/**
	 * New flows are saved as buildiq's and bound onto the application after the existing bindings.
	 *
	 * @return void
	 */
	public function testNewFlowsAreCreatedAndBound(): void {
		$existing = ['flow' => 'local-0', 'sourceUuid' => 'src-0', 'label' => 'Old'];
		$this->objectService->method('find')->willReturn(
			$this->entity(['name' => 'App', 'flows' => [$existing, 'garbage', ['sourceUuid' => '']]])
		);

		$saved = [];
		$this->flowService->expects($this->exactly(2))->method('save')->willReturnCallback(
			function (array $data) use (&$saved): Flow {
				$saved[] = $data;
				return $this->flow('local-'.count($saved));
			}
		);

		$rebound = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$rebound) {
				$rebound = $args[0];
				return $this->entity([]);
			}
		);

		$report = new ChannelApplyReport();
		$this->provisioner()->apply(
			['src-0' => ['name' => 'Old'], 'src-1' => ['name' => 'Intake', 'uuid' => 'src-1'], 'src-2' => []],
			self::APP_UUID,
			$report
		);

		$this->assertSame(['name' => 'Intake', 'uuid' => 'src-1', 'app' => 'buildiq'], $saved[0]);
		$this->assertSame(['app' => 'buildiq'], $saved[1]);
		$this->assertSame(
			[
				$existing,
				'garbage',
				['sourceUuid' => ''],
				['flow' => 'local-1', 'sourceUuid' => 'src-1', 'label' => 'Intake'],
				['flow' => 'local-2', 'sourceUuid' => 'src-2', 'label' => ''],
			],
			$rebound['flows']
		);
		$this->assertSame('App', $rebound['name']);

		$channel = $report->toArray()['channels']['flows'];
		$this->assertSame(3, $channel['declared']);
		$this->assertSame(2, $channel['created']);
		$this->assertSame(1, $channel['skipped']);
		$this->assertSame(ChannelApplyReport::REASON_EXISTS, $channel['items'][0]['reason']);
		$this->assertSame('flows/src-0', $channel['items'][0]['item']);
	}//end testNewFlowsAreCreatedAndBound()

	/**
	 * Only already-bound flows: nothing is created and the application is not rewritten.
	 *
	 * @return void
	 */
	public function testAllExistingFlowsLeaveTheApplicationAlone(): void {
		$this->objectService->method('find')->willReturn(
			$this->entity(['flows' => [['flow' => 'x', 'sourceUuid' => 'src-0']]])
		);
		$this->flowService->expects($this->never())->method('save');
		$this->objectService->expects($this->never())->method('saveObject');

		$report = new ChannelApplyReport();
		$this->provisioner()->apply(['src-0' => ['name' => 'Old']], self::APP_UUID, $report);

		$this->assertSame(1, $report->toArray()['channels']['flows']['skipped']);
	}//end testAllExistingFlowsLeaveTheApplicationAlone()

	/**
	 * A flow that cannot be saved is recorded as failed with its reason; the rest are still bound.
	 *
	 * @return void
	 */
	public function testAFailedFlowIsRecordedAndTheOthersBound(): void {
		$this->objectService->method('find')->willReturn($this->entity([]));
		$this->flowService->method('save')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('invalid nodes')),
			$this->flow('local-b')
		);
		$this->logger->expects($this->once())->method('warning')
			->with('Buildiq channel apply: flow "src-a" could not be created: invalid nodes');

		$rebound = null;
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (...$args) use (&$rebound) {
				$rebound = $args[0];
				return $this->entity([]);
			}
		);

		$report = new ChannelApplyReport();
		$this->provisioner()->apply(['src-a' => ['name' => 'A'], 'src-b' => ['name' => 'B']], self::APP_UUID, $report);

		$this->assertSame([['flow' => 'local-b', 'sourceUuid' => 'src-b', 'label' => 'B']], $rebound['flows']);
		$channel = $report->toArray()['channels']['flows'];
		$this->assertSame(1, $channel['failed']);
		$this->assertSame(1, $channel['created']);
		$this->assertSame('invalid nodes', $channel['items'][0]['reason']);
	}//end testAFailedFlowIsRecordedAndTheOthersBound()

	/**
	 * Every flow failing means no rebind at all.
	 *
	 * @return void
	 */
	public function testNoCreatedFlowMeansNoRebind(): void {
		$this->objectService->method('find')->willReturn($this->entity([]));
		$this->flowService->method('save')->willThrowException(new RuntimeException('nope'));
		$this->objectService->expects($this->never())->method('saveObject');

		$report = new ChannelApplyReport();
		$this->provisioner()->apply(['src-a' => []], self::APP_UUID, $report);

		$this->assertSame(1, $report->toArray()['channels']['flows']['failed']);
	}//end testNoCreatedFlowMeansNoRebind()

	/**
	 * Flows past the 512 bound are recorded as truncated, with one warning each, and not created.
	 *
	 * @return void
	 */
	public function testFlowsPastTheBoundAreTruncated(): void {
		$this->objectService->method('find')->willReturn($this->entity([]));
		$this->flowService->expects($this->exactly(512))->method('save')->willReturnCallback(
			fn (array $data): Flow => $this->flow('local')
		);
		$this->logger->expects($this->exactly(2))->method('warning')
			->with($this->stringContains('declared 514 items but the bound is 512'));
		$this->objectService->expects($this->once())->method('saveObject');

		$flows = [];
		for ($i = 0; $i < 514; $i++) {
			$flows['src-'.$i] = ['name' => 'F'.$i];
		}

		$report = new ChannelApplyReport();
		$this->provisioner()->apply($flows, self::APP_UUID, $report);

		$channel = $report->toArray()['channels']['flows'];
		$this->assertSame(512, $channel['created']);
		$this->assertSame(2, $channel['skipped']);
		$this->assertSame(2, $channel['truncated']);
		$this->assertSame('flows/src-513', $channel['items'][513]['item']);
	}//end testFlowsPastTheBoundAreTruncated()

	/**
	 * A rebind that fails keeps the flows reported as created and logs an error naming the count.
	 *
	 * @return void
	 */
	public function testAFailedRebindKeepsTheCreatedOutcome(): void {
		$this->objectService->method('find')->willReturn($this->entity([]));
		$this->flowService->method('save')->willReturn($this->flow('local-a'));
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('error')->with(
			'Buildiq channel apply: created 1 flow(s) but could not rebind them onto application "app-1": locked'
		);

		$report = new ChannelApplyReport();
		$this->provisioner()->apply(['src-a' => ['name' => 'A']], self::APP_UUID, $report);

		$this->assertSame(1, $report->toArray()['channels']['flows']['created']);
	}//end testAFailedRebindKeepsTheCreatedOutcome()

	/**
	 * The provisioner under test.
	 *
	 * @return FlowChannelProvisioner The provisioner.
	 */
	private function provisioner(): FlowChannelProvisioner {
		return new FlowChannelProvisioner($this->flowService, $this->objectService, $this->logger);
	}//end provisioner()

	/**
	 * A flow as FlowService::save() hands it back.
	 *
	 * @param string $uuid The minted uuid.
	 *
	 * @return Flow The flow.
	 */
	private function flow(string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		return $flow;
	}//end flow()

	/**
	 * An object entity wrapping a payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntityInterface&MockObject The entity.
	 */
	private function entity(array $payload): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($payload);
		return $entity;
	}//end entity()
}//end class
