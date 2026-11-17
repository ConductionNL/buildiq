<?php

/**
 * Tests for deriving a manifest's device features and the page's feature
 * policy (forms-signature-audio-and-location, REQ-BQSA-004).
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

use OCA\Buildiq\Service\DeviceFeatures;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Service\DeviceFeatures
 */
class DeviceFeaturesTest extends TestCase {

	/**
	 * An audio field asks the microphone; a location field and a map showing the user ask the position.
	 *
	 * @return void
	 */
	public function testTheFieldsAndMapsDecideTheFeatures(): void {
		$features = new DeviceFeatures();

		$manifest = [
			'pages' => [
				['type' => 'form', 'config' => ['fields' => [['key' => 'handtekening', 'type' => 'signature'], ['key' => 'spraaknotitie', 'type' => 'audio']]]],
				['type' => 'form', 'config' => ['fields' => [['key' => 'plek', 'type' => 'location']]]],
				['type' => 'map', 'config' => ['showUserLocation' => true]],
			],
		];
		$this->assertSame(['geolocation', 'microphone'], $features->fromManifest(manifest: $manifest));

		$this->assertSame(['geolocation'], $features->fromManifest(manifest: ['pages' => [['type' => 'map', 'config' => ['showUserLocation' => true]]]]));
		$this->assertSame([], $features->fromManifest(manifest: ['pages' => [['type' => 'map', 'config' => ['showUserLocation' => 'yes']]]]));
		$this->assertSame([], $features->fromManifest(manifest: ['pages' => [['type' => 'list', 'config' => ['showUserLocation' => true]]]]));
		$this->assertSame([], $features->fromManifest(manifest: ['pages' => [['type' => 'form', 'config' => ['fields' => [['type' => 'signature']]]]]]));
		$this->assertSame([], $features->fromManifest(manifest: null));
		$this->assertSame([], $features->fromManifest(manifest: ['pages' => 'nope']));
		$this->assertSame([], $features->fromManifest(manifest: ['pages' => [['type' => 'form', 'config' => 'nope'], 'nope']]));
	}//end testTheFieldsAndMapsDecideTheFeatures()

	/**
	 * A stored list keeps only known features, once, in a fixed order.
	 *
	 * @return void
	 */
	public function testAStoredListIsNormalised(): void {
		$features = new DeviceFeatures();

		$this->assertSame(['camera', 'microphone'], $features->normalise(features: ['microphone', 'usb', 'camera', 'microphone']));
		$this->assertSame([], $features->normalise(features: 'microphone'));
	}//end testAStoredListIsNormalised()

	/**
	 * The policy allows the page itself exactly the features listed and refuses the rest.
	 *
	 * @return void
	 */
	public function testThePolicyAllowsOnlyTheListedFeatures(): void {
		$features = new DeviceFeatures();

		$policy = $features->policyFor(features: ['geolocation'])->buildPolicy();
		$this->assertStringContainsString("geolocation 'self';", $policy);
		$this->assertStringContainsString("microphone 'none';", $policy);
		$this->assertStringContainsString("camera 'none';", $policy);

		$policy = $features->policyFor(features: ['microphone', 'camera'])->buildPolicy();
		$this->assertStringContainsString("microphone 'self';", $policy);
		$this->assertStringContainsString("camera 'self';", $policy);
		$this->assertStringContainsString("geolocation 'none';", $policy);

		$this->assertNull($features->policyFor(features: []));
		$this->assertNull($features->policyFor(features: ['usb']));
	}//end testThePolicyAllowsOnlyTheListedFeatures()

	/**
	 * An index page that finds a record by scanning uses the camera (REQ-BQQR-004).
	 *
	 * @return void
	 */
	public function testAScanningIndexPageUsesTheCamera(): void {
		$manifest = [
			'pages' => [
				['id' => 'assets', 'type' => 'index', 'config' => ['schema' => 'asset', 'scan' => ['field' => 'tag', 'onMatch' => 'open']]],
			],
		];

		$this->assertSame(['camera'], (new DeviceFeatures())->fromManifest(manifest: $manifest));
	}//end testAScanningIndexPageUsesTheCamera()

	/**
	 * A form with a scan field, however deep, uses the camera once, next to the other devices.
	 *
	 * @return void
	 */
	public function testAScanFieldInAFormUsesTheCameraOnce(): void {
		$manifest = [
			'pages' => [
				[
					'id' => 'intake',
					'type' => 'form',
					'config' => ['fields' => [['key' => 'name', 'type' => 'string'], ['key' => 'tag', 'type' => 'scan', 'formats' => ['qr']], ['key' => 'memo', 'type' => 'audio']]],
				],
				['id' => 'move', 'type' => 'form', 'config' => ['sections' => [['fields' => [['name' => 'to', 'type' => 'scan']]]]]],
			],
		];

		$this->assertSame(['camera', 'microphone'], (new DeviceFeatures())->fromManifest(manifest: $manifest));
	}//end testAScanFieldInAFormUsesTheCameraOnce()

	/**
	 * Showing a code, an empty scan action or a scan type without a field name keeps the camera closed.
	 *
	 * @return void
	 */
	public function testShowingACodeUsesNoCamera(): void {
		$manifest = [
			'pages' => [
				['id' => 'asset', 'type' => 'detail', 'config' => ['widgets' => [['id' => 'label', 'type' => 'code', 'format' => 'qr', 'source' => 'tag']]]],
				['id' => 'assets', 'type' => 'index', 'config' => ['scan' => []]],
				['id' => 'odd', 'type' => 'form', 'config' => ['type' => 'scan']],
				['id' => 'broken', 'type' => 'index', 'config' => 'not a config'],
				'not a page',
			],
		];

		$this->assertSame([], (new DeviceFeatures())->fromManifest(manifest: $manifest));
	}//end testShowingACodeUsesNoCamera()
}//end class
