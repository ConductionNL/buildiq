<?php

/**
 * Contract of DeviceFeatures
 *
 * The public surface of DeviceFeatures that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

use OCP\AppFramework\Http\FeaturePolicy;

/**
 * Contract of DeviceFeatures.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeviceFeaturesInterface {

	/**
	 * The device features a manifest uses, sorted and distinct.
	 *
	 * @param mixed $manifest The app manifest.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	public function fromManifest(mixed $manifest): array;

	/**
	 * A stored feature list kept to the known features, sorted and distinct.
	 *
	 * @param mixed $features The stored list.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/archive/2026-11-24-forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	public function normalise(mixed $features): array;

	/**
	 * The feature policy that allows the page itself to use exactly these features, or null for none.
	 *
	 * @param array<int,string> $features The features.
	 *
	 * @return FeaturePolicy|null Null leaves Nextcloud's default policy unchanged.
	 *
	 * @spec openspec/changes/archive/2026-11-24-forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
	 */
	public function policyFor(array $features): ?FeaturePolicy;
}//end interface
