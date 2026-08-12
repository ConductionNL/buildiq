<?php

/**
 * OpenBuild DecisionTableEvaluator
 *
 * Evaluates a DMN 1.4 decision table against an input payload and implements
 * the hit policies first / unique / priority / any / collect / rule-order
 * (design.md Decision 3). Each rule maps input-column conditions (FEEL-subset
 * cell expressions such as `>=18`, `5..10`, `in ('a','b')`) to output-column
 * values. Also detects overlapping and unreachable rules for the visual editor.
 *
 * Cell-condition grammar per column:
 *   - empty / "-" / "*"           → always matches (don't-care)
 *   - "<op> <literal>"            → comparison of the column value (>=18, == 'x')
 *   - "<low>..<high>"            → inclusive range membership
 *   - "in (a, b, c)"             → list membership
 *   - bare literal               → equality
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#3.1
 * @spec openspec/changes/business-rules-engine/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use RuntimeException;

/**
 * DMN decision-table evaluator with hit-policy and overlap analysis.
 */
class DecisionTableEvaluator {

	/**
	 * Cell tokens that mean "always matches".
	 *
	 * @var array<int,string>
	 */
	private const DONT_CARE = ['', '-', '*', 'any'];

	/**
	 * Constructor.
	 *
	 * @param ExpressionEvaluator $expressionEvaluator Resolves field paths / FEEL fragments.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExpressionEvaluator $expressionEvaluator,
	) {

	}//end __construct()

	/**
	 * Evaluate a decision table against a payload.
	 *
	 * @param array<string,mixed> $table The DecisionTable object data.
	 * @param array<string,mixed> $payload The input payload.
	 *
	 * @return array{outputColumns:array<string,mixed>,triggeredRuleId:string|null,matches:array<int,int>,overlap_warnings:array<int,string>,unreachable_rules:array<int,int>}
	 *
	 * @throws RuntimeException When hit policy `unique` matches more than one rule.
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-002-decisiontable-schema-for-dmn-based-multi-condition-rules
	 */
	public function evaluate(array $table, array $payload): array {
		$hitPolicy = (string)($table['hitPolicy'] ?? 'first');
		$inputs = ($table['inputColumns'] ?? []);
		$outputs = ($table['outputColumns'] ?? []);
		$rules = ($table['rules'] ?? []);

		// Resolve each input column's value from the payload once.
		$columnValues = [];
		foreach ($inputs as $col) {
			$name = (string)($col['name'] ?? '');
			$path = (string)($col['expressionPath'] ?? $name);
			$columnValues[$name] = $this->expressionEvaluator->evaluateExpression($path, $payload);
		}

		$matchedIndexes = [];
		foreach ($rules as $index => $rule) {
			if ($this->ruleMatches(conditions: ($rule['conditions'] ?? []), columnValues: $columnValues) === true) {
				$matchedIndexes[] = $index;
			}
		}

		$result = $this->applyHitPolicy(hitPolicy: $hitPolicy, matched: $matchedIndexes, rules: $rules, outputs: $outputs);

		return [
			'outputColumns' => $result['output'],
			'triggeredRuleId' => $result['ruleId'],
			'matches' => $matchedIndexes,
			'overlap_warnings' => [],
			'unreachable_rules' => [],
		];

	}//end evaluate()

	/**
	 * Apply the configured hit policy to the matched rule set.
	 *
	 * @param string $hitPolicy The DMN hit policy.
	 * @param array<int,int> $matched Indexes of matching rules (in table order).
	 * @param array<int,mixed> $rules All rules.
	 * @param array<int,mixed> $outputs Output column definitions.
	 *
	 * @return array{output:array<string,mixed>,ruleId:string|null}
	 *
	 * @throws RuntimeException On a `unique` policy violation.
	 */
	private function applyHitPolicy(string $hitPolicy, array $matched, array $rules, array $outputs): array {
		if ($matched === []) {
			return ['output' => $this->defaultOutput(outputs: $outputs), 'ruleId' => null];
		}

		switch ($hitPolicy) {
			case 'unique':
				if (count($matched) > 1) {
					throw new RuntimeException(
						'Hit policy "unique" violated: rules ' . implode(', ', $matched) . ' all matched.'
					);
				}
				return $this->singleOutput(index: $matched[0], rules: $rules);
			case 'priority':
				$best = $matched[0];
				$bestPrio = (int)($rules[$best]['priority'] ?? 0);
				foreach ($matched as $idx) {
					$prio = (int)($rules[$idx]['priority'] ?? 0);
					if ($prio > $bestPrio) {
						$best = $idx;
						$bestPrio = $prio;
					}
				}
				return $this->singleOutput(index: $best, rules: $rules);
			case 'any':
			case 'collect':
				$collected = [];
				foreach ($matched as $idx) {
					$collected[] = ($rules[$idx]['values'] ?? []);
				}
				return [
					'output' => ['collected' => $collected],
					'ruleId' => $this->ruleLabel(index: $matched[0], rules: $rules),
				];

			case 'first':
			case 'rule-order':
			default:
				return $this->singleOutput(index: $matched[0], rules: $rules);
		}//end switch

	}//end applyHitPolicy()

