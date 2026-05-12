<?php

/**
 * Unit tests for ApplicationsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Controller
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

namespace OCA\OpenBuilt\Tests\Unit\Controller;

use OCA\OpenBuilt\Controller\ApplicationsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationsController::getManifest, including the RBAC
 * permissions check introduced by the openbuilt-rbac change.
 */
class ApplicationsControllerTest extends TestCase
{
    /**
     * Mock OR ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock OR audit-trail mapper.
     *
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper&MockObject $auditTrailMapper;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
    }//end setUp()

    /**
     * Build the controller with a default user/group fixture.
     *
     * @param string             $uid          Caller UID
     * @param array<int, string> $callerGroups Group IDs the caller belongs to
     * @param bool               $isAdmin      Whether the caller is in the `admin` group
     *
     * @return ApplicationsController
     */
    private function buildController(string $uid = 'bob', array $callerGroups = [], bool $isAdmin = false): ApplicationsController
    {
        $request = $this->createMock(IRequest::class);

        $registerEntity = $this->getMockBuilder(Register::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $registerEntity->method('getId')->willReturn(926);
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->willReturn($registerEntity);

        $schemaEntity = $this->getMockBuilder(Schema::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $schemaEntity->method('getId')->willReturn(1635);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schemaEntity);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

        $groupMocks = [];
        foreach ($callerGroups as $gid) {
            $g = $this->createMock(IGroup::class);
            $g->method('getGID')->willReturn($gid);
            $groupMocks[] = $g;
        }
        $this->groupManager->method('getUserGroups')->with($user)->willReturn($groupMocks);
        $this->groupManager->method('isInGroup')->willReturnCallback(
            static function (string $callerUid, string $gid) use ($uid, $isAdmin): bool {
                return $callerUid === $uid && $gid === 'admin' && $isAdmin === true;
            }
        );

        return new ApplicationsController(
            request: $request,
            logger: $this->logger,
            objectService: $this->objectService,
            registerMapper: $registerMapper,
            schemaMapper: $schemaMapper,
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            auditTrailMapper: $this->auditTrailMapper,
        );
    }//end buildController()

    /**
     * Wire the OR mocks to return a route to an Application carrying the
     * given permissions block (and an empty manifest object for happy
     * paths that need a 200).
     *
     * @param array<string, mixed> $permissions The Application's permissions block
     *
     * @return void
     */
    private function wireApplication(array $permissions): void
    {
        $manifest = [
            'version' => '1.0.0',
            'menu'    => [],
            'pages'   => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
        ];
        $this->objectService->method('searchObjects')
            ->willReturn([['applicationUuid' => 'abc-123']]);

        // OR's ObjectService::find() returns an ObjectEntity (or null); the
        // controller normalises it via jsonSerialize().
        $applicationEntity = $this->createMock(ObjectEntity::class);
        $applicationEntity->method('jsonSerialize')->willReturn([
            'manifest'    => $manifest,
            'permissions' => $permissions,
        ]);
        $this->objectService->method('find')->willReturn($applicationEntity);
    }//end wireApplication()

    /**
     * Happy path — slug resolves to a published Application; manifest is returned unwrapped
     * to a caller whose group is in `permissions.viewers`.
     *
     * @return void
     */
    public function testGetManifestReturnsManifestForViewer(): void
    {
        $controller = $this->buildController(uid: 'bob', callerGroups: ['team-alpha']);
        $this->wireApplication(permissions: ['owners' => [], 'editors' => [], 'viewers' => ['team-alpha']]);

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertIsArray($result->getData());
        self::assertArrayHasKey('pages', $result->getData());
    }//end testGetManifestReturnsManifestForViewer()

    /**
     * Owner role passes the RBAC check.
     *
     * @return void
     */
    public function testGetManifestPassesForOwner(): void
    {
        $controller = $this->buildController(uid: 'alice', callerGroups: ['team-alpha']);
        $this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
    }//end testGetManifestPassesForOwner()

    /**
     * Editor role passes the RBAC check.
     *
     * @return void
     */
    public function testGetManifestPassesForEditor(): void
    {
        $controller = $this->buildController(uid: 'carol', callerGroups: ['team-beta']);
        $this->wireApplication(permissions: ['owners' => [], 'editors' => ['team-beta'], 'viewers' => []]);

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
    }//end testGetManifestPassesForEditor()

    /**
     * Caller with no role intersection gets 403 (NOT 404) — REQ-OBRBAC-002.
     *
     * @return void
     */
    public function testGetManifestReturns403ForNoRole(): void
    {
        $controller = $this->buildController(uid: 'eve', callerGroups: ['stranger']);
        $this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
        $data = $result->getData();
        self::assertSame('forbidden', $data['error']);
        self::assertSame('openbuilt.rbac.no_role', $data['code']);
        // The 403 body MUST NOT leak any manifest payload (REQ-OBRBAC-002).
        self::assertArrayNotHasKey('manifest', $data);
        self::assertArrayNotHasKey('pages', $data);
        self::assertArrayNotHasKey('name', $data);
    }//end testGetManifestReturns403ForNoRole()

    /**
     * Empty `permissions` array still produces a 403 — no group means no role.
     *
     * @return void
     */
    public function testGetManifestReturns403WhenPermissionsEmpty(): void
    {
        $controller = $this->buildController(uid: 'eve', callerGroups: ['stranger']);
        $this->wireApplication(permissions: ['owners' => [], 'editors' => [], 'viewers' => []]);

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
    }//end testGetManifestReturns403WhenPermissionsEmpty()

    /**
     * Admin bypass — a caller in the `admin` group passes even without a role,
     * and the bypass is logged for audit (REQ-OBRBAC-006).
     *
     * @return void
     */
    public function testGetManifestAdminBypassWritesAudit(): void
    {
        $controller = $this->buildController(uid: 'sysadmin', callerGroups: ['admin'], isAdmin: true);
        $this->wireApplication(permissions: ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []]);

        $this->logger->expects(self::atLeastOnce())
            ->method('info')
            ->with(
                self::stringContains('rbac.admin_bypass'),
                self::callback(static function (array $ctx): bool {
                    return ($ctx['event'] ?? null) === 'rbac.admin_bypass'
                        && ($ctx['actor'] ?? null) === 'sysadmin'
                        && ($ctx['slug'] ?? null) === 'hello-world';
                })
            );

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
    }//end testGetManifestAdminBypassWritesAudit()

