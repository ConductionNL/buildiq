<?php

/**
 * Unit tests for the argument checks of the manifest-editing MCP handlers
 * that the other handler tests leave out: an invalid app slug, a missing
 * page id, widget type or route, and an over-long widget title. Each is
 * refused as invalid_arguments before any session, role or object lookup.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Mcp\Handler;

use OCA\Buildiq\Mcp\Handler\AbstractToolHandler;
use OCA\Buildiq\Mcp\Handler\AddWidgetHandler;
use OCA\Buildiq\Mcp\Handler\UpsertMenuItemHandler;
use OCA\Buildiq\Mcp\Handler\UpsertPageHandler;
use OCA\Buildiq\Support\ManifestRoute;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the manifest handlers' argument checks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(AddWidgetHandler::class)]
#[CoversClass(UpsertPageHandler::class)]
#[CoversClass(UpsertMenuItemHandler::class)]
#[UsesClass(AbstractToolHandler::class)]
#[UsesClass(ManifestRoute::class)]
final class ManifestHandlerArgumentsTest extends TestCase {

	/**
	 * A malformed call is refused with its message, before the session, roles or objects are touched.
	 *
	 * @param class-string<AbstractToolHandler> $handlerClass The handler.
	 * @param array<string, mixed>              $args         The tool arguments.
	 * @param string                            $message      The expected message.
	 *
	 * @return void
	 */
	#[DataProvider('malformedCalls')]
	public function testAMalformedCallIsRefused(string $handlerClass, array $args, string $message): void {
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->never())->method('getUser');
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('searchObjectsBySlug');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$handler = new $handlerClass(
			userSession: $session,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			objectService: $objects
		);

		$this->assertSame(
			['isError' => true, 'error' => 'invalid_arguments', 'message' => $message],
			$handler->handle($args)
		);
	}//end testAMalformedCallIsRefused()

	/**
	 * Malformed calls per handler, and the message each gets.
	 *
	 * @return array<string, array{0: class-string<AbstractToolHandler>, 1: array<string, mixed>, 2: string}>
	 */
	public static function malformedCalls(): array {
		$widget = ['appSlug' => 'permits', 'pageId' => 'home', 'widgetType' => 'header'];
		$page = ['appSlug' => 'permits', 'pageId' => 'home', 'title' => 'Home', 'type' => 'dashboard', 'route' => '/home'];

		return [
			'widget: bad app slug' => [AddWidgetHandler::class, (['appSlug' => 'Permits!'] + $widget), "Invalid appSlug 'Permits!'."],
			'widget: no app slug' => [AddWidgetHandler::class, ['pageId' => 'home'], "Invalid appSlug ''."],
			'widget: no page' => [AddWidgetHandler::class, (['pageId' => ''] + $widget), 'pageId is required.'],
			'widget: no type' => [AddWidgetHandler::class, (['widgetType' => ''] + $widget), 'widgetType is required.'],
			'widget: long title' => [
				AddWidgetHandler::class,
				(['title' => '  ' . str_repeat('é', 81) . '  '] + $widget),
				'title must be 80 characters or fewer.',
			],
			'page: bad app slug' => [UpsertPageHandler::class, (['appSlug' => 'a'] + $page), "Invalid appSlug 'a'."],
			'page: no route' => [UpsertPageHandler::class, (['route' => ''] + $page), 'route is required.'],
			'menu: bad app slug' => [
				UpsertMenuItemHandler::class,
				['appSlug' => '-permits', 'id' => 'home', 'label' => 'Home'],
				"Invalid appSlug '-permits'.",
			],
		];
	}//end malformedCalls()

	/**
	 * A widget title of exactly 80 characters (after trimming) passes the argument checks.
	 *
	 * @return void
	 */
	public function testAnEightyCharacterTitlePassesTheArgumentChecks(): void {
		$session = $this->createMock(IUserSession::class);
		$session->expects($this->once())->method('getUser')->willReturn(null);

		$handler = new AddWidgetHandler(
			userSession: $session,
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class),
			groupManager: $this->createMock(IGroupManager::class),
			objectService: $this->createMock(ObjectServiceInterface::class)
		);

		$result = $handler->handle(['appSlug' => 'permits', 'pageId' => 'home', 'widgetType' => 'header', 'title' => ' ' . str_repeat('é', 80) . ' ']);

		// Past the argument checks, the next gate is the sign-in.
		$this->assertSame(['isError' => true, 'error' => 'forbidden', 'message' => 'You must be signed in.'], $result);
	}//end testAnEightyCharacterTitlePassesTheArgumentChecks()
}//end class
