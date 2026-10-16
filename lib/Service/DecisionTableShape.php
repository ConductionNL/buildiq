<?php

/**
 * Buildiq DecisionTableShape
 *
 * Maps a decision table between buildiq's authoring shape (input columns with
 * an expression path, rules as a condition map and a value map) and the shape
 * OpenRegister stores and evaluates in its decision-tables register (input and
 * output columns, rules as positional entries in the shared cell grammar).
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
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Converts a decision table between the authoring and the stored shape.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */
class DecisionTableShape {

	/**
	 * The `owner` every table this app writes carries.
	 */
	public const OWNER = 'buildiq';

	/**
	 * Constructor.
	 *
	 * @param DecisionTableEvaluator $evaluator Converts cells and hit policies to the stored dialect.
	 */
	public function __construct(
		private readonly DecisionTableEvaluator $evaluator,
	) {

	}//end __construct()

	/**
	 * Map a table from buildiq's authoring shape onto OpenRegister's stored shape.
	 *
	 * Lossless for everything the evaluator reads: an input column keeps its
	 * expression path, an output column its default, a rule its label and
	 * priority. A rule value whose name no output column declares becomes an
	 * output column, because the evaluator used to return a matched rule's
	 * values wholesale. Cells are converted to the stored dialect, which is the
	 * conversion the evaluator applied on every evaluation, so it is idempotent.
	 *
	 * @param string              $key   The table's key.
	 * @param string              $name  The table's display name.
	 * @param array<string,mixed> $table The table in authoring shape.
	 *
	 * @return array<string,mixed> The stored row.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function toStored(string $key, string $name, array $table): array {
		$rules = array_values(array_filter((array)($table['rules'] ?? []), 'is_array'));

		$inputs = $this->columns(raw: (array)($table['inputColumns'] ?? []), input: true);
		$outputs = $this->outputColumns(declared: (array)($table['outputColumns'] ?? []), rules: $rules);

		$storedRules = [];
		foreach ($rules as $index => $rule) {
			$inputEntries = [];
			foreach ($inputs as $column) {
				$inputEntries[] = $this->evaluator->sharedCell(
					cell: (string)($rule['conditions'][$column['name']] ?? '')
				);
			}

			$outputEntries = [];
			foreach ($outputs as $column) {
				$outputEntries[] = ($rule['values'][$column['name']] ?? null);
			}

			$storedRule = [
				'id' => 'rule-' . $index,
				'inputEntries' => $inputEntries,
				'outputEntries' => $outputEntries,
				'priority' => (int)($rule['priority'] ?? 0),
			];
			$label = trim((string)($rule['label'] ?? ''));
			if ($label !== '') {
				$storedRule['label'] = $label;
			}

			$storedRules[] = $storedRule;
		}//end foreach

		return [
			'key' => $key,
			'name' => $name,
			'hitPolicy' => $this->evaluator->sharedHitPolicy(hitPolicy: (string)($table['hitPolicy'] ?? 'first')),
			'inputs' => $inputs,
			'outputs' => $outputs,
			'rules' => $storedRules,
			'owner' => self::OWNER,
		];

	}//end toStored()

	/**
	 * Map a stored table back onto buildiq's authoring shape.
	 *
	 * @param array<string,mixed> $row         The stored row.
	 * @param string              $ruleSetSlug The rule set it belongs to.
	 *
	 * @return array<string,mixed> The table in authoring shape.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function fromStored(array $row, string $ruleSetSlug): array {
		$inputs = $this->columns(raw: (array)($row['inputs'] ?? []), input: true);
		$outputs = $this->columns(raw: (array)($row['outputs'] ?? []), input: false);

		$rules = [];
		foreach (array_filter((array)($row['rules'] ?? []), 'is_array') as $rule) {
			$rules[] = $this->authoringRule(rule: $rule, inputs: $inputs, outputs: $outputs);
		}

		return [
			'ruleSetId' => $ruleSetSlug,
			'hitPolicy' => strtolower((string)($row['hitPolicy'] ?? 'first')),
			'inputColumns' => $inputs,
			'outputColumns' => $outputs,
			'rules' => $rules,
		];

	}//end fromStored()

	/**
	 * One stored rule as a condition map and a value map.
	 *
	 * A wildcard cell is left out of the condition map, which is how the
	 * authoring shape says "any".
	 *
	 * @param array<string,mixed>            $rule    The stored rule.
	 * @param list<array{name:string}>       $inputs  The input columns, in order.
	 * @param list<array{name:string}>       $outputs The output columns, in order.
	 *
	 * @return array<string,mixed> The rule in authoring shape.
	 */
	private function authoringRule(array $rule, array $inputs, array $outputs): array {
		$inputEntries = array_values((array)($rule['inputEntries'] ?? []));
		$outputEntries = array_values((array)($rule['outputEntries'] ?? []));

		$conditions = [];
		foreach ($inputs as $position => $column) {
			$cell = trim((string)($inputEntries[$position] ?? '-'));
			if ($cell !== '-' && $cell !== '') {
				$conditions[$column['name']] = $cell;
			}
		}

		$values = [];
		foreach ($outputs as $position => $column) {
			if (array_key_exists($position, $outputEntries) === true && $outputEntries[$position] !== null) {
				$values[$column['name']] = $outputEntries[$position];
			}
		}

		$authoring = [
			'conditions' => $conditions,
			'values' => $values,
			'priority' => (int)($rule['priority'] ?? 0),
		];
		if (trim((string)($rule['label'] ?? '')) !== '') {
			$authoring['label'] = (string)$rule['label'];
		}

		return $authoring;

	}//end authoringRule()

	/**
	 * The declared output columns plus every value name no column declares.
	 *
	 * @param array<int|string,mixed>        $declared The declared output columns.
	 * @param list<array<string,mixed>>      $rules    The authoring rules.
	 *
	 * @return list<array<string,mixed>> The output columns, declared first.
	 */
	private function outputColumns(array $declared, array $rules): array {
		$outputs = $this->columns(raw: $declared, input: false);
		$seen = array_fill_keys(array_column($outputs, 'name'), true);

		foreach ($rules as $rule) {
			foreach (array_keys((array)($rule['values'] ?? [])) as $valueName) {
				$valueName = (string)$valueName;
				if ($valueName === '' || isset($seen[$valueName]) === true) {
					continue;
				}

				$outputs[] = ['name' => $valueName, 'type' => 'string'];
				$seen[$valueName] = true;
			}
		}

		return $outputs;

	}//end outputColumns()

	/**
	 * The named columns of a raw column list, first of each name kept.
	 *
	 * An input column keeps its expression path (its name when it has none),
	 * an output column its default value when it declares one.
	 *
	 * @param array<int|string,mixed> $raw   The raw columns.
	 * @param bool                    $input Whether these are input columns.
	 *
	 * @return list<array<string,mixed>> The columns.
	 */
	private function columns(array $raw, bool $input): array {
		$columns = [];
		foreach (array_filter($raw, 'is_array') as $column) {
			$name = trim((string)($column['name'] ?? ''));
			if ($name === '' || in_array($name, array_column($columns, 'name'), true) === true) {
				continue;
			}

			$kept = ['name' => $name, 'type' => (string)($column['type'] ?? 'string')];
			if ($input === true) {
				$kept['expressionPath'] = (string)($column['expressionPath'] ?? $name);
			}

			if ($input === false && array_key_exists('defaultValue', $column) === true) {
				$kept['defaultValue'] = $column['defaultValue'];
			}

			$columns[] = $kept;
		}

		return $columns;

	}//end columns()
}//end class
