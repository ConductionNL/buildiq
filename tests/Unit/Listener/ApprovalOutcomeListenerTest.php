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
 * @package  OCA\Buildiq\Tests\Unit\Listener
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

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\ApprovalOutcomeListener;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see ApprovalOutcomeListener}.
 */
final class ApprovalOutcomeListenerTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var AutomationCompilerService&MockObject
	 */
	/**
	 * @var TaskSequenceMapper&MockObject
	 */
	private TaskSequenceMapper&MockObject $sequenceMapper;

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
		// Resolved through the container at USE time — Nextcloud builds event
		// listeners from the SERVER container, which never carries an app's
		// registerServiceAlias(), so a constructor-injected interface cannot be
		// built. The mock is unchanged; only the delivery route is.
		$this->sequenceMapper = $this->createMock(TaskSequenceMapper::class);
		// The reject half resolves the chain key through the sequence mapper, so
		// the container has to answer per class name rather than return one
		// service for anything asked of it.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				return match ($id) {
					'OCA\\OpenRegister\\Db\\TaskSequenceMapper' => $this->sequenceMapper,
					default => $this->objectService,
				};
			}
		);

		$this->listener = new ApprovalOutcomeListener(
			$container,
			$this->compiler,
			$this->dispatcher,
			new NullLogger(),
		);

	}//end setUp()

	/**
	 * Build a mock TaskSequence carrying a chain key and anchor object.
	 *
	 * @param string $chainKey   The sequence's chain key (`aut-<slug>` when automation-owned).
	 * @param string $objectUuid The anchor object uuid.
	 *
	 * @return TaskSequence&MockObject
	 */
	private function sequenceFor(string $chainKey, string $objectUuid): TaskSequence&MockObject {
		$sequence = $this->createMock(TaskSequence::class);
		$sequence->method('getChainKey')->willReturn($chainKey);
		$sequence->method('getAnchorObjectUuid')->willReturn($objectUuid);

		return $sequence;
	}//end sequenceFor()

	/**
	 * Build a mock terminal Task, and the sequence lookup the listener does on it.
	 *
	 * The reject half only has the task, so it resolves the chain key by loading
	 * the task's sequence — that container lookup is wired here.
	 *
	 * @param string $objectUuid The object uuid the task is about.
	 * @param string $chainKey   The chain key its sequence carries.
	 *
	 * @return Task&MockObject
	 */
	private function taskFor(string $objectUuid, string $chainKey = 'aut-route-permit-application-for-approval'): Task&MockObject {
		$task = $this->createMock(Task::class);
		$task->method('getObjectUuid')->willReturn($objectUuid);
		$task->method('getSequenceUuid')->willReturn('sequence-uuid-1');

		$this->sequenceMapper->method('findByUuid')->willReturn($this->sequenceFor($chainKey, $objectUuid));

		return $task;
	}//end taskFor()

	/**
	 * On approve: the automation's `onApprove` follow-up actions dispatch;
	 * `onReject` does NOT.
	 *
	 * @return void
	 */
	public function testApprovedDispatchesOnApproveFollowUps(): void {
		$chain = 'aut-route-permit-application-for-approval';
		$step = $this->stepFor('object-uuid-1');
		$event = new TaskSequenceCompletedEvent(sequence: $this->sequenceFor($chain, 'object-uuid-1'));

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
					'object-op' => ['schema' => $a['schema'], 'operation' => $a['operation'], 'object' => $a['fieldMapping'], 'register' => 'buildiq'],
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
		$chain = 'aut-route-permit-application-for-approval';
		$step = $this->stepFor('object-uuid-2');
		$event = new TaskTerminalEvent(task: $this->taskFor('object-uuid-1', $chain), outcome: 'rejected');

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
		$chain = 'hand-authored-chain';
		$step = $this->stepFor('object-uuid-3');
		$event = new TaskSequenceCompletedEvent(sequence: $this->sequenceFor($chain, 'object-uuid-1'));

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
		$chain = 'aut-does-not-exist';
		$step = $this->stepFor('object-uuid-4');
		$event = new TaskSequenceCompletedEvent(sequence: $this->sequenceFor($chain, 'object-uuid-1'));

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
