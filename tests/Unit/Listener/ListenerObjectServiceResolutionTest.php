<?php

/**
 * Golden tests for how the automation listeners reach OpenRegister's object service.
 *
 * DocumentGenerationListener, AutomationApprovalTriggerListener and
 * ApprovalOutcomeListener resolve the object service through the container at
 * use time, by its concrete class name, and degrade to a logged warning when it
 * cannot be resolved. These pin the id asked for and the exact warning of each
 * listener, so the shared resolver cannot change either.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Listener\ApprovalOutcomeListener;
use OCA\Buildiq\Listener\AutomationApprovalTriggerListener;
use OCA\Buildiq\Listener\DocumentGenerationListener;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\DocumentGenerationService;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the object-service resolution of the automation listeners.
 */
class ListenerObjectServiceResolutionTest extends TestCase {

	/**
	 * The container id each listener asks for.
	 */
	private const OBJECT_SERVICE_ID = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Container double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The document listener asks for the concrete class and scans with what it gets.
	 *
	 * @return void
	 */
	public function testDocumentListenerResolvesTheConcreteClass(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::once())->method('findAll')->willReturn([]);
		$this->container->expects(self::once())->method('get')->with(self::OBJECT_SERVICE_ID)->willReturn($objectService);

		$this->documentListener()->handle(new ObjectCreatedEvent($this->object()));
	}//end testDocumentListenerResolvesTheConcreteClass()

	/**
	 * The document listener logs and stops when the object service cannot be resolved.
	 *
	 * @return void
	 */
	public function testDocumentListenerDegradesWhenUnresolvable(): void {
		$this->unresolvable();
		$this->logger->expects(self::once())->method('warning')
			->with('Buildiq: DocumentGenerationListener could not scan automations: no object service');

		$this->documentListener()->handle(new ObjectCreatedEvent($this->object()));
	}//end testDocumentListenerDegradesWhenUnresolvable()

	/**
	 * The approval trigger listener logs and stops when the object service cannot be resolved.
	 *
	 * @return void
	 */
	public function testApprovalTriggerListenerDegradesWhenUnresolvable(): void {
		$this->unresolvable();
		$this->logger->expects(self::once())->method('warning')
			->with('Buildiq: AutomationApprovalTriggerListener could not scan automations: no object service');

		$listener = new AutomationApprovalTriggerListener(
			$this->container,
			$this->createMock(SchemaMapper::class),
			$this->createMock(IUserSession::class),
			$this->logger,
		);
		$listener->handle(new ObjectCreatedEvent($this->object()));
	}//end testApprovalTriggerListenerDegradesWhenUnresolvable()

	/**
	 * The approval outcome listener logs and stops when the object service cannot be resolved.
	 *
	 * @return void
	 */
	public function testApprovalOutcomeListenerDegradesWhenUnresolvable(): void {
		$this->unresolvable();
		$this->logger->expects(self::once())->method('warning')
			->with('Buildiq: ApprovalOutcomeListener could not resolve automation "permit": no object service');
		$dispatcher = $this->createMock(RuleActionDispatcher::class);
		$dispatcher->expects(self::never())->method('__invoke');

		$listener = new ApprovalOutcomeListener($this->container, $this->createMock(AutomationCompilerService::class), $dispatcher, $this->logger);
		$listener->handle($this->completed(chainKey: 'aut-permit'));
	}//end testApprovalOutcomeListenerDegradesWhenUnresolvable()

	/**
	 * Make the container refuse the object service.
	 *
	 * @return void
	 */
	private function unresolvable(): void {
		$this->container->method('get')->with(self::OBJECT_SERVICE_ID)->willThrowException(new RuntimeException('no object service'));
	}//end unresolvable()

	/**
	 * The document listener under test.
	 *
	 * @return DocumentGenerationListener
	 */
	private function documentListener(): DocumentGenerationListener {
		return new DocumentGenerationListener($this->container, $this->createMock(DocumentGenerationService::class), $this->logger);
	}//end documentListener()

	/**
	 * A created object of the permit-application schema.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function object(): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn('permit-application');
		$entity->method('getUuid')->willReturn('object-uuid-1');
		return $entity;
	}//end object()

	/**
	 * The completion event of an approved, automation-owned sequence.
	 *
	 * @param string $chainKey The sequence's chain key.
	 *
	 * @return TaskSequenceCompletedEvent
	 */
	private function completed(string $chainKey): TaskSequenceCompletedEvent {
		$sequence = new TaskSequence();
		$sequence->setChainKey($chainKey);
		$sequence->setAnchorObjectUuid('object-uuid-1');
		$finalTask = new Task();
		$finalTask->setOutcome('approved');
		return new TaskSequenceCompletedEvent(sequence: $sequence, finalTask: $finalTask, decider: 'reviewer', statusOnApprove: 'approved');
	}//end completed()
}//end class
