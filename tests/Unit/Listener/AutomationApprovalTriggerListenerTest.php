<?php

/**
 * Unit tests for AutomationApprovalTriggerListener.
 *
 * Covers automation-approval-steps task 1.3 / spec REQ-AUTD-004 scenario
 * "Trigger firing initialises an approval step": an object-created event
 * matching an enabled automation's `approval` action calls
 * `TaskSequenceService::provision()`; a non-matching event, a disabled
 * automation, and an already-initialised object are all no-ops.
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

use OCA\Buildiq\Listener\AutomationApprovalTriggerListener;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see AutomationApprovalTriggerListener}.
 */
final class AutomationApprovalTriggerListenerTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var ApprovalChainAnnotationInstaller&MockObject
	 */
	private ApprovalChainAnnotationInstaller&MockObject $annotationInstaller;

	/**
	 * @var TaskSequenceMapper&MockObject
	 */
	private TaskSequenceMapper&MockObject $sequenceMapper;

	/**
	 * @var TaskSequenceService&MockObject
	 */
	private TaskSequenceService&MockObject $sequenceService;

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
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->annotationInstaller = $this->createMock(ApprovalChainAnnotationInstaller::class);
		$this->sequenceMapper = $this->createMock(TaskSequenceMapper::class);
		$this->sequenceService = $this->createMock(TaskSequenceService::class);

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		// The listener resolves OpenRegister's object service through the container
		// at USE time rather than injecting it — Nextcloud builds event listeners
		// from the SERVER container, which never carries an app's
		// registerServiceAlias(), so a constructor-injected interface cannot be
		// built at all. The mock is still an ObjectServiceInterface: only the
		// delivery route changed, not the contract under test.
		// Every OpenRegister collaborator now arrives through the container, so
		// the mock has to answer per class name rather than return one service
		// for anything asked of it.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\ApprovalChainAnnotationInstaller' => $this->annotationInstaller,
					'OCA\\OpenRegister\\Db\\TaskSequenceMapper' => $this->sequenceMapper,
					'OCA\\OpenRegister\\Service\\Task\\TaskSequenceService' => $this->sequenceService,
					default => $this->objectService,
				};
			}
		);

		$this->listener = new AutomationApprovalTriggerListener(
			$container,
			$this->schemaMapper,
			$userSession,
			new NullLogger(),
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
	 * Build a compiled task template, as ApprovalChainAnnotationInstaller
	 * returns one.
	 *
	 * @param string $templateId The deterministic template id.
	 *
	 * @return array<string, mixed> The compiled template.
	 */
	private function templateFor(string $templateId): array {
		return [
			'templateId' => $templateId,
			'templateVersion' => 1,
			'name' => 'aut-route-permit-application-for-approval',
			'schemaId' => 42,
			'positions' => [['order' => 1, 'role' => 'permit-reviewers']],
		];
	}//end templateFor()

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

		$template = $this->templateFor('tpl-permit-7');
		$this->annotationInstaller->method('compile')->willReturn($template);

		$this->sequenceMapper->method('findForAnchor')->willReturn([]);

		$this->sequenceService->expects($this->once())
			->method('provision')
			->with($template, 'object-uuid-1', null);

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
		$this->sequenceService->expects($this->never())->method('provision');

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
		$this->sequenceService->expects($this->never())->method('provision');

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
		$this->annotationInstaller->method('compile')->willReturn($this->templateFor('tpl-permit-7'));

		// A sequence already exists for (templateId, objectUuid) — the dedupe key
		// the retired (chainId, objectUuid) pair became.
		$this->sequenceMapper->method('findForAnchor')->willReturn([new \stdClass()]);

		$this->sequenceService->expects($this->never())->method('provision');

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
		$this->annotationInstaller->method('compile')->willReturn($this->templateFor('tpl-transition-1'));
		$this->sequenceMapper->method('findForAnchor')->willReturn([]);

		$this->sequenceService->expects($this->once())->method('provision');

		$entity = $this->objectEntity('permit', 'object-uuid-9');
		$event = new \OCA\OpenRegister\Event\ObjectTransitionedEvent(
			object: $entity,
			action: 'activate',
			from: 'draft',
			to: 'active',
			userId: null,
			register: 'buildiq',
			schema: 'permit'
		);
		$this->listener->handle($event);

	}//end testLifecycleTransitionMatchesOnTransitionName()
}//end class
