<?php

/**
 * Contract of VersionMergeRunner
 *
 * The public surface of VersionMergeRunner that other components call, so they
 * depend on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of VersionMergeRunner.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VersionMergeRunnerInterface {

	/**
	 * A new draft version branched from the source version.
	 *
	 * @param array<string,mixed> $application The Application.
	 * @param array<string,mixed> $source      The version to branch from.
	 * @param string              $name        The new version's slug.
	 *
	 * @return array<string,mixed> The new version.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-starts-a-branch-from-a-version-req-bqgb-001
	 */
	public function startBranch(array $application, array $source, string $name): array;

	/**
	 * The merge of the source version into the target version, written nowhere.
	 *
	 * @param array<string,mixed> $application The Application (`productionVersion`).
	 * @param array<string,mixed> $source      The version merged in.
	 * @param array<string,mixed> $target      The version merged into.
	 *
	 * @return array<string,mixed> `manifest`, `schemas`, `conflicts`, `breaking` and the target's `targetHash`.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function previewMerge(array $application, array $source, array $target): array;

	/**
	 * Apply the merge of the source version into the target version and write the target.
	 *
	 * @param array<string,mixed> $application The Application (`productionVersion`).
	 * @param array<string,mixed> $source      The version merged in.
	 * @param array<string,mixed> $target      The version merged into.
	 * @param array<string,mixed> $resolution  `choices`, the previewed `targetHash` and `acknowledgeBreaking`.
	 *
	 * @return array<string,mixed> The saved target version.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
	 */
	public function applyMerge(array $application, array $source, array $target, array $resolution): array;
}//end interface
