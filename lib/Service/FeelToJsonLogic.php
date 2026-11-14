<?php

/**
 * Buildiq FeelToJsonLogic
 *
 * Translates a FEEL-subset condition into the JSONLogic an OpenRegister
 * `openregister.filter` node evaluates, so an automation's condition gates
 * the flow it compiles to.
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
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Walks the AST FeelParser::parse() returns and emits JSONLogic (design D6).
 *
 * The output keeps ExpressionEvaluator's meaning, so a condition answers the
 * same in the rules engine and in a flow:
 *
 *   - ordering comparisons and arithmetic map to the JSONLogic operator of the
 *     same name;
 *   - `==` and `!=` keep the evaluator's rule that null equals only null:
 *     against a literal the other side must not be null, against a literal
 *     null the test is `===`, and between two fields both or neither are null;
 *   - `and`, `or` and `not` map to `and`, `or` and `!`, with a chain of one
 *     operator flattened into one list;
 *   - `is null` / `is not null` map to `===` / `!==` against null;
 *   - `x in (a, b, 1..5)` maps to an `or` of an equality per item and an inclusive
 *     `>=` / `<=` pair per range, the comparison ExpressionEvaluator makes;
 *   - a field path maps to `{"var": "json.<path>"}`, the flow item's data.
 *
 * One difference stays: a boolean compared with a string (`flag == 'x'`)
 * follows PHP's loose comparison in JSONLogic, where the evaluator compares
 * their text.
 *
 * A range outside an `in` list has no subject to compare, and a node it does
 * not know has no JSONLogic form: both are refused with the part named,
 * rather than guessed.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
 */
class FeelToJsonLogic {

	/**
	 * FEEL operators and the JSONLogic operator each becomes.
	 *
	 * @var array<string, string>
	 */
	private const BINARY_OPERATORS = [
		'==' => '==',
		'!=' => '!=',
		'<' => '<',
		'>' => '>',
		'<=' => '<=',
		'>=' => '>=',
		'+' => '+',
		'-' => '-',
		'*' => '*',
		'/' => '/',
	];

	/**
	 * Where a flow item keeps the record a condition reads.
	 *
	 * @var string
	 */
	private const DATA_ROOT = 'json';

	/**
	 * Constructor.
	 *
	 * @param FeelParser $parser Parses the expression.
	 */
	public function __construct(
		private readonly FeelParser $parser,
	) {

	}//end __construct()

	/**
	 * Translate a FEEL condition into JSONLogic.
	 *
	 * @param string $expression The condition.
	 *
	 * @return mixed The JSONLogic rule: an operator object, or a literal.
	 *
	 * @throws InvalidArgumentException When the expression does not parse or has no JSONLogic form.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
	 */
	public function translate(string $expression): mixed {
		return $this->translateNode(node: $this->parser->parse($expression));
	}//end translate()

	/**
	 * Translate one parsed node.
	 *
	 * @param array<string, mixed> $node A FeelParser AST node.
	 *
	 * @return mixed
	 *
	 * @throws InvalidArgumentException When the node has no JSONLogic form.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
	 */
	public function translateNode(array $node): mixed {
		$type = (string) ($node['type'] ?? '');

		return match ($type) {
			'literal' => $node['value'],
			'path' => self::variable(segments: (array) ($node['segments'] ?? [])),
			'binary' => $this->binary(node: $node),
			'logical' => $this->logical(node: $node),
			'not' => ['!' => [$this->translateNode(node: (array) $node['operand'])]],
			'is-null' => $this->nullCheck(node: $node),
			'in' => $this->membership(node: $node),
			'range' => throw new InvalidArgumentException(
				sprintf(
					'Cannot translate the range %s on its own: use it in a list, as in "x in (%s)".',
					self::describe(node: $node),
					self::describe(node: $node)
				)
			),
			default => throw new InvalidArgumentException(sprintf('Cannot translate the expression part "%s".', $type)),
		};
	}//end translateNode()

	/**
	 * A comparison or a calculation.
	 *
	 * @param array<string, mixed> $node The binary node.
	 *
	 * @return array<string, list<mixed>>
	 */
	private function binary(array $node): array {
		$operator = (string) ($node['op'] ?? '');
		if (isset(self::BINARY_OPERATORS[$operator]) === false) {
			throw new InvalidArgumentException(sprintf('Cannot translate the operator "%s".', $operator));
		}

		$left  = (array) $node['left'];
		$right = (array) $node['right'];
		if ($operator === '==') {
			return $this->equals(left: $left, right: $right);
		}

		if ($operator === '!=') {
			return ['!' => [$this->equals(left: $left, right: $right)]];
		}

		return [self::BINARY_OPERATORS[$operator] => [$this->translateNode(node: $left), $this->translateNode(node: $right)]];
	}//end binary()

