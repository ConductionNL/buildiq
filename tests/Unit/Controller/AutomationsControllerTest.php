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
 * @package  OCA\OpenBuild\Tests\Unit\Controller
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

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\AutomationsController;
use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenBuild\Service\ConditionActionExecutor;
use OCA\OpenBuild\Service\PermissionResolver;
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
final class AutomationsControllerTest extends TestCase
{
    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

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
    protected function setUp(): void
    {
        $this->request       = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->compiler          = $this->createMock(AutomationCompilerService::class);
        $this->conditionExecutor = $this->createMock(ConditionActionExecutor::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        // isAdmin() is only ever exercised via the "admin" NC group check;
        // wired per-test to true/false as needed (default false here).
        $this->groupManager->method('isAdmin')->willReturn(false);

        $permissionResolver = new PermissionResolver($this->groupManager, $this->createMock(LoggerInterface::class));

        $this->controller = new AutomationsController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            compiler: $this->compiler,
            conditionExecutor: $this->conditionExecutor,
            permissionResolver: $permissionResolver,
            userSession: $this->userSession
        );

    }//end setUp()

    /**
     * Wire a Nextcloud user with the given UID into the session mock.
     *
     * @param string $uid The UID.
     *
     * @return void
     */
    private function wireUser(string $uid): void
    {
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
    private function buildEntity(array $payload): ObjectEntity
    {
        $entity = new class () extends ObjectEntity {
            /**
             * @var array<string,mixed>
             */
            public array $payload = [];

            /**
             * @return array<string,mixed>
             */
            public function jsonSerialize(): array
            {
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
    private function wireLookup(array $automation, array $application): void
    {
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
    public function testEnableReturns403ForNonMember(): void
    {
        $this->wireUser(uid: 'eve-outsider');
        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
            application: [
                'id'          => 'app-1',
                'slug'        => 'permit-tracker',
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
    public function testEditorCanEnableOnDraftVersion(): void
    {
        $this->wireUser(uid: 'bob');
        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'enabled' => false],
            application: [
                'id'                => 'app-1',
                'slug'              => 'permit-tracker',
                'permissions'       => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
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
    public function testEditorCannotEnableOnProductionVersion(): void
    {
        $this->wireUser(uid: 'bob');
        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'production-version', 'enabled' => false],
            application: [
                'id'                => 'app-1',
                'slug'              => 'permit-tracker',
                'permissions'       => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
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
    public function testOwnerCanEnableOnProductionVersion(): void
    {
        $this->wireUser(uid: 'alice');
        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'production-version', 'enabled' => false],
            application: [
                'id'                => 'app-1',
                'slug'              => 'permit-tracker',
                'permissions'       => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
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
    public function testAdminBypassNotHonouredOnProductionEnable(): void
    {
        $this->wireUser(uid: 'admin');
        // Even if isAdmin() were true, matchesCaller() is called with
        // allowAdminBypass:false, so it must NEVER be consulted for the
        // bypass branch. Wiring it true here proves the bypass truly isn't used.
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'production-version', 'enabled' => false],
            application: [
                'id'                => 'app-1',
                'slug'              => 'permit-tracker',
                'permissions'       => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
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
    public function testReturns401WhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->compile(uuid: 'a-1');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testReturns401WhenUnauthenticated()

    /**
     * 404 when the automation uuid does not resolve.
     *
     * @return void
     */
    public function testReturns404WhenAutomationMissing(): void
    {
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
    public function testCompileReturns422OnUnsupportedCombination(): void
    {
        $this->wireUser(uid: 'bob');
        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
            application: [
                'id'          => 'app-1',
                'slug'        => 'permit-tracker',
                'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
            ]
        );

        $this->compiler->method('compile')->willThrowException(
            new \OCA\OpenBuild\Exception\UnsupportedAutomationCombinationException('bad combination')
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
    public function testDryRunReturnsWouldBeActionsWithoutSideEffects(): void
    {
        $this->wireUser(uid: 'bob');
        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'trigger' => ['type' => 'manual']],
            application: [
                'id'          => 'app-1',
                'slug'        => 'permit-tracker',
                'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']],
            ]
        );

        $this->request->method('getParams')->willReturn(['payload' => ['amount' => 5000]]);

        $this->compiler->expects($this->once())
            ->method('compileDryRunRule')
            ->willReturn(['naam' => 'a', 'conditie' => '', 'acties' => [['type' => 'send-notification', 'parameters' => []]], 'actief' => true]);

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
    public function testDryRunReportsApprovalState(): void
    {
        $this->wireUser(uid: 'bob');
        $this->wireLookup(
            automation: ['id' => 'a-1', 'applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'], 'provenance' => ['approvalChainName' => 'aut-x']],
            application: ['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']]]
        );

        $this->request->method('getParams')->willReturn(['payload' => []]);
        $this->compiler->method('compileDryRunRule')->willReturn(['naam' => 'a', 'conditie' => '', 'acties' => [], 'actief' => true]);
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
    public function testStatusReportsApprovalState(): void
    {
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
}//end class
