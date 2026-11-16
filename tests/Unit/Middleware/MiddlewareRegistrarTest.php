<?php

/**
 * Unit tests for MiddlewareRegistrar: the API contract middleware is
 * registered once, app-scoped, and nothing else is registered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Middleware;

use OCA\Buildiq\Middleware\ApiContractMiddleware;
use OCA\Buildiq\Middleware\MiddlewareRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Registration of the API contract middleware.
 *
 * @covers \OCA\Buildiq\Middleware\MiddlewareRegistrar
 */
final class MiddlewareRegistrarTest extends TestCase {

	/**
	 * The API contract middleware is registered exactly once, for this app only.
	 *
	 * @return void
	 */
	public function testRegistersTheApiContractMiddlewareAppScoped(): void {
		$calls   = new \ArrayObject();
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->once())
			->method('registerMiddleware')
			->willReturnCallback(
				static function (string $class, bool $global = false) use ($calls): void {
					$calls[] = [$class, $global];
				}
			);

		(new MiddlewareRegistrar())->registerApiContractMiddleware($context);

		$this->assertSame(
			expected: [[ApiContractMiddleware::class, false]],
			actual: $calls->getArrayCopy()
		);
	}//end testRegistersTheApiContractMiddlewareAppScoped()

	/**
	 * Registration touches only middleware: no services, aliases or listeners.
	 *
	 * @return void
	 */
	public function testRegistersNothingElse(): void {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->once())->method('registerMiddleware');
		$context->expects($this->never())->method('registerService');
		$context->expects($this->never())->method('registerServiceAlias');
		$context->expects($this->never())->method('registerEventListener');

		(new MiddlewareRegistrar())->registerApiContractMiddleware($context);
	}//end testRegistersNothingElse()

	/**
	 * The registered class is a real Nextcloud middleware.
	 *
	 * @return void
	 */
	public function testTheRegisteredClassIsAMiddleware(): void {
		$registered = null;
		$context    = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerMiddleware')
			->willReturnCallback(
				static function (string $class) use (&$registered): void {
					$registered = $class;
				}
			);

		(new MiddlewareRegistrar())->registerApiContractMiddleware($context);

		$this->assertTrue(
			condition: is_subclass_of(object_or_class: $registered, class: \OCP\AppFramework\Middleware::class)
		);
	}//end testTheRegisteredClassIsAMiddleware()
}//end class
