<?php

/**
 * Unit tests for the device feature policy of the built app's pages.
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
 *
 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\DashboardController;
use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Service\DeviceFeatures;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The built app's pages allow the camera for the page itself only where scanning is used (REQ-BQQR-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(DashboardController::class)]
#[UsesClass(DeviceFeatures::class)]
final class DashboardControllerDeviceFeaturesTest extends TestCase {

	/**
	 * The arguments deviceFeaturesForCaller() was asked with.
	 *
	 * @var list<array{0: string, 1: ?string}>
	 */
	private array $asked = [];

	/**
	 * The controller serving a version with these device features.
	 *
	 * @param list<string> $features The served version's device features.
	 * @param bool         $resolver Whether a manifest resolver is wired.
	 *
	 * @return DashboardController
	 */
	private function controller(array $features, bool $resolver=true): DashboardController {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnMap([['_version', '', 'staging']]);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($this->createStub(IUser::class));
		$groups = $this->createStub(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);

		$manifests = null;
		if ($resolver === true) {
			$manifests = $this->createStub(ManifestResolverServiceInterface::class);
			$manifests->method('deviceFeaturesForCaller')->willReturnCallback(
				function (string $appSlug, ?string $versionSlug) use ($features): array {
					$this->asked[] = [$appSlug, $versionSlug];
					return $features;
				}
			);
		}

		return new DashboardController(
			$request,
			$this->createStub(IInitialState::class),
			$session,
			$groups,
			$this->createStub(INavigationManager::class),
			$manifests
		);
	}//end controller()

	/**
	 * The three responses that serve a built app.
	 *
	 * @param DashboardController $controller The controller.
	 *
	 * @return array<string, TemplateResponse>
	 */
	private static function responses(DashboardController $controller): array {
		return [
			'builder' => $controller->builder(slug: 'stock'),
			'builderSlash' => $controller->builderSlash(slug: 'stock'),
			'builderPath' => $controller->builderPath(slug: 'stock', path: 'assets/12'),
		];
	}//end responses()

	/**
	 * A version that scans: each response allows the camera for the page itself, and nothing else.
	 *
	 * @return void
	 */
	public function testAScanningAppAllowsTheCameraForItselfOnEachResponse(): void {
		foreach (self::responses($this->controller(features: ['camera'])) as $name => $response) {
			$policy = $response->getFeaturePolicy()->buildPolicy();
			$this->assertStringContainsString("camera 'self'", $policy, $name);
			$this->assertStringContainsString("microphone 'none'", $policy, $name);
			$this->assertStringContainsString("geolocation 'none'", $policy, $name);
		}

		$this->assertSame(array_fill(0, 3, ['stock', 'staging']), $this->asked);
	}//end testAScanningAppAllowsTheCameraForItselfOnEachResponse()

	/**
	 * An app without scanning keeps the camera closed on each response.
	 *
	 * @return void
	 */
	public function testAnAppWithoutScanningKeepsTheCameraClosed(): void {
		foreach ([$this->controller(features: []), $this->controller(features: [], resolver: false)] as $controller) {
			foreach (self::responses($controller) as $name => $response) {
				$this->assertStringContainsString("camera 'none'", $response->getFeaturePolicy()->buildPolicy(), $name);
			}
		}
	}//end testAnAppWithoutScanningKeepsTheCameraClosed()
}//end class
