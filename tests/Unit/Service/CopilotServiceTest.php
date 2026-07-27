<?php

/**
 * Unit tests for CopilotService (spec ai-copilot REQ-OBAIC-001..005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Exception\CopilotException;
use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCA\OpenBuild\Service\ApplicationDeletionService;
use OCA\OpenBuild\Service\Copilot\CopilotPlanValidator;
use OCA\OpenBuild\Service\Copilot\CopilotPromptBuilder;
use OCA\OpenBuild\Service\CopilotService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CopilotService.
 */
class CopilotServiceTest extends TestCase
{

    /**
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var IUserManager&MockObject
     */
    private IUserManager&MockObject $userManager;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * @var OpenBuildToolProvider&MockObject
     */
    private OpenBuildToolProvider&MockObject $toolProvider;

    /**
     * @var ApplicationDeletionService&MockObject
     */
    private ApplicationDeletionService&MockObject $deletionService;

    /**
     * @var IManager&MockObject
     */
    private IManager&MockObject $taskManager;

    /**
     * Set up shared mocks + the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container      = $this->createMock(ContainerInterface::class);
        $this->objectService  = $this->createMock(ObjectService::class);
        $this->userManager    = $this->createMock(IUserManager::class);
        $this->groupManager   = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->toolProvider   = $this->createMock(OpenBuildToolProvider::class);
        $this->toolProvider->method('getToolDescriptors')->willReturn($this->descriptors());
        $this->deletionService = $this->createMock(ApplicationDeletionService::class);
        $this->taskManager     = $this->createMock(IManager::class);
    }//end setUp()

    /**
     * Build the SUT with the shared mocks, real PermissionResolver + validator + prompt builder.
     *
     * @return CopilotService
     */
    private function makeService(): CopilotService
    {
        $logger              = $this->createMock(LoggerInterface::class);
        $permissionResolver   = new PermissionResolver(groupManager: $this->groupManager, logger: $this->createMock(LoggerInterface::class));
        $planValidator        = new CopilotPlanValidator();
        $promptBuilder        = new CopilotPromptBuilder(toolProvider: $this->toolProvider);

        return new CopilotService(
            container: $this->container,
            logger: $logger,
            objectService: $this->objectService,
            userManager: $this->userManager,
            groupManager: $this->groupManager,
            permissionResolver: $permissionResolver,
            toolProvider: $this->toolProvider,
            planValidator: $planValidator,
            promptBuilder: $promptBuilder,
            applicationDeletionService: $this->deletionService,
        );
    }//end makeService()

    /**
     * Build the SUT with a caller-supplied group manager (so a test can make the
     * actor an admin, which the shared setUp fixes to non-admin) and an optional
     * audit-trail mapper. Used by the admin-bypass audit tests (#11-#5).
     *
     * @param IGroupManager         $groupManager The group manager driving admin/role checks.
     * @param AuditTrailMapper|null $auditMapper  The audit-trail mapper (or null).
     *
     * @return CopilotService
     */
    private function makeServiceWith(IGroupManager $groupManager, ?AuditTrailMapper $auditMapper): CopilotService
    {
        $permissionResolver = new PermissionResolver(groupManager: $groupManager, logger: $this->createMock(LoggerInterface::class));

        return new CopilotService(
            container: $this->container,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            userManager: $this->userManager,
            groupManager: $groupManager,
            permissionResolver: $permissionResolver,
            toolProvider: $this->toolProvider,
            planValidator: new CopilotPlanValidator(),
            promptBuilder: new CopilotPromptBuilder(toolProvider: $this->toolProvider),
            applicationDeletionService: $this->deletionService,
            auditTrailMapper: $auditMapper,
        );
    }//end makeServiceWith()

