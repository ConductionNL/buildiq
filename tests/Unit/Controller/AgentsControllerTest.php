<?php

/**
 * Unit tests for AgentsController::runs (spec `agent-workspace`).
 *
 * Covers the row-level owners/editors RBAC guard on the run-history read —
 * the exact IDOR shape hydra-gate-no-admin-idor flags when a
 * `#[NoAdminRequired]` endpoint lacks a per-object guard:
 *  - unauthenticated caller → 401
 *  - unknown agent / unknown application → 404
 *  - authenticated non-owner/non-editor → 403
 *  - owner/editor → 200 with the agent's runs, newest first
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\AgentsController;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
 * Tests for AgentsController::runs.
 */
class AgentsControllerTest extends TestCase
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
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Controller under test.
     */
    private AgentsController $controller;

    /**
     * Set up shared mocks + the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request        = $this->createMock(IRequest::class);
        $this->objectService  = $this->createMock(ObjectService::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->groupManager   = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->userSession = $this->createMock(IUserSession::class);

        // Real entities — NC Db entities expose getId() via magic __call,
        // which PHPUnit cannot mock; construct them and set the id instead.
        $register = new Register();
        $register->setId(1);
        $this->registerMapper->method('find')->willReturn($register);

        $schema = new Schema();
        $schema->setId(2);
        $this->schemaMapper->method('find')->willReturn($schema);

        $permissionResolver = new PermissionResolver(groupManager: $this->groupManager, logger: $this->createMock(LoggerInterface::class));

        $this->controller = new AgentsController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            permissionResolver: $permissionResolver,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
        );
    }//end setUp()

    /**
     * Build an IUser mock with the given UID and wire it into the session.
     *
     * @param string $uid The user id.
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
     * Wire `objectService->find()` for the agent + application lookups.
     *
     * @param array<string,mixed> $agent       The agent record.
     * @param array<string,mixed> $application The parent application record.
     *
     * @return void
     */
    private function wireAgentAndApplication(array $agent, array $application): void
    {
        $agentEntity       = $this->objectEntity($agent);
        $applicationEntity = $this->objectEntity($application);

        // ObjectService::find()'s real positional signature is
        // (id, extend, files, register, schema, rbac, multitenancy) —
        // AgentsController calls it with named arguments, but PHP resolves
        // those to the full positional list before the mock ever sees them,
        // so the callback must destructure by POSITION, not by whatever
        // parameter names it declares.
        $this->objectService->method('find')->willReturnCallback(
            function (string|int $id, mixed $extend, mixed $files, mixed $register, mixed $schema, mixed ...$rest) use ($agent, $application, $agentEntity, $applicationEntity) {
                if ($schema === 'agent') {
                    return ((string) $id) === ($agent['id'] ?? '') ? $agentEntity : null;
                }

                if ($schema === 'application') {
                    return ((string) $id) === ($application['slug'] ?? '') ? $applicationEntity : null;
                }

                return null;
            }
        );
    }//end wireAgentAndApplication()

    /**
     * Build a mocked `ObjectEntity` whose `jsonSerialize()` returns the given payload.
     *
     * @param array<string, mixed> $payload The payload `jsonSerialize()` should return.
     *
     * @return ObjectEntity&MockObject
     */
    private function objectEntity(array $payload): ObjectEntity&MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($payload);
        return $entity;
    }//end objectEntity()

    /**
     * Unauthenticated caller → 401.
     *
     * @return void
     */
    public function testRunsReturns401WhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller->runs(uuid: 'agent-1');
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testRunsReturns401WhenUnauthenticated()

    /**
     * Unknown agent uuid → 404.
     *
     * @return void
     */
    public function testRunsReturns404ForUnknownAgent(): void
    {
        $this->wireUser(uid: 'alice');
        $this->objectService->method('find')->willReturn(null);

        $response = $this->controller->runs(uuid: 'does-not-exist');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testRunsReturns404ForUnknownAgent()

    /**
     * A viewer-only (non-owner/non-editor) caller is denied — the row-level
     * guard that closes the IDOR class hydra-gate-no-admin-idor flags.
     *
     * @return void
     */
    public function testRunsReturns403ForViewerOnlyCaller(): void
    {
        $this->wireUser(uid: 'bob');
        $this->wireAgentAndApplication(
            agent: ['id' => 'agent-1', 'applicationSlug' => 'tool-library'],
            application: ['slug' => 'tool-library', 'permissions' => ['owners' => ['user:alice'], 'viewers' => ['user:bob']]]
        );

        $response = $this->controller->runs(uuid: 'agent-1');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testRunsReturns403ForViewerOnlyCaller()

    /**
     * An owner sees the agent's runs, newest first, with every tool call's
     * arguments and result intact (agent-workspace spec "Run history shows
     * every tool call's arguments and result").
     *
     * @return void
     */
    public function testRunsReturns200WithRunsNewestFirst(): void
    {
        $this->wireUser(uid: 'alice');
        $this->wireAgentAndApplication(
            agent: ['id' => 'agent-1', 'applicationSlug' => 'tool-library'],
            application: ['slug' => 'tool-library', 'permissions' => ['owners' => ['user:alice']]]
        );

        $this->objectService->method('searchObjects')->willReturn(
                [
                    ['agentId' => 'agent-1', 'outcome' => 'applied', 'createdAt' => '2026-07-20T10:00:00+00:00', 'toolCalls' => [['tool' => 'openbuild.upsertPage', 'arguments' => ['pageId' => 'a'], 'result' => ['success' => true]]]],
                    ['agentId' => 'agent-1', 'outcome' => 'discarded', 'createdAt' => '2026-07-22T10:00:00+00:00', 'toolCalls' => []],
                    ['agentId' => 'other-agent', 'outcome' => 'applied', 'createdAt' => '2026-07-23T10:00:00+00:00', 'toolCalls' => []],
                ]
                );

        $response = $this->controller->runs(uuid: 'agent-1');
        self::assertSame(Http::STATUS_OK, $response->getStatus());

        $data = $response->getData();
        self::assertCount(2, $data);
        self::assertSame('discarded', $data[0]['outcome']);
        self::assertSame('applied', $data[1]['outcome']);
        self::assertSame('openbuild.upsertPage', $data[1]['toolCalls'][0]['tool']);
    }//end testRunsReturns200WithRunsNewestFirst()

    /**
     * An admin bypasses the owners/editors check (logged) — matches the
     * shared `rbac.admin_bypass` posture used elsewhere in OpenBuild.
     *
     * @return void
     */
    public function testRunsAllowsAdminBypass(): void
    {
        $this->wireUser(uid: 'admin-1');
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $permissionResolver = new PermissionResolver(groupManager: $this->groupManager, logger: $this->createMock(LoggerInterface::class));
        $this->controller   = new AgentsController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            permissionResolver: $permissionResolver,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
        );
        $this->wireAgentAndApplication(
            agent: ['id' => 'agent-1', 'applicationSlug' => 'tool-library'],
            application: ['slug' => 'tool-library', 'permissions' => ['owners' => ['user:alice']]]
        );
        $this->objectService->method('searchObjects')->willReturn([]);

        $response = $this->controller->runs(uuid: 'agent-1');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testRunsAllowsAdminBypass()
}//end class
