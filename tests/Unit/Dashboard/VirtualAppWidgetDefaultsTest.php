<?php

/**
 * Unit tests for the VirtualAppWidget defaults the behaviour tests leave
 * implicit: the icon class, the order and title fallbacks of a bare panel,
 * the exact routes and parameters of the generated urls, and the item limit
 * and descriptor handed to the projector.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Dashboard;

use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Dashboard\VirtualAppWidget;
use OCA\Buildiq\Dashboard\VirtualAppWidgetContext;
use OCA\Buildiq\Kernel\Contracts\AppVisibilityResolverInterface;
use OCA\Buildiq\Kernel\Contracts\WidgetItemProjectorInterface;
use OCA\Buildiq\Kernel\Values\WidgetApplication;
use OCA\Buildiq\Kernel\Values\WidgetDescriptor;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Defaults of a dashboard widget built from a bare panel.
 *
 * @covers \OCA\Buildiq\Dashboard\VirtualAppWidget
 * @uses   \OCA\Buildiq\Dashboard\VirtualAppWidgetContext
 * @uses   \OCA\Buildiq\Kernel\Values\WidgetApplication
 * @uses   \OCA\Buildiq\Kernel\Values\WidgetDescriptor
 */
final class VirtualAppWidgetDefaultsTest extends TestCase {

	/**
	 * A descriptor with an empty panel.
	 *
	 * @return WidgetDescriptor The descriptor.
	 */
	private function bareDescriptor(): WidgetDescriptor {
		return new WidgetDescriptor(
			application: new WidgetApplication(uuid: 'app-uuid', slug: 'pet-store', name: 'Pet Store'),
			principals: ['group:*'],
			entryId: 'open-cases',
			widgetKey: 'stat',
			panel: [],
			placement: []
		);
	}//end bareDescriptor()

	/**
	 * A context around the given url generator and projector.
	 *
	 * @param IURLGenerator|null                $urlGenerator The url generator.
	 * @param WidgetItemProjectorInterface|null $projector    The projector.
	 *
	 * @return VirtualAppWidgetContext The context.
	 */
	private function context(
		?IURLGenerator $urlGenerator = null,
		?WidgetItemProjectorInterface $projector = null,
	): VirtualAppWidgetContext {
		return new VirtualAppWidgetContext(
			urlGenerator: ($urlGenerator ?? $this->createMock(originalClassName: IURLGenerator::class)),
			caller: new CallerContext(
				userSession: $this->createMock(originalClassName: IUserSession::class),
				groupManager: $this->createMock(originalClassName: IGroupManager::class),
				permissionResolver: $this->createMock(PermissionResolver::class)
			),
			visibility: $this->createMock(originalClassName: AppVisibilityResolverInterface::class),
			projector: ($projector ?? $this->createMock(originalClassName: WidgetItemProjectorInterface::class)),
			initialState: $this->createMock(originalClassName: IInitialState::class)
		);
	}//end context()

	/**
	 * The icon class is the app's fixed widget icon class.
	 *
	 * @return void
	 */
	public function testIconClassIsTheAppWidgetClass(): void {
		$widget = new VirtualAppWidget(descriptor: $this->bareDescriptor(), context: $this->context());

		$this->assertSame(expected: 'icon-buildiq-widget', actual: $widget->getIconClass());
	}//end testIconClassIsTheAppWidgetClass()

	/**
	 * A bare panel takes the entry id as title and the default order.
	 *
	 * @return void
	 */
	public function testABarePanelFallsBackToEntryIdAndDefaultOrder(): void {
		$widget = new VirtualAppWidget(descriptor: $this->bareDescriptor(), context: $this->context());

		$this->assertSame(expected: 'open-cases', actual: $widget->getTitle());
		$this->assertSame(expected: WidgetDescriptor::DEFAULT_ORDER, actual: $widget->getOrder());
		$this->assertSame(expected: 60, actual: $widget->getOrder());
	}//end testABarePanelFallsBackToEntryIdAndDefaultOrder()

	/**
	 * The icon url is the absolute light-icon route for the app's slug.
	 *
	 * @return void
	 */
	public function testIconUrlUsesTheLightIconRoute(): void {
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('buildiq.icon.iconLight', ['slug' => 'pet-store'])
			->willReturn('https://cloud.test/icon');

		$widget = new VirtualAppWidget(
			descriptor: $this->bareDescriptor(),
			context: $this->context(urlGenerator: $urlGenerator)
		);

		$this->assertSame(expected: 'https://cloud.test/icon', actual: $widget->getIconUrl());
	}//end testIconUrlUsesTheLightIconRoute()

	/**
	 * Without a declared link the url is the absolute builder route for the slug.
	 *
	 * @return void
	 */
	public function testUrlWithoutLinkUsesTheBuilderRoute(): void {
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('buildiq.dashboard.builder', ['slug' => 'pet-store'])
			->willReturn('https://cloud.test/builder');

		$widget = new VirtualAppWidget(
			descriptor: $this->bareDescriptor(),
			context: $this->context(urlGenerator: $urlGenerator)
		);

		$this->assertSame(expected: 'https://cloud.test/builder', actual: $widget->getUrl());
	}//end testUrlWithoutLinkUsesTheBuilderRoute()

	/**
	 * Without an explicit limit the projector is asked for seven items of this widget.
	 *
	 * @return void
	 */
	public function testItemsDefaultToSevenForThisWidgetsDescriptor(): void {
		$descriptor = $this->bareDescriptor();
		$expected   = new WidgetItems(items: [], emptyContentMessage: 'Nothing yet.');

		$projector = $this->createMock(originalClassName: WidgetItemProjectorInterface::class);
		$projector->expects($this->once())
			->method('project')
			->with($this->identicalTo(value: $descriptor), 7)
			->willReturn($expected);

		$widget = new VirtualAppWidget(descriptor: $descriptor, context: $this->context(projector: $projector));

		$this->assertSame(expected: $expected, actual: $widget->getItemsV2(userId: 'alice'));
	}//end testItemsDefaultToSevenForThisWidgetsDescriptor()
}//end class
