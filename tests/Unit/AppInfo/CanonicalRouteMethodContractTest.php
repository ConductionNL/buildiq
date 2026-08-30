<?php

/**
 * Tests for the canonical AppHost route table's method contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\AppInfo
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

namespace OCA\Buildiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The canonical AppHost route table routes a fixed set of names into THIS
 * app's controller namespace, and OpenRegister only substitutes its generic
 * controller when this app does not ship a class of that name.
 *
 * `\OCA\OpenRegister\AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * registers the DI alias `OCA\Buildiq\Controller\XController` ->
 * `OCA\OpenRegister\AppHost\Controller\GenericXController` ONLY when the leaf
 * class does not exist. So the seam has two sides, and they fail differently:
 *
 *   - Leaf does NOT ship the class  -> the alias binds and the generic serves
 *     every canonical method. Nothing is owed. (This is why the absence of
 *     HealthController / MetricsController on disk is correct and must not be
 *     "fixed" by creating them.)
 *   - Leaf DOES ship the class      -> the alias is skipped and the generic is
 *     never constructed, so the leaf owes EVERY method the canonical table
 *     routes to that controller. A missing one is not a 404: the router
 *     matches the URL, the dispatcher reflects the method, and the request
 *     dies with a 500.
 *
 * Measured 2026-08-08 on the running dev instance: Buildiq shipped its own
 * SettingsController with `index/create/load` but no `update()`, while the
 * canonical table routes `PUT /api/settings` to `settings#update`.
 *
 *     GET  /apps/buildiq/api/settings -> 200
 *     PUT  /apps/buildiq/api/settings -> 500
 *     ReflectionException: Method
 *     OCA\Buildiq\Controller\SettingsController::update() does not exist
 *     at lib/private/AppFramework/Utility/ControllerMethodReflector.php:40
 *
 * This test asserts the ITEM (each individual method), never the container
 * (the controller class merely existing).
 *
 * @spec openspec/specs/settings-and-observability/spec.md#req-obs-002
 */
class CanonicalRouteMethodContractTest extends TestCase {

	/**
	 * The canonical route names supplied by the AppHost table
	 * (`\OCA\OpenRegister\AppHost\Routes::canonicalRoutes()` plus the
	 * separately-appended SPA catch-all), keyed `controllerPrefix => [method]`.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CANONICAL_ROUTES = [
		'Dashboard' => ['page', 'catchAll'],
		'Settings' => ['index', 'create', 'update', 'load'],
		'Preferences' => ['getPreference', 'setPreference'],
		'Metrics' => ['index'],
		'Health' => ['index'],
	];

	/**
	 * Candidate locations for the OpenRegister sources, mirroring the probe
	 * order used by `tests/bootstrap-unit.php`.
	 *
	 * @var array<int, string>
	 */
	private const OPENREGISTER_LIB_CANDIDATES = [
		__DIR__ . '/../../../../openregister/lib',
		'/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib',
	];

	/**
	 * Positive control #1 — the canonical table must actually be in play.
	 *
	 * Buildiq's `appinfo/routes.php` declares NO settings routes of its own;
	 * it delegates the whole canonical set to `Routes::standard()`. If that
	 * call were ever removed, the method assertions below would still pass
	 * while nothing routed to them — a vacuous green. Read a green from the
	 * contract test only together with a green here.
	 *
	 * @return void
	 */
	public function testRoutesFileDelegatesToTheCanonicalAppHostTable(): void {
		$routesSource = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');

		$this->assertIsString($routesSource, 'appinfo/routes.php must be readable');

		$this->assertStringContainsString(
			'AppHost\Routes::standard(',
			$routesSource,
			'appinfo/routes.php no longer delegates to the AppHost canonical route '
			. 'table, so the canonical method contract asserted by this test class '
			. 'is no longer the thing that is actually routed.'
		);

		$this->assertStringNotContainsString(
			"'settings#",
			$routesSource,
			'appinfo/routes.php declares a settings route of its own; the canonical '
			. 'set is supposed to come exclusively from Routes::standard().'
		);
	}//end testRoutesFileDelegatesToTheCanonicalAppHostTable()

