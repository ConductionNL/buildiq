<?php

/**
 * Unit tests for the six OpenBuild MCP write-handler classes.
 *
 * Covers issue #160 (no tests for write handlers) and validates the input
 * guards added in #164 / #167 / #168:
 *   - Argument validation rejects bad slugs, missing required fields, and
 *     unsafe route values.
 *   - RBAC gate blocks unauthenticated callers before hitting any service.
 *   - Admin-only gate for createApp and upsertSchema.
 *   - widgetType allow-list in addWidget (#167).
 *   - route pattern guard in upsertPage and upsertMenuItem (#167).
 *
 * Each handler is exercised through the full OpenBuildToolProvider dispatch
 * path so argument normalisation, gate ordering, and handler wiring are all
 * covered end-to-end.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Mcp\Handler
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

namespace OCA\OpenBuild\Tests\Unit\Mcp\Handler;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenBuild\Mcp\Handler\AbstractToolHandler;
use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates argument guards and RBAC on all six write handlers.
 */
class WriteHandlerValidationTest extends TestCase {

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
	 * An admin-user mock.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $adminUser;

	/**
	 * An owner-user mock (has app write access but is not NC admin).
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $ownerUser;

	/**
	 * Set up mocks and the provider instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->adminUser = $this->createMock(IUser::class);
		$this->adminUser->method('getUID')->willReturn('admin-user');

		$this->ownerUser = $this->createMock(IUser::class);
		$this->ownerUser->method('getUID')->willReturn('alice');

		$this->provider = new OpenBuildToolProvider(
			$this->userSession,
			$this->groupManager,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	// =========================================================================
	// createApp
	// =========================================================================

	/**
	 * createApp rejects an empty slug before touching any service.
	 *
	 * @return void
	 */
	public function testCreateAppRejectsEmptySlug(): void {
		$this->userSession->method('getUser')->willReturn($this->adminUser);
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.createApp', ['slug' => '', 'name' => 'My App']);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testCreateAppRejectsEmptySlug()

	/**
	 * createApp rejects a name that is too short.
	 *
	 * @return void
	 */
	public function testCreateAppRejectsTooShortName(): void {
		$this->userSession->method('getUser')->willReturn($this->adminUser);
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.createApp', ['slug' => 'my-app', 'name' => 'X']);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testCreateAppRejectsTooShortName()

	/**
	 * createApp rejects an unknown preset.
	 *
	 * @return void
	 */
	public function testCreateAppRejectsUnknownPreset(): void {
		$this->userSession->method('getUser')->willReturn($this->adminUser);
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool(
			'openbuild.createApp',
			['slug' => 'my-app', 'name' => 'My App', 'preset' => 'invalid-preset']
		);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testCreateAppRejectsUnknownPreset()

	/**
	 * createApp returns forbidden when unauthenticated.
	 *
	 * @return void
	 */
	public function testCreateAppForbiddenWhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.createApp', ['slug' => 'my-app', 'name' => 'My App']);

		$this->assertTrue($result['isError']);
		$this->assertSame('forbidden', $result['error']);

	}//end testCreateAppForbiddenWhenUnauthenticated()

	/**
	 * createApp returns forbidden for non-admin.
	 *
	 * @return void
	 */
	public function testCreateAppForbiddenForNonAdmin(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.createApp', ['slug' => 'my-app', 'name' => 'My App']);

		$this->assertTrue($result['isError']);
		$this->assertSame('forbidden', $result['error']);

	}//end testCreateAppForbiddenForNonAdmin()

	// =========================================================================
	// upsertSchema
	// =========================================================================

	/**
	 * upsertSchema rejects a missing appSlug.
	 *
	 * @return void
	 */
	public function testUpsertSchemaRejectsMissingAppSlug(): void {
		$this->userSession->method('getUser')->willReturn($this->adminUser);
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.upsertSchema', [
			'slug' => 'my-schema',
			'title' => 'My Schema',
			'properties' => ['name' => ['type' => 'string']],
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertSchemaRejectsMissingAppSlug()

	/**
	 * upsertSchema rejects an empty properties object.
	 *
	 * @return void
	 */
	public function testUpsertSchemaRejectsEmptyProperties(): void {
		$this->userSession->method('getUser')->willReturn($this->adminUser);
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.upsertSchema', [
			'appSlug' => 'my-app',
			'slug' => 'my-schema',
			'title' => 'My Schema',
			'properties' => [],
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertSchemaRejectsEmptyProperties()

	/**
	 * upsertSchema returns forbidden for non-admin.
	 *
	 * @return void
	 */
	public function testUpsertSchemaForbiddenForNonAdmin(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.upsertSchema', [
			'appSlug' => 'my-app',
			'slug' => 'my-schema',
			'title' => 'My Schema',
			'properties' => ['name' => ['type' => 'string']],
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('forbidden', $result['error']);

	}//end testUpsertSchemaForbiddenForNonAdmin()

	// =========================================================================
	// upsertPage — argument guards + route validation (#167)
	// =========================================================================

	/**
	 * upsertPage rejects a missing pageId.
	 *
	 * @return void
	 */
	public function testUpsertPageRejectsMissingPageId(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.upsertPage', [
			'appSlug' => 'my-app',
			'title' => 'Home',
			'type' => 'dashboard',
			'route' => '/home',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertPageRejectsMissingPageId()

	/**
	 * upsertPage rejects an unsafe route (javascript: URI) — issue #167.
	 *
	 * @return void
	 */
	public function testUpsertPageRejectsJavascriptRoute(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.upsertPage', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'title' => 'Home',
			'type' => 'dashboard',
			'route' => 'javascript:alert(1)',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertPageRejectsJavascriptRoute()

	/**
	 * upsertPage rejects a route that does not start with '/'.
	 *
	 * @return void
	 */
	public function testUpsertPageRejectsRelativeRoute(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.upsertPage', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'title' => 'Home',
			'type' => 'dashboard',
			'route' => 'home/dashboard',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertPageRejectsRelativeRoute()

	/**
	 * upsertPage accepts a valid absolute route (RBAC gate then fails with not_found
	 * because no version is wired, proving the validation passed).
	 *
	 * @return void
	 */
	public function testUpsertPageAcceptsValidRoute(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$callCount = 0;
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsBySlug')
			->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				if ($callCount === 1) {
					return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice']]]];
				}

				if ($callCount === 2) {
					return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App']];
				}

				return [];
			});

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.upsertPage', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'title' => 'Home',
			'type' => 'dashboard',
			'route' => '/home/dashboard',
		]);

		// Route was valid; hit not_found (no version), NOT invalid_arguments.
		$this->assertTrue($result['isError']);
		$this->assertNotSame('invalid_arguments', $result['error'], 'Valid route must pass validation');
		$this->assertSame('not_found', $result['error']);

	}//end testUpsertPageAcceptsValidRoute()

	// =========================================================================
	// upsertMenuItem — route validation (#167)
	// =========================================================================

	/**
	 * upsertMenuItem rejects an unsafe route (javascript: URI) — issue #167.
	 *
	 * @return void
	 */
	public function testUpsertMenuItemRejectsJavascriptRoute(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.upsertMenuItem', [
			'appSlug' => 'my-app',
			'id' => 'nav-home',
			'label' => 'Home',
			'route' => 'javascript:void(0)',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertMenuItemRejectsJavascriptRoute()

	/**
	 * upsertMenuItem rejects a route that is a protocol-relative URL.
	 *
	 * @return void
	 */
	public function testUpsertMenuItemRejectsProtocolRelativeRoute(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.upsertMenuItem', [
			'appSlug' => 'my-app',
			'id' => 'nav-ext',
			'label' => 'External',
			'route' => '//evil.example.com/path',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertMenuItemRejectsProtocolRelativeRoute()

	/**
	 * upsertMenuItem rejects a missing id.
	 *
	 * @return void
	 */
	public function testUpsertMenuItemRejectsMissingId(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.upsertMenuItem', [
			'appSlug' => 'my-app',
			'label' => 'Home',
			'route' => '/home',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testUpsertMenuItemRejectsMissingId()

	// =========================================================================
	// addWidget — widgetType allow-list (#167)
	// =========================================================================

	/**
	 * addWidget rejects an unknown widgetType — issue #167.
	 *
	 * @return void
	 */
	public function testAddWidgetRejectsUnknownWidgetType(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'malicious-widget-type',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testAddWidgetRejectsUnknownWidgetType()

	/**
	 * addWidget rejects a missing pageId.
	 *
	 * @return void
	 */
	public function testAddWidgetRejectsMissingPageId(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		$objectService = $this->buildOwnerObjectService(uid: 'alice', appSlug: 'my-app');
		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.addWidget', [
			'appSlug' => 'my-app',
			'widgetType' => 'stats-block',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testAddWidgetRejectsMissingPageId()

	/**
	 * addWidget accepts a known widgetType (proceeds past validation to not_found for
	 * the page, proving the allow-list did not reject it).
	 *
	 * @return void
	 */
	public function testAddWidgetAcceptsKnownWidgetType(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		// Return app (RBAC OK) + version with empty pages (page not found).
		$callCount = 0;
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsBySlug')
			->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				if ($callCount === 1) {
					// RBAC
					return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:alice']]]];
				}

				if ($callCount === 2) {
					// loadVersion: app found
					return [['uuid' => 'app-uuid-1', 'slug' => 'my-app', 'name' => 'My App']];
				}

				if ($callCount === 3) {
					// loadVersion: version found with empty manifest
					return [['uuid' => 'ver-uuid-1', 'slug' => 'development', 'manifest' => ['pages' => []]]];
				}

				return [];
			});

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$result = $this->provider->invokeTool('openbuild.addWidget', [
			'appSlug' => 'my-app',
			'pageId' => 'home',
			'widgetType' => 'stats-block',
		]);

		// Widget type was valid; hit not_found (page not in manifest), not invalid_arguments.
		$this->assertTrue($result['isError']);
		$this->assertNotSame('invalid_arguments', $result['error'], 'Known widgetType must pass validation');
		$this->assertSame('not_found', $result['error']);

	}//end testAddWidgetAcceptsKnownWidgetType()

	// =========================================================================
	// promoteVersion — argument validation
	// =========================================================================

	/**
	 * promoteVersion rejects a missing appSlug.
	 *
	 * @return void
	 */
	public function testPromoteVersionRejectsMissingAppSlug(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.promoteVersion', [
			'sourceVersionSlug' => 'development',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testPromoteVersionRejectsMissingAppSlug()

	/**
	 * promoteVersion rejects an unknown strategy.
	 *
	 * @return void
	 */
	public function testPromoteVersionRejectsUnknownStrategy(): void {
		$this->userSession->method('getUser')->willReturn($this->ownerUser);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.promoteVersion', [
			'appSlug' => 'my-app',
			'sourceVersionSlug' => 'development',
			'strategy' => 'invalid-strategy',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('invalid_arguments', $result['error']);

	}//end testPromoteVersionRejectsUnknownStrategy()

	/**
	 * promoteVersion returns forbidden when unauthenticated.
	 *
	 * @return void
	 */
	public function testPromoteVersionForbiddenWhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->container->expects($this->never())->method('get');

		$result = $this->provider->invokeTool('openbuild.promoteVersion', [
			'appSlug' => 'my-app',
			'sourceVersionSlug' => 'development',
		]);

		$this->assertTrue($result['isError']);
		$this->assertSame('forbidden', $result['error']);

	}//end testPromoteVersionForbiddenWhenUnauthenticated()

	// =========================================================================
	// Shared helpers
	// =========================================================================

	/**
	 * Build an ObjectService mock that returns an app with $uid in owners for RBAC,
	 * and returns [] for any subsequent calls (version not found).
	 *
	 * @param string $uid The user UID to place in owners.
	 * @param string $appSlug The app slug.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectServiceInterface&MockObject
	 */
	private function buildOwnerObjectService(string $uid, string $appSlug): object {
		$objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$objectService->method('searchObjectsBySlug')
			->willReturnCallback(function (string $register, string $schema, array $filters) use ($uid, $appSlug) {
				if ($schema === 'application' && ($filters['slug'] ?? '') === $appSlug) {
					return [['uuid' => 'app-uuid-1', 'slug' => $appSlug, 'permissions' => ['owners' => ['user:' . $uid]]]];
				}

				return [];
			});

		return $objectService;
	}//end buildOwnerObjectService()

	/**
	 * Build an AbstractToolHandler exposing recordAdminBypass for testing.
	 *
	 * @param AuditTrailMapper|null $auditMapper The audit-trail mapper (or null).
	 *
	 * @return object A handler with a public callRecordAdminBypass() shim.
	 */
	private function bypassHandler(?AuditTrailMapper $auditMapper): object {
		return new class($this->userSession, $this->container, $this->logger, $this->groupManager, null, $auditMapper) extends AbstractToolHandler {
			public function handle(array $args): array {
				return [];
			}

			public function callRecordAdminBypass(mixed $entity, string $slug, string $uid): void {
				$this->recordAdminBypass(appEntity: $entity, appSlug: $slug, uid: $uid);
			}
		};
	}//end bypassHandler()

	/**
	 * L2: an MCP admin-bypass with the audit mapper + app entity available
	 * writes an audit-trail entry (parity with the HTTP path).
	 *
	 * @return void
	 */
	public function testAdminBypassWritesAuditTrail(): void {
		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$entity = $this->createMock(ObjectEntity::class);

		$auditMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with($entity, 'rbac.admin_bypass', $this->anything());

		$this->bypassHandler($auditMapper)->callRecordAdminBypass($entity, 'my-app', 'admin-user');

	}//end testAdminBypassWritesAuditTrail()

	/**
	 * L2: with no audit mapper injected, the bypass falls back to a PSR log and
	 * does not attempt an audit write (best-effort, non-aborting).
	 *
	 * @return void
	 */
	public function testAdminBypassFallsBackToLogWhenNoMapper(): void {
		$entity = $this->createMock(ObjectEntity::class);
		$this->logger->expects($this->once())->method('info')
			->with('OpenBuild MCP: rbac.admin_bypass', $this->anything());

		$this->bypassHandler(null)->callRecordAdminBypass($entity, 'my-app', 'admin-user');

	}//end testAdminBypassFallsBackToLogWhenNoMapper()

	/**
	 * Build an AbstractToolHandler exposing requireWriteRole, wired to a container
	 * whose ObjectService returns $searchResult from searchObjectsBySlug and
	 * $findEntity from find().
	 *
	 * @param AuditTrailMapper|null $auditMapper The audit mapper (or null).
	 * @param array<int,mixed> $searchResult What searchObjectsBySlug returns (may be rendered arrays).
	 * @param mixed $findEntity What ObjectService::find() returns (an ObjectEntity when hydrated).
	 *
	 * @return object A handler with a public callRequireWriteRole() shim.
	 */
	private function writeRoleHandler(?AuditTrailMapper $auditMapper, array $searchResult, mixed $findEntity): object {
		$objectService = new class($searchResult, $findEntity) {
			/**
			 * @param array<int,mixed> $searchResult The search result.
			 * @param mixed $findEntity The find() return value.
			 */
			public function __construct(
				private array $searchResult,
				private mixed $findEntity,
			) {
			}//end __construct()

			/**
			 * @return array<int,mixed>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->searchResult;
			}//end searchObjectsBySlug()

			/**
			 * @return mixed
			 */
			public function find(string $id): mixed {
				return $this->findEntity;
			}//end find()
		};

		$this->container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		return new class($this->userSession, $this->container, $this->logger, $this->groupManager, null, $auditMapper) extends AbstractToolHandler {
			public function handle(array $args): array {
				return [];
			}

			/**
			 * @return array<string,mixed>|null
			 */
			public function callRequireWriteRole(string $appSlug): ?array {
				return $this->requireWriteRole(appSlug: $appSlug);
			}
		};
	}//end writeRoleHandler()

	/**
	 * L2 / #11-#3: the MCP admin-bypass audit write MUST fire even when
	 * searchObjectsBySlug returns a rendered ARRAY (which OpenRegister does when
	 * the schema has property-level authorization or _extend/_fields are set).
	 * requireWriteRole re-resolves the app via ObjectService::find() to obtain a
	 * hydrated ObjectEntity, so the audit is written regardless of render shape —
	 * closing the silent-skip gap where `$apps[0] instanceof ObjectEntity` was
	 * false and the audit degraded to a PSR log.
	 *
	 * @return void
	 */
	public function testAdminBypassAuditsEvenWhenSearchReturnsRenderedArray(): void {
		$this->userSession->method('getUser')->willReturn($this->adminUser);
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		// searchObjectsBySlug returns a rendered ARRAY (no owner/editor for the
		// admin) — the pre-fix instanceof gate would have skipped the audit.
		$renderedArray = [['id' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:someone-else']]]];
		// find() returns a hydrated ObjectEntity for the audit write.
		$entity = $this->createMock(ObjectEntity::class);

		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with($entity, 'rbac.admin_bypass', $this->anything());

		$result = $this->writeRoleHandler($auditMapper, $renderedArray, $entity)->callRequireWriteRole('my-app');
		$this->assertNull($result, 'admin bypass grants write access (null = authorised)');

	}//end testAdminBypassAuditsEvenWhenSearchReturnsRenderedArray()

	/**
	 * L2 / #11-#5: no admin_bypass audit is recorded when the admin ALSO holds a
	 * genuine owner/editor role on the app — that is authorised access, not a
	 * bypass, and auditing it would produce false compliance records. Only a
	 * genuine bypass (admin with no role) is audited.
	 *
	 * @return void
	 */
	public function testNoBypassAuditWhenAdminAlsoHoldsRealRole(): void {
		$this->userSession->method('getUser')->willReturn($this->adminUser);
		$this->groupManager->method('isAdmin')->with('admin-user')->willReturn(true);
		$this->groupManager->method('getUserGroups')->willReturn([]);

		// The admin is ALSO an owner (user:admin-user) — a real role, not a bypass.
		$app = [['id' => 'app-uuid-1', 'slug' => 'my-app', 'permissions' => ['owners' => ['user:admin-user']]]];
		$entity = $this->createMock(ObjectEntity::class);

		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->expects($this->never())->method('createAuditTrailEntry');

		$result = $this->writeRoleHandler($auditMapper, $app, $entity)->callRequireWriteRole('my-app');
		$this->assertNull($result, 'owner is authorised (null), and no bypass is audited');

	}//end testNoBypassAuditWhenAdminAlsoHoldsRealRole()
}//end class
