<?php

/**
 * Buildiq Production Version
 *
 * Tells whether a version is the one an app's users see: the Application's
 * `productionVersion`.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Reads the production version of an Application.
 *
 * OpenRegister serves a relation as a uuid string or, expanded, as an object
 * carrying `id` or `uuid`; both are read. Only an owner changes what is live
 * (REQ-BQCV-027): the callers refuse a non-owner's write onto the version
 * this class names.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
 */
final class ProductionVersion {
	/**
	 * Whether the given version reference is the Application's production version.
	 *
	 * @param array<string,mixed> $application The Application record.
	 * @param mixed               $version     A version uuid, or an expanded version object.
	 *
	 * @return bool True when both are known and the same version.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
	 */
	public static function isProduction(array $application, mixed $version): bool {
		$production = self::uuidOf(reference: ($application['productionVersion'] ?? null));
		$candidate  = self::uuidOf(reference: $version);

		return $production !== '' && $production === $candidate;
	}//end isProduction()

	/**
	 * The uuid of a relation as OpenRegister serves it.
	 *
	 * @param mixed $reference A uuid string, an object with `id` or `uuid`, or nothing.
	 *
	 * @return string The uuid, or '' when there is none.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
	 */
	private static function uuidOf(mixed $reference): string {
		if (is_array($reference) === true) {
			$reference = ($reference['id'] ?? $reference['uuid'] ?? '');
		}

		if (is_string($reference) === false) {
			return '';
		}

		return $reference;
	}//end uuidOf()
}//end class
