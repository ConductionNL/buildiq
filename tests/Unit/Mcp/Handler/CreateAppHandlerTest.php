<?php

/**
 * Unit tests for the createApp MCP tool.
 *
 * Covers the argument checks (slug, name length, preset), the NC-admin gate,
 * the creation call handed to ApplicationCreationService and the error result
 * when that service throws.
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

use OCA\Buildiq\Mcp\Handler\CreateAppHandler;
use OCA\Buildiq\Service\ApplicationCreationService;
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
 * Tests for CreateAppHandler::handle.
 *
 * @covers \OCA\Buildiq\Mcp\Handler\CreateAppHandler
 * @uses   \OCA\Buildiq\Mcp\Handler\AbstractToolHandler
 */
class CreateAppHandlerTest extends TestCase {

	/**
	 * Mock creation service, handed out by the container.
	 *
	 * @var ApplicationCreationService&MockObject
	 */
	private ApplicationCreationService&MockObject $creation;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Handler under test.
	 *
	 * @var CreateAppHandler
	 */
	private CreateAppHandler $handler;

	/**
	 * Wire a signed-in caller `alice`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
		$this->groupManager = $this->createMock(originalClassName: IGroupManager::class);

		$this->creation = $this->createMock(originalClassName: ApplicationCreationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with('OCA\Buildiq\Service\ApplicationCreationService')->willReturn($this->creation);

		$this->handler = new CreateAppHandler(
			userSession: $this->session,
			container: $container,
			logger: $this->logger,
			groupManager: $this->groupManager,
			objectService: $this->createMock(originalClassName: ObjectServiceInterface::class)
		);
	}//end setUp()

	/**
	 * An admin creates an app: the service gets the arguments, the result names it.
	 *
	 * @return void
	 */
	public function testAnAdminCreatesTheApp(): void {
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(true);
		$this->creation->expects($this->once())->method('createApplication')
			->with(['slug' => 'demo-app', 'name' => 'Demo', 'description' => 'A demo', 'preset' => 'single'])
			->willReturn('uuid-1');

		$result = $this->handler->handle(['slug' => 'demo-app', 'name' => 'Demo', 'description' => 'A demo', 'preset' => 'single']);

		$this->assertSame(
			expected: [
				'success' => true,
				'created' => true,
				'app' => ['uuid' => 'uuid-1', 'slug' => 'demo-app', 'name' => 'Demo', 'preset' => 'single'],
				'sources' => [['type' => 'buildiq.application', 'uuid' => 'uuid-1', 'url' => '/apps/buildiq/builder/demo-app', 'label' => 'Demo']],
			],
			actual: $result
		);
	}//end testAnAdminCreatesTheApp()

	/**
	 * Without a preset the app is created as dev-prod with an empty description.
	 *
	 * @return void
	 */
	public function testThePresetDefaultsToDevProd(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->creation->expects($this->once())->method('createApplication')
			->with(['slug' => 'demo', 'name' => 'Demo', 'description' => '', 'preset' => 'dev-prod'])
			->willReturn('uuid-2');

		$result = $this->handler->handle(['slug' => 'demo', 'name' => 'Demo']);

		$this->assertSame(expected: 'dev-prod', actual: $result['app']['preset']);
	}//end testThePresetDefaultsToDevProd()

	/**
	 * Invalid arguments are refused before any privilege check or creation.
	 *
	 * @return void
	 */
	public function testInvalidArgumentsAreRefused(): void {
		$this->groupManager->expects($this->never())->method('isAdmin');
		$this->creation->expects($this->never())->method('createApplication');

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => "Invalid slug 'Bad_Slug'."],
			actual: $this->handler->handle(['slug' => 'Bad_Slug', 'name' => 'Demo'])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => "Invalid slug ''."],
			actual: $this->handler->handle(['name' => 'Demo'])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Name must be between 2 and 80 characters.'],
			actual: $this->handler->handle(['slug' => 'demo', 'name' => 'D'])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => 'Name must be between 2 and 80 characters.'],
			actual: $this->handler->handle(['slug' => 'demo', 'name' => str_repeat('x', 81)])
		);
		$this->assertSame(
			expected: ['isError' => true, 'error' => 'invalid_arguments', 'message' => "Invalid preset 'prod-only'."],
			actual: $this->handler->handle(['slug' => 'demo', 'name' => 'Demo', 'preset' => 'prod-only'])
		);
	}//end testInvalidArgumentsAreRefused()

	/**
	 * A name of exactly 80 characters is still accepted.
	 *
	 * @return void
	 */
	public function testANameOfEightyCharactersIsAccepted(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->creation->expects($this->once())->method('createApplication')->willReturn('uuid-3');

		$result = $this->handler->handle(['slug' => 'demo', 'name' => str_repeat('x', 80)]);

		$this->assertTrue(condition: $result['success']);
	}//end testANameOfEightyCharactersIsAccepted()

	/**
	 * A caller who is not an NC admin cannot create an app.
	 *
	 * @return void
	 */
	public function testANonAdminIsRefused(): void {
		$this->groupManager->method('isAdmin')->with('alice')->willReturn(false);
		$this->creation->expects($this->never())->method('createApplication');

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'forbidden', 'message' => 'This operation requires Nextcloud admin privileges.'],
			actual: $this->handler->handle(['slug' => 'demo', 'name' => 'Demo'])
		);
	}//end testANonAdminIsRefused()

	/**
	 * A failing creation is logged and reported without the exception text.
	 *
	 * @return void
	 */
	public function testAFailingCreationIsReportedAndLogged(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->creation->method('createApplication')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Buildiq MCP: createApp failed',
			$this->callback(callback: fn (array $ctx): bool => $ctx['slug'] === 'demo' && $ctx['exception'] === 'db down')
		);

		$this->assertSame(
			expected: ['isError' => true, 'error' => 'create_failed', 'message' => 'Failed to create virtual app. See server logs for details.'],
			actual: $this->handler->handle(['slug' => 'demo', 'name' => 'Demo'])
		);
	}//end testAFailingCreationIsReportedAndLogged()
}//end class
