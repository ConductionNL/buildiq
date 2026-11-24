<?php

/**
 * The health and metrics probe factories ask for OpenRegister first.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ControllerRegistrar;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Error;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The probe controllers are OpenRegister's generic implementations, so each
 * factory establishes that OpenRegister is installed before it reaches into
 * the AppHost namespace, and builds the controller when it is.
 *
 * @covers \OCA\Buildiq\Controller\ControllerRegistrar
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class ControllerRegistrarObservabilityTest extends TestCase {

	private const PROBES = [
		'OCA\\Buildiq\\Controller\\HealthController' => 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController',
		'OCA\\Buildiq\\Controller\\MetricsController' => 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController',
	];

	/**
	 * The probe factories the registrar binds.
	 *
	 * @return array<string,callable>
	 */
	private function factories(): array {
		$factories = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ControllerRegistrar())->registerObservabilityControllers($context);

		return $factories;
	}//end factories()

	/**
	 * A container whose app manager answers $installed for OpenRegister and
	 * hands out a mock for every other service.
	 *
	 * @param bool $installed Whether OpenRegister is installed
	 *
	 * @return ContainerInterface
	 */
	private function container(bool $installed): ContainerInterface {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => $app === 'openregister' && $installed
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($appManager): object {
				if ($id === IAppManager::class) {
					return $appManager;
				}

				return $this->createMock(originalClassName: $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * Both probes are bound.
	 *
	 * @return void
	 */
	public function testBothProbesAreBound(): void {
		self::assertSame(expected: array_keys(self::PROBES), actual: array_keys($this->factories()));
	}//end testBothProbesAreBound()

	/**
	 * Without OpenRegister each factory refuses with a message naming it.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterEachProbeRefusesByName(): void {
		$factories = $this->factories();
		foreach (array_keys(self::PROBES) as $name) {
			try {
				($factories[$name])($this->container(installed: false));
				self::fail(message: $name . ' was built without OpenRegister.');
			} catch (RuntimeException $exception) {
				self::assertStringContainsString(needle: 'OpenRegister', haystack: $exception->getMessage());
			}
		}
	}//end testWithoutOpenRegisterEachProbeRefusesByName()

	/**
	 * With OpenRegister each factory passes the guard and builds the generic
	 * controller. Where this run has no OpenRegister sources on its include
	 * path, reaching the missing class proves the guard let the call through.
	 *
	 * @return void
	 */
	public function testWithOpenRegisterEachProbeIsTheGenericController(): void {
		$factories = $this->factories();
		foreach (self::PROBES as $name => $class) {
			try {
				$controller = ($factories[$name])($this->container(installed: true));
				self::assertInstanceOf(expected: $class, actual: $controller);
			} catch (RuntimeException $exception) {
				self::fail(message: $name . ' refused although OpenRegister is installed: ' . $exception->getMessage());
			} catch (Error $error) {
				self::assertFalse(condition: class_exists(class: $class));
				$short = substr(string: $class, offset: (int) strrpos(haystack: $class, needle: '\\') + 1);
				self::assertStringContainsString(needle: $short, haystack: $error->getMessage());
			}
		}
	}//end testWithOpenRegisterEachProbeIsTheGenericController()
}//end class
