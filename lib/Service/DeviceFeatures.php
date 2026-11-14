<?php

/**
 * Buildiq DeviceFeatures
 *
 * The browser devices a built app uses, read from its manifest: the
 * microphone for an `audio` form field, the position for a `location` form
 * field or a map page that shows the user's position. The page that serves
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

use OCP\AppFramework\Http\FeaturePolicy;

/**
 * Derives a manifest's device features and the feature policy they need.
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
 */
class DeviceFeatures {

	/**
	 * The microphone, for an audio form field.
	 */
	public const MICROPHONE = 'microphone';

	/**
	 * The device position, for a location form field or a map showing the user.
	 */
	public const GEOLOCATION = 'geolocation';

	/**
	 * The camera, declared by other changes through the same list.
	 */
	public const CAMERA = 'camera';

	/**
	 * Every feature this list can hold, in policy order.
	 *
	 * @var array<int,string>
	 */
	public const ALL = [self::CAMERA, self::GEOLOCATION, self::MICROPHONE];

	/**
	 * The device features a manifest uses, sorted and distinct.
	 *
	 * @param mixed $manifest The app manifest.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	public function fromManifest(mixed $manifest): array {
		$features = [];
		foreach ($this->pagesOf(manifest: $manifest) as $page) {
			$config = ($page['config'] ?? null);
			if (is_array($config) === false) {
				continue;
			}

			if (($page['type'] ?? null) === 'map' && ($config['showUserLocation'] ?? false) === true) {
				$features[] = self::GEOLOCATION;
			}

			foreach ((array)($config['fields'] ?? []) as $field) {
				$type = ($field['type'] ?? null);
				if (is_array($field) === true && $type === 'audio') {
					$features[] = self::MICROPHONE;
				}

				if (is_array($field) === true && $type === 'location') {
					$features[] = self::GEOLOCATION;
				}
			}
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
	public function policyFor(array $features): ?FeaturePolicy {
		$features = $this->normalise(features: $features);
		if ($features === []) {
			return null;
		}

		$policy = new FeaturePolicy();
		if (in_array(self::CAMERA, $features, true) === true) {
			$policy->addAllowedCameraDomain("'self'");
		}

		if (in_array(self::GEOLOCATION, $features, true) === true) {
			$policy->addAllowedGeoLocationDomain("'self'");
		}

		if (in_array(self::MICROPHONE, $features, true) === true) {
			$policy->addAllowedMicrophoneDomain("'self'");
		}

		return $policy;
	}//end policyFor()

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
