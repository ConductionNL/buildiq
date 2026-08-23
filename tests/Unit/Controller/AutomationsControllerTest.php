<?php

/**
 * Unit tests for AutomationsController.
 *
 * Covers spec REQ-AUTD-008: 403 for a non-member, editor allowed on a draft
 * (non-production) version, editor 403 / owner 200 on production enable, and
 * NC admin-bypass NOT honoured on production enable.
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
 * @spec openspec/changes/automation-designer/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AutomationsController;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\AutomationWriteService;
use OCA\Buildiq\Service\ConditionActionExecutor;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see AutomationsController}.
 */
final class AutomationsControllerTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var AutomationCompilerService&MockObject
	 */
	private AutomationCompilerService&MockObject $compiler;

	/**
	 * @var ConditionActionExecutor&MockObject
	 */
	private ConditionActionExecutor&MockObject $conditionExecutor;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The controller under test.
	 *
	 * @var AutomationsController
	 */
	private AutomationsController $controller;

	/**
	 * Wire the controller with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->compiler = $this->createMock(AutomationCompilerService::class);
		$this->conditionExecutor = $this->createMock(ConditionActionExecutor::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		// isAdmin() is only ever exercised via the "admin" NC group check;
		// wired per-test to true/false as needed (default false here).
		$this->groupManager->method('isAdmin')->willReturn(false);

		$permissionResolver = new PermissionResolver($this->groupManager, $this->createMock(LoggerInterface::class));

		// The write service is wired REAL (over the same mocked boundaries)
		// rather than mocked: the controller's create/update/destroy are pure
		// delegation, so a mocked collaborator would assert only that the
		// delegation happened and nothing about what it does.
		$writeService = new AutomationWriteService(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			compiler: $this->compiler,
			permissionResolver: $permissionResolver,
			userSession: $this->userSession
		);

		$this->controller = new AutomationsController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			compiler: $this->compiler,
			conditionExecutor: $this->conditionExecutor,
			permissionResolver: $permissionResolver,
			userSession: $this->userSession,
			writeService: $writeService
		);

	}//end setUp()

	/**
	 * Wire a Nextcloud user with the given UID into the session mock.
	 *
	 * @param string $uid The UID.
	 *
	 * @return void
	 */
	private function wireUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end wireUser()

	/**
	 * Build an ObjectEntity carrying a JSON payload (mirrors
	 * VersionPromotionControllerTest's fixture builder).
	 *
	 * @param array<string,mixed> $payload The inner payload.
	 *
	 * @return ObjectEntity
	 */
	private function buildEntity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string,mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;

		return $entity;
	}//end buildEntity()

	/**
	 * Wire `objectService->find()` to return the automation first, then the
	 * application (the controller's own resolution order).
	 *
	 * @param array<string,mixed> $automation The automation payload.
	 * @param array<string,mixed> $application The application payload.
	 *
	 * @return void
	 */
	private function wireLookup(array $automation, array $application): void {
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->buildEntity($automation),
			$this->buildEntity($application)
		);

	}//end wireLookup()

	/**
	 * 403 for a caller with no role on the Application at all.
	 *
	 * @return void
	 */
	public function testEnableReturns403ForNonMember(): void {
		$this->wireUser(uid: 'eve-outsider');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);

		$this->compiler->expects($this->never())->method('compile');

		$response = $this->controller->enable(uuid: 'a-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testEnableReturns403ForNonMember()

	/**
	 * An editor can enable an automation on a NON-production (draft) version.
	 *
	 * @return void
	 */
	public function testEditorCanEnableOnDraftVersion(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'enabled' => false],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
				'productionVersion' => 'production-version',
			]
		);

		$this->compiler->method('compile')->willReturn(['notifications' => [], 'lifecycleActions' => [], 'schedules' => [], 'ruleSet' => null, 'conditionActionRule' => null, 'hash' => 'sha256:x']);
		$this->compiler->method('apply')->willReturn(['notificationKeys' => [], 'lifecycleActions' => [], 'scheduleIds' => [], 'ruleSetSlug' => null, 'openconnectorObjects' => [], 'compiledHash' => 'sha256:x']);

		$this->objectService->method('saveObject')->willReturn($this->buildEntity(['id' => 'a-1', 'enabled' => true]));

		$response = $this->controller->enable(uuid: 'a-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testEditorCanEnableOnDraftVersion()

	/**
	 * REQ-AUTD-008: an editor CANNOT enable on the Application's current
	 * production version.
	 *
	 * @return void
	 */
	public function testEditorCannotEnableOnProductionVersion(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'production-version', 'enabled' => false],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
				'productionVersion' => 'production-version',
			]
		);

		$this->compiler->expects($this->never())->method('compile');

		$response = $this->controller->enable(uuid: 'a-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testEditorCannotEnableOnProductionVersion()

	/**
	 * REQ-AUTD-008: an owner CAN enable on the Application's current
	 * production version.
	 *
	 * @return void
	 */
	public function testOwnerCanEnableOnProductionVersion(): void {
		$this->wireUser(uid: 'alice');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'production-version', 'enabled' => false],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
				'productionVersion' => 'production-version',
			]
		);

		$this->compiler->method('compile')->willReturn(['notifications' => [], 'lifecycleActions' => [], 'schedules' => [], 'ruleSet' => null, 'conditionActionRule' => null, 'hash' => 'sha256:x']);
		$this->compiler->method('apply')->willReturn(['notificationKeys' => [], 'lifecycleActions' => [], 'scheduleIds' => [], 'ruleSetSlug' => null, 'openconnectorObjects' => [], 'compiledHash' => 'sha256:x']);
		$this->objectService->method('saveObject')->willReturn($this->buildEntity(['id' => 'a-1', 'enabled' => true]));

		$response = $this->controller->enable(uuid: 'a-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testOwnerCanEnableOnProductionVersion()

	/**
	 * REQ-AUTD-008: an NC admin who is NOT owner/editor is still rejected on
	 * production enable — no admin bypass (allowAdminBypass: false).
	 *
	 * @return void
	 */
	public function testAdminBypassNotHonouredOnProductionEnable(): void {
		$this->wireUser(uid: 'admin');
		// Even if isAdmin() were true, matchesCaller() is called with
		// allowAdminBypass:false, so it must NEVER be consulted for the
		// bypass branch. Wiring it true here proves the bypass truly isn't used.
		$this->groupManager->method('isAdmin')->willReturn(true);

		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'production-version', 'enabled' => false],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
				'productionVersion' => 'production-version',
			]
		);

		$this->compiler->expects($this->never())->method('compile');

		$response = $this->controller->enable(uuid: 'a-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAdminBypassNotHonouredOnProductionEnable()

	/**
	 * 401 when no session user is present.
	 *
	 * @return void
	 */
	public function testReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->compile(uuid: 'a-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testReturns401WhenUnauthenticated()

	/**
	 * 404 when the automation uuid does not resolve.
	 *
	 * @return void
	 */
	public function testReturns404WhenAutomationMissing(): void {
		$this->wireUser(uid: 'alice');
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller->compile(uuid: 'missing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testReturns404WhenAutomationMissing()

	/**
	 * A matrix rejection from the compiler maps to 422 with the code surfaced.
	 *
	 * @return void
	 */
	public function testCompileReturns422OnUnsupportedCombination(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);

		$this->compiler->method('compile')->willThrowException(
			new \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException('bad combination')
		);

		$response = $this->controller->compile(uuid: 'a-1');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testCompileReturns422OnUnsupportedCombination()

	/**
	 * REQ-AUTD-007: dry-run compiles the automation in-memory and evaluates
	 * it via the rules engine with dryRun:true, returning the condition
	 * match + would-be actions without dispatching any side effect.
	 *
	 * @return void
	 */
	public function testDryRunReturnsWouldBeActionsWithoutSideEffects(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'trigger' => ['type' => 'manual']],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);

		$this->request->method('getParams')->willReturn(['payload' => ['amount' => 5000]]);

		$this->compiler->expects($this->once())
			->method('compileDryRunRule')
			->willReturn(['name' => 'a', 'condition' => '', 'actions' => [['type' => 'send-notification', 'parameters' => []]], 'active' => true]);

		$this->conditionExecutor->expects($this->once())
			->method('execute')
			->with(
				$this->anything(),
				['amount' => 5000],
				true,
				null
			)
			->willReturn(['triggeredRules' => [['id' => 'a', 'name' => 'a', 'actions_executed' => ['send-notification (dry-run, skipped)']]], 'result' => [], 'errors' => []]);

		$response = $this->controller->dryRun(uuid: 'a-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['conditionMatched']);
		$this->assertSame(['send-notification (dry-run, skipped)'], $data['actions']);

	}//end testDryRunReturnsWouldBeActionsWithoutSideEffects()

	/**
	 * automation-approval-steps REQ-AUTD-007 task 5.2: dry-run response
	 * additionally reports the automation's live `approvalState`.
	 *
	 * @return void
	 */
	public function testDryRunReportsApprovalState(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'], 'provenance' => ['approvalChainName' => 'aut-x']],
			application: ['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']]]
		);

		$this->request->method('getParams')->willReturn(['payload' => []]);
		$this->compiler->method('compileDryRunRule')->willReturn(['name' => 'a', 'condition' => '', 'actions' => [], 'active' => true]);
		$this->conditionExecutor->method('execute')->willReturn(['triggeredRules' => [], 'result' => [], 'errors' => []]);
		$this->compiler->expects($this->once())->method('approvalState')->willReturn('pending');

		$response = $this->controller->dryRun(uuid: 'a-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('pending', $response->getData()['approvalState']);

	}//end testDryRunReportsApprovalState()

	/**
	 * automation-approval-steps REQ-AUTD-007 task 5.1: status() additionally
	 * reports the automation's live `approvalState`.
	 *
	 * @return void
	 */
	public function testStatusReportsApprovalState(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'provenance' => ['approvalChainName' => 'aut-x']],
			application: ['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']]]
		);

		$this->compiler->method('status')->willReturn(['drift' => false, 'compiledHash' => 'sha256:x', 'liveHash' => 'sha256:x']);
		$this->compiler->expects($this->once())->method('approvalState')->willReturn('approved');

		$response = $this->controller->status(uuid: 'a-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('approved', $response->getData()['approvalState']);

	}//end testStatusReportsApprovalState()

	/**
	 * disable(): an editor can disable an automation on a draft version, and
	 * the recompile is driven with `enabled: false`.
	 *
	 * Wire-contract test for the `disable` endpoint (gate-25). `enable` was
	 * covered from five angles and its mirror image from none.
	 *
	 * @return void
	 */
	public function testEditorCanDisableOnDraftVersion(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'enabled' => true],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
				'productionVersion' => 'production-version',
			]
		);

		$this->compiler->method('compile')->willReturn(['notifications' => [], 'lifecycleActions' => [], 'schedules' => [], 'ruleSet' => null, 'conditionActionRule' => null, 'hash' => 'sha256:x']);
		$this->compiler->method('apply')->willReturn(['notificationKeys' => [], 'lifecycleActions' => [], 'scheduleIds' => [], 'ruleSetSlug' => null, 'openconnectorObjects' => [], 'compiledHash' => 'sha256:x']);

		$this->objectService->method('saveObject')->willReturn($this->buildEntity(['id' => 'a-1', 'enabled' => false]));

		$response = $this->controller->disable(uuid: 'a-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testEditorCanDisableOnDraftVersion()

	/**
	 * disable(): a non-member is forbidden and nothing is recompiled.
	 *
	 * @return void
	 */
	public function testDisableReturns403ForNonMember(): void {
		$this->wireUser(uid: 'eve-outsider');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'enabled' => true],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);

		$this->compiler->expects($this->never())->method('compile');

		$response = $this->controller->disable(uuid: 'a-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testDisableReturns403ForNonMember()

	/**
	 * REQ-AUTD-008 / Conduction/buildiq#173: create() authorises against the
	 * parent Application named in the body and 201s an editor.
	 *
	 * @return void
	 */
	public function testCreateReturns201ForEditorOfTheNamedApplication(): void {
		$this->wireUser(uid: 'bob');
		$this->request->method('getParams')->willReturn(
			[
				'_route' => 'buildiq.automations.create',
				'applicationSlug' => 'permit-tracker',
				'versionUuid' => 'draft-version',
				'slug' => 'nag-on-overdue',
			]
		);
		// create() resolves ONLY the Application (there is no stored
		// automation yet).
		$this->objectService->method('find')->willReturn(
			$this->buildEntity(
				[
					'id' => 'app-1',
					'slug' => 'permit-tracker',
					'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
				]
			)
		);
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturn($this->buildEntity(['id' => 'a-new', 'slug' => 'nag-on-overdue']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('a-new', $response->getData()['id']);

	}//end testCreateReturns201ForEditorOfTheNamedApplication()

	/**
	 * REQ-AUTD-008: a caller holding no role on the named Application cannot
	 * create an automation on it, and nothing is written.
	 *
	 * @return void
	 */
	public function testCreateReturns403ForNonMember(): void {
		$this->wireUser(uid: 'eve-outsider');
		$this->request->method('getParams')->willReturn(
			[
				'applicationSlug' => 'permit-tracker',
				'versionUuid' => 'draft-version',
			]
		);
		$this->objectService->method('find')->willReturn(
			$this->buildEntity(
				[
					'id' => 'app-1',
					'slug' => 'permit-tracker',
					'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
				]
			)
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCreateReturns403ForNonMember()

	/**
	 * REQ-AUTD-008: `applicationSlug`/`versionUuid` ARE the authorization
	 * scope, so a create without them is a 400 — never an unscoped write.
	 *
	 * @return void
	 */
	public function testCreateReturns400WithoutAuthorizationScope(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParams')->willReturn(['slug' => 'nag-on-overdue']);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);

	}//end testCreateReturns400WithoutAuthorizationScope()

	/**
	 * REQ-AUTD-008: update() is authorised against the STORED record's parent
	 * Application, so a non-member is forbidden and nothing is written.
	 *
	 * @return void
	 */
	public function testUpdateReturns403ForNonMember(): void {
		$this->wireUser(uid: 'eve-outsider');
		$this->request->method('getParams')->willReturn(['slug' => 'renamed']);
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->update(uuid: 'a-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testUpdateReturns403ForNonMember()

	/**
	 * REQ-AUTD-008: end-to-end through the route, the ownership fields are
	 * pinned to the STORED values — a body naming a different application does
	 * not re-parent the record.
	 *
	 * @return void
	 */
	public function testUpdatePinsApplicationScopeThroughTheRoute(): void {
		$this->wireUser(uid: 'bob');
		$this->request->method('getParams')->willReturn(
			[
				'uuid' => 'a-1',
				'applicationSlug' => 'attacker-owned-app',
				'versionUuid' => 'attacker-version',
				'slug' => 'renamed',
			]
		);
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);

		$written = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (...$arguments) use (&$written): ObjectEntity {
				$written = $arguments[0];
				return $this->buildEntity(['id' => 'a-1']);
			});

		$response = $this->controller->update(uuid: 'a-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertIsArray($written);
		$this->assertSame('permit-tracker', $written['applicationSlug']);
		$this->assertSame('draft-version', $written['versionUuid']);
		$this->assertSame('renamed', $written['slug']);
		// The route placeholder must never land on the stored object.
		$this->assertArrayNotHasKey('uuid', $written);

	}//end testUpdatePinsApplicationScopeThroughTheRoute()

	/**
	 * REQ-AUTD-008: destroy() is forbidden for a non-member, and no compiled
	 * artifact is touched.
	 *
	 * @return void
	 */
	public function testDestroyReturns403ForNonMember(): void {
		$this->wireUser(uid: 'eve-outsider');
		$this->wireLookup(
			automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);

		$this->compiler->expects($this->never())->method('remove');
		$this->objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller->destroy(uuid: 'a-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testDestroyReturns403ForNonMember()

	/**
	 * REQ-AUTD-008: end-to-end through the route, an editor's delete removes
	 * the compiled artifacts BEFORE the definition itself.
	 *
	 * @return void
	 */
	public function testDestroyRemovesArtifactsBeforeDeletingThroughTheRoute(): void {
		$this->wireUser(uid: 'bob');
		$this->wireLookup(
			automation: [
				'id' => 'a-1',
				'applicationSlug' => 'permit-tracker',
				'versionUuid' => 'draft-version',
				'provenance' => ['notificationKeys' => ['k-1']],
			],
			application: [
				'id' => 'app-1',
				'slug' => 'permit-tracker',
				'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
			]
		);

		$order = [];
		$this->compiler->expects($this->once())
			->method('remove')
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'remove';
			});
		$this->objectService->expects($this->once())
			->method('deleteObject')
			->willReturnCallback(function () use (&$order): bool {
				$order[] = 'deleteObject';
				return true;
			});

		$response = $this->controller->destroy(uuid: 'a-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['deleted' => 'a-1'], $response->getData());
		$this->assertSame(['remove', 'deleteObject'], $order);

	}//end testDestroyRemovesArtifactsBeforeDeletingThroughTheRoute()
}//end class
