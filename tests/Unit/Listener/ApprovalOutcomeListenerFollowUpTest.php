<?php

/**
 * Unit tests for how ApprovalOutcomeListener picks and dispatches follow-up
 * actions, beyond the approve/reject routing its main test covers: an empty
 * automation slug, malformed action lists, a follow-up the compiler cannot
 * map, parameters that are not a list, an update that already names its
 * object, and a dispatch that fails.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\ApprovalOutcomeListener;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Follow-up selection and dispatch of ApprovalOutcomeListener.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ApprovalOutcomeListenerFollowUpTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The compiler double.
	 *
	 * @var AutomationCompilerService&MockObject
	 */
	private AutomationCompilerService $compiler;

	/**
	 * Every dispatch, as [type, params, payload].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: array<mixed>}>
	 */
	private array $dispatched = [];

	/**
	 * Errors logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * The listener under test.
	 *
	 * @var ApprovalOutcomeListener
	 */
	private ApprovalOutcomeListener $listener;

	/**
	 * The dispatch to throw on, by type, or null.
	 *
	 * @var string|null
	 */
	private ?string $failOn = null;

	/**
	 * Build the listener over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->compiler = $this->createMock(AutomationCompilerService::class);
		$this->dispatched = [];
		$this->errors = [];
		$this->failOn = null;

		$dispatcher = $this->createMock(RuleActionDispatcher::class);
		$dispatcher->method('__invoke')->willReturnCallback(
			function (string $type, array $params, array $payload): mixed {
				if ($type === $this->failOn) {
					throw new RuntimeException('target missing');
				}

				$this->dispatched[] = [$type, $params, $payload];
				return null;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		$this->listener = new ApprovalOutcomeListener($container, $this->compiler, $dispatcher, $logger);
	}//end setUp()

	/**
	 * A chain named only by the prefix has no automation slug, so nothing is looked up.
	 *
	 * @return void
	 */
	public function testAPrefixWithoutASlugLooksNothingUp(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->listener->handle($this->approved(chainKey: 'aut-'));

		$this->assertSame([], $this->dispatched);
	}//end testAPrefixWithoutASlugLooksNothingUp()

	/**
	 * Only array entries under the outcome key of approval actions are dispatched, in order.
	 *
	 * @return void
	 */
	public function testOnlyWellFormedApprovalFollowUpsAreDispatched(): void {
		$this->automation(
			[
				'not an action',
				['type' => 'notify', 'onApprove' => [['type' => 'never']]],
				['type' => 'approval', 'onApprove' => 'not a list'],
				['type' => 'approval', 'onApprove' => [['type' => 'first'], 'skip', ['type' => 'second']]],
				['type' => 'approval', 'onReject' => [['type' => 'never']]],
				['type' => 'approval', 'onApprove' => [['type' => 'third']]],
			]
		);
		$this->compiler->method('mapActionToRuleAction')->willReturnCallback(
			static fn (array $action): array => ['type' => $action['type'], 'parameters' => ['from' => $action['type']]]
		);

		$this->listener->handle($this->approved(chainKey: 'aut-route'));

		$this->assertSame(['first', 'second', 'third'], array_column($this->dispatched, 0));
	}//end testOnlyWellFormedApprovalFollowUpsAreDispatched()

	/**
	 * An automation whose actions are not a list dispatches nothing.
	 *
	 * @return void
	 */
	public function testActionsThatAreNotAListDispatchNothing(): void {
		$this->objectService->method('findAll')->willReturn([['slug' => 'route', 'actions' => 'approval']]);
		$this->compiler->expects($this->never())->method('mapActionToRuleAction');

		$this->listener->handle($this->approved(chainKey: 'aut-route'));

		$this->assertSame([], $this->dispatched);
	}//end testActionsThatAreNotAListDispatchNothing()

	/**
	 * A follow-up the compiler cannot map is logged and skipped; the next still runs.
	 *
	 * @return void
	 */
	public function testAnUnmappableFollowUpIsLoggedAndSkipped(): void {
		$this->automation([['type' => 'approval', 'onApprove' => [['type' => 'broken'], ['type' => 'fine']]]]);
		$this->compiler->method('mapActionToRuleAction')->willReturnCallback(
			static function (array $action): array {
				if ($action['type'] === 'broken') {
					throw new RuntimeException('unknown action type');
				}

				return ['type' => 'fine', 'parameters' => []];
			}
		);

		$this->listener->handle($this->approved(chainKey: 'aut-route'));

		$this->assertSame([['fine', [], []]], $this->dispatched);
		$this->assertSame(['Buildiq: ApprovalOutcomeListener could not map follow-up action: unknown action type'], $this->errors);
	}//end testAnUnmappableFollowUpIsLoggedAndSkipped()

	/**
	 * Parameters that are not a list become empty; an update that names its object keeps that id; a missing type is empty.
	 *
	 * @return void
	 */
	public function testParametersAreNormalised(): void {
		$this->automation([['type' => 'approval', 'onApprove' => [['n' => 1], ['n' => 2], ['n' => 3]]]]);
		$this->compiler->method('mapActionToRuleAction')->willReturnCallback(
			static fn (array $action): array => match ($action['n']) {
				1 => ['type' => 'object-op', 'parameters' => 'operation=update'],
				2 => ['type' => 'object-op', 'parameters' => ['operation' => 'update', 'id' => 'other-object']],
				3 => ['parameters' => ['operation' => 'create']],
			}
		);

		$this->listener->handle($this->approved(chainKey: 'aut-route'));

		$this->assertSame(
			[
				['object-op', [], []],
				['object-op', ['operation' => 'update', 'id' => 'other-object'], []],
				['', ['operation' => 'create'], []],
			],
			$this->dispatched
		);
	}//end testParametersAreNormalised()

	/**
	 * A failing dispatch is logged with its action type and does not stop the next one.
	 *
	 * @return void
	 */
	public function testAFailingDispatchIsLogged(): void {
		$this->automation([['type' => 'approval', 'onApprove' => [['type' => 'send-notification'], ['type' => 'object-op']]]]);
		$this->compiler->method('mapActionToRuleAction')->willReturnCallback(
			static fn (array $action): array => ['type' => $action['type'], 'parameters' => []]
		);
		$this->failOn = 'send-notification';

		$this->listener->handle($this->approved(chainKey: 'aut-route'));

		$this->assertSame([['object-op', [], []]], $this->dispatched);
		$this->assertSame(
			['Buildiq: ApprovalOutcomeListener follow-up dispatch failed for action "send-notification": target missing'],
			$this->errors
		);
	}//end testAFailingDispatchIsLogged()

	/**
	 * The automation `route` with these actions, as findAll returns it.
	 *
	 * @param array<int, mixed> $actions The actions.
	 *
	 * @return void
	 */
	private function automation(array $actions): void {
		$this->objectService->method('findAll')->willReturn([['slug' => 'route', 'actions' => $actions]]);
	}//end automation()

	/**
	 * The completion event for an approved sequence on object `object-1`.
	 *
	 * @param string $chainKey The sequence's chain key.
	 *
	 * @return TaskSequenceCompletedEvent The event.
	 */
	private function approved(string $chainKey): TaskSequenceCompletedEvent {
		$sequence = new TaskSequence();
		$sequence->setChainKey($chainKey);
		$sequence->setAnchorObjectUuid('object-1');
		$finalTask = new Task();
		$finalTask->setOutcome('approved');

		return new TaskSequenceCompletedEvent(sequence: $sequence, finalTask: $finalTask, decider: 'reviewer', statusOnApprove: 'approved');
	}//end approved()
}//end class
