<?php

/**
 * Unit tests for running a manual automation from a record.
 *
 * Covers REQ-BQAR-006: the run route refuses a caller without a role on the
 * app, a record the caller cannot read and an automation that is not manual
 * and enabled, and starts the automation's flow on the record otherwise; the
 * listing answers only a caller with a role on the app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AutomationsController;
use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Service\Automation\ManualAutomationRun;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\AutomationWriteService;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ConditionActionExecutor;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowRunOptions;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see AutomationsController::run()} and {@see AutomationsController::manual()}.
 *
 * @covers \OCA\Buildiq\Controller\AutomationsController
 * @covers \OCA\Buildiq\Service\Automation\ManualAutomationRun
 * @uses   \OCA\Buildiq\Service\AutomationWriteService
 * @uses   \OCA\Buildiq\Service\CallerContext
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 * @uses   \OCA\Buildiq\Service\Automation\AutomationRecords
 */
final class AutomationsControllerManualRunTest extends TestCase {

	private const APPLICATION = [
		'id' => 'app-1',
		'slug' => 'permits',
		'permissions' => ['owners' => ['user:olga'], 'viewers' => ['user:clerk']],
	];

	private const AUTOMATION = [
		'id' => 'aut-1',
		'name' => 'Send to inspection',
		'applicationSlug' => 'permits',
		'versionUuid' => 'v-1',
		'enabled' => true,
		'trigger' => ['type' => 'manual', 'schema' => 'vergunning'],
		'provenance' => ['flowUuid' => 'flow-9'],
	];

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flows;

	/**
	 * @var AutomationRunRecorderInterface&MockObject
	 */
	private AutomationRunRecorderInterface&MockObject $recorder;

	/**
	 * The controller under test.
	 *
	 * @var AutomationsController
	 */
	private AutomationsController $controller;

