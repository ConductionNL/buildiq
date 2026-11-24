<?php

/**
 * Buildiq DeviceFeatures
 *
 * The browser devices a built app uses, read from its manifest: the
 * microphone for an `audio` form field, the position for a `location` form
 * field or a map page that shows the user's position, and the camera for an
 * index page that scans or a `scan` field (pages-qr-and-barcodes REQ-BQQR-004). The page that serves
 * the app allows `'self'` for exactly those features and leaves Nextcloud's
 * default policy for every other one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\DeviceFeaturesInterface;
use OCP\AppFramework\Http\FeaturePolicy;

/**
 * Derives a manifest's device features and the feature policy they need.
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
 */
class DeviceFeatures implements DeviceFeaturesInterface {

	/**
	 * The microphone, for an audio form field.
	 */
	public const MICROPHONE = 'microphone';

	/**
	 * The device position, for a location form field or a map showing the user.
	 */
	public const GEOLOCATION = 'geolocation';

	/**
	 * The camera, for an index page that finds records by scanning or a scan form field (REQ-BQQR-004).
	 */
	public const CAMERA = 'camera';

	/**
	 * Every feature this list can hold, in policy order.
	 *
	 * @var array<int,string>
	 */
	public const ALL = [self::CAMERA, self::GEOLOCATION, self::MICROPHONE];

	/**
	 * The feature each device form field type uses.
	 *
	 * @var array<string,string>
	 */
	private const FIELD_FEATURES = ['audio' => self::MICROPHONE, 'location' => self::GEOLOCATION];

	/**
	 * The FeaturePolicy method that allows the page itself to use each feature.
	 *
	 * @var array<string,string>
	 */
	private const POLICY_METHODS = [
		self::CAMERA      => 'addAllowedCameraDomain',
		self::GEOLOCATION => 'addAllowedGeoLocationDomain',
		self::MICROPHONE  => 'addAllowedMicrophoneDomain',
	];

	/**
	 * The device features a manifest uses, sorted and distinct.
	 *
	 * @param mixed $manifest The app manifest.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	#[\Override]
	public function fromManifest(mixed $manifest): array {
		$features = [];
		foreach ($this->pagesOf(manifest: $manifest) as $page) {
			$features = array_merge($features, $this->featuresOfPage(page: $page));
		}

		return $this->normalise(features: $features);
	}//end fromManifest()

	/**
	 * A stored feature list kept to the known features, sorted and distinct.
	 *
	 * @param mixed $features The stored list.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	#[\Override]
	public function normalise(mixed $features): array {
		if (is_array($features) === false) {
			return [];
		}

		return array_values(array_intersect(self::ALL, $features));
	}//end normalise()

	/**
	 * The feature policy that allows the page itself to use exactly these features, or null for none.
	 *
	 * @param array<int,string> $features The features.
	 *
	 * @return FeaturePolicy|null Null leaves Nextcloud's default policy unchanged.
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	#[\Override]
	public function policyFor(array $features): ?FeaturePolicy {
		$features = $this->normalise(features: $features);
		if ($features === []) {
			return null;
		}

		$policy = new FeaturePolicy();
		foreach ($features as $feature) {
			$policy->{self::POLICY_METHODS[$feature]}("'self'");
		}

		return $policy;
	}//end policyFor()

	/**
	 * The features one page uses: a map showing the user's location, and its form's device fields.
	 *
	 * @param array<string,mixed> $page The page entry.
	 *
	 * @return array<int,string> Unsorted, possibly repeated.
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	private function featuresOfPage(array $page): array {
		$config = ($page['config'] ?? null);
		if (is_array($config) === false) {
			return [];
		}

		$features = [];
		if (($page['type'] ?? null) === 'map' && ($config['showUserLocation'] ?? false) === true) {
			$features[] = self::GEOLOCATION;
		}

		if ($this->scans(page: $page, config: $config) === true) {
			$features[] = self::CAMERA;
		}

		foreach ((array) ($config['fields'] ?? []) as $field) {
			$features[] = $this->featureOfField(field: $field);
		}

		return array_values(array_filter($features, 'is_string'));
	}//end featuresOfPage()

	/**
	 * Whether a page scans a code: an index page with `config.scan`, or a scan field anywhere in its config.
	 *
	 * @param array<string,mixed> $page   The page entry.
	 * @param array<mixed>        $config The page's config.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
	 */
	private function scans(array $page, array $config): bool {
		if (($page['type'] ?? '') === 'index' && is_array($config['scan'] ?? null) === true && $config['scan'] !== []) {
			return true;
		}

		return $this->hasScanField(node: $config);
	}//end scans()

	/**
	 * Whether a config node holds a field of type `scan`, at any depth.
	 *
	 * @param array<mixed> $node The config node.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/pages-qr-and-barcodes/tasks.md#task-T05
	 */
	private function hasScanField(array $node): bool {
		if (($node['type'] ?? null) === 'scan' && (array_key_exists('key', $node) === true || array_key_exists('name', $node) === true)) {
			return true;
		}

		foreach ($node as $value) {
			if (is_array($value) === true && $this->hasScanField(node: $value) === true) {
				return true;
			}
		}

		return false;
	}//end hasScanField()

	/**
	 * The feature one form field uses, or null for none.
	 *
	 * @param mixed $field The field entry.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	private function featureOfField(mixed $field): ?string {
		if (is_array($field) === false || is_string($field['type'] ?? null) === false) {
			return null;
		}

		return (self::FIELD_FEATURES[$field['type']] ?? null);
	}//end featureOfField()

	/**
	 * The page entries of a manifest.
	 *
	 * @param mixed $manifest The manifest.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pagesOf(mixed $manifest): array {
		if (is_array($manifest) === false || is_array($manifest['pages'] ?? null) === false) {
			return [];
		}

		return array_values(array_filter($manifest['pages'], 'is_array'));
	}//end pagesOf()
}//end class