    /**
     * Unknown slug → 404 with not_found error code (preserved from spec #1).
     *
     * @return void
     */
    public function testGetManifestReturns404WhenSlugUnknown(): void
    {
        $controller = $this->buildController();
        $this->objectService->method('searchObjects')->willReturn([]);

        $result = $controller->getManifest(slug: 'no-such-app');

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        self::assertSame('not_found', $data['error']);
    }//end testGetManifestReturns404WhenSlugUnknown()

    /**
     * Inconsistent state — route exists but no applicationUuid → 500.
     *
     * @return void
     */
    public function testGetManifestReturns500WhenRouteMissingApplicationUuid(): void
    {
        $controller = $this->buildController();
        $this->objectService->method('searchObjects')
            ->willReturn([['slug' => 'hello-world']]);

        $this->logger->expects(self::atLeastOnce())->method('warning');

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        self::assertSame('inconsistent_state', $data['error']);
    }//end testGetManifestReturns500WhenRouteMissingApplicationUuid()

    /**
     * listMine returns only Applications the caller has a role on
     * (REQ-OBRBAC-002 / REQ-OBR-007). Non-role rows are filtered out
     * server-side; the caller never sees their `permissions` or
     * `manifest` payloads.
     *
     * @return void
     */
    public function testListMineFiltersToRoledApplications(): void
    {
        $controller = $this->buildController(uid: 'bob', callerGroups: ['team-alpha']);

        $alpha = [
            'uuid'        => 'app-alpha',
            'slug'        => 'alpha',
            'name'        => 'Alpha',
            'manifest'    => ['version' => '1.0.0'],
            'permissions' => ['owners' => [], 'editors' => [], 'viewers' => ['team-alpha']],
        ];
        $beta = [
            'uuid'        => 'app-beta',
            'slug'        => 'beta',
            'name'        => 'Beta',
            'manifest'    => ['version' => '1.0.0'],
            'permissions' => ['owners' => ['team-omega'], 'editors' => [], 'viewers' => []],
        ];

        $this->objectService->method('searchObjects')->willReturn([$alpha, $beta]);

        $result = $controller->listMine();

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        self::assertCount(1, $data);
        self::assertSame('app-alpha', $data[0]['uuid']);
    }//end testListMineFiltersToRoledApplications()

