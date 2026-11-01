<?php

/**
 * THE PIN TEST for the synthetic-service registration path.
 *
 * The whole registration design in design.md D1 rests on behaviour Nextcloud
 * documents by IMPLEMENTATION rather than by contract:
 *
 *   ServerContainer::query() routes any `OCA\…` name to that app's
 *   DIContainer::queryNoFallback(), which checks offsetExists($name) FIRST and
 *   returns the registered service BEFORE it tries to instantiate the name as
 *   a class. That is what lets a SERVICE name that is not a class resolve at
 *   all, and it is promised by no interface.
 *
 * If a Nextcloud upgrade changes it, the failure mode is widgets that quietly
 * STOP APPEARING, which looks exactly like a working system on which no user
 * has configured any widgets. Nothing throws, nothing logs, nothing 404s.
 *
 * So this test runs against the LIVE container, not a double, and fails loudly
 * on the Nextcloud version that breaks the assumption, at the moment that
 * version is first run, rather than at a user's report months later.
 *
 * Run it in the container:
 *   docker exec nextcloud php /var/www/html/custom_apps/buildiq/vendor/bin/phpunit \
 *     -c /var/www/html/custom_apps/buildiq/phpunit.xml \
 *     --filter SyntheticServiceResolutionPinTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Dashboard
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

namespace OCA\Buildiq\Tests\Unit\Dashboard;

use OCA\Buildiq\Dashboard\VirtualAppWidget;
use OCA\Buildiq\Dashboard\VirtualAppWidgetContext;
use OCA\Buildiq\Dashboard\WidgetDescriptor;
use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCA\Buildiq\Service\DashboardWidgetRegistrar;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the synthetic-service resolution path against the live container.
 */
class SyntheticServiceResolutionPinTest extends TestCase {
	/**
	 * The failure message every assertion in this file carries.
	 *
	 * @var string
	 */
	private const BROKEN =
		"THE SYNTHETIC-SERVICE RESOLUTION PATH HAS CHANGED.\n"
		. "\n"
		. "Nextcloud no longer resolves a container service registered under a name that is\n"
		. "not a real class. Every promoted Buildiq widget therefore stops being registered.\n"
		. "That failure is SILENT: the dashboard renders normally with the panels simply\n"
		. "absent, which is indistinguishable from a working system on which no user has\n"
		. "configured any widgets. No exception, no log line, no 404.\n"
		. "\n"
		. "See openspec/changes/publish-widgets-to-nc-dashboard/design.md, risk R1.\n"
		. "THE FALLBACK IS: generate one real PHP class per promoted widget into a writable\n"
		. "app directory and register those, with a cache-invalidation story on every\n"
		. 'manifest save and an autoloader entry for the generated namespace.';

	/**
	 * A stable, obviously fake Application UUID.
	 *
	 * @var string
	 */
	private const APP_UUID = '11111111-2222-3333-4444-555555555555';

	/**
	 * Skip out of container. This test is only meaningful against a booted
	 * Nextcloud, because the thing it pins IS the live container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists('\OC', false) === false || isset(\OC::$server) === false) {
			$this->markTestSkipped(
				'The synthetic-service pin needs a booted Nextcloud. Run it in the container: '
				. 'docker exec nextcloud php /var/www/html/custom_apps/buildiq/vendor/bin/phpunit '
				. '-c /var/www/html/custom_apps/buildiq/phpunit.xml '
				. '--filter SyntheticServiceResolutionPinTest'
			);
		}
	}//end setUp()

	/**
	 * A service registered on the app container under a SYNTHETIC name, which
	 * is not a class and is never autoloaded, resolves through the SERVER
	 * container by that name.
	 *
	 * This is the R1 assumption itself, asserted directly.
	 *
	 * @return void
	 */
	public function testTheServerContainerResolvesASyntheticServiceName(): void {
		$descriptor = $this->descriptor(entryId: 'pin-one');
		$serviceName = DashboardWidgetRegistrar::serviceNameFor(widgetId: $descriptor->id);

		$this->assertFalse(
			class_exists($serviceName, false),
			'The pin is only meaningful while the name is NOT a class.'
		);

		$widget = new VirtualAppWidget(descriptor: $descriptor, context: $this->context());
		$appContainer = \OC::$server->getRegisteredAppContainer('buildiq');
		$appContainer->registerService($serviceName, static fn (): VirtualAppWidget => $widget);

		try {
			$resolved = \OC::$server->get($serviceName);
		} catch (\Throwable $e) {
			// A throw here IS the broken pin, so it must read as one rather
			// than as an unrelated container error.
			$this->fail(self::BROKEN . "\n\nThe container threw: " . $e->getMessage());
		}

		$this->assertSame($widget, $resolved, self::BROKEN);
	}//end testTheServerContainerResolvesASyntheticServiceName()

