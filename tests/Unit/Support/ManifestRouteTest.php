<?php

/**
 * Unit tests for ManifestRoute.
 *
 * The bare routes here are the ones a real model produced on the live
 * instance on 2026-09-18, from a brief asking for a tool library, with the
 * tool catalogue followed exactly: every one of the four menu items it
 * proposed carried the page id as its route. The handler refused the first
 * one, which rolled the whole approved plan back.
 *
 * The scheme cases are the other half of the same rule: they must stay
 * refused, or the route-injection guard (issue #167) and the ai-copilot
 * spec's "Execution reuses the handlers, not a copy" scenario would both
 * quietly stop meaning anything.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
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

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\ManifestRoute;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestRoute.
 */
class ManifestRouteTest extends TestCase {

	/**
	 * The four bare menu routes the live plan carried become paths, and a
	 * route parameter survives the rooting.
	 *
	 * @return void
	 */
	public function testABarePageIdBecomesAPath(): void {
		self::assertSame('/overview', ManifestRoute::normalise(route: 'overview'));
		self::assertSame('/tools', ManifestRoute::normalise(route: 'tools'));
		self::assertSame('/loans', ManifestRoute::normalise(route: 'loans'));
		self::assertSame('/borrow-tool', ManifestRoute::normalise(route: 'borrow-tool'));
		self::assertSame('/tools/:id', ManifestRoute::normalise(route: 'tools/:id'));
		self::assertSame('/loans/{id}', ManifestRoute::normalise(route: 'loans/{id}'));
	}//end testABarePageIdBecomesAPath()

	/**
	 * Every rooted route is one the guard then accepts — normalising that
	 * produced a route the handler still refused would fix nothing.
	 *
	 * @return void
	 */
	public function testEveryRootedRouteIsAcceptedByTheGuard(): void {
		foreach (['overview', 'tools', 'loans', 'borrow-tool', 'tools/:id', 'loans/{id}'] as $bare) {
			self::assertTrue(
				ManifestRoute::isValid(route: ManifestRoute::normalise(route: $bare)),
				"normalised '{$bare}' should pass the route guard"
			);
		}
	}//end testEveryRootedRouteIsAcceptedByTheGuard()

	/**
	 * A route that already names a path is left exactly as it is, so a plan
	 * that was already right cannot be changed by running this over it.
	 *
	 * @return void
	 */
	public function testAnAbsoluteRouteIsUnchangedAndNormalisingIsIdempotent(): void {
		self::assertSame('/tools', ManifestRoute::normalise(route: '/tools'));
		self::assertSame('/', ManifestRoute::normalise(route: '/'));
		self::assertSame(
			'/overview',
			ManifestRoute::normalise(route: ManifestRoute::normalise(route: 'overview'))
		);
	}//end testAnAbsoluteRouteIsUnchangedAndNormalisingIsIdempotent()

	/**
	 * A value naming a scheme or a host is handed back untouched and stays
	 * refused. Prefixing a slash would turn exactly the inputs the guard
	 * exists to stop into ones it accepts.
	 *
	 * @return void
	 */
	public function testASchemeOrHostIsNeitherRootedNorAccepted(): void {
		$hostile = ['javascript:alert(1)', 'javascript:void(0)', 'https://example.org/x', '//example.org/x', 'data:text/html,x'];
		foreach ($hostile as $route) {
			self::assertSame($route, ManifestRoute::normalise(route: $route), "'{$route}' must not be rooted");
			self::assertFalse(
				ManifestRoute::isValid(route: ManifestRoute::normalise(route: $route)),
				"'{$route}' must stay refused"
			);
		}
	}//end testASchemeOrHostIsNeitherRootedNorAccepted()

	/**
	 * An empty route stays empty, so the handler's own "route is required"
	 * message is the one the caller sees.
	 *
	 * @return void
	 */
	public function testAnEmptyRouteStaysEmpty(): void {
		self::assertSame('', ManifestRoute::normalise(route: ''));
		self::assertSame('', ManifestRoute::normalise(route: '   '));
	}//end testAnEmptyRouteStaysEmpty()

	/**
	 * A route longer than the manifest allows is refused however it is spelt.
	 *
	 * @return void
	 */
	public function testAnOverlongRouteIsRefused(): void {
		self::assertFalse(ManifestRoute::isValid(route: '/' . str_repeat('a', 300)));
	}//end testAnOverlongRouteIsRefused()
}//end class