	/**
	 * Positive control #2 — guard the hard-coded expectation against drift.
	 *
	 * `self::CANONICAL_ROUTES` is a local transcription of OpenRegister's
	 * table. When the OpenRegister sources are resolvable, assert the
	 * transcription still matches reality, so this suite cannot keep asserting
	 * a contract the engine has since changed. When they are not resolvable
	 * (a stripped CI checkout), the test is skipped rather than silently
	 * passing on nothing.
	 *
	 * @return void
	 */
	public function testTranscribedCanonicalSettingsRoutesMatchOpenRegisterSource(): void {
		$routesFile = null;
		foreach (self::OPENREGISTER_LIB_CANDIDATES as $candidate) {
			$path = $candidate . '/AppHost/Routes.php';
			if (file_exists($path) === true) {
				$routesFile = $path;
				break;
			}
		}

		if ($routesFile === null) {
			$this->markTestSkipped(
				'OpenRegister sources are not resolvable in this checkout, so the '
				. 'transcription in self::CANONICAL_ROUTES cannot be cross-checked.'
			);
		}

		$source = file_get_contents($routesFile);
		$this->assertIsString($source, 'OpenRegister AppHost/Routes.php must be readable');

		foreach (self::CANONICAL_ROUTES['Settings'] as $method) {
			$this->assertStringContainsString(
				"'settings#" . $method . "'",
				$source,
				sprintf(
					'OpenRegister no longer ships the canonical route "settings#%s"; '
					. 'self::CANONICAL_ROUTES is stale.',
					$method
				)
			);
		}

		// The specific regression this whole file exists for: PUT /api/settings.
		$this->assertMatchesRegularExpression(
			"/'name'\s*=>\s*'settings#update'.*'verb'\s*=>\s*'PUT'/",
			$source,
			'OpenRegister no longer routes PUT /api/settings to settings#update.'
		);
	}//end testTranscribedCanonicalSettingsRoutesMatchOpenRegisterSource()

	/**
	 * A controller this app ships itself must implement every canonical
	 * method routed to it — the AppHost generic will not fill the gap.
	 *
	 * @return void
	 */
	public function testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem(): void {
		$inspected = 0;
		$missing = [];

		foreach (self::CANONICAL_ROUTES as $prefix => $methods) {
			$class = 'OCA\\Buildiq\\Controller\\' . $prefix . 'Controller';

			// The class file existing on disk is what makes the AppHost skip
			// the alias. `class_exists()` alone would also be satisfied by the
			// DI alias target in a booted container, which is precisely the
			// case this test must NOT treat as leaf-owned.
			$file = __DIR__ . '/../../../lib/Controller/' . $prefix . 'Controller.php';
			if (file_exists($file) === false) {
				continue;
			}

			$this->assertTrue(
				class_exists($class),
				sprintf('%s exists on disk but does not autoload as %s', $file, $class)
			);

			$reflection = new ReflectionClass($class);

			foreach ($methods as $method) {
				$inspected++;

				if ($reflection->hasMethod($method) === false) {
					$missing[] = $prefix . 'Controller::' . $method . '()';
					continue;
				}

				$reflected = $reflection->getMethod($method);

				$this->assertTrue(
					$reflected->isPublic(),
					sprintf('%s::%s() must be public to be dispatchable', $class, $method)
				);

				$this->assertFalse(
					$reflected->isStatic(),
					sprintf('%s::%s() must not be static to be dispatchable', $class, $method)
				);
			}
		}//end foreach

		// Positive control: an empty `$missing` list is only meaningful if
		// something was actually inspected. Zero inspections would mean the
		// file-existence probe above silently matched nothing.
		$this->assertGreaterThan(
			0,
			$inspected,
			'No leaf-owned canonical controller method was inspected — the '
			. 'lib/Controller path probe is broken, so the empty finding list '
			. 'means nothing.'
		);

		$this->assertSame(
			[],
			$missing,
			sprintf(
				'The canonical AppHost route table routes to these method(s), but '
				. 'Buildiq ships the controller itself so no generic is aliased in. '
				. "Each of these is a 500, not a 404.\n  - %s",
				implode("\n  - ", $missing)
			)
		);
	}//end testLeafOwnedControllersImplementEveryCanonicalMethodRoutedToThem()

}//end class
