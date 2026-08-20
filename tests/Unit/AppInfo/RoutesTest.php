<?php

/**
 * Tests for appinfo/routes.php — the virtual-app builder route matrix (#100).
 *
 * Verifies BOTH structural ordering (route-reachability convention:
 * more-specific routes must precede the generic catch-all-style routes they
 * would otherwise be shadowed by) AND actual first-match-wins resolution for
 * the documented matrix: app root, bare app sub-path, and the reserved
 * OpenBuild designer surfaces (pages / schemas / schemas/{id} / walkthrough).
 *
 * The route array is loaded by literally `require`-ing appinfo/routes.php —
 * in the unit-test harness `\OCA\OpenRegister\AppHost\Routes::standard()` is
 * a stub (tests/stubs/openregister-stubs.php) that returns the `$extra` array
 * unmodified (no canonical routes prepended, no SPA catch-all appended), so
 * the result is exactly OpenBuild's own domain route list in declaration
 * order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\AppInfo
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

namespace OCA\OpenBuild\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Route matrix + ordering tests for appinfo/routes.php.
 */
class RoutesTest extends TestCase {
	/**
	 * The route list returned by requiring appinfo/routes.php.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $routes;

	/**
	 * Load appinfo/routes.php once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$result = require __DIR__ . '/../../../appinfo/routes.php';
		$this->routes = $result['routes'];
	}//end setUp()

	/**
	 * Find the declaration index of a route by name.
	 *
	 * @param string $name Route name (e.g. 'dashboard#builder').
	 *
	 * @return int
	 */
	private function indexOf(string $name): int {
		foreach ($this->routes as $i => $route) {
			if ($route['name'] === $name) {
				return $i;
			}
		}

		$this->fail(sprintf('Route "%s" not found in appinfo/routes.php', $name));
	}//end indexOf()

	/**
	 * Compile one NC/Symfony-style route entry (`url` + `requirements`) into a
	 * PCRE anchored on the full path, mimicking NC's underlying Symfony route
	 * matching (placeholders substituted with their requirement regex, or the
	 * Symfony default `[^/]+` when unconstrained).
	 *
	 * @param array<string, mixed> $route One routes.php entry.
	 *
	 * @return string A `#^...$#` anchored PCRE.
	 */
	private function compile(array $route): string {
		$requirements = $route['requirements'] ?? [];
		$pattern = preg_replace_callback(
			'/\{(\w+)\}/',
			static function (array $m) use ($requirements): string {
				$requirement = $requirements[$m[1]] ?? '[^/]+';
				return '(?P<' . $m[1] . '>' . $requirement . ')';
			},
			(string)$route['url']
		);

		return '#^' . $pattern . '$#';
	}//end compile()

	/**
	 * Resolve a GET path against the route list using first-match-wins
	 * semantics (NC/Symfony's router tries routes in declaration order and
	 * stops at the first match).
	 *
	 * @param string $path The request path (e.g. '/builder/spectr/pages').
	 *
	 * @return string|null The matched route name, or null.
	 */
	private function resolve(string $path): ?string {
		foreach ($this->routes as $route) {
			if (($route['verb'] ?? 'GET') !== 'GET') {
				continue;
			}

			if (preg_match($this->compile($route), $path) === 1) {
				return $route['name'];
			}
		}

		return null;
	}//end resolve()

	// -------------------------------------------------------------------------
	// Structural ordering — more-specific routes precede generic ones.
	// -------------------------------------------------------------------------

	/**
	 * The builder route family must be declared in specific-to-generic order:
	 * bare slug, trailing slash, reserved designer paths, then the generic
	 * deep-link fallback — otherwise the generic route would shadow the
	 * designer surfaces (order-sensitive first-match routing).
	 *
	 * @return void
	 */
	public function testBuilderRouteFamilyIsOrderedSpecificFirst(): void {
		$builder = $this->indexOf('dashboard#builder');
		$builderSlash = $this->indexOf('dashboard#builderSlash');
		$builderDesigner = $this->indexOf('dashboard#builderDesigner');
		$builderPath = $this->indexOf('dashboard#builderPath');

		$this->assertLessThan($builderDesigner, $builder);
		$this->assertLessThan($builderDesigner, $builderSlash);
		$this->assertLessThan($builderPath, $builderDesigner, 'reserved designer routes must precede the generic deep-link route');
	}//end testBuilderRouteFamilyIsOrderedSpecificFirst()

	/**
	 * The generic deep-link route's `path` requirement must allow slashes
	 * (so nested app pages like /tenders/{id} deep-link correctly) — the
	 * same '.+'-style trick already used by the SPA catch-all.
	 *
	 * @return void
	 */
	public function testBuilderPathRequirementAllowsSlashes(): void {
		$route = $this->routes[$this->indexOf('dashboard#builderPath')];

		$this->assertSame('/builder/{slug}/{path}', $route['url']);
		$this->assertMatchesRegularExpression('/^\.[*+]$/', $route['requirements']['path']);
	}//end testBuilderPathRequirementAllowsSlashes()