	/**
	 * Wire the controller with a real manual-run service over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request       = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession   = $this->createMock(IUserSession::class);
		$this->flows         = $this->createMock(FlowService::class);
		$this->recorder      = $this->createMock(originalClassName: AutomationRunRecorderInterface::class);
		$groupManager        = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);
		$permissionResolver = new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class));
		$compiler           = $this->createMock(AutomationCompilerService::class);
		$container          = $this->createMock(ContainerInterface::class);
		// OpenRegister stores a record's schema as its id: schema 43 is `vergunning`.
		$slugs = $this->createMock(originalClassName: ObjectSchemaSlugResolverInterface::class);
		$slugs->method('schemaSlug')->willReturnCallback(
			static fn (object $entity): string => match ((string)$entity->getSchema()) {
				'43' => 'vergunning',
				'44' => 'melding',
				default => '',
			}
		);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				FlowService::class => $this->flows,
				ObjectSchemaSlugResolverInterface::class => $slugs,
			}
		);

		$this->controller = new AutomationsController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			compiler: $compiler,
			conditionExecutor: $this->createMock(ConditionActionExecutor::class),
			caller: new CallerContext(userSession: $this->userSession, groupManager: $groupManager, permissionResolver: $permissionResolver),
			writeService: new AutomationWriteService(
				logger: $this->createMock(LoggerInterface::class),
				objectService: $this->objectService,
				compiler: $compiler,
				permissionResolver: $permissionResolver,
				userSession: $this->userSession
			),
			manualRuns: new ManualAutomationRun(container: $container, objectService: $this->objectService, runs: $this->recorder)
		);
	}//end setUp()

	/**
	 * A colleague without a role on the app is refused and no flow starts.
	 *
	 * @return void
	 */
	public function testACallerWithoutARoleIsForbidden(): void {
		$this->signIn(uid: 'colleague');
		$this->wireObjects(automation: self::AUTOMATION, record: $this->record(schema: 'vergunning'));
		$this->flows->expects($this->never())->method('runForAction');

		$response = $this->controller->run(uuid: 'aut-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('insufficient_permission', $response->getData()['error']);
	}//end testACallerWithoutARoleIsForbidden()

	/**
	 * A record the caller cannot read is refused and no flow starts.
	 *
	 * @return void
	 */
	public function testARecordTheCallerCannotReadIsForbidden(): void {
		$this->signIn(uid: 'clerk');
		$this->wireObjects(automation: self::AUTOMATION, record: null);
		$this->flows->expects($this->never())->method('runForAction');

		$response = $this->controller->run(uuid: 'aut-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('record_not_readable', $response->getData()['error']);
	}//end testARecordTheCallerCannotReadIsForbidden()

	/**
	 * A record of another schema than the automation's trigger is refused.
	 *
	 * @return void
	 */
	public function testARecordOfAnotherSchemaIsForbidden(): void {
		$this->signIn(uid: 'clerk');
		$this->wireObjects(automation: self::AUTOMATION, record: $this->record(schema: 'melding'));
		$this->flows->expects($this->never())->method('runForAction');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->run(uuid: 'aut-1')->getStatus());
	}//end testARecordOfAnotherSchemaIsForbidden()

	/**
	 * An automation that is not manual, or not enabled, is refused.
	 *
	 * @return void
	 */
	public function testAnAutomationThatIsNotManualAndEnabledIsRefused(): void {
		$this->signIn(uid: 'clerk');
		$scheduled = ['trigger' => ['type' => 'schedule', 'schema' => 'vergunning']] + self::AUTOMATION;
		$disabled  = ['enabled' => false] + self::AUTOMATION;
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id) use ($scheduled, $disabled): ?ObjectEntity {
				return match ($id) {
					'aut-scheduled' => $this->entity(payload: $scheduled),
					'aut-disabled' => $this->entity(payload: $disabled),
					'permits' => $this->entity(payload: self::APPLICATION),
					default => $this->record(schema: 'vergunning'),
				};
			}
		);
		$this->flows->expects($this->never())->method('runForAction');

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->run(uuid: 'aut-scheduled')->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $this->controller->run(uuid: 'aut-disabled')->getStatus());
	}//end testAnAutomationThatIsNotManualAndEnabledIsRefused()

	/**
	 * A user of the app starts the automation's flow on the record they read.
	 *
	 * @return void
	 */
	public function testAUserOfTheAppStartsTheFlowOnTheRecord(): void {
		$this->signIn(uid: 'clerk');
		$this->wireObjects(automation: self::AUTOMATION, record: $this->record(schema: 'vergunning'));
		$run = $this->createMock(FlowRun::class);
		$run->method('jsonSerialize')->willReturn(['uuid' => 'run-5']);
		$this->flows->expects($this->once())->method('runForAction')->with(
			'flow-9',
			$this->callback(
				static fn (FlowRunOptions $options): bool => $options->subject === ['uuid' => 'rec-42', 'register' => 'permits-reg', 'schema' => 'vergunning']
					&& $options->sync === false
					&& $options->trigger === 'manual'
					&& $options->context === ['automation' => 'aut-1']
			)
		)->willReturn($run);

		$response = $this->controller->run(uuid: 'aut-1');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['started' => true, 'flowUuid' => 'flow-9', 'run' => 'run-5'], $response->getData());
	}//end testAUserOfTheAppStartsTheFlowOnTheRecord()

	/**
	 * A record whose schema OpenRegister stores as its id is matched on the
	 * slug that id resolves to: schema 43 (`vergunning`) runs, 44 (`melding`)
	 * is refused.
	 *
	 * @return void
	 */
	public function testTheRecordSchemaIdIsMatchedOnItsSlug(): void {
		$this->signIn(uid: 'clerk');
		$run = $this->createMock(originalClassName: FlowRun::class);
		$run->method('jsonSerialize')->willReturn(['uuid' => 'run-6']);
		$this->flows->expects($this->once())->method('runForAction')->willReturn($run);

		$this->wireObjects(automation: self::AUTOMATION, record: $this->record(schema: '43'));
		$this->assertSame(expected: Http::STATUS_ACCEPTED, actual: $this->controller->run(uuid: 'aut-1')->getStatus());
	}//end testTheRecordSchemaIdIsMatchedOnItsSlug()

	/**
	 * A record of another schema, stored as its id, is refused.
	 *
	 * @return void
	 */
	public function testARecordOfAnotherSchemaIdIsRefused(): void {
		$this->signIn(uid: 'clerk');
		$this->flows->expects($this->never())->method('runForAction');

		$this->wireObjects(automation: self::AUTOMATION, record: $this->record(schema: '44'));
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->run(uuid: 'aut-1')->getStatus());
	}//end testARecordOfAnotherSchemaIdIsRefused()

	/**
	 * The started run is recorded waiting, as the caller, naming its flow run;
	 * a refused run records nothing.
	 *
	 * @return void
	 */
	public function testAStartedRunIsRecordedWaitingWithItsFlowRun(): void {
		$this->signIn(uid: 'clerk');
		$this->wireObjects(automation: self::AUTOMATION, record: $this->record(schema: 'vergunning'));
		$run = $this->createMock(originalClassName: FlowRun::class);
		$run->method('jsonSerialize')->willReturn(['uuid' => 'run-5']);
		$this->flows->method('runForAction')->willReturn($run);
		$this->recorder->expects($this->once())->method('start')
			->with(self::AUTOMATION, 'manual', ['schema' => 'vergunning', 'uuid' => 'rec-42'], 'clerk')
			->willReturn(['automation' => 'aut-1', 'trigger' => 'manual']);
		$this->recorder->expects($this->once())->method('finish')
			->with(['automation' => 'aut-1', 'trigger' => 'manual', 'flowRun' => 'run-5'], 'waiting')
			->willReturn('ar-1');

		$this->assertSame(expected: Http::STATUS_ACCEPTED, actual: $this->controller->run(uuid: 'aut-1')->getStatus());
	}//end testAStartedRunIsRecordedWaitingWithItsFlowRun()

	/**
	 * A run that is refused writes no run record.
	 *
	 * @return void
	 */
	public function testARefusedRunRecordsNothing(): void {
		$this->signIn(uid: 'colleague');
		$this->wireObjects(automation: self::AUTOMATION, record: $this->record(schema: 'vergunning'));
		$this->recorder->expects($this->never())->method('start');
		$this->recorder->expects($this->never())->method('finish');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller->run(uuid: 'aut-1')->getStatus());
	}//end testARefusedRunRecordsNothing()

	/**
	 * The listing answers a user of the app with the enabled manual automations of the schema only.
	 *
	 * @return void
	 */
	public function testTheListingHoldsTheEnabledManualAutomationsOfTheSchema(): void {
		$this->signIn(uid: 'clerk');
		$this->objectService->method('find')->willReturn($this->entity(payload: self::APPLICATION));
		$this->objectService->expects($this->once())->method('findAll')->willReturn(
			[
				$this->entity(payload: self::AUTOMATION),
				$this->entity(payload: ['id' => 'aut-2', 'enabled' => false] + self::AUTOMATION),
				$this->entity(payload: ['id' => 'aut-3', 'trigger' => ['type' => 'manual', 'schema' => 'melding']] + self::AUTOMATION),
				$this->entity(payload: ['id' => 'aut-4', 'provenance' => []] + self::AUTOMATION),
			]
		);

		$response = $this->controller->manual();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['uuid' => 'aut-1', 'name' => 'Send to inspection']]], $response->getData());
	}//end testTheListingHoldsTheEnabledManualAutomationsOfTheSchema()

	/**
	 * The listing refuses a caller without a role on the app and reads nothing.
	 *
	 * @return void
	 */
	public function testTheListingRefusesACallerWithoutARole(): void {
		$this->signIn(uid: 'colleague');
		$this->objectService->method('find')->willReturn($this->entity(payload: self::APPLICATION));
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->manual()->getStatus());
	}//end testTheListingRefusesACallerWithoutARole()

	/**
	 * Sign a user in.
	 *
	 * @param string $uid The UID.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ['object' => 'rec-42', 'app' => 'permits', 'schema' => 'vergunning'][$key] ?? null);
	}//end signIn()

	/**
	 * Answer find() with the automation, the application and the record (null: not readable).
	 *
	 * @param array<string,mixed> $automation The automation.
	 * @param ObjectEntity|null   $record     The record as the caller reads it.
	 *
	 * @return void
	 */
	private function wireObjects(array $automation, ?ObjectEntity $record): void {
		$this->objectService->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => match ($id) {
				'aut-1' => $this->entity(payload: $automation),
				'permits' => $this->entity(payload: self::APPLICATION),
				default => $record,
			}
		);
	}//end wireObjects()

	/**
	 * A record of the given schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity
	 */
	private function record(string $schema): ObjectEntity {
		$record = $this->createMock(ObjectEntity::class);
		$record->method('jsonSerialize')->willReturn(['id' => 'rec-42']);
		$record->method('getUuid')->willReturn('rec-42');
		$record->method('getRegister')->willReturn('permits-reg');
		$record->method('getSchema')->willReturn($schema);

		return $record;
	}//end record()

	/**
	 * An entity serialising to the payload.
	 *
	 * @param array<string,mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);

		return $entity;
	}//end entity()
}//end class
