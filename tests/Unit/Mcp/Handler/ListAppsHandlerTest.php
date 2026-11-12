<?php

/**
 * Unit tests for the listApps MCP tool.
 *
 * Covers the limit and status arguments, the sign-in check, the status filter
 * passed to OpenRegister, the per-app role filter applied before the cap, the
 * mapped app shape and the error result when OpenRegister throws.
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

use OCA\Buildiq\Mcp\Handler\ListAppsHandler;
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
 * Tests for ListAppsHandler::handle.
 *
 * @covers \OCA\Buildiq\Mcp\Handler\ListAppsHandler
 * @uses   \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class ListAppsHandlerTest extends TestCase {

	/**
	 * Mock OR object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

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
	 * Wire mocks; the caller is `alice`, no admin, no groups.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->user = $user;
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the handler with the current caller.
	 *
	 * @return ListAppsHandler
	 */
	private function handler(): ListAppsHandler {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroups')->willReturn([]);

		return new ListAppsHandler(
			userSession: $session,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->logger,
			groupManager: $groupManager,
			objectService: $this->objectService
		);
	}//end handler()

	/**
	 * An app record `alice` can (or cannot) see.
	 *
	 * @param string $slug    The app slug.
	 * @param bool   $visible Whether alice is an editor.
	 *
	 * @return array<string, mixed>
	 */
	private function app(string $slug, bool $visible = true): array {
		$editors = ['user:bob'];
		if ($visible === true) {
			$editors = ['user:alice'];
		}

		return ['uuid' => "uuid-{$slug}", 'slug' => $slug, 'permissions' => ['editors' => $editors]];
	}//end app()

	/**
	 * The apps the caller holds a role on are mapped, with their sources.
	 *
	 * @return void
	 */
	public function testListsTheAppsTheCallerHasARoleOn(): void {
		$this->objectService->expects($this->once())->method('searchObjectsBySlug')
			->with('buildiq', 'built-app', [], true, false)
			->willReturn(
				[
					[
						'uuid' => 'u1',
						'slug' => 'one',
						'name' => 'One',
						'description' => 'First',
						'status' => 'published',
						'version' => '1.0.0',
						'permissions' => ['owners' => ['user:alice']],
					],
					$this->app(slug: 'hidden', visible: false),
					['id' => 'u2', 'slug' => 'two', 'permissions' => ['editors' => ['user:alice']]],
				]
			);

		$this->assertSame(
			expected: [
				'success' => true,
				'apps' => [
					['uuid' => 'u1', 'slug' => 'one', 'name' => 'One', 'description' => 'First', 'status' => 'published', 'version' => '1.0.0'],
					['uuid' => 'u2', 'slug' => 'two', 'name' => 'two', 'description' => '', 'status' => 'draft', 'version' => ''],
				],
				'sources' => [
					['type' => 'buildiq.application', 'uuid' => 'u1', 'url' => '/apps/buildiq/builder/one', 'label' => 'One'],
					['type' => 'buildiq.application', 'uuid' => 'u2', 'url' => '/apps/buildiq/builder/two', 'label' => 'two'],
				],
			],
			actual: $this->handler()->handle([])
		);
	}//end testListsTheAppsTheCallerHasARoleOn()

	/**
	 * A status other than `any` is passed to OpenRegister as a filter.
	 *
	 * @return void
	 */
	public function testAStatusFilterIsPassedOn(): void {
		$this->objectService->expects($this->once())->method('searchObjectsBySlug')
			->with('buildiq', 'built-app', ['status' => 'archived'], true, false)
			->willReturn([]);

		$this->assertSame(expected: ['success' => true, 'apps' => [], 'sources' => []], actual: $this->handler()->handle(['statusFilter' => 'archived']));
	}//end testAStatusFilterIsPassedOn()

	/**
	 * The limit applies after the role filter, and never exceeds 20.
	 *
	 * @return void
	 */
	public function testTheCapAppliesToAuthorisedAppsOnly(): void {
		$raw = [$this->app(slug: 'h1', visible: false), $this->app(slug: 'h2', visible: false)];
		for ($i = 1; $i <= 25; $i++) {
			$raw[] = $this->app(slug: "app{$i}");
		}

		$this->objectService->method('searchObjectsBySlug')->willReturn($raw);

		$two = $this->handler()->handle(['limit' => 2]);
		$this->assertSame(expected: ['app1', 'app2'], actual: array_column($two['apps'], 'slug'));

		$fifty = $this->handler()->handle(['limit' => 50]);
		$this->assertCount(expectedCount: 20, haystack: $fifty['apps']);
		$this->assertSame(expected: 'app20', actual: $fifty['apps'][19]['slug']);
	}//end testTheCapAppliesToAuthorisedAppsOnly()

	/**
	 * A non-array search result lists nothing.
	 *
	 * @return void
	 */
	public function testACountResultListsNothing(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturn(7);

		$this->assertSame(expected: ['success' => true, 'apps' => [], 'sources' => []], actual: $this->handler()->handle([]));
	}//end testACountResultListsNothing()

	/**
	 * Out-of-range limits and unknown statuses are refused before any lookup.
	 *
	 * @return void
	 */
	public function testInvalidArgumentsAreRefused(): void {
		$this->objectService->expects($this->never())->method('searchObjectsBySlug');
		$handler = $this->handler();

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Invalid limit 0.'],
			actual: $handler->handle(['limit' => 0])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Invalid limit 51.'],
			actual: $handler->handle(['limit' => 51])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => "Invalid statusFilter 'deleted'."],
			actual: $handler->handle(['statusFilter' => 'deleted'])
		);
	}//end testInvalidArgumentsAreRefused()

	/**
	 * An anonymous caller is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->user = null;
		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'forbidden', 'message' => 'You must be signed in to list virtual apps.'],
			actual: $this->handler()->handle([])
		);
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A failing search is logged and reported without the exception text.
	 *
	 * @return void
	 */
	public function testAFailingSearchIsReportedAndLogged(): void {
		$this->objectService->method('searchObjectsBySlug')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')
			->with('Buildiq MCP: listApps failed', ['exception' => 'db down']);

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'internal_error', 'message' => 'Failed to retrieve virtual apps.'],
			actual: $this->handler()->handle([])
		);
	}//end testAFailingSearchIsReportedAndLogged()
}//end class
