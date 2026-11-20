<?php

/**
 * Tests the concrete DashboardController factory of ControllerRegistrar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ControllerRegistrar;
use OCA\Buildiq\Controller\DashboardController;
use OCA\Buildiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * The factory must hand the controller every collaborator it reads in
 * production; a missing named argument silently falls back to null.
 *
 * @covers \OCA\Buildiq\Controller\ControllerRegistrar
 * @uses   \OCA\Buildiq\Controller\DashboardController
 * @uses   \OCA\Buildiq\Service\Icon\SvgRasteriser
 */
class ControllerRegistrarDashboardTest extends TestCase {
	/**
	 * The registered DashboardController factory passes the settings service,
	 * so the offline-apps initial state reflects the instance setting.
	 *
	 * @return void
	 */
	public function testDashboardFactoryPassesTheSettingsService(): void {
		$factories = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ControllerRegistrar())->registerConcreteControllers($context);
		$this->assertArrayHasKey(key: DashboardController::class, array: $factories);

		$services = [];
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$services): object {
				$services[$id] ??= $this->createMock(originalClassName: $id);

				return $services[$id];
			}
		);

		$controller = ($factories[DashboardController::class])($container);
		$this->assertInstanceOf(expected: DashboardController::class, actual: $controller);

		$settings = new ReflectionProperty(DashboardController::class, 'settings');
		$this->assertArrayHasKey(key: SettingsServiceInterface::class, array: $services);
		$this->assertSame(expected: $services[SettingsServiceInterface::class], actual: $settings->getValue(object: $controller));
	}//end testDashboardFactoryPassesTheSettingsService()
}//end class
