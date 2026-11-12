<?php

/**
 * Unit tests for the catalogue and dispatch of BuildiqToolProvider: both
 * catalogue accessors return the same list, every catalogue entry is a
 * well-formed tool with a unique id, and the unknown-tool answer lists the
 * whole catalogue in order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Mcp;

use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Catalogue and dispatch of BuildiqToolProvider.
 *
 * @covers \OCA\Buildiq\Mcp\BuildiqToolProvider
 */
final class BuildiqToolProviderDispatchTest extends TestCase {

	/**
	 * The provider under test, with no collaborator ever reached.
	 *
	 * @return BuildiqToolProvider The provider.
	 */
	private function provider(): BuildiqToolProvider {
		return new BuildiqToolProvider(
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ObjectServiceInterface::class),
		);
	}//end provider()

	/**
	 * getTools() and getToolDescriptors() return the same catalogue.
	 *
	 * @return void
	 */
	public function testBothAccessorsReturnTheSameCatalogue(): void {
		$provider = $this->provider();

		self::assertSame($provider->getTools(), $provider->getToolDescriptors());
	}//end testBothAccessorsReturnTheSameCatalogue()

	/**
	 * Every catalogue entry has a unique buildiq-prefixed id, a description and an object input schema.
	 *
	 * @return void
	 */
	public function testEveryCatalogueEntryIsAWellFormedTool(): void {
		$tools = $this->provider()->getTools();
		$ids   = array_column($tools, 'id');

		self::assertCount(8, $tools);
		self::assertSame($ids, array_unique($ids));
		foreach ($tools as $tool) {
			self::assertMatchesRegularExpression('/^buildiq\.[a-zA-Z]+$/', $tool['id']);
			self::assertIsString($tool['description']);
			self::assertNotSame('', $tool['description']);
			self::assertSame('object', $tool['inputSchema']['type'] ?? null, $tool['id']);
		}
	}//end testEveryCatalogueEntryIsAWellFormedTool()

	/**
	 * An unknown tool is answered with every catalogue id, in catalogue order.
	 *
	 * @return void
	 */
	public function testAnUnknownToolListsTheWholeCatalogue(): void {
		$provider = $this->provider();
		$ids      = array_column($provider->getTools(), 'id');

		self::assertSame(
			[
				'isError' => true,
				'error'   => 'unknown_tool',
				'message' => "Unknown tool id 'buildiq.dropTable'. Available tools: ".implode(', ', $ids).'.',
			],
			$provider->invokeTool('buildiq.dropTable', [])
		);
	}//end testAnUnknownToolListsTheWholeCatalogue()

	/**
	 * Tool ids are matched exactly: case and surrounding space do not dispatch.
	 *
	 * @return void
	 */
	public function testToolIdsAreMatchedExactly(): void {
		$provider = $this->provider();

		self::assertSame('unknown_tool', $provider->invokeTool('BUILDIQ.LISTAPPS', [])['error']);
		self::assertSame('unknown_tool', $provider->invokeTool(' buildiq.listApps', [])['error']);
		self::assertSame('unknown_tool', $provider->invokeTool('listApps', [])['error']);
	}//end testToolIdsAreMatchedExactly()
}//end class
