<?php

/**
 * Unit tests for AutomationApprovalTriggerListener.
 *
 * Covers automation-approval-steps task 1.3 / spec REQ-AUTD-004 scenario
 * "Trigger firing initialises an approval step": an object-created event
 * matching an enabled automation's `approval` action calls
 * `ApprovalService::initializeChain()`; a non-matching event, a disabled
 * automation, and an already-initialised object are all no-ops.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-approval-steps/tasks.md#6.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Listener;

use OCA\OpenBuild\Listener\AutomationApprovalTriggerListener;
use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalChainMapper;
use OCA\OpenRegister\Db\ApprovalStepMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ApprovalService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see AutomationApprovalTriggerListener}.
 */
final class AutomationApprovalTriggerListenerTest extends TestCase {
	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var ApprovalChainMapper&MockObject
	 */
	private ApprovalChainMapper&MockObject $chainMapper;

	/**
	 * @var ApprovalStepMapper&MockObject
	 */
	private ApprovalStepMapper&MockObject $stepMapper;

	/**
	 * @var ApprovalService&MockObject
	 */
	private ApprovalService&MockObject $approvalService;

	/**
	 * Listener under test.
	 *
	 * @var AutomationApprovalTriggerListener
	 */
	private AutomationApprovalTriggerListener $listener;

	/**
	 * Set up mocks + SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->chainMapper = $this->createMock(ApprovalChainMapper::class);
		$this->stepMapper = $this->createMock(ApprovalStepMapper::class);
		$this->approvalService = $this->createMock(ApprovalService::class);

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$this->listener = new AutomationApprovalTriggerListener(
			$this->objectService,
			$this->schemaMapper,
			$this->chainMapper,
			$this->stepMapper,
			$this->approvalService,
			$userSession,
			new NullLogger()
		);

	}//end setUp()

	/**
	 * Build a mock ObjectEntity carrying schema slug + uuid.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid The object uuid.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function objectEntity(string $schema, string $uuid): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getUuid')->willReturn($uuid);

		return $entity;
	}//end objectEntity()

	/**
	 * Build a mock Schema with a fixed `getId()`.
	 *
	 * @param int $id The fixed id.
	 *
	 * @return Schema&MockObject
	 */
	private function schemaWithId(int $id): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn($id);

		return $schema;
	}//end schemaWithId()

	/**
	 * Build a mock ApprovalChain with a fixed `getId()`.
	 *
	 * @param int $id The fixed id.
	 *
	 * @return ApprovalChain&MockObject
	 */
	private function chainWithId(int $id): ApprovalChain&MockObject {
		$chain = $this->createMock(ApprovalChain::class);
		$chain->method('getId')->willReturn($id);

		return $chain;
	}//end chainWithId()

	/**
	 * A matching, enabled automation's compiled chain is initialised for
	 * the fired object's uuid.
	 *
	 * @return void
	 */
	public function testMatchingEnabledAutomationInitialisesChain(): void {
		$automation = [
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'permit-reviewers']],
			'provenance' => ['approvalChainName' => 'aut-route-permit-application-for-approval'],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);

		$this->schemaMapper->method('find')->willReturn($this->schemaWithId(42));

		$chain = $this->chainWithId(7);
		$this->chainMapper->method('findBySchemaAndName')->with(42, 'aut-route-permit-application-for-approval')->willReturn($chain);

		$this->stepMapper->method('findByChainAndObject')->willReturn([]);

		$this->approvalService->expects($this->once())
			->method('initializeChain')
			->with($chain, 'object-uuid-1', null);

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testMatchingEnabledAutomationInitialisesChain()

	/**
	 * A disabled automation is never initialised.
	 *
	 * @return void
	 */
	public function testDisabledAutomationIsSkipped(): void {
		$automation = [
			'enabled' => false,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'permit-reviewers']],
			'provenance' => ['approvalChainName' => 'aut-x'],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->approvalService->expects($this->never())->method('initializeChain');

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testDisabledAutomationIsSkipped()

	/**
	 * An automation on a DIFFERENT schema is never initialised.
	 *
	 * @return void
	 */
	public function testNonMatchingSchemaIsSkipped(): void {
		$automation = [
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'some-other-schema'],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'permit-reviewers']],
			'provenance' => ['approvalChainName' => 'aut-x'],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->approvalService->expects($this->never())->method('initializeChain');

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testNonMatchingSchemaIsSkipped()

	/**
	 * Idempotency guard: an object that already has a step for the compiled
	 * chain is NOT re-initialised.
	 *
	 * @return void
	 */
	public function testAlreadyInitialisedObjectIsSkipped(): void {
		$automation = [
			'enabled' => true,
			'trigger' => ['type' => 'object-updated', 'schema' => 'permit-application'],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'permit-reviewers']],
			'provenance' => ['approvalChainName' => 'aut-x'],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);

		$this->schemaMapper->method('find')->willReturn($this->schemaWithId(42));
		$this->chainMapper->method('findBySchemaAndName')->willReturn($this->chainWithId(7));

		$existingStep = $this->createMock(\OCA\OpenRegister\Db\ApprovalStep::class);
		$this->stepMapper->method('findByChainAndObject')->willReturn([$existingStep]);

		$this->approvalService->expects($this->never())->method('initializeChain');

		$event = new \OCA\OpenRegister\Event\ObjectUpdatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testAlreadyInitialisedObjectIsSkipped()

	/**
	 * A lifecycle-transition event matches only an automation declaring the
	 * SAME transition action name.
	 *
	 * @return void
	 */
	public function testLifecycleTransitionMatchesOnTransitionName(): void {
		$automation = [
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'activate'],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'ops']],
			'provenance' => ['approvalChainName' => 'aut-activate-needs-approval'],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);

		$this->schemaMapper->method('find')->willReturn($this->schemaWithId(9));
		$this->chainMapper->method('findBySchemaAndName')->willReturn($this->chainWithId(1));
		$this->stepMapper->method('findByChainAndObject')->willReturn([]);

		$this->approvalService->expects($this->once())->method('initializeChain');

		$entity = $this->objectEntity('permit', 'object-uuid-9');
		$event = new \OCA\OpenRegister\Event\ObjectTransitionedEvent(
			object: $entity,
			action: 'activate',
			from: 'draft',
			to: 'active',
			userId: null,
			register: 'openbuild',
			schema: 'permit'
		);
		$this->listener->handle($event);

	}//end testLifecycleTransitionMatchesOnTransitionName()
}//end class
