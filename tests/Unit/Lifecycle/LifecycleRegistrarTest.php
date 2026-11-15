<?php

/**
 * Unit tests for LifecycleRegistrar: the owner guard is registered as a
 * container service under its own FQCN, and the factory builds it from the
 * four collaborators it resolves from the container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Lifecycle;

use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\Buildiq\Lifecycle\LifecycleRegistrar;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registration of the per-Application lifecycle owner guard.
 *
 * @covers \OCA\Buildiq\Lifecycle\LifecycleRegistrar
 * @uses   \OCA\Buildiq\Lifecycle\ApplicationVersionOwnerGuard
 */
final class LifecycleRegistrarTest extends TestCase {

	/**
	 * Register against a mocked context and return what was registered.
	 *
	 * @return array{0: string, 1: callable} The service name and its factory.
	 */
	private function register(): array {
		$captured = new \ArrayObject();
		$context  = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->once())
			->method('registerService')
			->willReturnCallback(
				static function (string $name, callable $factory) use ($captured): void {
					$captured['name']    = $name;
					$captured['factory'] = $factory;
				}
			);
		$context->expects($this->never())->method('registerServiceAlias');

		(new LifecycleRegistrar())->registerOwnerGuard($context);

		return [$captured['name'], $captured['factory']];
	}//end register()

	/**
	 * The guard is registered under its FQCN, the key the lifecycle `requires` tag uses.
	 *
	 * @return void
	 */
	public function testRegistersTheGuardUnderItsClassName(): void {
		[$name] = $this->register();

		$this->assertSame(expected: ApplicationVersionOwnerGuard::class, actual: $name);
	}//end testRegistersTheGuardUnderItsClassName()

	/**
	 * The factory resolves exactly the guard's four collaborators and builds the guard.
	 *
	 * @return void
	 */
	public function testFactoryBuildsTheGuardFromTheContainer(): void {
		[, $factory] = $this->register();

		$services = [
			ObjectServiceInterface::class      => $this->createMock(originalClassName: ObjectServiceInterface::class),
			PermissionResolverInterface::class => $this->createMock(originalClassName: PermissionResolverInterface::class),
			IUserManager::class                => $this->createMock(originalClassName: IUserManager::class),
			LoggerInterface::class             => $this->createMock(originalClassName: LoggerInterface::class),
		];

		$requested = [];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(
				static function (string $id) use ($services, &$requested): object {
					$requested[] = $id;
					return $services[$id];
				}
			);

		$guard = $factory($container);

		$this->assertInstanceOf(expected: ApplicationVersionOwnerGuard::class, actual: $guard);
		$this->assertSame(
			expected: [
				ObjectServiceInterface::class,
				PermissionResolverInterface::class,
				IUserManager::class,
				LoggerInterface::class,
			],
			actual: $requested
		);
	}//end testFactoryBuildsTheGuardFromTheContainer()

	/**
	 * Each factory call builds a fresh guard rather than sharing one instance.
	 *
	 * @return void
	 */
	public function testFactoryBuildsANewGuardPerCall(): void {
		[, $factory] = $this->register();

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(
				fn (string $id): object => $this->createMock(originalClassName: $id)
			);

		$this->assertNotSame(expected: $factory($container), actual: $factory($container));
	}//end testFactoryBuildsANewGuardPerCall()
}//end class