	/**
	 * Equality as ExpressionEvaluator decides it: null equals only null,
	 * anything else compares loosely. A missing field reads as null in both,
	 * so `amount == 0` does not pass a record without an amount.
	 *
	 * @param array<string, mixed> $left  The left node.
	 * @param array<string, mixed> $right The right node.
	 *
	 * @return array<string, list<mixed>>
	 */
	private function equals(array $left, array $right): array {
		$one = $this->translateNode(node: $left);
		$two = $this->translateNode(node: $right);
		if (self::isNullLiteral(node: $left) === true || self::isNullLiteral(node: $right) === true) {
			return ['===' => [$one, $two]];
		}

		if (($left['type'] ?? null) === 'literal') {
			return ['and' => [['!==' => [$two, null]], ['==' => [$one, $two]]]];
		}

		if (($right['type'] ?? null) === 'literal') {
			return ['and' => [['!==' => [$one, null]], ['==' => [$one, $two]]]];
		}

		return [
			'or' => [
				['and' => [['===' => [$one, null]], ['===' => [$two, null]]]],
				['and' => [['!==' => [$one, null]], ['!==' => [$two, null]], ['==' => [$one, $two]]]],
			],
		];
	}//end equals()

	/**
	 * Whether a node is the literal null.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return bool
	 */
	private static function isNullLiteral(array $node): bool {
		return ($node['type'] ?? null) === 'literal' && array_key_exists('value', $node) === true && $node['value'] === null;
	}//end isNullLiteral()

	/**
	 * `and` / `or`, a chain of the same operator flattened into one list.
	 *
	 * @param array<string, mixed> $node The logical node.
	 *
	 * @return array<string, list<mixed>>
	 */
	private function logical(array $node): array {
		$operator = (string) $node['op'];
		if ($operator !== 'and' && $operator !== 'or') {
			throw new InvalidArgumentException(sprintf('Cannot translate the operator "%s".', $operator));
		}

		$operands = [];
		foreach ([(array) $node['left'], (array) $node['right']] as $side) {
			$translated = $this->translateNode(node: $side);
			if (($side['type'] ?? null) === 'logical' && ($side['op'] ?? null) === $operator) {
				$operands = array_merge($operands, $translated[$operator]);
				continue;
			}

			$operands[] = $translated;
		}

		return [$operator => $operands];
	}//end logical()

	/**
	 * `is null` / `is not null`, strict as ExpressionEvaluator's check is.
	 *
	 * @param array<string, mixed> $node The null-check node.
	 *
	 * @return array<string, list<mixed>>
	 */
	private function nullCheck(array $node): array {
		$operator = '===';
		if (($node['negated'] ?? false) === true) {
			$operator = '!==';
		}

		return [$operator => [$this->translateNode(node: (array) $node['operand']), null]];
	}//end nullCheck()

	/**
	 * `x in (…)`: true when x equals an item or lies within a range item.
	 *
	 * @param array<string, mixed> $node The in node.
	 *
	 * @return array<string, list<mixed>>|false
	 */
	private function membership(array $node): array|false {
		$subject = $this->translateNode(node: (array) $node['operand']);
		$tests   = [];
		foreach ((array) $node['list'] as $item) {
			$item = (array) $item;
			if (($item['type'] ?? null) === 'range') {
				$tests[] = [
					'and' => [
						['>=' => [$subject, $this->translateNode(node: (array) $item['low'])]],
						['<=' => [$subject, $this->translateNode(node: (array) $item['high'])]],
					],
				];
				continue;
			}

			$tests[] = $this->equals(left: (array) $node['operand'], right: $item);
		}

		if ($tests === []) {
			return false;
		}

		if (count($tests) === 1) {
			return $tests[0];
		}

		return ['or' => $tests];
	}//end membership()

	/**
	 * A field path as a JSONLogic variable on the flow item's data.
	 *
	 * @param array<int, mixed> $segments The path segments.
	 *
	 * @return array<string, string>
	 */
	private static function variable(array $segments): array {
		foreach ($segments as $segment) {
			if (is_string($segment) === false || $segment === '') {
				throw new InvalidArgumentException('Cannot translate a field path with an empty part.');
			}
		}

		if ($segments === []) {
			throw new InvalidArgumentException('Cannot translate an empty field path.');
		}

		return ['var' => self::DATA_ROOT.'.'.implode('.', $segments)];
	}//end variable()

	/**
	 * A short text for a node, to name it in a refusal.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return string
	 */
	private static function describe(array $node): string {
		return match ((string) ($node['type'] ?? '')) {
			'literal' => var_export($node['value'], true),
			'path' => implode('.', (array) $node['segments']),
			'range' => self::describe(node: (array) $node['low']).'..'.self::describe(node: (array) $node['high']),
			default => (string) ($node['type'] ?? '?'),
		};
	}//end describe()
}//end class
