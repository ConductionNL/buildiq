<?php

/**
 * Automation Rule Sets
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Resolves the rule sets the rule-decision steps of an automation name
 * (logic-automation-actions-that-run D7): their type, their version, the key
 * of the decision table OpenRegister holds for them and its output columns.
 * The flow references that stored table by key, so it runs the table the
 * rules engine runs.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Kernel\Contracts\RuleEngineServiceInterface;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The rule sets of an automation's rule decisions.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
 */
class AutomationRuleSets {

	/**
	 * The shared buildiq register.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the rule sets.
	 * @param StoredDecisionTables   $tables        Names and reads a rule set's stored decision table.
	 * @param LoggerInterface        $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly StoredDecisionTables $tables,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The rule sets a rule-decision step names, by slug: type, version, the
	 * key of the stored decision table and its output columns (D7). A slug
	 * that resolves to nothing is left out, so the builder refuses it by name.
	 *
	 * @param list<string> $slugs The rule set slugs.
	 *
	 * @return array<string, array{ruleType: string, version: string, tableRef: string, outputs: list<string>}>
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
	 */
	public function ruleSets(array $slugs): array {
		$out = [];
		foreach ($slugs as $slug) {
			$ruleSet = $this->findRuleSet(slug: (string)$slug);
			if ($ruleSet === null) {
				continue;
			}

			$ruleType = (string)($ruleSet['ruleType'] ?? 'decision-table');
			$tableRef = '';
			$outputs = [];
			if ($ruleType === 'decision-table') {
				$table = ($this->tables->forRuleSet(ruleSet: $ruleSet)[0] ?? null);
				if (is_array($table) === true) {
					$tableRef = $this->tables->keyOfRuleSet(ruleSet: $ruleSet);
					$outputs = self::columnNames(columns: (array)($table['outputColumns'] ?? []));
				}
			}

			$out[(string)$slug] = [
				'ruleType' => $ruleType,
				'version' => (string)($ruleSet['version'] ?? ''),
				'tableRef' => $tableRef,
				'outputs' => $outputs,
			];
		}//end foreach

		return $out;
	}//end ruleSets()

	/**
	 * One rule set by slug, or null.
	 *
	 * @param string $slug The slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AutomationRecords is a pure coercion with no collaborators.
	 */
	private function findRuleSet(string $slug): ?array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER_SLUG, 'schema' => RuleEngineServiceInterface::RULE_SET_SCHEMA, 'slug' => $slug], 'limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationRuleSets could not read rule set "' . $slug . '": ' . $e->getMessage());
			return null;
		}

		if ($found === []) {
			return null;
		}

		return AutomationRecords::toArray(object: $found[0]);
	}//end findRuleSet()

	/**
	 * The names of the columns that have one.
	 *
	 * @param array<int, mixed> $columns The columns.
	 *
	 * @return list<string>
	 */
	private static function columnNames(array $columns): array {
		$names = [];
		foreach ($columns as $column) {
			$name = $column;
			if (is_array($column) === true) {
				$name = ($column['name'] ?? '');
			}

			$name = (string)$name;
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end columnNames()
}//end class
