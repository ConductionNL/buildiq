<?php

/**
 * Every routed controller method sits behind the Nextcloud login and keeps
 * the CSRF check on state-changing verbs.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Platform
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/platform-properties/spec.md (REQ-PLAT-003, REQ-PLAT-004, REQ-PLAT-012)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reflects every controller method routed in appinfo/routes.php.
 */
final class RouteProtectionTest extends TestCase {
	/**
	 * The methods that may open without a Nextcloud session, each with the
	 * reason. Anything else carrying #[PublicPage] fails this test.
	 */
	private const PUBLIC_ALLOWED = [
		'OCA\Buildiq\Controller\OpenApiController::show' => 'the API description itself, no records; an anonymous rate limit guards it (api-contract)',
		'OCA\Buildiq\Controller\GeneratedDocumentController::download' => 'a random token in the link is the authorisation',
	];

	/**
	 * Every routed method, as [verb, controller class, method].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	private function routed(): array {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$out = [];
		foreach (['routes', 'ocs'] as $kind) {
			foreach (($routes[$kind] ?? []) as $route) {
				[$controller, $method] = explode('#', (string)$route['name']);
				$class = 'OCA\Buildiq\Controller\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $controller))) . 'Controller';
				$verb = strtoupper((string)($route['verb'] ?? 'GET'));
				$out[$verb . ' ' . $class . '::' . $method] = [$verb, $class, $method];
			}
		}

		return $out;
	}//end routed()

	/**
	 * Whether a method carries an attribute or the docblock tag of the same name.
	 *
	 * @param ReflectionMethod $method The method.
	 * @param string           $name   The short attribute name.
	 *
	 * @return bool
	 */
	private static function carries(ReflectionMethod $method, string $name): bool {
		foreach ($method->getAttributes() as $attribute) {
			if (str_ends_with($attribute->getName(), '\\' . $name) === true) {
				return true;
			}
		}

		return str_contains((string)$method->getDocComment(), '@' . $name) === true;
	}//end carries()

	/**
	 * Every routed method exists, so the reflection below sees them all.
	 *
	 * @return void
	 */
	public function testEveryRoutedMethodExists(): void {
		$routed = $this->routed();
		self::assertNotEmpty($routed);
		foreach ($routed as $key => [$verb, $class, $method]) {
			self::assertTrue(method_exists($class, $method), $key . ' is routed but does not exist');
		}
	}//end testEveryRoutedMethodExists()

	/**
	 * No routed method opens without a session, except the allow-listed ones
	 * (REQ-PLAT-003, REQ-PLAT-012).
	 *
	 * @return void
	 */
	public function testOnlyAllowListedMethodsArePublic(): void {
		$public = [];
		foreach ($this->routed() as [$verb, $class, $method]) {
			if (self::carries(new ReflectionMethod($class, $method), 'PublicPage') === true) {
				$public[$class . '::' . $method] = true;
			}
		}

		$allowed = array_keys(self::PUBLIC_ALLOWED);
		sort($allowed);
		$found = array_keys($public);
		sort($found);
		self::assertSame($allowed, $found, 'A #[PublicPage] method is not on the allow-list, or an allow-listed one is gone.');
	}//end testOnlyAllowListedMethodsArePublic()

	/**
	 * The CSRF check is switched off only on GET routes (REQ-PLAT-004).
	 *
	 * @return void
	 */
	public function testNoCsrfRequiredOnlyOnGet(): void {
		$offending = [];
		foreach ($this->routed() as $key => [$verb, $class, $method]) {
			if ($verb !== 'GET' && self::carries(new ReflectionMethod($class, $method), 'NoCSRFRequired') === true) {
				$offending[] = $key;
			}
		}

		self::assertSame([], $offending);
	}//end testNoCsrfRequiredOnlyOnGet()
}//end class
