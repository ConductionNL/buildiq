<?php

/**
 * Unit tests for OpenBuildToolProvider.
 *
 * Covers: getAppId, getTools catalogue shape, invokeTool dispatch of an
 * unknown tool id (no throw), argument validation, the unauthenticated
 * forbidden path, and the per-Application RBAC gate on write tools.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Mcp;

use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit test suite for OpenBuildToolProvider.
 *
 * Every test runs in isolation with mocked services. The stub at
 * tests/Stubs/Mcp/IMcpToolProvider.php satisfies the interface declaration
 * when the openregister runtime (PR #1466) is absent.
 */
class OpenBuildToolProviderTest extends TestCase
{

    /**
     * Provider under test.
     *
     * @var OpenBuildToolProvider
     */
    private OpenBuildToolProvider $provider;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up mocks and the provider instance.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->container    = $this->createMock(ContainerInterface::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->provider = new OpenBuildToolProvider(
            $this->userSession,
            $this->groupManager,
            $this->container,
            $this->logger,
        );

    }//end setUp()

    /**
     * getAppId() returns "openbuild".
     *
     * @return void
     */
    public function testGetAppIdReturnsOpenbuild(): void
    {
        $this->assertSame('openbuild', $this->provider->getAppId());

    }//end testGetAppIdReturnsOpenbuild()

    /**
     * getTools() returns four well-formed descriptors with openbuild.* ids.
     *
     * @return void
     */
    public function testGetToolsCatalogue(): void
    {
        $tools = $this->provider->getTools();

        // The catalogue grew over time:
        //   v0: listApps + getAppManifest (2)
        //   versioning chain: + createApp + promoteVersion (4)
        //   schema/page-editor: + upsertSchema + upsertPage + addWidget
        //                       + upsertMenuItem (8)
        $this->assertCount(8, $tools);

        $ids = array_column($tools, 'id');
        $this->assertContains('openbuild.listApps', $ids);
        $this->assertContains('openbuild.getAppManifest', $ids);
        $this->assertContains('openbuild.createApp', $ids);
        $this->assertContains('openbuild.promoteVersion', $ids);
        $this->assertContains('openbuild.upsertSchema', $ids);
        $this->assertContains('openbuild.upsertPage', $ids);
        $this->assertContains('openbuild.addWidget', $ids);
        $this->assertContains('openbuild.upsertMenuItem', $ids);

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('id', $tool);
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);

            $this->assertIsString($tool['id']);
            $this->assertStringStartsWith('openbuild.', $tool['id']);
            $this->assertIsString($tool['name']);
            $this->assertNotSame('', $tool['name']);
            $this->assertIsString($tool['description']);
            $this->assertNotSame('', $tool['description']);

