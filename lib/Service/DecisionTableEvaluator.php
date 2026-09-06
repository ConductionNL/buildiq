<?php

/**
 * Buildiq DecisionTableEvaluator
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
 * @spec openspec/changes/business-rules-engine/tasks.md#3.1
 * @spec openspec/changes/business-rules-engine/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
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
	 * @param ExpressionEvaluator    $expressionEvaluator Resolves input-column field paths against the payload.
	 * @param SharedEvaluator     $sharedEvaluator     OpenRegister's shared rule matcher and hit-policy engine.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExpressionEvaluator $expressionEvaluator,
		private readonly SharedEvaluator $sharedEvaluator,
	) {

	}//end __construct()

	/**
	 * Evaluate a decision table against a payload.
	 *
	 * The division of labour: buildiq resolves each input column's value from
	 * the payload (that is buildiq's `expressionPath` concept, which the shared
	 * evaluator knows nothing about), then hands named values and a translated
	 * table to OpenRegister, which decides which rules match and which one wins.
	 * The winning rule's outputs are read back out of buildiq's OWN rule rows by
	 * index, not rebuilt from the shared result, so the returned shape is
	 * identical to what this method has always returned.
	 *
	 * @param array<string,mixed> $table The DecisionTable object data.
	 * @param array<string,mixed> $payload The input payload.
	 *
	 * @return array{outputColumns:array<string,mixed>,triggeredRuleId:string|null,matches:array<int,int>,overlap_warnings:array<int,string>,unreachable_rules:array<int,int>}
	 *
	 * @throws RuntimeException When hit policy `unique` matches more than one rule, or `any` matches rules that disagree.
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-002-decisiontable-schema-for-dmn-based-multi-condition-rules
	 */
	public function evaluate(array $table, array $payload): array {
		$inputs = ($table['inputColumns'] ?? []);
		$outputs = ($table['outputColumns'] ?? []);
		$rules = array_values(($table['rules'] ?? []));
		$hitPolicy = $this->sharedHitPolicy(hitPolicy: (string)($table['hitPolicy'] ?? 'first'));

		// Resolve each input column's value from the payload once.
		$columnValues = [];
		foreach ($inputs as $col) {
			$name = (string)($col['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$path = (string)($col['expressionPath'] ?? $name);
			$columnValues[$name] = $this->expressionEvaluator->evaluateExpression($path, $payload);
		}

		// A path that does not resolve in the payload yields null, and the shared
		// evaluator coerces every declared input up front, so a single null
		// would refuse the whole table with `type_mismatch`. buildiq's contract
		// is the opposite: an unresolved column simply fails the conditions that
		// test it, and the table falls through to its defaults. Dropping the
		// null columns and the rules that test them reproduces exactly that,
		// without reimplementing any matching here.
		$unresolved = array_keys(array_filter($columnValues, static fn (mixed $value): bool => $value === null));
		$columnValues = array_diff_key($columnValues, array_flip($unresolved));

		try {
			$outcome = $this->sharedEvaluator->evaluate(
				decisionTable: $this->toSharedTable(
					inputs: $inputs,
					outputs: $outputs,
					rules: $rules,
					hitPolicy: $hitPolicy,
					unresolved: $unresolved,
				),
				inputs: $columnValues,
			);
		} catch (DecisionEvaluationException $e) {
			if ($e->getErrorCode() === 'no_rule_matched') {
				return $this->noMatchResult(outputs: $outputs);
			}

			if ($e->getErrorCode() === 'hit_policy_violation') {
				$matched = implode(', ', ($e->getDetails()['matchedRuleIds'] ?? []));
				throw new RuntimeException(
					'Hit policy "' . strtolower($hitPolicy) . '" violated: rules ' . $matched . ' all matched.'
				);
			}

			throw $e;
		}//end try

		$matched = array_map(static fn (string $id): int => (int)$id, $outcome['matchedRuleIds']);
		if ($matched === []) {
			return $this->noMatchResult(outputs: $outputs);
		}

		if ($hitPolicy === 'COLLECT') {
			$collected = [];
			foreach ($matched as $index) {
				$collected[] = ($rules[$index]['values'] ?? []);
			}

			return $this->result(output: ['collected' => $collected], ruleId: $this->ruleLabel(index: $matched[0], rules: $rules), matched: $matched);
		}

		return $this->result(
			output: ($rules[$matched[0]]['values'] ?? []),
			ruleId: $this->ruleLabel(index: $matched[0], rules: $rules),
			matched: $matched,
		);

	}//end evaluate()

	/**
	 * Translate buildiq's table shape into the shared evaluator's.
	 *
	 * Rule ids are set to the rule's own index as a string, so the shared
	 * evaluator's `matchedRuleIds` come back as buildiq's rule indexes and the
	 * winning row can be read straight out of buildiq's `rules` array.
	 *
	 * @param array<int,mixed>  $inputs     buildiq's input columns.
	 * @param array<int,mixed>  $outputs    buildiq's output columns.
	 * @param array<int,mixed>  $rules      buildiq's rule rows.
	 * @param string            $hitPolicy  The already-translated hit policy.
	 * @param array<int,string> $unresolved Columns whose payload path yielded null.
	 *
	 * @return array<string,mixed> The shared evaluator's table shape.
	 */
	private function toSharedTable(array $inputs, array $outputs, array $rules, string $hitPolicy, array $unresolved = []): array {
		$sharedRules = [];
		foreach ($rules as $index => $rule) {
			$inputEntries = [];
			$testsNullColumn = false;
			foreach ($inputs as $col) {
				$name = (string)($col['name'] ?? '');
				if ($name === '') {
					continue;
				}

				$cell = $this->sharedCell(cell: (string)($rule['conditions'][$name] ?? ''));
				if (in_array($name, $unresolved, true) === true) {
					// The column has no value, so a real test on it cannot pass.
					// A wildcard still would, and dropping the column leaves the
					// rest of the rule to decide, which is what used to happen.
					if ($cell !== '-') {
						$testsNullColumn = true;
					}

					continue;
				}

				$inputEntries[] = $cell;
			}

			if ($testsNullColumn === true) {
				continue;
			}

			$outputEntries = [];
			foreach ($outputs as $col) {
				$name = (string)($col['name'] ?? '');
				if ($name === '') {
					continue;
				}

				$outputEntries[] = ($rule['values'][$name] ?? null);
			}

			$sharedRules[] = [
				'id' => (string)$index,
				'inputEntries' => $inputEntries,
				'outputEntries' => $outputEntries,
				'priority' => (int)($rule['priority'] ?? 0),
			];
		}//end foreach

		return [
			'hitPolicy' => $hitPolicy,
			'inputs' => $this->sharedColumns(columns: $inputs, exclude: $unresolved),
			'outputs' => $this->sharedColumns(columns: $outputs),
			'rules' => $sharedRules,
		];

	}//end toSharedTable()

	/**
	 * Reduce buildiq's column definitions to the shared `{name, type}` shape.
	 *
	 * @param array<int,mixed>  $columns The column definitions.
	 * @param array<int,string> $exclude Column names to leave out.
	 *
	 * @return array<int,array{name:string,type:string}> The shared columns.
	 */
	private function sharedColumns(array $columns, array $exclude = []): array {
		$shared = [];
		foreach ($columns as $col) {
			$name = (string)($col['name'] ?? '');
			if ($name === '' || in_array($name, $exclude, true) === true) {
				continue;
			}

			$shared[] = ['name' => $name, 'type' => (string)($col['type'] ?? 'string')];
		}

		return $shared;

	}//end sharedColumns()

	/**
	 * Translate one cell from buildiq's dialect into the shared unary-test grammar.
	 *
	 * The two grammars overlap but are not the same, and the differences are
	 * silent in the dangerous direction: an untranslated `*` reads as a literal
	 * and simply stops matching, with no error to notice. Measured against both
	 * evaluators, three dialects need translating:
	 *
	 * - `*` and `any` are buildiq wildcards. The shared grammar knows only `-`
	 *   and the empty cell.
	 * - `==7` is buildiq's equality. The shared grammar spells it `=7`.
	 * - `18..65` is a buildiq range. The shared grammar requires the brackets,
	 *   `[18..65]`, and rejects the bare form.
	 *
	 * Everything else (`>=`, `<`, `!=`, `in (...)`, bare literals, `-`, empty)
	 * is common to both and passes through untouched.
	 *
	 * @param string $cell The raw cell condition.
	 *
	 * @return string The cell in the shared grammar.
	 */
	private function sharedCell(string $cell): string {
		$trimmed = trim($cell);

		if (in_array(strtolower($trimmed), self::DONT_CARE, true) === true) {
			return '-';
		}

		if (str_starts_with($trimmed, '==') === true) {
			return '=' . substr($trimmed, 2);
		}

		if (str_contains($trimmed, '..') === true
			&& str_starts_with($trimmed, '[') === false
			&& str_starts_with($trimmed, '(') === false
		) {
			return '[' . $trimmed . ']';
		}

		return $trimmed;

	}//end sharedCell()

	/**
	 * Translate buildiq's hit policy into the shared evaluator's vocabulary.
	 *
	 * Buildiq spells its policies in lower case and treats anything it does not
	 * recognise as `first`. The shared evaluator spells them upper case and
	 * refuses what it does not implement, so the fallback has to happen here or
	 * a table with a typo'd policy would start erroring instead of deciding.
	 *
	 * @param string $hitPolicy buildiq's declared hit policy.
	 *
	 * @return string The shared evaluator's spelling.
	 */
	private function sharedHitPolicy(string $hitPolicy): string {
		$upper = strtoupper(trim($hitPolicy));
		if ($upper === 'RULE-ORDER') {
			return 'FIRST';
		}

		if (in_array($upper, ['UNIQUE', 'FIRST', 'COLLECT', 'PRIORITY', 'ANY'], true) === false) {
			return 'FIRST';
		}

		return $upper;

	}//end sharedHitPolicy()

	/**
	 * The result when no rule matched: the declared defaults, and no rule id.
	 *
	 * @param array<int,mixed> $outputs Output column definitions.
	 *
	 * @return array{outputColumns:array<string,mixed>,triggeredRuleId:string|null,matches:array<int,int>,overlap_warnings:array<int,string>,unreachable_rules:array<int,int>}
	 */
	private function noMatchResult(array $outputs): array {
		return $this->result(output: $this->defaultOutput(outputs: $outputs), ruleId: null, matched: []);

	}//end noMatchResult()

	/**
	 * Assemble the public result shape.
	 *
	 * `matches` reports what the shared evaluator returned. Under `collect`
	 * that is every matching rule, as before. Under the single-hit policies it
	 * is the winning rule only, where this method used to report every rule
	 * that matched. Nothing in buildiq reads the key, and neither does anything
	 * in its test suite, so the narrowing is recorded here rather than worked
	 * around.
	 *
	 * @param array<string,mixed> $output  The output column values.
	 * @param string|null         $ruleId  The triggered rule's label.
	 * @param array<int,int>      $matched The matching rule indexes.
	 *
	 * @return array{outputColumns:array<string,mixed>,triggeredRuleId:string|null,matches:array<int,int>,overlap_warnings:array<int,string>,unreachable_rules:array<int,int>}
	 */
	private function result(array $output, ?string $ruleId, array $matched): array {
		return [
			'outputColumns' => $output,
			'triggeredRuleId' => $ruleId,
			'matches' => $matched,
			'overlap_warnings' => [],
			'unreachable_rules' => [],
		];

	}//end result()

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