	/**
	 * Build the single-rule output payload.
	 *
	 * @param int $index The winning rule index.
	 * @param array<int,mixed> $rules All rules.
	 *
	 * @return array{output:array<string,mixed>,ruleId:string|null}
	 */
	private function singleOutput(int $index, array $rules): array {
		return [
			'output' => ($rules[$index]['values'] ?? []),
			'ruleId' => $this->ruleLabel(index: $index, rules: $rules),
		];

	}//end singleOutput()

	/**
	 * Derive a stable rule identifier (label or positional).
	 *
	 * @param int $index The rule index.
	 * @param array<int,mixed> $rules All rules.
	 *
	 * @return string
	 */
	private function ruleLabel(int $index, array $rules): string {
		$label = (string)($rules[$index]['label'] ?? '');
		if ($label !== '') {
			return $label;
		}

		return 'rule-' . $index;
	}//end ruleLabel()

	/**
	 * Compose the default output from output-column defaults.
	 *
	 * @param array<int,mixed> $outputs Output column definitions.
	 *
	 * @return array<string,mixed>
	 */
	private function defaultOutput(array $outputs): array {
		$out = [];
		foreach ($outputs as $col) {
			$name = (string)($col['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$out[$name] = ($col['defaultValue'] ?? null);
		}

		return $out;
	}//end defaultOutput()

	/**
	 * Test whether all of a rule's column conditions match the resolved values.
	 *
	 * @param array<string,mixed> $conditions The rule's per-column conditions.
	 * @param array<string,mixed> $columnValues Resolved column values.
	 *
	 * @return bool
	 */
	private function ruleMatches(array $conditions, array $columnValues): bool {
		foreach ($conditions as $column => $condition) {
			$value = ($columnValues[$column] ?? null);
			if ($this->cellMatches(condition: (string)$condition, value: $value) === false) {
				return false;
			}
		}

		return true;
	}//end ruleMatches()

	/**
	 * Evaluate a single cell condition against a column value.
	 *
	 * @param string $condition The cell condition source.
	 * @param mixed $value The resolved column value.
	 *
	 * @return bool
	 */
	private function cellMatches(string $condition, mixed $value): bool {
		$trimmed = trim($condition);
		if (in_array(strtolower($trimmed), self::DONT_CARE, true) === true) {
			return true;
		}

		// Build a FEEL expression with `__v` bound to the column value, e.g.
		// ">=18" → "__v >= 18", "18..65" → "__v in (18..65)", "in (1,2)" → "__v in (1,2)".
		$context = ['__v' => $value];

		$expr = $this->buildCellExpression(trimmed: $trimmed);

		return (bool)$this->expressionEvaluator->evaluateExpression($expr, $context);
	}//end cellMatches()

	/**
	 * Build the FEEL expression for one cell condition, with `__v` bound to the
	 * column value.
	 *
	 * Extracted from {@see self::cellMatches()} so the condition dialects read
	 * as an ordered sequence of guards rather than an if/else-if chain. The
	 * order is significant and unchanged: comparison operator, range, explicit
	 * `in (...)`, numeric equality, then bare string equality as the fallback.
	 *
	 * @param string $trimmed The trimmed cell condition.
	 *
	 * @return string The FEEL expression.
	 */
	private function buildCellExpression(string $trimmed): string {
		if (preg_match('/^(==|!=|<=|>=|<|>)\s*(.+)$/', $trimmed, $matches) === 1) {
			return '__v ' . $matches[1] . ' ' . $matches[2];
		}

		if (str_contains($trimmed, '..') === true) {
			return '__v in (' . $trimmed . ')';
		}

		if (preg_match('/^in\s*\(/i', $trimmed) === 1) {
			return '__v ' . $trimmed;
		}

		if (is_numeric($trimmed) === true) {
			return '__v == ' . $trimmed;
		}

		// Bare string literal equality.
		return "__v == '" . str_replace("'", '', $trimmed) . "'";
	}//end buildCellExpression()

	/**
	 * Detect overlapping, unreachable and (for `unique`) gap issues.
	 *
	 * A pair of rules "overlaps" when there exists at least one resolvable
	 * column set for which both rules match. Approximated structurally: two
	 * rules overlap when, for every shared column, their conditions are
	 * compatible (one is a don't-care, equal, or numerically overlapping
	 * comparison/range). Under `first`/`rule-order` an overlapping later rule
	 * shadowed by an earlier one is reported as unreachable.
	 *
	 * @param array<string,mixed> $table The DecisionTable object data.
	 *
	 * @return array{overlaps:array<int,string>,unreachable:array<int,string>}
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-012-visual-editor-feedback-overlap-and-completeness-detection
	 */
	public function detectIssues(array $table): array {
		$rules = array_values(($table['rules'] ?? []));
		$hitPolicy = (string)($table['hitPolicy'] ?? 'first');
		$count = count($rules);

		$overlaps = [];
		$unreachable = [];

		for ($i = 0; $i < $count; $i++) {
			for ($j = ($i + 1); $j < $count; $j++) {
				if ($this->rulesOverlap(a: ($rules[$i]['conditions'] ?? []), b: ($rules[$j]['conditions'] ?? [])) === true) {
					$overlaps[] = 'Rules ' . $i . ' and ' . $j . ' overlap.';

					if (in_array($hitPolicy, ['first', 'rule-order'], true) === true
						&& $this->subsumes(a: ($rules[$i]['conditions'] ?? []), b: ($rules[$j]['conditions'] ?? [])) === true
					) {
						$unreachable[] = 'Rule ' . $j . ' is unreachable — shadowed by rule ' . $i . '.';
					}
				}
			}
		}

		return [
			'overlaps' => $overlaps,
			'unreachable' => array_values(array_unique($unreachable)),
		];

	}//end detectIssues()

	/**
	 * Structural overlap test between two condition sets.
	 *
	 * @param array<string,mixed> $a First rule conditions.
	 * @param array<string,mixed> $b Second rule conditions.
	 *
	 * @return bool
	 */
	private function rulesOverlap(array $a, array $b): bool {
		$columns = array_unique(array_merge(array_keys($a), array_keys($b)));
		foreach ($columns as $column) {
			$condA = trim((string)($a[$column] ?? ''));
			$condB = trim((string)($b[$column] ?? ''));

			$aDontCare = in_array(strtolower($condA), self::DONT_CARE, true);
			$bDontCare = in_array(strtolower($condB), self::DONT_CARE, true);
			if ($aDontCare === true || $bDontCare === true) {
				continue;
			}

			// Distinct literal equalities never overlap.
			if ($this->isLiteral(condition: $condA) === true && $this->isLiteral(condition: $condB) === true && $condA !== $condB) {
				return false;
			}
		}

		return true;
	}//end rulesOverlap()

	/**
	 * Whether condition set $a subsumes (fully shadows) $b under first-match.
	 *
	 * @param array<string,mixed> $a Earlier rule conditions.
	 * @param array<string,mixed> $b Later rule conditions.
	 *
	 * @return bool
	 */
	private function subsumes(array $a, array $b): bool {
		// The earlier rule shadows the later one when, for every column the
		// later rule constrains, the earlier rule is either a don't-care (so it
		// matches everything the later rule would) or carries the identical
		// condition. Any column where the earlier rule is stricter breaks the
		// shadow because there are inputs the later rule matches but the earlier
		// does not.
		$columns = array_unique(array_merge(array_keys($a), array_keys($b)));
		foreach ($columns as $column) {
			$condA = strtolower(trim((string)($a[$column] ?? '')));
			$condB = strtolower(trim((string)($b[$column] ?? '')));

			if (in_array($condA, self::DONT_CARE, true) === true) {
				continue;
			}

			if ($condA !== $condB) {
				return false;
			}
		}

		return true;
	}//end subsumes()

	/**
	 * Whether a cell condition is a bare literal (no operator/range/list).
	 *
	 * @param string $condition The cell condition.
	 *
	 * @return bool
	 */
	private function isLiteral(string $condition): bool {
		if (str_contains($condition, '..') === true) {
			return false;
		}

		if (preg_match('/^(==|!=|<=|>=|<|>|in)\b|^(==|!=|<=|>=|<|>)/i', $condition) === 1) {
			return false;
		}

		return true;
	}//end isLiteral()
}//end class
