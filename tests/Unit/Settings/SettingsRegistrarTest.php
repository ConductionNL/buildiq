<?php

/**
 * Unit tests for SettingsRegistrar: the admin settings section and panel are
 * bound by service factories, under their own class names, with the fixed
 * section id, name, priorities and the container's collaborators.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Sections\SettingsSection;
use OCA\Buildiq\Settings\AdminSettings;
use OCA\Buildiq\Settings\SettingsRegistrar;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Registration of the admin settings section and panel.
 *
 * @covers \OCA\Buildiq\Settings\SettingsRegistrar
 * @uses   \OCA\Buildiq\Sections\SettingsSection
 * @uses   \OCA\Buildiq\Settings\AdminSettings
 */
final class SettingsRegistrarTest extends TestCase {

	/**
	 * Register against a mocked context and return the factories by service name.
	 *
	 * @return array<string, callable> The factories, in registration order.
	 */
	private function factories(): array {
		$captured = new \ArrayObject();
		$context  = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerService')
			->willReturnCallback(
				static function (string $name, callable $factory) use ($captured): void {
					$captured[$name] = $factory;
				}
			);

		(new SettingsRegistrar())->registerAdminSettings($context);

		return $captured->getArrayCopy();
	}//end factories()

	/**
	 * A container that hands out one mock per requested id and records the ids.
	 *
	 * @param array<int, string> $requested Receives every requested id.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(array &$requested): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')
			->willReturnCallback(
				function (string $id) use (&$requested): object {
					$requested[] = $id;
					return $this->createMock(originalClassName: $id);
				}
			);

		return $container;
	}//end container()

	/**
	 * The section is registered first, then the panel, under their class names.
	 *
	 * @return void
	 */
	public function testRegistersTheSectionThenThePanel(): void {
		$this->assertSame(
			expected: [SettingsSection::class, AdminSettings::class],
			actual: array_keys($this->factories())
		);
	}//end testRegistersTheSectionThenThePanel()

	/**
	 * Registration binds factories only: no aliases, listeners or middleware.
	 *
	 * @return void
	 */
	public function testRegistersNothingButTheTwoServices(): void {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->exactly(count: 2))->method('registerService');
		$context->expects($this->never())->method('registerServiceAlias');
		$context->expects($this->never())->method('registerEventListener');
		$context->expects($this->never())->method('registerMiddleware');

		(new SettingsRegistrar())->registerAdminSettings($context);
	}//end testRegistersNothingButTheTwoServices()

	/**
	 * The section factory builds the app's section with its fixed id, name and priority.
	 *
	 * @return void
	 */
	public function testSectionFactoryBuildsTheAppSection(): void {
		$requested = [];
		$section   = $this->factories()[SettingsSection::class]($this->container(requested: $requested));

		$this->assertInstanceOf(expected: SettingsSection::class, actual: $section);
		$this->assertSame(expected: 'buildiq', actual: $section->getID());
		$this->assertSame(expected: 'Buildiq', actual: $section->getName());
		$this->assertSame(expected: 75, actual: $section->getPriority());
		$this->assertSame(expected: [IURLGenerator::class], actual: $requested);
	}//end testSectionFactoryBuildsTheAppSection()

	/**
	 * The panel factory builds the panel in the app's section at priority 10.
	 *
	 * @return void
	 */
	public function testPanelFactoryBuildsTheAdminPanel(): void {
		$requested = [];
		$panel     = $this->factories()[AdminSettings::class]($this->container(requested: $requested));

		$this->assertInstanceOf(expected: AdminSettings::class, actual: $panel);
		$this->assertSame(expected: 'buildiq', actual: $panel->getSection());
		$this->assertSame(expected: 10, actual: $panel->getPriority());
		$this->assertSame(
			expected: [IAppManager::class, IInitialState::class, IAppConfig::class],
			actual: $requested
		);
	}//end testPanelFactoryBuildsTheAdminPanel()

	/**
	 * The panel lives in the section the section factory registers.
	 *
	 * @return void
	 */
	public function testThePanelLivesInTheRegisteredSection(): void {
		$requested = [];
		$factories = $this->factories();
		$container = $this->container(requested: $requested);

		$this->assertSame(
			expected: $factories[SettingsSection::class]($container)->getID(),
			actual: $factories[AdminSettings::class]($container)->getSection()
		);
	}//end testThePanelLivesInTheRegisteredSection()
}//end class