    /**
     * L2 / #11-#5: assertWriteRoleOnApp records an admin_bypass audit entry only
     * for a GENUINE bypass — an admin with no owner/editor role on the app. An
     * admin who also holds a real role is authorised, not bypassing, and must NOT
     * produce a false compliance record.
     *
     * @return void
     */
    public function testAdminBypassAuditedOnlyWhenAdminLacksRealRole(): void
    {
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('admin-user');
        $this->userManager->method('get')->with('admin-user')->willReturn($admin);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
        $groupManager->method('getUserGroups')->willReturn([]);

        $entity = $this->createMock(ObjectEntity::class);
        $this->objectService->method('find')->willReturn($entity);

        // Genuine bypass: admin is NOT an owner/editor → audit MUST fire once.
        $genuineMapper = $this->createMock(AuditTrailMapper::class);
        $genuineMapper->expects($this->once())
            ->method('createAuditTrailEntry')
            ->with($entity, 'rbac.admin_bypass', $this->anything());

        $appBypass = ['slug' => 'my-app', 'uuid' => 'app-uuid-1', 'permissions' => ['owners' => ['user:someone-else'], 'editors' => [], 'viewers' => []]];
        $this->invokeAssertWriteRole($this->makeServiceWith($groupManager, $genuineMapper), $appBypass, 'admin-user');

        // Legitimate admin-owner: NOT a bypass → audit MUST NOT fire.
        $ownerMapper = $this->createMock(AuditTrailMapper::class);
        $ownerMapper->expects($this->never())->method('createAuditTrailEntry');

        $appOwner = ['slug' => 'my-app', 'uuid' => 'app-uuid-1', 'permissions' => ['owners' => ['user:admin-user'], 'editors' => [], 'viewers' => []]];
        $this->invokeAssertWriteRole($this->makeServiceWith($groupManager, $ownerMapper), $appOwner, 'admin-user');

    }//end testAdminBypassAuditedOnlyWhenAdminLacksRealRole()

    /**
     * Reflection shim to drive the private assertWriteRoleOnApp() without the
     * heavy public generate() path (LLM/task-manager wiring).
     *
     * @param CopilotService       $service The service under test.
     * @param array<string, mixed> $app     The application data.
     * @param string               $userId  The acting user UID.
     *
     * @return void
     */
    private function invokeAssertWriteRole(CopilotService $service, array $app, string $userId): void
    {
        $method = new \ReflectionMethod(CopilotService::class, 'assertWriteRoleOnApp');
        $method->setAccessible(true);
        $method->invoke($service, $app, $userId);

    }//end invokeAssertWriteRole()

    /**
     * Minimal tool catalogue mirroring the real descriptors closely enough for validation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function descriptors(): array
    {
        return [
            [
                'id'          => 'openbuild.createApp',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]*[a-z0-9]$', 'minLength' => 2, 'maxLength' => 48],
                        'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                    ],
                    'required'   => ['slug', 'name'],
                ],
            ],
            [
                'id'          => 'openbuild.upsertPage',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'appSlug' => ['type' => 'string'],
                        'pageId'  => ['type' => 'string'],
                        'title'   => ['type' => 'string'],
                        'type'    => ['type' => 'string', 'enum' => ['dashboard', 'index', 'detail', 'form']],
                        'route'   => ['type' => 'string'],
                    ],
                    'required'   => ['appSlug', 'pageId', 'title', 'type', 'route'],
                ],
            ],
        ];
    }//end descriptors()

    /**
     * Wire the container to resolve the mocked TaskProcessing manager.
     *
     * @return void
     */
    private function wireTaskProcessingManager(): void
    {
        $this->container->method('get')
            ->with('OCP\\TaskProcessing\\IManager')
            ->willReturn($this->taskManager);
    }//end wireTaskProcessingManager()

    /**
     * Wire `scheduleTask` to assign an id and `getTask` to return a completed task
     * with the given output text.
     *
     * @param string $outputText The `output` text the "LLM" replies with.
     *
     * @return void
     */
    private function wireSuccessfulLlmReply(string $outputText): void
    {
        $nextId = 1;
        $this->taskManager->method('scheduleTask')->willReturnCallback(function (Task $task) use (&$nextId): void {
            $task->setId($nextId++);
        });

        $done = new Task(TextToText::ID, ['input' => 'x'], 'openbuild', 'alice');
        $done->setStatus(Task::STATUS_SUCCESSFUL);
        $done->setOutput(['output' => $outputText]);
        $this->taskManager->method('getTask')->willReturn($done);
    }//end wireSuccessfulLlmReply()

    // -------------------------------------------------------------------
    // health()
    // -------------------------------------------------------------------

    /**
     * health() reports unsupported_server when the TaskProcessing manager cannot be resolved.
     *
     * @return void
     */
    public function testHealthReportsUnsupportedServerWhenManagerUnresolvable(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('no such service'));

        $service = $this->makeService();
        $health  = $service->health();

