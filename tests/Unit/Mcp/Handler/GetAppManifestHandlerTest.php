<?php

/**
 * Unit tests for the getAppManifest MCP tool.
 *
 * Covers the argument and sign-in checks, the route -> application lookup and
 * its inconsistent states, the per-app role gate, the stripped permissions
 * roster and the error result when OpenRegister throws.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Mcp\Handler
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

namespace OCA\Buildiq\Tests\Unit\Mcp\Handler;

use OCA\Buildiq\Mcp\Handler\GetAppManifestHandler;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for GetAppManifestHandler::handle.
 *
 * @covers \OCA\Buildiq\Mcp\Handler\GetAppManifestHandler
 * @uses   \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class GetAppManifestHandlerTest extends TestCase {

	/**
	 * Mock OR object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

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
	 * The signed-in caller, or null for an anonymous one.
	 *
	 * @var IUser|null
	 */
	private ?IUser $user = null;

	/**
	 * Wire mocks; the caller defaults to `alice`, no admin, no groups.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->user = $user;
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the handler with the current caller.
	 *
	 * @return GetAppManifestHandler
	 */
	private function handler(): GetAppManifestHandler {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->user);

		return new GetAppManifestHandler(
			userSession: $session,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->logger,
			groupManager: $this->groupManager,
			objectService: $this->objectService
		);
	}//end handler()

	/**
	 * An entity mock that serialises to the given data.
	 *
	 * @param array<string, mixed> $data The serialised payload.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(array $data): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * Route `demo` to application `app-uuid` with the given data.
	 *
	 * @param array<string, mixed> $application The application payload.
	 *
	 * @return void
	 */
	private function arrangeApp(array $application): void {
		$this->objectService->method('searchObjectsBySlug')
			->with('buildiq', 'built-app-route', ['slug' => 'demo'], true, false)
			->willReturn([['applicationUuid' => 'app-uuid']]);
		$this->objectService->method('find')
			->with('app-uuid', $this->anything(), $this->anything(), 'buildiq', 'built-app')
			->willReturn($this->entity(data: $application));
	}//end arrangeApp()

	/**
	 * An editor reads the manifest; the permissions roster is stripped.
	 *
	 * @return void
	 */
	public function testAnEditorReadsTheManifestWithoutThePermissions(): void {
		$this->arrangeApp(
			application: [
				'uuid' => 'app-uuid',
				'name' => 'Demo',
				'permissions' => ['editors' => ['user:alice']],
				'manifest' => ['pages' => [['id' => 'home']], 'permissions' => ['owners' => ['user:bob']]],
			]
		);

		$this->assertSame(
			expected: [
				'success' => true,
				'slug' => 'demo',
				'name' => 'Demo',
				'manifest' => ['pages' => [['id' => 'home']]],
				'sources' => [['type' => 'buildiq.application', 'uuid' => 'app-uuid', 'url' => '/apps/buildiq/builder/demo', 'label' => 'Demo']],
			],
			actual: $this->handler()->handle(['slug' => 'demo'])
		);
	}//end testAnEditorReadsTheManifestWithoutThePermissions()

	/**
	 * A missing or malformed slug is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAMissingOrInvalidSlugIsRefused(): void {
		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Required argument slug is missing.'],
			actual: $this->handler()->handle([])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Required argument slug is missing.'],
			actual: $this->handler()->handle(['slug' => ''])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => "Invalid slug 'Demo App'."],
			actual: $this->handler()->handle(['slug' => 'Demo App'])
		);
	}//end testAMissingOrInvalidSlugIsRefused()

	/**
	 * An anonymous caller is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->user = null;
		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'forbidden', 'message' => 'You must be signed in to read a virtual app manifest.'],
			actual: $this->handler()->handle(['slug' => 'demo'])
		);
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * An unknown slug, a route without application and a dangling route.
	 *
	 * @return void
	 */
	public function testUnresolvableRoutesAreReported(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturnOnConsecutiveCalls(
			[],
			[['applicationUuid' => '']],
			[['applicationUuid' => 'gone']]
		);
		$this->objectService->method('find')->willReturn(null);
		$handler = $this->handler();

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'not_found', 'message' => "No published virtual app found for slug 'demo'."],
			actual: $handler->handle(['slug' => 'demo'])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid.'],
			actual: $handler->handle(['slug' => 'demo'])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'inconsistent_state', 'message' => 'Route points to an Application that does not exist.'],
			actual: $handler->handle(['slug' => 'demo'])
		);
	}//end testUnresolvableRoutesAreReported()

	/**
	 * A caller without any role on the app gets no manifest.
	 *
	 * @return void
	 */
	public function testACallerWithoutARoleIsRefused(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->arrangeApp(application: ['uuid' => 'app-uuid', 'permissions' => ['editors' => ['user:bob']], 'manifest' => ['pages' => []]]);

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'forbidden', 'message' => 'You do not have access to this application.'],
			actual: $this->handler()->handle(['slug' => 'demo'])
		);
	}//end testACallerWithoutARoleIsRefused()

	/**
	 * An application without a manifest array is reported, and the name falls back to the slug.
	 *
	 * @return void
	 */
	public function testAnApplicationWithoutAManifestIsReported(): void {
		$this->arrangeApp(application: ['uuid' => 'app-uuid', 'permissions' => ['owners' => ['user:alice']], 'manifest' => 'broken']);

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'no_manifest', 'message' => 'Application has no manifest.'],
			actual: $this->handler()->handle(['slug' => 'demo'])
		);
	}//end testAnApplicationWithoutAManifestIsReported()

	/**
	 * Without a name, the slug labels the result.
	 *
	 * @return void
	 */
	public function testTheSlugNamesAnUnnamedApplication(): void {
		$this->arrangeApp(application: ['id' => 'app-uuid', 'permissions' => ['owners' => ['user:alice']], 'manifest' => []]);

		$result = $this->handler()->handle(['slug' => 'demo']);

		$this->assertSame(expected: 'demo', actual: $result['name']);
		$this->assertSame(expected: 'app-uuid', actual: $result['sources'][0]['uuid']);
	}//end testTheSlugNamesAnUnnamedApplication()

	/**
	 * A failing lookup is logged and reported without the exception text.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsReportedAndLogged(): void {
		$this->objectService->method('searchObjectsBySlug')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')
			->with('Buildiq MCP: getAppManifest failed', ['slug' => 'demo', 'exception' => 'db down']);

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'internal_error', 'message' => 'Failed to resolve manifest.'],
			actual: $this->handler()->handle(['slug' => 'demo'])
		);
	}//end testAFailingLookupIsReportedAndLogged()
}//end class
