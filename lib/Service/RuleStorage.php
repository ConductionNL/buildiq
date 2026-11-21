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
	 * The name an exported rule set file carries in its `format` field.
	 */
	public const EXPORT_FORMAT = 'buildiq.rule-set-export.v1';

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

	/**
	 * A rule set as one exportable document: its metadata, decision tables,
	 * condition-action rules and test cases.
	 *
	 * Execution logs are never read, so they cannot end up in the file. Each
	 * object loses its `@self` block, which names this instance's ids,
	 * organisation and owner and means nothing where the file is imported.
	 *
	 * @param array<string,mixed> $ruleSet The resolved RuleSet.
	 *
	 * @return array<string,mixed> The export document.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
	 */
	public function exportBundle(array $ruleSet): array {
		$slug = (string)($ruleSet['slug'] ?? '');
		$caRules = $this->reader->findScoped(schema: 'condition-action-rule', filters: ['ruleSetId' => $slug]);
		$testCases = $this->reader->findScoped(schema: 'rule-test-case', filters: ['ruleSetId' => $slug]);

		return [
			'format' => self::EXPORT_FORMAT,
			'ruleSet' => self::withoutSelf(row: $ruleSet),
			'decisionTables' => $this->tables->forRuleSet(ruleSet: $ruleSet),
			'conditionActionRules' => array_map(callback: self::withoutSelf(...), array: $caRules),
			'testCases' => array_map(callback: self::withoutSelf(...), array: $testCases),
		];
	}//end exportBundle()

	/**
	 * An object without its `@self` metadata block.
	 *
	 * @param array<string,mixed> $row The object.
	 *
	 * @return array<string,mixed> The object without `@self`.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
	 */
	private static function withoutSelf(array $row): array {
		unset($row['@self']);
		return $row;
	}//end withoutSelf()
}//end class
