<?php

/**
 * Buildiq RuleEvaluationOptions
 *
 * How one rule-set evaluation runs: which version, whether it may act, whether
 * its log masks personal data, and whether it is a live form preview.
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
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#5.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The optional settings of one rule-set evaluation.
 *
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#5.1
 */
final class RuleEvaluationOptions {

	/**
	 * Constructor.
	 *
	 * @param string|null $version Pinned version (null: the active one).
	 * @param bool        $dryRun  When true, side-effecting actions are suppressed.
	 * @param bool        $maskPii When true, mask PII fields in the audit log.
	 * @param bool        $preview When true, no execution log entry is written.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $version = null,
		public readonly bool $dryRun = false,
		public readonly bool $maskPii = true,
		public readonly bool $preview = false,
	) {
	}//end __construct()
}//end class
