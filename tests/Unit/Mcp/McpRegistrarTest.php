<?php

/**
 * Unit tests for McpRegistrar: the MCP tool provider is registered under the
 * per-app alias openregister enumerates and under the Buildiq contract, both
 * resolving to BuildiqToolProvider, and nothing else is registered.
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

use OCA\Buildiq\Kernel\Contracts\BuildiqToolProviderInterface;
use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Mcp\McpRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Registration of the MCP tool provider.
 *
 * @covers \OCA\Buildiq\Mcp\McpRegistrar
 */
final class McpRegistrarTest extends TestCase {

	/**
	 * Record every alias registration made against a mocked context.
	 *
	 * @return array{0: IRegistrationContext, 1: \ArrayObject<int, array{0: string, 1: string}>}
	 */
	private function recordingContext(): array {
		$calls   = new \ArrayObject();
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerServiceAlias')
			->willReturnCallback(
				static function (string $alias, string $target) use ($calls): void {
					$calls[] = [$alias, $target];
				}
			);

		return [$context, $calls];
	}//end recordingContext()

	/**
	 * Both aliases are registered, in order, and both point at the provider.
	 *
	 * @return void
	 */
	public function testRegistersTheMcpAliasThenTheContract(): void {
		[$context, $calls] = $this->recordingContext();

		(new McpRegistrar())->registerToolProvider($context);

		$this->assertSame(
			expected: [
				['OCA\\OpenRegister\\Mcp\\IMcpToolProvider::buildiq', BuildiqToolProvider::class],
				[BuildiqToolProviderInterface::class, BuildiqToolProvider::class],
			],
			actual: $calls->getArrayCopy()
		);
	}//end testRegistersTheMcpAliasThenTheContract()

	/**
	 * The MCP alias carries the app id suffix openregister discovers providers by.
	 *
	 * @return void
	 */
	public function testMcpAliasIsKeyedByTheAppId(): void {
		[$context, $calls] = $this->recordingContext();

		(new McpRegistrar())->registerToolProvider($context);

		$this->assertStringEndsWith(suffix: '::buildiq', string: $calls[0][0]);
		$this->assertStringStartsWith(prefix: 'OCA\\OpenRegister\\Mcp\\IMcpToolProvider', string: $calls[0][0]);
	}//end testMcpAliasIsKeyedByTheAppId()

	/**
	 * Registration touches only service aliases: no services, listeners or middleware.
	 *
	 * @return void
	 */
	public function testRegistersNothingButTheTwoAliases(): void {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->exactly(count: 2))->method('registerServiceAlias');
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerEventListener');
		$context->expects($this->never())->method('registerMiddleware');

		(new McpRegistrar())->registerToolProvider($context);
	}//end testRegistersNothingButTheTwoAliases()

	/**
	 * The registered target fulfils the contract it is aliased under.
	 *
	 * @return void
	 */
	public function testTheTargetImplementsTheContract(): void {
		[$context, $calls] = $this->recordingContext();

		(new McpRegistrar())->registerToolProvider($context);

		$this->assertTrue(condition: is_subclass_of(object_or_class: $calls[1][1], class: $calls[1][0]));
	}//end testTheTargetImplementsTheContract()
}//end class
