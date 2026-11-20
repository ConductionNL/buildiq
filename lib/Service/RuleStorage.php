<?php

/**
 * Buildiq Rule Storage
 *
 * The rule object reader, stored decision tables and rule set versioning. RulesController reads
 * rules, stored decision tables and rule set versions through these three collaborators; they come
 * as one object.
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
 * The rule object reader, stored decision tables and rule set versioning.
 *
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#9.1
 */
final class RuleStorage {
	/**
	 * Constructor.
	 *
	 * @param RuleObjectReader $reader Reads rule objects.
	 * @param StoredDecisionTables $tables Stored decision tables.
	 * @param RuleSetVersioningService $versioningService Rule set versioning.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly RuleObjectReader $reader,
		public readonly StoredDecisionTables $tables,
		public readonly RuleSetVersioningService $versioningService,
	) {
	}//end __construct()

	/**
	 * The input and output columns of a rule set's decision table.
	 *
	 * A condition-action rule set has no declared columns and answers two
	 * empty lists.
	 *
	 * @param array<string,mixed> $ruleSet The resolved RuleSet.
	 *
	 * @return array{inputs:list<array{name:string,path:string,type:string}>,outputs:list<array{name:string,type:string}>}
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
	 */
	public function decisionColumns(array $ruleSet): array {
		$tables = $this->tables->forRuleSet(ruleSet: $ruleSet);

		return RuleSetColumns::fromTable(table: (array)($tables[0] ?? []));
	}//end decisionColumns()
}//end class
