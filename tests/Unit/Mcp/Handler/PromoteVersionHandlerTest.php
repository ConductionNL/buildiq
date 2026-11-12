<?php

/**
 * Unit tests for the promoteVersion MCP tool.
 *
 * Promotion is never granted to an NC admin by role alone (REQ-OBVP-007): the
 * caller needs an explicit owners or editors entry. Covers that gate, the
 * argument checks, a version without a downstream target, a successful
 * promotion and a failing one.
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

use OCA\Buildiq\Mcp\Handler\PromoteVersionHandler;
use OCA\Buildiq\Service\VersionPromotionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PromoteVersionHandler::handle.
 */
class PromoteVersionHandlerTest extends TestCase {

	/**
	 * Mock OR object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock promotion service, handed out by the container.
	 *
	 * @var VersionPromotionService&MockObject
	 */
	private VersionPromotionService&MockObject $promotion;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Handler under test.
	 *
	 * @var PromoteVersionHandler
	 */
	private PromoteVersionHandler $handler;

	/**
	 * Wire a signed-in caller `alice` who is also an NC admin.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);
		$groupManager->method('getUserGroups')->willReturn([]);

		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->promotion = $this->createMock(originalClassName: VersionPromotionService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with('OCA\Buildiq\Service\VersionPromotionService')->willReturn($this->promotion);

		$this->handler = new PromoteVersionHandler(
			userSession: $session,
			container: $container,
			logger: $this->logger,
			groupManager: $groupManager,
			objectService: $this->objectService
		);
	}//end setUp()

	/**
	 * Build an entity mock that serialises to the given data.
	 *
	 * @param array<string, mixed> $data The serialised payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * Route the app and source-version lookups.
	 *
	 * @param array<string, mixed> $permissions The app's permissions block.
	 * @param array<string, mixed> $source The source version.
	 *
	 * @return void
	 */
	private function arrange(array $permissions, array $source): void {
		$app = ['id' => 'app-uuid', 'slug' => 'demo', 'name' => 'Demo', 'permissions' => $permissions];
		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(
			function (string $register, string $schema) use ($app, $source): array {
				if ($schema === 'built-app') {
					return [$this->entity(data: $app)];
				}

				return [$this->entity(data: $source)];
			}
		);
	}//end arrange()

	/**
	 * An NC admin without an owners or editors entry cannot promote.
	 *
	 * @return void
	 */
	public function testAnAdminWithoutAnExplicitRoleIsRefused(): void {
		$this->arrange(permissions: ['viewers' => ['user:alice']], source: ['id' => 'dev', 'promotesTo' => 'prod']);
		$this->promotion->expects(self::never())->method('promote');

		$result = $this->handler->handle(args: ['appSlug' => 'demo', 'sourceVersionSlug' => 'development']);

		self::assertSame(expected: 'forbidden', actual: $result['error']);
	}//end testAnAdminWithoutAnExplicitRoleIsRefused()

	/**
	 * A version without a downstream target cannot be promoted.
	 *
	 * @return void
	 */
	public function testAVersionWithoutATargetIsRefused(): void {
		$this->arrange(permissions: ['owners' => ['user:alice']], source: ['id' => 'dev', 'promotesTo' => '']);
		$this->promotion->expects(self::never())->method('promote');

		$result = $this->handler->handle(args: ['appSlug' => 'demo', 'sourceVersionSlug' => 'development']);

		self::assertSame(expected: 'no_promote_target', actual: $result['error']);
		self::assertSame(expected: "Version 'development' has no downstream target.", actual: $result['message']);
	}//end testAVersionWithoutATargetIsRefused()

	/**
	 * A promotion with the requested strategy reports both ends and the source app.
	 *
	 * @return void
	 */
	public function testPromotesWithTheRequestedStrategy(): void {
		$source = ['id' => 'dev-uuid', 'slug' => 'development', 'promotesTo' => 'prod-uuid'];
		$this->arrange(permissions: ['editors' => ['user:alice']], source: $source);
		$this->promotion->expects(self::once())->method('promote')
			->with(self::callback(callback: static fn (array $s): bool => $s['id'] === 'dev-uuid'), 'migrate-existing-data')
			->willReturn(['id' => 'prod-uuid', 'slug' => 'production', 'status' => 'published']);

		$result = $this->handler->handle(
			args: ['appSlug' => 'demo', 'sourceVersionSlug' => 'development', 'strategy' => 'migrate-existing-data']
		);

		self::assertTrue(condition: $result['promoted']);
		self::assertSame(expected: 'migrate-existing-data', actual: $result['strategy']);
		self::assertSame(expected: ['uuid' => 'dev-uuid', 'slug' => 'development'], actual: $result['from']);
		self::assertSame(expected: ['uuid' => 'prod-uuid', 'slug' => 'production', 'status' => 'published'], actual: $result['to']);
		self::assertSame(expected: 'app-uuid', actual: $result['sources'][0]['uuid']);
		self::assertSame(expected: 'Demo', actual: $result['sources'][0]['label']);
	}//end testPromotesWithTheRequestedStrategy()

	/**
	 * A failing promotion is logged and answered with a generic error.
	 *
	 * @return void
	 */
	public function testAFailingPromotionReturnsAGenericError(): void {
		$this->arrange(permissions: ['owners' => ['user:alice']], source: ['id' => 'dev', 'promotesTo' => 'prod']);
		$this->promotion->method('promote')->willThrowException(new RuntimeException('target locked'));
		$this->logger->expects(self::once())->method('error')->with('Buildiq MCP: promoteVersion failed', self::anything());

		$result = $this->handler->handle(args: ['appSlug' => 'demo', 'sourceVersionSlug' => 'development']);

		self::assertSame(expected: 'promote_failed', actual: $result['error']);
		self::assertStringNotContainsString(needle: 'target locked', haystack: $result['message']);
	}//end testAFailingPromotionReturnsAGenericError()

	/**
	 * Malformed slugs and an unknown strategy are refused before any lookup.
	 *
	 * @return void
	 */
	public function testRejectsMalformedArguments(): void {
		$this->objectService->expects(self::never())->method('searchObjectsBySlug');

		$noSource = $this->handler->handle(args: ['appSlug' => 'demo']);
		$badStrategy = $this->handler->handle(
			args: ['appSlug' => 'demo', 'sourceVersionSlug' => 'development', 'strategy' => 'overwrite']
		);

		self::assertSame(expected: "Invalid sourceVersionSlug ''.", actual: $noSource['message']);
		self::assertSame(expected: "Invalid strategy 'overwrite'.", actual: $badStrategy['message']);
	}//end testRejectsMalformedArguments()
}//end class
