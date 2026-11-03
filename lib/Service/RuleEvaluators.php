<?php

/**
 * Buildiq Rule Evaluators
 *
 * The decision table evaluator, condition executor, action dispatcher and rule cache.
 * RuleEngineService evaluates rules with these four collaborators; they come as one object.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The decision table evaluator, condition executor, action dispatcher and rule cache.
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#5.1
 */
final class RuleEvaluators {
	/**
	 * Constructor.
	 *
	 * @param DecisionTableEvaluator $decisionEvaluator Evaluates decision tables.
	 * @param ConditionActionExecutor $conditionExecutor Runs condition-action rules.
	 * @param RuleActionDispatcher $actionDispatcher Dispatches rule actions.
	 * @param RuleSetCacheManager $cacheManager Caches compiled rule sets.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DecisionTableEvaluator $decisionEvaluator,
		public readonly ConditionActionExecutor $conditionExecutor,
		public readonly RuleActionDispatcher $actionDispatcher,
		public readonly RuleSetCacheManager $cacheManager,
	) {
	}//end __construct()
}//end class
