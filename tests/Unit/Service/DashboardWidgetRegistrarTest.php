<?php

/**
 * Unit tests for DashboardWidgetRegistrar.
 *
 * Covers descriptor collection from a fixture manifest, one registration per
 * promoted placement, the never-throwing boot guard, and the per-request query
 * accounting that keeps navigation and widgets on ONE OpenRegister read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
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

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Dashboard\VirtualAppWidget;
use OCA\Buildiq\Dashboard\VirtualAppWidgetContext;
use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCA\Buildiq\Service\AppNavigationService;
use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCA\Buildiq\Service\DashboardWidgetRegistrar;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\Buildiq\Tests\Unit\Dashboard\WidgetDescriptorTest;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IManager;
use OCP\IAppConfig;
use OCP\IContainer;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see DashboardWidgetRegistrar}.
 */
class DashboardWidgetRegistrarTest extends TestCase {
	/**
	 * Mock object service.
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
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * One descriptor per promoted entry, none for an unpromoted one, and the
	 * panel fields come from ncDashboard while the placement fields do not.
	 *
	 * @return void
	 */
	public function testCollectsOneDescriptorPerPromotedEntry(): void {
		$descriptors = $this->registrar()->collectDescriptors(
			applications: [WidgetDescriptorTest::fixtureApplication(slug: 'pet-store', name: 'Pet Store')]
		);

		$this->assertCount(2, $descriptors, 'The unpromoted third placement must not appear.');

		$byEntry = [];
		foreach ($descriptors as $descriptor) {
			$byEntry[$descriptor->entryId] = $descriptor;
		}

		$this->assertSame(['open-cases', 'recent-cases'], array_keys($byEntry));
		$this->assertArrayNotHasKey('internal-only', $byEntry);

		// Panel fields from ncDashboard.
		$this->assertSame('Open cases', $byEntry['open-cases']->title);
		$this->assertSame(12, $byEntry['open-cases']->order);
		// Declared nothing, so the default order applies.
		$this->assertSame(WidgetDescriptor::DEFAULT_ORDER, $byEntry['recent-cases']->order);
		// Placement fields from the entry, not from ncDashboard.
		$this->assertSame('object-table', $byEntry['recent-cases']->widgetKey);
		$this->assertSame('/overview', $byEntry['recent-cases']->pageRoute);
		$this->assertSame(
			['register' => 'r', 'schema' => 's'],
			$byEntry['recent-cases']->dataSource
		);
	}//end testCollectsOneDescriptorPerPromotedEntry()

	/**
	 * A promoted placement carrying no id is skipped with a warning, rather
	 * than given an identity derived from its position in the array.
	 *
	 * @return void
	 */
	public function testAPromotedPlacementWithoutAnIdIsSkipped(): void {
		$application = WidgetDescriptorTest::fixtureApplication(slug: 'pet-store', name: 'Pet Store');
		$application['productionVersion']['manifest']['pages'][0]['widgets'] = [
			['widgetKey' => 'stat', 'slot' => 'body', 'ncDashboard' => ['title' => 'Nameless']],
		];

		$this->logger->expects($this->once())->method('warning');

		$descriptors = $this->registrar()->collectDescriptors(applications: [$application]);
		$this->assertSame([], $descriptors);
	}//end testAPromotedPlacementWithoutAnIdIsSkipped()

	/**
	 * An Application whose production pointer is still a bare UUID falls back
	 * to its own manifest, the same order ManifestResolverService applies.
	 *
	 * @return void
	 */
	public function testFallsBackToTheApplicationManifestWhenTheVersionIsNotEmbedded(): void {
		$application = WidgetDescriptorTest::fixtureApplication(slug: 'pet-store', name: 'Pet Store');
		$application['manifest'] = $application['productionVersion']['manifest'];
		$application['productionVersion'] = '99999999-8888-7777-6666-555555555555';

		$descriptors = $this->registrar()->collectDescriptors(applications: [$application]);
		$this->assertCount(2, $descriptors);
	}//end testFallsBackToTheApplicationManifestWhenTheVersionIsNotEmbedded()