    /**
     * listMine returns the full unfiltered list to a Nextcloud admin
     * and records a single audit event for the bypass (REQ-OBRBAC-006).
     *
     * @return void
     */
    public function testListMineAdminBypassReturnsAllAndAudits(): void
    {
        $controller = $this->buildController(uid: 'sysadmin', callerGroups: ['admin'], isAdmin: true);

        $alpha = [
            'uuid'        => 'app-alpha',
            'slug'        => 'alpha',
            'permissions' => ['owners' => [], 'editors' => [], 'viewers' => ['team-alpha']],
        ];
        $beta = [
            'uuid'        => 'app-beta',
            'slug'        => 'beta',
            'permissions' => ['owners' => ['team-omega'], 'editors' => [], 'viewers' => []],
        ];

        $this->objectService->method('searchObjects')->willReturn([$alpha, $beta]);

        $this->logger->expects(self::atLeastOnce())
            ->method('info')
            ->with(
                self::stringContains('rbac.admin_bypass'),
                self::callback(static function (array $ctx): bool {
                    return ($ctx['actor'] ?? null) === 'sysadmin'
                        && ($ctx['count'] ?? null) === 2;
                })
            );

        $result = $controller->listMine();

        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertCount(2, $result->getData());
    }//end testListMineAdminBypassReturnsAllAndAudits()

    /**
     * Admin bypass on the manifest endpoint writes an entry to the OR
     * audit trail (REQ-OBRBAC-006), not just the PSR logger. The
     * Application is passed in as an ObjectEntity so the mapper can be
     * called.
     *
     * @return void
     */
    public function testGetManifestAdminBypassWritesOrAuditTrail(): void
    {
        $controller = $this->buildController(uid: 'sysadmin', callerGroups: ['admin'], isAdmin: true);

        // Use an ObjectEntity-like stub so the controller reaches the
        // AuditTrailMapper write path (the array-only fixture used by
        // wireApplication() exercises the fallback PSR-log branch).
        $manifest = [
            'version' => '1.0.0',
            'menu'    => [],
            'pages'   => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
        ];

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn([
            'manifest'    => $manifest,
            'permissions' => ['owners' => ['team-alpha'], 'editors' => [], 'viewers' => []],
        ]);

        $this->objectService->method('searchObjects')
            ->willReturn([['applicationUuid' => 'abc-123']]);
        $this->objectService->method('find')->willReturn($entity);

        $this->auditTrailMapper->expects(self::once())
            ->method('createAuditTrailEntry')
            ->with(
                self::identicalTo($entity),
                self::equalTo('rbac.admin_bypass'),
                self::callback(static function (array $ctx): bool {
                    return ($ctx['actor'] ?? null) === 'sysadmin'
                        && ($ctx['slug'] ?? null) === 'hello-world';
                })
            );

        $result = $controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_OK, $result->getStatus());
    }//end testGetManifestAdminBypassWritesOrAuditTrail()
}//end class