	/**
	 * TWO widgets built from ONE class, registered under TWO synthetic names,
	 * both appear in IManager::getWidgets(), each under its own derived id.
	 *
	 * @return void
	 */
	public function testTwoWidgetsFromOneClassAppearUnderTheirOwnIds(): void {
		$first = $this->descriptor(entryId: 'pin-open-cases');
		$second = $this->descriptor(entryId: 'pin-recent-cases');
		$context = $this->context();

		$appContainer = \OC::$server->getRegisteredAppContainer('buildiq');
		$names = [];
		foreach ([$first, $second] as $descriptor) {
			$widget = new VirtualAppWidget(descriptor: $descriptor, context: $context);
			$name = DashboardWidgetRegistrar::serviceNameFor(widgetId: $descriptor->id);
			$appContainer->registerService($name, static fn (): VirtualAppWidget => $widget);
			$names[] = $name;
		}

		// A FRESH manager, not the shared one: the shared one has already
		// loaded its lazy panels, so appending to it would assert nothing.
		$manager = new \OC\Dashboard\Manager(
			$this->serverContainerWithEnabledApp(),
			\OC::$server->get(LoggerInterface::class)
		);
		foreach ($names as $name) {
			$manager->lazyRegisterWidget($name, 'buildiq');
		}

		$widgets = $manager->getWidgets();

		$this->assertArrayHasKey($first->id, $widgets, self::BROKEN);
		$this->assertArrayHasKey($second->id, $widgets, self::BROKEN);
		$this->assertSame($first->id, $widgets[$first->id]->getId());
		$this->assertSame($second->id, $widgets[$second->id]->getId());
		$this->assertNotSame(
			$widgets[$first->id],
			$widgets[$second->id],
			'N instances of ONE class must become N distinct widgets.'
		);
	}//end testTwoWidgetsFromOneClassAppearUnderTheirOwnIds()

	/**
	 * A widget whose isEnabled() is false never enters the widget map at all,
	 * so neither the picker nor a stored id from an earlier grant can surface
	 * it.
	 *
	 * @return void
	 */
	public function testAWidgetTheUserMayNotSeeNeverEntersTheWidgetMap(): void {
		$descriptor = $this->descriptor(entryId: 'pin-hidden', principals: ['group:nobody-is-in-this']);
		$widget = new VirtualAppWidget(descriptor: $descriptor, context: $this->context());
		$name = DashboardWidgetRegistrar::serviceNameFor(widgetId: $descriptor->id);

		\OC::$server->getRegisteredAppContainer('buildiq')
			->registerService($name, static fn (): VirtualAppWidget => $widget);

		$manager = new \OC\Dashboard\Manager(
			$this->serverContainerWithEnabledApp(),
			\OC::$server->get(LoggerInterface::class)
		);
		$manager->lazyRegisterWidget($name, 'buildiq');

		$this->assertArrayNotHasKey($descriptor->id, $manager->getWidgets());
	}//end testAWidgetTheUserMayNotSeeNeverEntersTheWidgetMap()

	/**
	 * The live server container, with IAppManager answering "buildiq is
	 * enabled for this user".
	 *
	 * The test runs on the CLI with no user session, where
	 * isEnabledForUser() would otherwise decide the answer and
	 * loadLazyPanels() would `continue` past every widget, turning the pin
	 * into a test that cannot fail. Only that one service is substituted;
	 * every other name, including the synthetic one this test exists to
	 * resolve, goes to the real container.
	 *
	 * @return ContainerInterface The wrapped server container.
	 */
	private function serverContainerWithEnabledApp(): ContainerInterface {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		return new class(\OC::$server, $appManager) implements ContainerInterface {
			/**
			 * Constructor.
			 *
			 * @param ContainerInterface $inner The real server container.
			 * @param IAppManager $appManager The substituted app manager.
			 */
			public function __construct(
				private ContainerInterface $inner,
				private IAppManager $appManager,
			) {
			}

			/**
			 * Resolve a service.
			 *
			 * @param string $id The service name.
			 *
			 * @return mixed The service.
			 */
			public function get(string $id): mixed {
				if ($id === IAppManager::class) {
					return $this->appManager;
				}

				return $this->inner->get($id);
			}

			/**
			 * Whether a service is known.
			 *
			 * @param string $id The service name.
			 *
			 * @return bool True when known.
			 */
			public function has(string $id): bool {
				return ($id === IAppManager::class || $this->inner->has($id));
			}
		};
	}//end serverContainerWithEnabledApp()

	/**
	 * A descriptor for one promoted placement.
	 *
	 * @param string $entryId The placement's own id.
	 * @param array<int,string> $principals The principals allowed to see it.
	 *
	 * @return WidgetDescriptor The descriptor.
	 */
	private function descriptor(string $entryId, array $principals = ['group:*']): WidgetDescriptor {
		return new WidgetDescriptor(
			applicationUuid: self::APP_UUID,
			applicationSlug: 'pin-fixture-app',
			applicationName: 'Pin Fixture App',
			principals: $principals,
			entryId: $entryId,
			widgetKey: 'stat',
			panel: ['title' => 'Pin fixture ' . $entryId],
			placement: ['pageRoute' => '/overview']
		);
	}//end descriptor()

	/**
	 * A widget context whose session reports a signed-in fixture user.
	 *
	 * @return VirtualAppWidgetContext The context.
	 */
	private function context(): VirtualAppWidgetContext {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pin-fixture-user');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);

		return new VirtualAppWidgetContext(
			urlGenerator: $this->createMock(IURLGenerator::class),
			userSession: $userSession,
			groupManager: $groupManager,
			visibility: new AppVisibilityResolver(),
			projector: $this->createMock(WidgetItemProjector::class),
			initialState: $this->createMock(IInitialState::class)
		);
	}//end context()
}//end class
