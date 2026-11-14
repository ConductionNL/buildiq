<?php

/**
 * CSRF posture tests for the settings and preference write routes.
 *
 * Nextcloud's SecurityMiddleware skips the request-token check when the
 * dispatched method carries `#[NoCSRFRequired]` OR the `@NoCSRFRequired`
 * docblock annotation (`hasAnnotationOrAttribute`). Removing only the
 * attribute therefore leaves CSRF off. These tests assert that neither form
 * is present on the state-changing routes, so the middleware rejects a
 * request without a valid `requesttoken` (or `OCS-APIRequest` header).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/settings-and-observability/spec.md#requirement-state-changing-settings-endpoints-must-enforce-csrf-protection
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\PreferencesController;
use OCA\Buildiq\Controller\SettingsController;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Asserts the write routes require the CSRF token.
 *
 * @spec openspec/specs/settings-and-observability/spec.md#requirement-state-changing-settings-endpoints-must-enforce-csrf-protection
 */
class SettingsControllerCsrfTest extends TestCase {

	/**
	 * The state-changing routes that must keep the CSRF check.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function writeRoutes(): array {
		return [
			'settings#update (PUT /api/settings)'       => [SettingsController::class, 'update'],
			'settings#create (POST /api/settings)'      => [SettingsController::class, 'create'],
			'settings#load (POST /api/settings/load)'   => [SettingsController::class, 'load'],
			'preferences#setPreference (PUT)'           => [PreferencesController::class, 'setPreference'],
		];
	}//end writeRoutes()

	/**
	 * A write route carries neither the attribute nor the docblock annotation.
	 *
	 * @param class-string $class  The controller class.
	 * @param string       $method The routed method.
	 *
	 * @return void
	 */
	#[DataProvider('writeRoutes')]
	public function testWriteRouteDoesNotSkipCsrf(string $class, string $method): void {
		$reflection = new ReflectionMethod($class, $method);

		$this->assertSame(
			expected: [],
			actual: $reflection->getAttributes(NoCSRFRequired::class),
			message: "$class::$method() must not carry #[NoCSRFRequired]"
		);
		$this->assertFalse(
			condition: self::hasDocblockAnnotation(reflection: $reflection),
			message: "$class::$method() must not carry the @NoCSRFRequired docblock annotation"
		);
	}//end testWriteRouteDoesNotSkipCsrf()

	/**
	 * Control: the read-only `settings#index` GET keeps its exemption, which
	 * proves the detector above can see both forms when they are present.
	 *
	 * @return void
	 */
	public function testDetectorSeesTheExemptionOnTheReadOnlyIndex(): void {
		$reflection = new ReflectionMethod(SettingsController::class, 'index');

		$this->assertCount(expectedCount: 1, haystack: $reflection->getAttributes(NoCSRFRequired::class));
		$this->assertTrue(condition: self::hasDocblockAnnotation(reflection: $reflection));
	}//end testDetectorSeesTheExemptionOnTheReadOnlyIndex()

	/**
	 * Whether the docblock carries `@NoCSRFRequired`, matched the way
	 * Nextcloud's ControllerMethodReflector parses annotations.
	 *
	 * @param ReflectionMethod $reflection The method.
	 *
	 * @return bool
	 */
	private static function hasDocblockAnnotation(ReflectionMethod $reflection): bool {
		$doc = $reflection->getDocComment();
		if ($doc === false) {
			return false;
		}

		return preg_match('/@NoCSRFRequired\b/', $doc) === 1;
	}//end hasDocblockAnnotation()
}//end class