        self::assertFalse($health['available']);
        self::assertSame('unsupported_server', $health['reason']);
    }//end testHealthReportsUnsupportedServerWhenManagerUnresolvable()

    /**
     * health() reports no_provider when no TextToText task type is available.
     *
     * @return void
     */
    public function testHealthReportsNoProviderWhenTextToTextMissing(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn(['some:other-type' => []]);

        $health = $this->makeService()->health();
        self::assertFalse($health['available']);
        self::assertSame('no_provider', $health['reason']);
    }//end testHealthReportsNoProviderWhenTextToTextMissing()

    /**
     * health() reports available:true when a TextToText provider is configured.
     *
     * @return void
     */
    public function testHealthReportsAvailableWhenProviderConfigured(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn([TextToText::ID => []]);

        $health = $this->makeService()->health();
        self::assertTrue($health['available']);
    }//end testHealthReportsAvailableWhenProviderConfigured()

    // -------------------------------------------------------------------
    // plan()
    // -------------------------------------------------------------------

    /**
     * plan() returns a validated plan on a well-formed LLM reply, and performs zero writes.
     *
     * @return void
     */
    public function testPlanHappyPathPerformsZeroWrites(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn([TextToText::ID => []]);
        $this->wireSuccessfulLlmReply(json_encode([
            'summary' => 'A tool library',
            'steps'   => [
                ['tool' => 'openbuild.createApp', 'arguments' => ['slug' => 'tool-library', 'name' => 'Tool Library']],
            ],
        ]));

        $this->objectService->expects(self::never())->method('saveObject');
        $this->toolProvider->expects(self::never())->method('invokeTool');

        $result = $this->makeService()->plan(brief: 'A tool library', appSlug: null, userId: 'alice');

        self::assertSame('A tool library', $result['summary']);
        self::assertCount(1, $result['steps']);
    }//end testPlanHappyPathPerformsZeroWrites()

    /**
     * Unparsable LLM output twice in a row triggers exactly one repair retry then 422.
     *
     * @return void
     */
    public function testPlanRetriesExactlyOnceThenFails(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn([TextToText::ID => []]);
        $this->wireSuccessfulLlmReply('not json, just prose');

        $this->taskManager->expects(self::exactly(2))->method('scheduleTask');

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->plan(brief: 'A tool library', appSlug: null, userId: 'alice');
        } catch (CopilotException $e) {
            self::assertSame('plan_invalid', $e->getErrorCode());
            self::assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }//end testPlanRetriesExactlyOnceThenFails()

    /**
     * A step outside the allow-list is rejected with 422 plan_invalid.
     *
     * @return void
     */
    public function testPlanRejectsStepOutsideAllowList(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn([TextToText::ID => []]);
        $this->wireSuccessfulLlmReply(json_encode([
            'summary' => 'x',
            'steps'   => [['tool' => 'openbuild.deleteApp', 'arguments' => []]],
        ]));

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->plan(brief: 'x', appSlug: null, userId: 'alice');
        } catch (CopilotException $e) {
            self::assertSame('plan_invalid', $e->getErrorCode());
            throw $e;
        }
    }//end testPlanRejectsStepOutsideAllowList()

    /**
     * A hybrid target app is rejected before any LLM call is made.
     *
     * @return void
     */
    public function testPlanRejectsHybridTargetBeforeLlmCall(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn([TextToText::ID => []]);
        $this->taskManager->expects(self::never())->method('scheduleTask');

        $this->objectService->method('searchObjectsBySlug')->willReturn([
            ['id' => 'app-1', 'slug' => 'installed-app', 'appType' => 'hybrid', 'permissions' => ['owners' => ['user:alice']]],
        ]);

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->plan(brief: 'x', appSlug: 'installed-app', userId: 'alice');
        } catch (CopilotException $e) {
            self::assertSame('unsupported_target', $e->getErrorCode());
            self::assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }//end testPlanRejectsHybridTargetBeforeLlmCall()

    /**
     * An unknown target app is rejected with 404 before any LLM call.
     *
     * @return void
     */
    public function testPlanRejectsUnknownTargetApp(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn([TextToText::ID => []]);
        $this->taskManager->expects(self::never())->method('scheduleTask');
        $this->objectService->method('searchObjectsBySlug')->willReturn([]);

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->plan(brief: 'x', appSlug: 'does-not-exist', userId: 'alice');
        } catch (CopilotException $e) {
            self::assertSame('not_found', $e->getErrorCode());
            self::assertSame(404, $e->getHttpStatus());
            throw $e;
        }
    }//end testPlanRejectsUnknownTargetApp()

    /**
     * A viewer-only caller is denied (403) for a plan targeting an existing app.
     *
     * @return void
     */
    public function testPlanDeniesViewerOnlyCaller(): void
    {
        $this->wireTaskProcessingManager();
        $this->taskManager->method('getAvailableTaskTypes')->willReturn([TextToText::ID => []]);
        $this->objectService->method('searchObjectsBySlug')->willReturn([
            ['id' => 'app-1', 'slug' => 'tool-library', 'permissions' => ['owners' => ['user:alice'], 'viewers' => ['user:bob']]],
        ]);
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->plan(brief: 'x', appSlug: 'tool-library', userId: 'bob');
        } catch (CopilotException $e) {
            self::assertSame('forbidden', $e->getErrorCode());
            self::assertSame(403, $e->getHttpStatus());
            throw $e;
        }
    }//end testPlanDeniesViewerOnlyCaller()

    /**
     * health() unavailable maps to a 503 CopilotException from plan().
     *
     * @return void
     */
    public function testPlanThrows503WhenUnavailable(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('unavailable'));

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->plan(brief: 'x', appSlug: null, userId: 'alice');
        } catch (CopilotException $e) {
            self::assertSame(503, $e->getHttpStatus());
            throw $e;
        }
    }//end testPlanThrows503WhenUnavailable()

    // -------------------------------------------------------------------
    // predictManifests()
    // -------------------------------------------------------------------

    /**
     * predictManifests() applies upsertPage steps in memory and returns current+predicted.
     *
     * @return void
     */
    public function testPredictManifestsComputesPredictedManifest(): void
    {
        $plan = [
            'summary' => 'x',
            'steps'   => [
                ['tool' => 'openbuild.createApp', 'arguments' => ['slug' => 'tool-library', 'name' => 'Tool Library']],
                ['tool' => 'openbuild.upsertPage', 'arguments' => ['appSlug' => 'tool-library', 'pageId' => 'home', 'title' => 'Home', 'type' => 'index', 'route' => '/']],
            ],
        ];

        $manifests = $this->makeService()->predictManifests(plan: $plan, appSlug: null);

        self::assertArrayHasKey('tool-library@development', $manifests);
        self::assertCount(0, $manifests['tool-library@development']['current']['pages']);
        self::assertCount(1, $manifests['tool-library@development']['predicted']['pages']);
    }//end testPredictManifestsComputesPredictedManifest()

    /**
     * predictManifests() throws when the predicted manifest exceeds the pages cap.
     *
     * @return void
     */
    public function testPredictManifestsThrowsOnCapViolation(): void
    {
        $existingPages = [];
        for ($i = 0; $i < 100; $i++) {
            $existingPages[] = ['id' => 'p'.$i, 'route' => '/'.$i, 'type' => 'index', 'title' => 'P'.$i, 'config' => []];
        }

        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
            function (string $register, string $schema, array $filters) use ($existingPages): array {
                if ($schema === 'application') {
                    return [['id' => 'app-1', 'slug' => 'tool-library', 'permissions' => []]];
                }
                return [['id' => 'ver-1', 'slug' => 'development', 'application' => 'app-1', 'manifest' => ['version' => '1.0.0', 'menu' => [], 'pages' => $existingPages]]];
            }
        );

        $plan = [
            'summary' => 'x',
            'steps'   => [
                ['tool' => 'openbuild.upsertPage', 'arguments' => ['appSlug' => 'tool-library', 'pageId' => 'one-more', 'title' => 'One more', 'type' => 'index', 'route' => '/one-more']],
            ],
        ];

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->predictManifests(plan: $plan, appSlug: 'tool-library');
        } catch (CopilotException $e) {
            self::assertSame('plan_invalid', $e->getErrorCode());
            self::assertStringContainsString('100 pages', $e->getMessage());
            throw $e;
        }
    }//end testPredictManifestsThrowsOnCapViolation()

    // -------------------------------------------------------------------
    // execute()
    // -------------------------------------------------------------------

    /**
     * execute() dispatches steps in order (createApp first) through invokeTool
     * and returns ordered per-step results on success.
     *
     * @return void
     */
    public function testExecuteDispatchesInOrderAndReturnsResults(): void
    {
        $plan = [
            'summary' => 'x',
            'steps'   => [
                ['tool' => 'openbuild.upsertPage', 'arguments' => ['appSlug' => 'tool-library', 'pageId' => 'home', 'title' => 'Home', 'type' => 'index', 'route' => '/']],
                ['tool' => 'openbuild.createApp', 'arguments' => ['slug' => 'tool-library', 'name' => 'Tool Library']],
            ],
        ];

        $invokedOrder = [];
        $this->toolProvider->method('invokeTool')->willReturnCallback(function (string $tool) use (&$invokedOrder): array {
            $invokedOrder[] = $tool;
            if ($tool === 'openbuild.createApp') {
                return ['success' => true, 'created' => true, 'app' => ['uuid' => 'app-uuid-1', 'slug' => 'tool-library', 'name' => 'Tool Library']];
            }
            return ['success' => true, 'action' => 'created'];
        });

        $result = $this->makeService()->execute(plan: $plan, userId: 'alice');

        self::assertSame(['openbuild.createApp', 'openbuild.upsertPage'], $invokedOrder);
        self::assertCount(2, $result['results']);
    }//end testExecuteDispatchesInOrderAndReturnsResults()

    /**
     * A mid-plan isError step result rolls back every snapshot and deletes the created app.
     *
     * @return void
     */
    public function testExecuteRollsBackOnMidPlanFailure(): void
    {
        $plan = [
            'summary' => 'x',
            'steps'   => [
                ['tool' => 'openbuild.createApp', 'arguments' => ['slug' => 'tool-library', 'name' => 'Tool Library']],
                ['tool' => 'openbuild.upsertPage', 'arguments' => ['appSlug' => 'tool-library', 'pageId' => 'home', 'title' => 'Home', 'type' => 'index', 'route' => '/']],
            ],
        ];

        $this->toolProvider->method('invokeTool')->willReturnCallback(function (string $tool): array {
            if ($tool === 'openbuild.createApp') {
                return ['success' => true, 'created' => true, 'app' => ['uuid' => 'app-uuid-1', 'slug' => 'tool-library', 'name' => 'Tool Library']];
            }
            return ['isError' => true, 'error' => 'upsert_failed', 'message' => 'boom'];
        });

        $this->deletionService->expects(self::once())
            ->method('deleteApplication')
            ->with(appUuid: 'app-uuid-1', appSlug: 'tool-library', deleteData: false);

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->execute(plan: $plan, userId: 'alice');
        } catch (CopilotException $e) {
            self::assertSame('execution_failed', $e->getErrorCode());
            self::assertSame(1, $e->getStepIndex());
            self::assertSame(422, $e->getHttpStatus());
            throw $e;
        }
    }//end testExecuteRollsBackOnMidPlanFailure()

    /**
     * execute() denies a viewer-only caller against an existing app (403) and runs no step.
     *
     * @return void
     */
    public function testExecuteDeniesViewerOnlyCaller(): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturn([
            ['id' => 'app-1', 'slug' => 'tool-library', 'permissions' => ['owners' => ['user:alice'], 'viewers' => ['user:bob']]],
        ]);
        $bob = $this->createMock(IUser::class);
        $bob->method('getUID')->willReturn('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);

        $this->toolProvider->expects(self::never())->method('invokeTool');

        $plan = [
            'summary' => 'x',
            'steps'   => [
                ['tool' => 'openbuild.upsertPage', 'arguments' => ['appSlug' => 'tool-library', 'pageId' => 'home', 'title' => 'Home', 'type' => 'index', 'route' => '/']],
            ],
        ];

        $this->expectException(CopilotException::class);
        try {
            $this->makeService()->execute(plan: $plan, userId: 'bob');
        } catch (CopilotException $e) {
            self::assertSame('forbidden', $e->getErrorCode());
            self::assertSame(403, $e->getHttpStatus());
            throw $e;
        }
    }//end testExecuteDeniesViewerOnlyCaller()

    /**
     * execute() runs a createApp-only plan without any existing-app RBAC check
     * (the caller becomes owner of the created app — REQ-OBAIC-005).
     *
     * @return void
     */
    public function testExecuteCreateAppOnlyRequiresNoExistingAppRole(): void
    {
        $this->objectService->expects(self::never())->method('searchObjectsBySlug');

        $this->toolProvider->method('invokeTool')->willReturn([
            'success' => true,
            'created' => true,
            'app'     => ['uuid' => 'app-uuid-1', 'slug' => 'tool-library', 'name' => 'Tool Library'],
        ]);

        $plan = [
            'summary' => 'x',
            'steps'   => [
                ['tool' => 'openbuild.createApp', 'arguments' => ['slug' => 'tool-library', 'name' => 'Tool Library']],
            ],
        ];

        $result = $this->makeService()->execute(plan: $plan, userId: 'anyone');
        self::assertCount(1, $result['results']);
    }//end testExecuteCreateAppOnlyRequiresNoExistingAppRole()
}//end class
