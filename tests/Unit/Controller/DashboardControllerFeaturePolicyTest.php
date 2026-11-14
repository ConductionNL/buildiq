<?php

/**
 * Tests for the feature policy of a built app's page
 * (forms-signature-audio-and-location, REQ-BQSA-004).
 *
 * The controller and DeviceFeatures are the real classes; the published
 * applications come from a mocked PublishedApplicationProvider.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\DashboardController;
use OCA\Buildiq\Service\DeviceFeatures;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Controller\DashboardController
 * @uses \OCA\Buildiq\Service\DeviceFeatures
 */
class DashboardControllerFeaturePolicyTest extends TestCase {

	/**
	 * An app whose forms use a location field and no audio field allows the position and still refuses the microphone.
	 *
	 * @return void
	 */
	public function testAnAppWithoutAudioDoesNotGetTheMicrophone(): void {
		$apps = [
			['slug' => 'meldingen', 'productionVersion' => ['manifest' => ['pages' => []], 'deviceFeatures' => ['geolocation']]],
		];

		$policy = $this->controller(applications: $apps)->builder(slug: 'meldingen')->getFeaturePolicy()->buildPolicy();

		$this->assertStringContainsString("geolocation 'self';", $policy);
		$this->assertStringContainsString("microphone 'none';", $policy);
		$this->assertStringContainsString("camera 'none';", $policy);
	}//end testAnAppWithoutAudioDoesNotGetTheMicrophone()

	/**
	 * A version saved before features were stored has them derived from its manifest, on every builder route.
	 *
	 * @return void
	 */
	public function testAnOlderVersionHasItsFeaturesDerived(): void {
		$apps = [
			['slug' => 'other', 'productionVersion' => ['deviceFeatures' => ['camera']]],
			['slug' => 'inspectie', 'productionVersion' => ['manifest' => ['pages' => [['type' => 'form', 'config' => ['fields' => [['type' => 'audio']]]]]]]],
		];
		$controller = $this->controller(applications: $apps);

		foreach ([$controller->builderSlash(slug: 'inspectie'), $controller->builderPath(slug: 'inspectie', path: 'a/b')] as $response) {
			$policy = $response->getFeaturePolicy()->buildPolicy();
			$this->assertStringContainsString("microphone 'self';", $policy);
			$this->assertStringContainsString("camera 'none';", $policy);
		}
	}//end testAnOlderVersionHasItsFeaturesDerived()

	/**
	 * An app that uses no device, an unknown slug and an unreadable app list keep Nextcloud's default policy.
	 *
	 * @return void
	 */
	public function testOtherwiseTheDefaultPolicyStays(): void {
		$apps = [
			['slug' => 'plain', 'productionVersion' => ['manifest' => ['pages' => []], 'deviceFeatures' => []]],
			['slug' => 'unpublished', 'productionVersion' => 'uuid-only'],
		];
		$controller = $this->controller(applications: $apps);
		$default    = (new FeaturePolicy())->buildPolicy();
		$this->assertStringContainsString("microphone 'none';", $default);

		$this->assertSame($default, $controller->builder(slug: 'plain')->getFeaturePolicy()->buildPolicy());
		$this->assertSame($default, $controller->builder(slug: 'unpublished')->getFeaturePolicy()->buildPolicy());
		$this->assertSame($default, $controller->builder(slug: 'missing')->getFeaturePolicy()->buildPolicy());

		$broken = $this->createMock(PublishedApplicationProvider::class);
		$broken->method('getPublishedApplications')->willThrowException(new \RuntimeException('OpenRegister is down'));
		$this->assertSame($default, $this->controller(applications: [], provider: $broken)->builder(slug: 'plain')->getFeaturePolicy()->buildPolicy());

		$bare = new DashboardController(
			$this->createMock(IRequest::class),
			$this->createMock(IInitialState::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(INavigationManager::class)
		);
		$this->assertSame($default, $bare->builder(slug: 'plain')->getFeaturePolicy()->buildPolicy());
	}//end testOtherwiseTheDefaultPolicyStays()

	/**
	 * The controller over a list of published applications.
	 *
	 * @param array<int,array<string,mixed>>    $applications The published applications.
	 * @param PublishedApplicationProvider|null $provider     A provider to use instead.
	 *
	 * @return DashboardController
	 */
	private function controller(array $applications, ?PublishedApplicationProvider $provider=null): DashboardController {
		if ($provider === null) {
			$provider = $this->createMock(PublishedApplicationProvider::class);
			$provider->method('getPublishedApplications')->willReturn($applications);
		}

		return new DashboardController(
			$this->createMock(IRequest::class),
			$this->createMock(IInitialState::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(INavigationManager::class),
			$provider,
			new DeviceFeatures()
		);
	}//end controller()
}//end class
