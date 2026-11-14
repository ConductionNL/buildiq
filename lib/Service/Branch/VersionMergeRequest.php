<?php

/**
 * Buildiq VersionMergeRequest
 *
 * The two versions of a merge and, for an apply, the side chosen per conflict,
 * the target hash the preview returned and whether the maker confirmed a
 * breaking schema change (lifecycle-git-branches).
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Branch;

/**
 * One merge request.
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
 */
final class VersionMergeRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $source     The version merged in.
	 * @param array<string,mixed> $target     The version merged into.
	 * @param array<string,mixed> $resolution For an apply: `choices` (conflict unit to `source` or
	 *                                        `target`), the previewed `targetHash`, and
	 *                                        `acknowledgeBreaking` for a breaking schema change.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $source,
		public readonly array $target,
		private readonly array $resolution=[],
	) {
	}//end __construct()

	/**
	 * The side chosen per conflict unit; only string choices count.
	 *
	 * @return array<string,string>
	 */
	public function choices(): array {
		return array_filter((array)($this->resolution['choices'] ?? []), 'is_string');
	}//end choices()

	/**
	 * The target hash the preview returned.
	 *
	 * @return string
	 */
	public function targetHash(): string {
		return (string)($this->resolution['targetHash'] ?? '');
	}//end targetHash()

	/**
	 * Whether the maker confirmed a breaking schema change.
	 *
	 * @return bool
	 */
	public function acknowledgeBreaking(): bool {
		return in_array(($this->resolution['acknowledgeBreaking'] ?? false), [true, 'true'], true);
	}//end acknowledgeBreaking()
}//end class