	/**
	 * The reserved designer route must enumerate exactly the four known
	 * OpenBuild designer surfaces (src/manifest.json).
	 *
	 * @return void
	 */
	public function testBuilderDesignerRequirementCoversKnownDesignerSurfaces(): void {
		$route = $this->routes[$this->indexOf('dashboard#builderDesigner')];
		$requirement = $route['requirements']['designerPath'];

		foreach (['pages', 'schemas', 'schemas/abc-123', 'walkthrough'] as $sample) {
			$this->assertSame(
				1,
				preg_match('#^(?:' . $requirement . ')$#', $sample),
				sprintf('designerPath requirement must match "%s"', $sample)
			);
		}

		// Must NOT swallow an arbitrary app-defined page.
		$this->assertSame(0, preg_match('#^(?:' . $requirement . ')$#', 'tenders'));
	}//end testBuilderDesignerRequirementCoversKnownDesignerSurfaces()

	// -------------------------------------------------------------------------
	// Resolution matrix — the exact scenarios from issue #100.
	// -------------------------------------------------------------------------

	/**
	 * The full resolution matrix: app root, app sub-path (the #100 bug),
	 * designer pages/schemas/schemas-detail/walkthrough, and a deeply nested
	 * app-defined sub-path.
	 *
	 * @return void
	 */
	public function testResolutionMatrix(): void {
		$cases = [
			'/builder/spectr' => 'dashboard#builder',
			'/builder/spectr/' => 'dashboard#builderSlash',
			'/builder/spectr/pages' => 'dashboard#builderDesigner',
			'/builder/spectr/schemas' => 'dashboard#builderDesigner',
			'/builder/spectr/schemas/abc-123' => 'dashboard#builderDesigner',
			'/builder/spectr/walkthrough' => 'dashboard#builderDesigner',
			'/builder/spectr/tenders' => 'dashboard#builderPath',
			'/builder/spectr/tenders/123' => 'dashboard#builderPath',
			'/builder/spectr/reports/2026/q1' => 'dashboard#builderPath',
		];

		foreach ($cases as $path => $expectedName) {
			$this->assertSame($expectedName, $this->resolve($path), sprintf('GET %s should resolve to %s', $path, $expectedName));
		}
	}//end testResolutionMatrix()

	// -------------------------------------------------------------------------
	// Durability guard — designer regex vs the frontend's source of truth.
	// -------------------------------------------------------------------------

	/**
	 * Extract the designer sub-routes ('/builder/:slug/<sub>') declared in the
	 * frontend SPA's single source of truth, src/manifest.json — the runtime
	 * BuilderHost wildcard ('/builder/:slug/:pathMatch(.*)') is excluded, since
	 * it is the standalone runtime, not a designer surface. Each simple :param
	 * segment is substituted with a concrete sample so the result can be
	 * resolved against the backend route matrix.
	 *
	 * @return array<int,string> Concrete designer sub-paths, e.g. 'schemas/sample-id'.
	 */
	private function manifestDesignerSubRoutes(): array {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$subRoutes = [];
		foreach (($manifest['pages'] ?? []) as $page) {
			if (preg_match('#^/builder/:slug/(.+)$#', (string)($page['route'] ?? ''), $m) !== 1) {
				continue;
			}

			// Skip the runtime host's catch-all wildcard (e.g. ':pathMatch(.*)?')
			// — it is served by the generic builderPath route by design.
			if (str_contains($m[1], '(') === true) {
				continue;
			}

			// Substitute simple :param segments with a concrete sample value.
			$subRoutes[] = preg_replace('#:[A-Za-z0-9_]+#', 'sample-id', $m[1]);
		}

		return $subRoutes;
	}//end manifestDesignerSubRoutes()

	/**
	 * DURABILITY GUARD (route maintenance-trap): every designer surface declared
	 * under /builder/:slug/ in src/manifest.json MUST resolve to
	 * dashboard#builderDesigner (the OpenBuild SPA shell), NOT fall through to
	 * the standalone-runtime builderPath route. Because the expected list is
	 * DERIVED from the manifest rather than hardcoded, adding a designer page
	 * there without extending builderDesigner's `designerPath` requirement in
	 * appinfo/routes.php fails HERE — instead of silently rendering the runtime
	 * at that URL until a user reports it.
	 *
	 * @return void
	 */
	public function testManifestDesignerRoutesAllResolveToTheDesigner(): void {
		$subRoutes = $this->manifestDesignerSubRoutes();
		$this->assertNotEmpty($subRoutes, 'expected at least one /builder/:slug/* designer page in src/manifest.json');

		foreach ($subRoutes as $sub) {
			$path = '/builder/spectr/' . $sub;
			$this->assertSame(
				'dashboard#builderDesigner',
				$this->resolve($path),
				sprintf(
					'Designer route "%s" (from src/manifest.json) must resolve to the designer shell, not the '
					. 'standalone runtime — add it to builderDesigner\'s designerPath requirement in appinfo/routes.php.',
					$path
				)
			);
		}
	}//end testManifestDesignerRoutesAllResolveToTheDesigner()
}//end class
