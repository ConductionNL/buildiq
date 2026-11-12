<?php

/**
 * Buildiq RuleSetEvaluation
 *
 * One evaluation of a rule set: which rule set, against which payload, and how.
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
 * @spec openspec/changes/business-rules-engine/tasks.md#5.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * A rule set evaluated against one payload.
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#5.1
 */
final class RuleSetEvaluation {

	/**
	 * Constructor.
	 *
	 * @param string                $ruleSetSlug The RuleSet slug.
	 * @param array<string,mixed>   $payload     The input payload.
	 * @param RuleEvaluationOptions $options     Version, dry-run, PII masking and preview.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $ruleSetSlug,
		public readonly array $payload,
		public readonly RuleEvaluationOptions $options,
	) {
	}//end __construct()
}//end class