	/**
	 * One container service and one lazyRegisterWidget() call per promoted
	 * placement, each under its own synthetic name, and none for an
	 * unpromoted one.
	 *
	 * @return void
	 */
	public function testRegistersOneWidgetPerPromotedPlacement(): void {
		$this->objectService->method('findAll')->willReturn(
			[WidgetDescriptorTest::fixtureApplication(slug: 'pet-store', name: 'Pet Store')]
		);

		$services = [];
		$container = $this->createMock(IContainer::class);
		$container->method('registerService')
			->willReturnCallback(function (string $name, $factory) use (&$services): void {
				$services[$name] = $factory;
			});

		$lazy = [];
		$dashboard = $this->createMock(IManager::class);
		$dashboard->method('lazyRegisterWidget')
			->willReturnCallback(function (string $class, string $appId) use (&$lazy): void {
				$lazy[] = [$class, $appId];
			});

		$count = $this->registrar()->registerWidgets(
			container: $container,
			dashboardManager: $dashboard,
			context: $this->widgetContext()
		);

		$this->assertSame(2, $count);
		$this->assertCount(2, $lazy);
		$this->assertSame(
			array_keys($services),
			array_column($lazy, 0),
			'Every registered service name must be the one handed to lazyRegisterWidget().'
		);

		foreach ($lazy as [$name, $appId]) {
			$this->assertSame('buildiq', $appId);
			$this->assertStringStartsWith(DashboardWidgetRegistrar::SERVICE_PREFIX, $name);
			$this->assertFalse(
				class_exists($name, false),
				'The synthetic name must be a SERVICE name. No class is generated and none is faked.'
			);
		}

		// Each factory builds a real VirtualAppWidget with its own id.
		$ids = [];
		foreach ($services as $factory) {
			$widget = $factory();
			$this->assertInstanceOf(VirtualAppWidget::class, $widget);
			$ids[] = $widget->getId();
		}

		$this->assertSame($ids, array_unique($ids), 'Two widgets from one class must carry two ids.');
	}//end testRegistersOneWidgetPerPromotedPlacement()

	/**
	 * A failing object service completes, logs a warning and registers nothing,
	 * so an instance without OpenRegister still renders its other widgets.
	 *
	 * @return void
	 */
	public function testAFailingObjectServiceRegistersNothingAndLogsAWarning(): void {
		$this->objectService->method('findAll')
			->willThrowException(new \RuntimeException('OpenRegister is not installed'));

		$this->logger->expects($this->once())->method('warning');

		$container = $this->createMock(IContainer::class);
		$container->expects($this->never())->method('registerService');
		$dashboard = $this->createMock(IManager::class);
		$dashboard->expects($this->never())->method('lazyRegisterWidget');

		$count = $this->registrar()->registerWidgets(
			container: $container,
			dashboardManager: $dashboard,
			context: $this->widgetContext()
		);

		$this->assertSame(0, $count);
	}//end testAFailingObjectServiceRegistersNothingAndLogsAWarning()

	/**
	 * THE QUERY ACCOUNTING. One boot registers the navigation entries AND the
	 * dashboard widgets, and the published Applications are read exactly once.
	 *
	 * @return void
	 */
	public function testOneBootReadsThePublishedApplicationsExactlyOnce(): void {
		$reads = 0;
		$this->objectService
			->method('findAll')
			->willReturnCallback(function () use (&$reads): array {
				$reads++;
				return [WidgetDescriptorTest::fixtureApplication(slug: 'pet-store', name: 'Pet Store')];
			});

		// The shared instance, exactly as Application::register binds it.
		$provider = new PublishedApplicationProvider(objectService: $this->objectService);
		$visibility = new AppVisibilityResolver();

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/buildiq/builder/pet-store');
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('/icon/pet-store.svg');

		$navigation = new AppNavigationService(
			objectService: $this->objectService,
			urlGenerator: $urlGenerator,
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->logger,
			applicationProvider: $provider,
			visibilityResolver: $visibility
		);

		$registrar = new DashboardWidgetRegistrar(
			applications: $provider,
			visibility: $visibility,
			logger: $this->logger
		);

		// One boot: navigation entries, then dashboard widgets.
		$navigation->registerNavEntries($this->createMock(INavigationManager::class));
		$registered = $registrar->registerWidgets(
			container: $this->createMock(IContainer::class),
			dashboardManager: $this->createMock(IManager::class),
			context: $this->widgetContext()
		);

		$this->assertSame(
			1,
			$reads,
			'Registering dashboard widgets must add no second OpenRegister read to a boot '
			. 'that already reads the same records for the app menu.'
		);
		$this->assertSame(
			2,
			$registered,
			'The widgets must still be registered off the one shared read.'
		);
	}//end testOneBootReadsThePublishedApplicationsExactlyOnce()

	/**
	 * Build the registrar under test.
	 *
	 * @return DashboardWidgetRegistrar The registrar.
	 */
	private function registrar(): DashboardWidgetRegistrar {
		return new DashboardWidgetRegistrar(
			applications: new PublishedApplicationProvider(objectService: $this->objectService),
			visibility: new AppVisibilityResolver(),
			logger: $this->logger
		);
	}//end registrar()

	/**
	 * A widget context wired from mocks.
	 *
	 * @return VirtualAppWidgetContext The context.
	 */
	private function widgetContext(): VirtualAppWidgetContext {
		return new VirtualAppWidgetContext(
			urlGenerator: $this->createMock(IURLGenerator::class),
			userSession: $this->createMock(IUserSession::class),
			groupManager: $this->createMock(IGroupManager::class),
			visibility: new AppVisibilityResolver(),
			projector: $this->createMock(WidgetItemProjector::class),
			initialState: $this->createMock(IInitialState::class)
		);
	}//end widgetContext()
}//end class
