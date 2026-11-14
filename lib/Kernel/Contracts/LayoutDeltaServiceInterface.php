<?php

/**
 * Contract of LayoutDeltaService
 *
 * The public surface of LayoutDeltaService that other components call, so they depend
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

/**
 * Contract of LayoutDeltaService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LayoutDeltaServiceInterface {

	/**
	 * Whether a stored fingerprint still describes the base in hand.
	 *
	 * An override with no fingerprint at all is NOT treated as current. It was
	 * written by something that did not pin its base, and the honest answer is
	 * that nobody knows what it was cut against.
	 *
	 * @param array<string, mixed> $base The base layout.
	 * @param string $storedFingerprint The fingerprint the override recorded.
	 *
	 * @return bool True when the base is unchanged.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	public function isCurrent(array $base, string $storedFingerprint): bool;

	/**
	 * Apply a delta to a base.
	 *
	 * @param array<string, mixed> $base The layout being patched.
	 * @param array<string, mixed> $delta The patch.
	 *
	 * @return array<string, mixed> The result.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-001)
	 */
	public function merge(array $base, array $delta): array;

	/**
	 * Which paths of a delta find nothing in the base.
	 *
	 * Used to say WHICH parts of a withheld override no longer apply. A
	 * maintainer told only that something drifted has to diff two layouts by
	 * hand to find out what.
	 *
	 * @param array<string, mixed> $base The base.
	 * @param array<string, mixed> $delta The patch.
	 *
	 * @return array<int, string> The orphaned paths.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	public function orphanedPaths(array $base, array $delta): array;
}//end interface