            $this->assertIsArray($tool['inputSchema']);
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertArrayHasKey('properties', $tool['inputSchema']);
            $this->assertIsArray($tool['inputSchema']['properties']);
            $this->assertArrayHasKey('required', $tool['inputSchema']);
            $this->assertIsArray($tool['inputSchema']['required']);
        }

    }//end testGetToolsCatalogue()

    /**
     * invokeTool() with an unknown id returns a structured error array (no throw).
     *
     * @return void
     */
    public function testInvokeUnknownToolReturnsErrorArray(): void
    {
        $result = $this->provider->invokeTool('openbuild.bogus', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('isError', $result);
        $this->assertTrue($result['isError']);
        $this->assertSame('unknown_tool', $result['error']);
        $this->assertStringContainsString('openbuild.listApps', $result['message']);

    }//end testInvokeUnknownToolReturnsErrorArray()

    /**
     * listApps rejects an out-of-range limit before touching any service.
     *
     * @return void
     */
    public function testListAppsRejectsInvalidLimit(): void
    {
        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.listApps', ['limit' => 999]);

        $this->assertTrue($result['isError']);
        $this->assertSame('invalid_arguments', $result['error']);

    }//end testListAppsRejectsInvalidLimit()

    /**
     * listApps returns forbidden when no user is signed in (per-object auth gate).
     *
     * @return void
     */
    public function testListAppsForbiddenWhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.listApps', []);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testListAppsForbiddenWhenUnauthenticated()

    /**
     * getAppManifest rejects a missing slug argument.
     *
     * @return void
     */
    public function testGetAppManifestRejectsMissingSlug(): void
    {
        $result = $this->provider->invokeTool('openbuild.getAppManifest', []);

        $this->assertTrue($result['isError']);
        $this->assertSame('invalid_arguments', $result['error']);

    }//end testGetAppManifestRejectsMissingSlug()

    /**
     * getAppManifest rejects a malformed slug.
     *
     * @return void
     */
    public function testGetAppManifestRejectsBadSlug(): void
    {
        $result = $this->provider->invokeTool('openbuild.getAppManifest', ['slug' => 'Not A Slug']);

        $this->assertTrue($result['isError']);
        $this->assertSame('invalid_arguments', $result['error']);

    }//end testGetAppManifestRejectsBadSlug()

    /**
     * getAppManifest returns forbidden when unauthenticated, after slug validation.
     *
     * @return void
     */
    public function testGetAppManifestForbiddenWhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.getAppManifest', ['slug' => 'hello-world']);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testGetAppManifestForbiddenWhenUnauthenticated()

    /**
     * An authenticated user is resolved from the session (smoke test of the gate).
     *
     * @return void
     */
    public function testAuthenticatedUserIsResolved(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // No ObjectService available — handler should fail closed with internal_error,
        // proving the auth gate passed and business logic was reached.
        $this->container->method('get')->willThrowException(new \RuntimeException('no ObjectService in test'));

        $result = $this->provider->invokeTool('openbuild.listApps', []);

        $this->assertTrue($result['isError']);
        $this->assertSame('internal_error', $result['error']);

    }//end testAuthenticatedUserIsResolved()

    // -------------------------------------------------------------------------
    // C1 + C2: RBAC gate tests for write tools
    // -------------------------------------------------------------------------

    /**
     * createApp returns forbidden when caller is not an NC admin (C2/C1 policy).
     *
     * @return void
     */
    public function testCreateAppForbiddenForNonAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);

        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.createApp', [
            'slug' => 'my-app',
            'name' => 'My App',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testCreateAppForbiddenForNonAdmin()

    /**
     * createApp returns forbidden when unauthenticated.
     *
     * @return void
     */
    public function testCreateAppForbiddenWhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.createApp', [
            'slug' => 'my-app',
            'name' => 'My App',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testCreateAppForbiddenWhenUnauthenticated()

    /**
     * upsertSchema returns forbidden for non-admin (C2 gate).
     *
     * @return void
     */
    public function testUpsertSchemaForbiddenForNonAdmin(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);

        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.upsertSchema', [
            'appSlug'    => 'my-app',
            'slug'       => 'my-schema',
            'title'      => 'My Schema',
            'properties' => ['name' => ['type' => 'string']],
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testUpsertSchemaForbiddenForNonAdmin()

    /**
     * upsertSchema returns forbidden when unauthenticated (C2 gate).
     *
     * @return void
     */
    public function testUpsertSchemaForbiddenWhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.upsertSchema', [
            'appSlug'    => 'my-app',
            'slug'       => 'my-schema',
            'title'      => 'My Schema',
            'properties' => ['name' => ['type' => 'string']],
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testUpsertSchemaForbiddenWhenUnauthenticated()

    /**
     * upsertPage returns forbidden when caller has no owners/editors role (C1 gate).
     *
     * The ObjectService is wired to return the app without the caller in any role bucket.
     *
     * @return void
     */
    public function testUpsertPageForbiddenForNonOwner(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

        // Not an admin.
        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
        // No group memberships.
        $this->groupManager->method('getUserGroups')->willReturn([]);

        // App exists but bob is not an owner/editor.
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsBySlug')->willReturn([
            ['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice'], 'editors' => []]],
        ]);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->provider->invokeTool('openbuild.upsertPage', [
            'appSlug' => 'my-app',
            'pageId'  => 'home',
            'title'   => 'Home',
            'type'    => 'dashboard',
            'route'   => '/home',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testUpsertPageForbiddenForNonOwner()

    /**
     * upsertPage proceeds past the RBAC gate when caller is in owners (C1 fix).
     *
     * The ObjectService returns the app with alice in owners; a second call
     * (loadVersion) returns not_found, proving the gate was passed and business
     * logic was reached.
     *
     * @return void
     */
    public function testUpsertPageAllowedForOwner(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $callCount     = 0;
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsBySlug')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // RBAC lookup — app found, alice is an owner.
                    return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice'], 'editors' => []]]];
                }

                // loadVersion lookup — app found again, version not found.
                if ($callCount === 2) {
                    return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App', 'permissions' => ['owners' => ['user:alice']]]];
                }

                return [];
            });

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->provider->invokeTool('openbuild.upsertPage', [
            'appSlug' => 'my-app',
            'pageId'  => 'home',
            'title'   => 'Home',
            'type'    => 'dashboard',
            'route'   => '/home',
        ]);

        // Gate passed; business logic returned not_found (no version), not forbidden.
        $this->assertTrue($result['isError']);
        $this->assertNotSame('forbidden', $result['error'], 'Owner should pass RBAC gate');
        $this->assertSame('not_found', $result['error']);

    }//end testUpsertPageAllowedForOwner()

    /**
     * addWidget returns forbidden for a non-owner (C1 gate).
     *
     * @return void
     */
    public function testAddWidgetForbiddenForNonOwner(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsBySlug')->willReturn([
            ['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice'], 'editors' => []]],
        ]);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->provider->invokeTool('openbuild.addWidget', [
            'appSlug'    => 'my-app',
            'pageId'     => 'home',
            'widgetType' => 'stats-block',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testAddWidgetForbiddenForNonOwner()

    /**
     * upsertMenuItem returns forbidden for a non-owner (C1 gate).
     *
     * @return void
     */
    public function testUpsertMenuItemForbiddenForNonOwner(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

        $this->groupManager->method('isAdmin')->with('bob')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsBySlug')->willReturn([
            ['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice'], 'editors' => []]],
        ]);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->provider->invokeTool('openbuild.upsertMenuItem', [
            'appSlug' => 'my-app',
            'id'      => 'nav-home',
            'label'   => 'Home',
            'route'   => '/home',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testUpsertMenuItemForbiddenForNonOwner()

    /**
     * promoteVersion returns forbidden for a non-owner even when caller is NC admin
     * (spec REQ-OBVP-007 — no admin bypass for promotion).
     *
     * @return void
     */
    public function testPromoteVersionForbiddenForAdminWithoutExplicitRole(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin-user');
        $this->userSession->method('getUser')->willReturn($user);

        // Caller IS an NC admin but has no explicit role entry.
        $this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsBySlug')->willReturn([
            ['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice'], 'editors' => []]],
        ]);

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->provider->invokeTool('openbuild.promoteVersion', [
            'appSlug'           => 'my-app',
            'sourceVersionSlug' => 'development',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testPromoteVersionForbiddenForAdminWithoutExplicitRole()

    /**
     * promoteVersion allows a caller with an explicit owners entry (no admin bypass
     * needed; the gate is per-Application RBAC — spec REQ-OBVP-007).
     *
     * After the RBAC gate passes, loadVersion returns not_found (no version data
     * wired), confirming the gate was cleared.
     *
     * @return void
     */
    public function testPromoteVersionAllowedForExplicitOwner(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // isAdmin is NOT called for promoteVersion (allowAdminBypass=false).
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $callCount     = 0;
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('searchObjectsBySlug')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // RBAC lookup — alice is an owner.
                    return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice']]]];
                }

                // loadVersion: app found, version not found.
                if ($callCount === 2) {
                    return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App']];
                }

                return [];
            });

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $result = $this->provider->invokeTool('openbuild.promoteVersion', [
            'appSlug'           => 'my-app',
            'sourceVersionSlug' => 'development',
        ]);

        // Gate passed; no version found → not_found, NOT forbidden.
        $this->assertTrue($result['isError']);
        $this->assertNotSame('forbidden', $result['error'], 'Explicit owner must pass RBAC gate');
        $this->assertSame('not_found', $result['error']);

    }//end testPromoteVersionAllowedForExplicitOwner()

    /**
     * promoteVersion returns forbidden when unauthenticated.
     *
     * @return void
     */
    public function testPromoteVersionForbiddenWhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuild.promoteVersion', [
            'appSlug'           => 'my-app',
            'sourceVersionSlug' => 'development',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('forbidden', $result['error']);

    }//end testPromoteVersionForbiddenWhenUnauthenticated()

}//end class
