<?php

/**
 * Unit tests for DocumentGenerationListener.
 *
 * Covers automation-document-action task 3.1 / spec REQ-AUTD-004 scenario
 * "Document-generation action fires via the pinned Docudesk route": an
 * object event/lifecycle-transition matching an enabled automation's
 * `generateDocument` action calls `DocumentGenerationService::generate()`; a
 * non-matching event, a disabled automation, and an automation with no
 * `generateDocument` action are all no-ops.
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
 * @spec openspec/changes/automation-document-action/tasks.md#6.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Listener\DocumentGenerationListener;
use OCA\Buildiq\Service\AutomationRunLog;
use OCA\Buildiq\Service\DocumentGenerationService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see DocumentGenerationListener}.
 */
final class DocumentGenerationListenerTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var DocumentGenerationService&MockObject
	 */
	private DocumentGenerationService&MockObject $documentGenerator;

	/**
	 * Listener under test.
	 *
	 * @var DocumentGenerationListener
	 */
	private DocumentGenerationListener $listener;

	/**
	 * Set up mocks + SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->documentGenerator = $this->createMock(DocumentGenerationService::class);

		// Resolved through the container at USE time — Nextcloud builds event
		// listeners from the SERVER container, which never carries an app's
		// registerServiceAlias(), so a constructor-injected interface cannot be
		// built. The mock is unchanged; only the delivery route is.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->listener = new DocumentGenerationListener(
			$container,
			$this->documentGenerator,
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
	 * A matching, enabled automation's `generateDocument` action is
	 * dispatched for the fired object.
	 *
	 * @return void
	 */
	public function testMatchingEnabledAutomationDispatchesGeneration(): void {
		$automation = [
			'slug' => 'gen-on-create',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);

		$this->documentGenerator->expects($this->once())
			->method('generate')
			->with($automation, $automation['actions'][0], 'permit-application', 'object-uuid-1')
			->willReturn(true);

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testMatchingEnabledAutomationDispatchesGeneration()

	/**
	 * A disabled automation is never dispatched.
	 *
	 * @return void
	 */
	public function testDisabledAutomationIsSkipped(): void {
		$automation = [
			'slug' => 'gen-disabled',
			'enabled' => false,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->documentGenerator->expects($this->never())->method('generate');

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testDisabledAutomationIsSkipped()

	/**
	 * An automation on a DIFFERENT schema is never dispatched.
	 *
	 * @return void
	 */
	public function testNonMatchingSchemaIsSkipped(): void {
		$automation = [
			'slug' => 'gen-other-schema',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'some-other-schema'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->documentGenerator->expects($this->never())->method('generate');

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testNonMatchingSchemaIsSkipped()

	/**
	 * An automation with NO `generateDocument` action (e.g. only
	 * `send-notification`) is never dispatched.
	 *
	 * @return void
	 */
	public function testAutomationWithoutGenerateDocumentActionIsSkipped(): void {
		$automation = [
			'slug' => 'notify-only',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'actions' => [['type' => 'send-notification', 'subject' => ['en' => 'x']]],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->documentGenerator->expects($this->never())->method('generate');

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

	}//end testAutomationWithoutGenerateDocumentActionIsSkipped()

	/**
	 * No idempotency guard: repeated firing dispatches generation again each
	 * time (design.md Risks/Trade-offs — each firing legitimately generates
	 * a NEW document).
	 *
	 * @return void
	 */
	public function testRepeatedFiringDispatchesEachTime(): void {
		$automation = [
			'slug' => 'gen-on-update',
			'enabled' => true,
			'trigger' => ['type' => 'object-updated', 'schema' => 'permit-application'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->documentGenerator->expects($this->exactly(2))->method('generate')->willReturn(true);

		$event = new ObjectUpdatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);
		$this->listener->handle($event);

	}//end testRepeatedFiringDispatchesEachTime()

	/**
	 * A lifecycle-transition event matches only an automation declaring the
	 * SAME transition action name.
	 *
	 * @return void
	 */
	public function testLifecycleTransitionMatchesOnTransitionName(): void {
		$automation = [
			'slug' => 'gen-on-approve',
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'approve'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->documentGenerator->expects($this->once())
			->method('generate')
			->with($automation, $automation['actions'][0], 'permit', 'object-uuid-9')
			->willReturn(true);

		$entity = $this->objectEntity('permit', 'object-uuid-9');
		$event = new ObjectTransitionedEvent(
			object: $entity,
			move: new TransitionMove(action: 'approve', from: 'draft', to: 'approved'),
			scope: new RegisterSchemaRef(register: 'buildiq', schema: 'permit'),
		);
		$this->listener->handle($event);

	}//end testLifecycleTransitionMatchesOnTransitionName()

	/**
	 * A listener-level throw from `generate()` is caught and logged, never
	 * propagated (one bad automation must not block the others / the caller).
	 *
	 * @return void
	 */
	public function testGenerationFailureIsCaughtNotPropagated(): void {
		$automation = [
			'slug' => 'gen-throws',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->objectService->method('findAll')->willReturn([$automation]);
		$this->documentGenerator->method('generate')->willThrowException(new RuntimeException('boom'));

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

		$this->addToAssertionCount(1);

	}//end testGenerationFailureIsCaughtNotPropagated()

	/**
	 * Each generated document is one run of its automation, with the trigger and the object as subject.
	 *
	 * @return void
	 */
	public function testAGeneratedDocumentIsRecordedAsASucceededRun(): void {
		$runs = $this->recordingListener(error: null);

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

		$this->assertSame(
			[
				['start', 'object-created', ['schema' => 'permit-application', 'uuid' => 'object-uuid-1']],
				['step', 'generateDocument', 'succeeded', 'Document generated'],
				['finish', 'succeeded', null],
			],
			$runs->calls
		);

	}//end testAGeneratedDocumentIsRecordedAsASucceededRun()

	/**
	 * A document that could not be generated is a failed run carrying the reason.
	 *
	 * @return void
	 */
	public function testAFailedDocumentIsRecordedAsAFailedRun(): void {
		$runs = $this->recordingListener(error: 'Docudesk answered 500');

		$event = new ObjectCreatedEvent($this->objectEntity('permit-application', 'object-uuid-1'));
		$this->listener->handle($event);

		$this->assertSame(
			[
				['start', 'object-created', ['schema' => 'permit-application', 'uuid' => 'object-uuid-1']],
				['step', 'generateDocument', 'failed', 'Docudesk answered 500'],
				['finish', 'failed', 'Docudesk answered 500'],
			],
			$runs->calls
		);

	}//end testAFailedDocumentIsRecordedAsAFailedRun()

	/**
	 * Rebuild the listener with a run log over a recorder that records its calls.
	 *
	 * @param string|null $error What generation throws, or null when it succeeds.
	 *
	 * @return object{calls: array<int, array<int, mixed>>} The recorded calls.
	 */
	private function recordingListener(?string $error): object {
		$runs     = new \stdClass();
		$runs->calls = [];
		$recorder = $this->createMock(AutomationRunRecorderInterface::class);
		$recorder->method('start')->willReturnCallback(
			static function (array $automation, string $trigger, ?array $subject, string $actor) use ($runs): array {
				$runs->calls[] = ['start', $trigger, $subject];
				return ['automation' => 'a1'];
			}
		);
		$recorder->method('step')->willReturnCallback(
			static function (array $run, string $action, string $outcome, string $message = '') use ($runs): array {
				$runs->calls[] = ['step', $action, $outcome, $message];
				return $run;
			}
		);
		$recorder->method('finish')->willReturnCallback(
			static function (array $run, string $outcome, ?string $reason = null) use ($runs): string {
				$runs->calls[] = ['finish', $outcome, $reason];
				return 'run-1';
			}
		);

		$this->objectService->method('findAll')->willReturn(
			[
				[
					'slug' => 'gen-on-create',
					'enabled' => true,
					'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
					'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
				],
			]
		);
		if ($error === null) {
			$this->documentGenerator->method('generate')->willReturn(true);
		} else {
			$this->documentGenerator->method('generate')->willThrowException(new RuntimeException($error));
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);
		$this->listener = new DocumentGenerationListener(
			$container,
			$this->documentGenerator,
			new NullLogger(),
			new AutomationRunLog(recorder: $recorder, objectService: $this->objectService, logger: new NullLogger())
		);

		return $runs;

	}//end recordingListener()
}//end class
