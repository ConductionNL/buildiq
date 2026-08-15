<?php

/**
 * Unit tests for ApprovalOutcomeListener.
 *
 * Covers automation-approval-steps spec REQ-AUTD-004 approval scenarios:
 * on-approve follow-up actions dispatch on approve, on-reject follow-up
 * actions dispatch on reject, and a chain not owned by any automation is a
 * single no-op lookup (task 2.2).
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

use OCA\OpenBuild\Listener\ApprovalOutcomeListener;
use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenBuild\Service\RuleActionDispatcher;
use OCA\OpenRegister\Db\ApprovalChain;
use OCA\OpenRegister\Db\ApprovalStep;
use OCA\OpenRegister\Event\ApprovalStepApprovedEvent;
use OCA\OpenRegister\Event\ApprovalStepRejectedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see ApprovalOutcomeListener}.
 */
final class ApprovalOutcomeListenerTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * @var AutomationCompilerService&MockObject
	 */
	private AutomationCompilerService&MockObject $compiler;

	/**
	 * @var RuleActionDispatcher&MockObject
	 */
	private RuleActionDispatcher&MockObject $dispatcher;

	/**
	 * Listener under test.
	 *
	 * @var ApprovalOutcomeListener
	 */
	private ApprovalOutcomeListener $listener;

	/**
	 * Set up mocks + SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->compiler = $this->createMock(AutomationCompilerService::class);
		$this->dispatcher = $this->createMock(RuleActionDispatcher::class);
		$this->listener = new ApprovalOutcomeListener($this->objectService, $this->compiler, $this->dispatcher, new NullLogger(),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * Build a mock ApprovalChain with the given `name`.
	 *
	 * @param string $name The chain name.
	 *
	 * @return ApprovalChain&MockObject
	 */
	private function chainNamed(string $name): ApprovalChain&MockObject {
		$chain = $this->createMock(ApprovalChain::class);
		$chain->method('getName')->willReturn($name);

		return $chain;
	}//end chainNamed()

	/**
	 * Build a mock ApprovalStep carrying the given object uuid.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return ApprovalStep&MockObject
	 */
	private function stepFor(string $objectUuid): ApprovalStep&MockObject {
		$step = $this->createMock(ApprovalStep::class);
		$step->method('getObjectUuid')->willReturn($objectUuid);

		return $step;
	}//end stepFor()

	/**
	 * On approve: the automation's `onApprove` follow-up actions dispatch;
	 * `onReject` does NOT.
	 *
	 * @return void
	 */
	public function testApprovedDispatchesOnApproveFollowUps(): void {
		$chain = $this->chainNamed('aut-route-permit-application-for-approval');
		$step = $this->stepFor('object-uuid-1');
		$event = new ApprovalStepApprovedEvent(chain: $chain, step: $step, userId: 'alice', statusOnApprove: 'approved', nextStep: null);

		$automation = [
			'slug' => 'route-permit-application-for-approval',
			'actions' => [
				[
					'type' => 'approval',
					'assigneeGroup' => 'permit-reviewers',
					'onApprove' => [['type' => 'object-op', 'operation' => 'update', 'schema' => 'permit-application', 'fieldMapping' => ['status' => 'approved']]],
					'onReject' => [['type' => 'send-notification', 'subject' => ['en' => 'Rejected']]],
				],
			],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->compiler->method('mapActionToRuleAction')->willReturnCallback(
			static fn (array $a): array => [
				'type' => $a['type'],
				'parameters' => match ($a['type']) {
					'object-op' => ['schema' => $a['schema'], 'operation' => $a['operation'], 'object' => $a['fieldMapping'], 'register' => 'openbuild'],
					default => [],
				},
			]
		);

		$this->dispatcher->expects($this->once())
			->method('__invoke')
			->with(
				'object-op',
				$this->callback(static fn (array $params): bool => ($params['id'] ?? null) === 'object-uuid-1' && $params['operation'] === 'update'),
				[]
			);

		$this->listener->handle($event);

	}//end testApprovedDispatchesOnApproveFollowUps()

	/**
	 * On reject: the automation's `onReject` follow-up actions dispatch;
	 * `onApprove` does NOT.
	 *
	 * @return void
	 */
	public function testRejectedDispatchesOnRejectFollowUps(): void {
		$chain = $this->chainNamed('aut-route-permit-application-for-approval');
		$step = $this->stepFor('object-uuid-2');
		$event = new ApprovalStepRejectedEvent(chain: $chain, step: $step, userId: 'bob', statusOnReject: 'rejected');

		$automation = [
			'slug' => 'route-permit-application-for-approval',
			'actions' => [
				[
					'type' => 'approval',
					'assigneeGroup' => 'permit-reviewers',
					'onApprove' => [['type' => 'object-op', 'operation' => 'update', 'schema' => 'permit-application', 'fieldMapping' => ['status' => 'approved']]],
					'onReject' => [['type' => 'send-notification', 'subject' => ['en' => 'Your application was rejected']]],
				],
			],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->compiler->method('mapActionToRuleAction')->willReturnCallback(
			static fn (array $a): array => [
				'type' => $a['type'],
				'parameters' => match ($a['type']) {
					'send-notification' => ['subject' => ($a['subject']['en'] ?? ''), 'recipientUid' => ''],
					default => [],
				},
			]
		);

		$this->dispatcher->expects($this->once())
			->method('__invoke')
			->with('send-notification', ['subject' => 'Your application was rejected', 'recipientUid' => ''], []);

		$this->listener->handle($event);

	}//end testRejectedDispatchesOnRejectFollowUps()

	/**
	 * A chain NOT owned by any automation (name has no `aut-` prefix) is a
	 * single string check — no register scan, no dispatch (task 2.2).
	 *
	 * @return void
	 */
	public function testNonAutomationChainIsNoOp(): void {
		$chain = $this->chainNamed('hand-authored-chain');
		$step = $this->stepFor('object-uuid-3');
		$event = new ApprovalStepApprovedEvent(chain: $chain, step: $step, userId: 'alice', statusOnApprove: 'approved', nextStep: null);

		$this->objectService->expects($this->never())->method('findAll');
		$this->dispatcher->expects($this->never())->method('__invoke');

		$this->listener->handle($event);

	}//end testNonAutomationChainIsNoOp()

	/**
	 * An `aut-`-prefixed chain name that matches no automation slug is a
	 * single lookup, no dispatch.
	 *
	 * @return void
	 */
	public function testUnmatchedAutomationSlugIsNoOp(): void {
		$chain = $this->chainNamed('aut-does-not-exist');
		$step = $this->stepFor('object-uuid-4');
		$event = new ApprovalStepApprovedEvent(chain: $chain, step: $step, userId: 'alice', statusOnApprove: 'approved', nextStep: null);

		$this->objectService->method('findAll')->willReturn([]);

		$this->dispatcher->expects($this->never())->method('__invoke');

		$this->listener->handle($event);

	}//end testUnmatchedAutomationSlugIsNoOp()

	/**
	 * A different event type entirely is ignored.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEventTypes(): void {
		$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$event = new ObjectUpdatingEvent($entity);

		$this->dispatcher->expects($this->never())->method('__invoke');

		$this->listener->handle($event);

	}//end testIgnoresOtherEventTypes()
}//end class
