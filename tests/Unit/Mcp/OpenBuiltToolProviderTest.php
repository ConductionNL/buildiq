<?php

/**
 * Unit tests for OpenBuiltToolProvider.
 *
 * Covers: getAppId, getTools catalogue shape, invokeTool dispatch of an
 * unknown tool id (no throw), argument validation, and the unauthenticated
 * forbidden path.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Mcp
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

namespace OCA\OpenBuilt\Tests\Unit\Mcp;

use OCA\OpenBuilt\Mcp\OpenBuiltToolProvider;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit test suite for OpenBuiltToolProvider.
 *
 * Every test runs in isolation with mocked services. The stub at
 * tests/Stubs/Mcp/IMcpToolProvider.php satisfies the interface declaration
 * when the openregister runtime (PR #1466) is absent.
 */
class OpenBuiltToolProviderTest extends TestCase
{

    /**
     * Provider under test.
     *
     * @var OpenBuiltToolProvider
     */
    private OpenBuiltToolProvider $provider;

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

        $this->provider = new OpenBuiltToolProvider(
            $this->userSession,
            $this->groupManager,
            $this->container,
            $this->logger,
        );

    }//end setUp()

    /**
     * getAppId() returns "openbuilt".
     *
     * @return void
     */
    public function testGetAppIdReturnsOpenbuilt(): void
    {
        $this->assertSame('openbuilt', $this->provider->getAppId());

    }//end testGetAppIdReturnsOpenbuilt()

    /**
     * getTools() returns four well-formed descriptors with openbuilt.* ids.
     *
     * @return void
     */
    public function testGetToolsCatalogue(): void
    {
        $tools = $this->provider->getTools();

        // The catalogue grew from 2 (listApps + getAppManifest) to 4
        // (added createApp + promoteVersion in the wizard + promotion
        // chain). Assert the new shape.
        $this->assertCount(4, $tools);

        $ids = array_column($tools, 'id');
        $this->assertContains('openbuilt.listApps', $ids);
        $this->assertContains('openbuilt.getAppManifest', $ids);
        $this->assertContains('openbuilt.createApp', $ids);
        $this->assertContains('openbuilt.promoteVersion', $ids);

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('id', $tool);
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);

            $this->assertIsString($tool['id']);
            $this->assertStringStartsWith('openbuilt.', $tool['id']);
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
        $result = $this->provider->invokeTool('openbuilt.bogus', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('isError', $result);
        $this->assertTrue($result['isError']);
        $this->assertSame('unknown_tool', $result['error']);
        $this->assertStringContainsString('openbuilt.listApps', $result['message']);

    }//end testInvokeUnknownToolReturnsErrorArray()

    /**
     * listApps rejects an out-of-range limit before touching any service.
     *
     * @return void
     */
    public function testListAppsRejectsInvalidLimit(): void
    {
        $this->container->expects($this->never())->method('get');

        $result = $this->provider->invokeTool('openbuilt.listApps', ['limit' => 999]);

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

        $result = $this->provider->invokeTool('openbuilt.listApps', []);

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
        $result = $this->provider->invokeTool('openbuilt.getAppManifest', []);

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
        $result = $this->provider->invokeTool('openbuilt.getAppManifest', ['slug' => 'Not A Slug']);

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

        $result = $this->provider->invokeTool('openbuilt.getAppManifest', ['slug' => 'hello-world']);

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

        $result = $this->provider->invokeTool('openbuilt.listApps', []);

        $this->assertTrue($result['isError']);
        $this->assertSame('internal_error', $result['error']);

    }//end testAuthenticatedUserIsResolved()

}//end class
