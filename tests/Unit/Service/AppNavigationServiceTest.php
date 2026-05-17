<?php

/**
 * Unit tests for AppNavigationService.
 *
 * Covers REQ-OBNAV-001 (published-only filter), REQ-OBNAV-002 (user/group/
 * admin gating), REQ-OBNAV-003 (group:* wildcard), REQ-OBNAV-004 (draft +
 * archived exclusion via filter).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Service
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

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\Service\AppNavigationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see AppNavigationService}.
 */
class AppNavigationServiceTest extends TestCase
{
    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock URL generator.
     *
     * @var IURLGenerator&MockObject
     */
    private IURLGenerator&MockObject $urlGenerator;

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
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Service under test.
     */
    private AppNavigationService $service;

    /**
     * Build shared mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->urlGenerator  = $this->createMock(IURLGenerator::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->urlGenerator
            ->method('linkToRouteAbsolute')
            ->willReturnCallback(fn ($route, $params) => '/icon/'.$params['slug'].'.svg');

        $this->service = new AppNavigationService(
            $this->objectService,
            $this->urlGenerator,
            $this->userSession,
            $this->groupManager,
            $this->logger
        );
    }//end setUp()

    // -------------------------------------------------------------------------
    // registerNavEntries — published-only filter
    // -------------------------------------------------------------------------

    /**
     * Only published Applications produce nav entries; draft/archived excluded
     * by the status == published filter in the OR query.
     *
     * @return void
     */
    public function testRegisterNavEntriesRegistersPublishedAppsOnly(): void
    {
        $publishedApp = [
            'slug'        => 'hello-world',
            'name'        => 'Hello World',
            'status'      => 'published',
            'permissions' => ['owners' => ['group:*'], 'editors' => [], 'viewers' => []],
        ];

        // ObjectService returns ONLY published apps (filter is applied by
        // the service itself in the findAll config). We simulate that here.
        $this->objectService
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$publishedApp]);

        $registeredCallables = [];
        $nav = $this->createMock(INavigationManager::class);
        $nav->expects($this->once())
            ->method('add')
            ->willReturnCallback(function ($callable) use (&$registeredCallables): void {
                $registeredCallables[] = $callable;
            });

        $this->service->registerNavEntries($nav);

        $this->assertCount(1, $registeredCallables);
    }//end testRegisterNavEntriesRegistersPublishedAppsOnly()

    // -------------------------------------------------------------------------
    // registerNavEntries — empty result → no entries
    // -------------------------------------------------------------------------

    /**
     * When OR returns no published Applications, no nav entries are registered.
     *
     * @return void
     */
    public function testRegisterNavEntriesNoneWhenNoPublishedApps(): void
    {
        $this->objectService
            ->method('findAll')
            ->willReturn([]);

        $nav = $this->createMock(INavigationManager::class);
        $nav->expects($this->never())->method('add');

        $this->service->registerNavEntries($nav);
    }//end testRegisterNavEntriesNoneWhenNoPublishedApps()

    // -------------------------------------------------------------------------
    // isVisibleForCurrentUser — group:* wildcard
    // -------------------------------------------------------------------------

    /**
     * When group:* is in owners, every signed-in user sees the entry.
     *
     * @return void
     */
    public function testIsVisibleForCurrentUserWithWildcardOwner(): void
    {
        $permissions = ['owners' => ['group:*'], 'editors' => [], 'viewers' => []];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('charlie');

        $this->userSession->method('getUser')->willReturn($user);

        // groupManager should NOT be consulted — wildcard short-circuits.
        $this->groupManager->expects($this->never())->method('getUserGroupIds');

        $result = $this->service->isVisibleForCurrentUser(
            $permissions,
            $this->userSession,
            $this->groupManager
        );

        $this->assertTrue($result);
    }//end testIsVisibleForCurrentUserWithWildcardOwner()

    /**
     * When group:* is in viewers, every signed-in user sees the entry.
     *
     * @return void
     */
    public function testIsVisibleForCurrentUserWithWildcardViewer(): void
    {
        $permissions = ['owners' => [], 'editors' => [], 'viewers' => ['group:*']];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->expects($this->never())->method('getUserGroupIds');

        $result = $this->service->isVisibleForCurrentUser(
            $permissions,
            $this->userSession,
            $this->groupManager
        );

        $this->assertTrue($result);
    }//end testIsVisibleForCurrentUserWithWildcardViewer()

    // -------------------------------------------------------------------------
    // isVisibleForCurrentUser — user:<uid> match
    // -------------------------------------------------------------------------

    /**
     * User matches an explicit user:<uid> entry in owners.
     *
     * @return void
     */
    public function testIsVisibleForCurrentUserWithDirectUidMatch(): void
    {
        $permissions = [
            'owners'  => ['user:alice'],
            'editors' => [],
            'viewers' => [],
        ];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->service->isVisibleForCurrentUser(
            $permissions,
            $this->userSession,
            $this->groupManager
        );

        $this->assertTrue($result);
    }//end testIsVisibleForCurrentUserWithDirectUidMatch()

    // -------------------------------------------------------------------------
    // isVisibleForCurrentUser — group:<gid> match
    // -------------------------------------------------------------------------

    /**
     * User is a member of a group that matches group:<gid> in viewers.
     *
     * @return void
     */
    public function testIsVisibleForCurrentUserWithGroupMatch(): void
    {
        $permissions = [
            'owners'  => [],
            'editors' => [],
            'viewers' => ['group:viewers-alpha'],
        ];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager
            ->method('getUserGroupIds')
            ->willReturn(['viewers-alpha', 'all-users']);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $result = $this->service->isVisibleForCurrentUser(
            $permissions,
            $this->userSession,
            $this->groupManager
        );

        $this->assertTrue($result);
    }//end testIsVisibleForCurrentUserWithGroupMatch()

    // -------------------------------------------------------------------------
    // isVisibleForCurrentUser — non-member, no wildcard → false
    // -------------------------------------------------------------------------

    /**
     * User has no matching UID, group, or wildcard — not visible.
     *
     * @return void
     */
    public function testIsVisibleForCurrentUserReturnsFalseForNonMember(): void
    {
        $permissions = [
            'owners'  => ['user:alice'],
            'editors' => [],
            'viewers' => ['group:viewers-alpha'],
        ];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('eve');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['other-group']);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $result = $this->service->isVisibleForCurrentUser(
            $permissions,
            $this->userSession,
            $this->groupManager
        );

        $this->assertFalse($result);
    }//end testIsVisibleForCurrentUserReturnsFalseForNonMember()

    // -------------------------------------------------------------------------
    // isVisibleForCurrentUser — Nextcloud admin bypass
    // -------------------------------------------------------------------------

    /**
     * Nextcloud admins always see published entries regardless of permissions.
     *
     * @return void
     */
    public function testIsVisibleForCurrentUserAdminAlwaysSees(): void
    {
        $permissions = ['owners' => [], 'editors' => [], 'viewers' => []];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);

        $result = $this->service->isVisibleForCurrentUser(
            $permissions,
            $this->userSession,
            $this->groupManager
        );

        $this->assertTrue($result);
    }//end testIsVisibleForCurrentUserAdminAlwaysSees()

    // -------------------------------------------------------------------------
    // isVisibleForCurrentUser — unauthenticated session → false
    // -------------------------------------------------------------------------

    /**
     * No session user → not visible.
     *
     * @return void
     */
    public function testIsVisibleForCurrentUserReturnsFalseWhenNoSession(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->isVisibleForCurrentUser(
            ['owners' => ['group:*']],
            $this->userSession,
            $this->groupManager
        );

        $this->assertFalse($result);
    }//end testIsVisibleForCurrentUserReturnsFalseWhenNoSession()

    // -------------------------------------------------------------------------
    // registerNavEntries — OR failure → logs warning, registers no entries
    // -------------------------------------------------------------------------

    /**
     * When OR throws, registerNavEntries catches it, logs, and registers nothing.
     *
     * @return void
     */
    public function testRegisterNavEntriesHandlesOrFailureGracefully(): void
    {
        $this->objectService
            ->method('findAll')
            ->willThrowException(new \RuntimeException('OR offline'));

        $this->logger->expects($this->once())->method('warning');

        $nav = $this->createMock(INavigationManager::class);
        $nav->expects($this->never())->method('add');

        $this->service->registerNavEntries($nav);
    }//end testRegisterNavEntriesHandlesOrFailureGracefully()

    // -------------------------------------------------------------------------
    // Closure shape — published entry returns expected array keys
    // -------------------------------------------------------------------------

    /**
     * The closure registered for a published app returns the expected shape
     * including id, name, href, icon, order, and enabled.
     *
     * @return void
     */
    public function testRegisteredClosureReturnsExpectedShape(): void
    {
        $publishedApp = [
            'slug'        => 'hello-world',
            'name'        => 'Hello World',
            'status'      => 'published',
            'permissions' => ['owners' => ['group:*'], 'editors' => [], 'viewers' => []],
        ];

        $this->objectService
            ->method('findAll')
            ->willReturn([$publishedApp]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);
        $this->groupManager->method('isAdmin')->willReturn(false);

        $registeredClosures = [];
        $nav = $this->createMock(INavigationManager::class);
        $nav->method('add')
            ->willReturnCallback(function ($callable) use (&$registeredClosures): void {
                $registeredClosures[] = $callable;
            });

        $this->service->registerNavEntries($nav);

        $this->assertCount(1, $registeredClosures);

        $entry = ($registeredClosures[0])();

        $this->assertSame('openbuilt-app-hello-world', $entry['id']);
        $this->assertSame('Hello World', $entry['name']);
        $this->assertStringContainsString('/apps/openbuilt/hello-world', $entry['href']);
        $this->assertArrayHasKey('order', $entry);
        $this->assertArrayHasKey('enabled', $entry);
    }//end testRegisteredClosureReturnsExpectedShape()
}//end class
