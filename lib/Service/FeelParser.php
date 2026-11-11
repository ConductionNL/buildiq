<?php

/**
 * Buildiq FeelParser
 *
 * Tokenises and parses the FEEL (Friendly Enough Expression Language) subset
 * used by the business-rules engine for decision-table cell conditions and
 * condition-action rule conditions. The supported subset (design.md Decision 2)
 * is deliberately small and auditable:
 *
 *   - Comparisons:      == != < > <= >=
 *   - Ranges:           5..10 (inclusive)
 *   - Lists:            in (1, 2, 3)
 *   - Logical:          and or not
 *   - Null checks:      is null / is not null
 *   - Arithmetic:       + - * /
 *   - Literals:         numbers, single-quoted strings, true/false/null
 *   - Field paths:      dot-notation (applicant.age)
 *
 * It SHALL NOT support string interpolation, function calls, or user-defined
 * functions — those belong in n8n workflows or backend services.
 *
 * parse() returns an immutable array AST consumed by ExpressionEvaluator.
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
 * @spec openspec/changes/business-rules-engine/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * Recursive-descent parser for the business-rules FEEL subset.
 *
 * AST node shapes (all plain arrays so they serialise and cache cleanly):
 *   ['type' => 'literal',    'value' => mixed]
 *   ['type' => 'path',       'segments' => string[]]
 *   ['type' => 'binary',     'op' => string, 'left' => node, 'right' => node]
 *   ['type' => 'logical',    'op' => 'and'|'or', 'left' => node, 'right' => node]
 *   ['type' => 'not',        'operand' => node]
 *   ['type' => 'range',      'low' => node, 'high' => node]
 *   ['type' => 'in',         'operand' => node, 'list' => node[]]
 *   ['type' => 'is-null',    'operand' => node, 'negated' => bool]
 */
class FeelParser {

	/**
	 * Maximum source length in characters (DoS hardening, harden-xss-dos-csrf).
	 *
	 * A FEEL expression is authored rule metadata, so 4 KiB is generous for any
	 * legitimate condition while rejecting a multi-megabyte string that would
	 * build an unbounded token array.
	 */
	private const MAX_SOURCE_LENGTH = 4096;

	/**
	 * Maximum token count. Also bounds the AST node count (nodes <= tokens), so
	 * a flat but enormous chain (`a or a or a …`) cannot build a deep tree that
	 * would overflow the evaluator's recursion.
	 */
	private const MAX_TOKENS = 512;

	/**
	 * Maximum recursion depth across the re-entrant parse methods. Bounds the
	 * PHP call stack against pathological nesting (`((((…))))`, `not not …`,
	 * `- - …`) which would otherwise recurse without limit.
	 */
	private const MAX_DEPTH = 128;

	/**
	 * Operators that build a comparison node.
	 */
	private const COMPARISON_OPERATORS = ['==', '!=', '<', '>', '<=', '>='];

	/**
	 * Keywords that parse as a literal, mapped to their value.
	 */
	private const KEYWORD_LITERALS = ['true' => true, 'false' => false, 'null' => null];

	/**
	 * The tokens produced by the lexer for the expression under parse.
	 *
	 * @var array<int,array{kind:string,value:mixed,pos:int}>
	 */
	private array $tokens = [];

	/**
	 * Cursor into $tokens.
	 *
	 * @var integer
	 */
	private int $cursor = 0;

	/**
	 * Current recursion depth across the re-entrant parse methods.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * Parse a FEEL-subset expression into an AST.
	 *
	 * Re-parsing an already-parsed expression is supported because the input
	 * is always a string; the engine never feeds an AST back into parse().
	 *
	 * @param string $expression The FEEL expression source.
	 *
	 * @return array<string,mixed> The root AST node.
	 *
	 * @throws InvalidArgumentException On any syntax error, with the offending position.
	 */
	public function parse(string $expression): array {
		$this->assertSourceLength(expression: $expression);

		$this->tokens = $this->tokenize(src: $expression);
		$this->cursor = 0;
		$this->depth = 0;

		$this->assertTokenCount();

		$node = $this->parseOr();
		$this->assertFullyConsumed();

		return $node;
	}//end parse()

	/**
	 * DoS guard: reject an over-length source before tokenising it.
	 *
	 * @param string $expression The FEEL expression source.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the source is too long.
	 */
	private function assertSourceLength(string $expression): void {
		if (strlen($expression) > self::MAX_SOURCE_LENGTH) {
			throw new InvalidArgumentException(
				'Syntax error: expression exceeds the maximum length of ' . self::MAX_SOURCE_LENGTH . ' characters.'
			);
		}

	}//end assertSourceLength()

	/**
	 * Reject an empty token stream and, as a DoS guard, one with too many
	 * tokens (the `eof` token is always present, so allow MAX_TOKENS + 1).
	 * This transitively bounds the AST node count.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On an empty or over-long token stream.
	 */
	private function assertTokenCount(): void {
		if ($this->tokens === []) {
			throw new InvalidArgumentException('Syntax error: empty expression.');
		}

		if (count($this->tokens) > (self::MAX_TOKENS + 1)) {
			throw new InvalidArgumentException(
				'Syntax error: expression has too many tokens (max ' . self::MAX_TOKENS . ').'
			);
		}

	}//end assertTokenCount()

	/**
	 * Reject trailing tokens after a complete expression.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a token other than `eof` remains.
	 */
	private function assertFullyConsumed(): void {
		if ($this->peek()['kind'] !== 'eof') {
			$tok = $this->peek();
			throw new InvalidArgumentException(
				'Syntax error at position ' . $tok['pos'] . ': unexpected token "' . (string)$tok['value'] . '".'
			);
		}

	}//end assertFullyConsumed()

	/**
	 * Lexer — convert the source string into a token stream.
	 *
	 * @param string $src The expression source.
	 *
	 * @return array<int,array{kind:string,value:mixed,pos:int}>
	 *
	 * @throws InvalidArgumentException On an unknown character or operator.
	 */
	private function tokenize(string $src): array {
		return (new FeelLexer(src: $src))->tokenize();

	}//end tokenize()

	/**
	 * Peek at the current token without consuming it.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 */
	private function peek(): array {
		return $this->tokens[$this->cursor];
	}//end peek()

	/**
	 * Consume and return the current token.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 */
	private function next(): array {
		$tok = $this->tokens[$this->cursor];
		++$this->cursor;
		return $tok;
	}//end next()

	/**
	 * Enter a recursive parse step, enforcing the maximum nesting depth.
	 *
	 * Call once at the top of each re-entrant parse method and pair with a
	 * `--$this->depth` in a `finally` so the counter tracks live stack depth.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the nesting depth cap is exceeded.
	 */
	private function enterDepth(): void {
		++$this->depth;
		if ($this->depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException(
				'Syntax error: expression nesting exceeds the maximum depth of ' . self::MAX_DEPTH . '.'
			);
		}

	}//end enterDepth()

	/**
	 * Parse an `or`-precedence expression (lowest binding).
	 *
	 * @return array<string,mixed>
	 */
	private function parseOr(): array {
		$this->enterDepth();
		try {
			$left = $this->parseAnd();
			while ($this->peek()['kind'] === 'keyword' && $this->peek()['value'] === 'or') {
				$this->next();
				$right = $this->parseAnd();
				$left = ['type' => 'logical', 'op' => 'or', 'left' => $left, 'right' => $right];
			}

			return $left;
		} finally {
			--$this->depth;
		}

	}//end parseOr()

	/**
	 * Parse an `and`-precedence expression.
	 *
	 * @return array<string,mixed>
	 */
	private function parseAnd(): array {
		$left = $this->parseNot();
		while ($this->peek()['kind'] === 'keyword' && $this->peek()['value'] === 'and') {
			$this->next();
			$right = $this->parseNot();
			$left = ['type' => 'logical', 'op' => 'and', 'left' => $left, 'right' => $right];
		}

		return $left;
	}//end parseAnd()

	/**
	 * Parse a `not`-prefixed expression.
	 *
	 * @return array<string,mixed>
	 */
	private function parseNot(): array {
		$this->enterDepth();
		try {
			if ($this->peek()['kind'] === 'keyword' && $this->peek()['value'] === 'not') {
				$this->next();
				return ['type' => 'not', 'operand' => $this->parseNot()];
			}

			return $this->parseComparison();
		} finally {
			--$this->depth;
		}

	}//end parseNot()

	/**
	 * Parse a comparison, range-membership, list-membership or null check.
	 *
	 * @return array<string,mixed>
	 */
	private function parseComparison(): array {
		$left = $this->parseAddition();

		$tok = $this->peek();

		if ($this->isKeyword(token: $tok, value: 'is') === true) {
			return $this->parseNullCheck(operand: $left);
		}

		if ($this->isKeyword(token: $tok, value: 'in') === true) {
			return $this->parseListMembership(operand: $left);
		}

		// Comparison operators. A bare `a..b` becomes a range node in
		// parseRange; here we just build the comparison.
		if ($tok['kind'] === 'op' && in_array($tok['value'], self::COMPARISON_OPERATORS, true) === true) {
			return $this->parseComparisonRight(left: $left);
		}

		return $left;
	}//end parseComparison()

	/**
	 * Consume a comparison operator and parse its right-hand side.
	 *
	 * @param array<string,mixed> $left The already-parsed left-hand side.
	 *
	 * @return array<string,mixed>
	 */
	private function parseComparisonRight(array $left): array {
		$op = $this->next()['value'];
		$right = $this->parseAddition();
		return ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];

	}//end parseComparisonRight()

	/**
	 * Parse `is null` / `is not null` after its operand.
	 *
	 * @param array<string,mixed> $operand The already-parsed left-hand side.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException When `null` does not follow.
	 */
	private function parseNullCheck(array $operand): array {
		$this->next();
		$negated = false;
		if ($this->isKeyword(token: $this->peek(), value: 'not') === true) {
			$this->next();
			$negated = true;
		}

		$nullTok = $this->next();
		if ($this->isKeyword(token: $nullTok, value: 'null') === false) {
			throw new InvalidArgumentException(
				'Syntax error at position ' . $nullTok['pos'] . ': expected "null" after "is".'
			);
		}

		return ['type' => 'is-null', 'operand' => $operand, 'negated' => $negated];

	}//end parseNullCheck()

	/**
	 * Parse `in (a, b, c)` after its operand.
	 *
	 * @param array<string,mixed> $operand The already-parsed left-hand side.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException When a parenthesis is missing.
	 */
	private function parseListMembership(array $operand): array {
		$this->next();
		$this->expectPunct(value: '(');
		$list = $this->parseListItems();
		$this->expectPunct(value: ')');
		return ['type' => 'in', 'operand' => $operand, 'list' => $list];

	}//end parseListMembership()

	/**
	 * Parse the comma-separated items of a list, stopping before `)`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parseListItems(): array {
		if ($this->peek()['value'] === ')') {
			return [];
		}

		$list = [$this->parseAddition()];
		while ($this->peek()['kind'] === 'punct' && $this->peek()['value'] === ',') {
			$this->next();
			$list[] = $this->parseAddition();
		}

		return $list;

	}//end parseListItems()

	/**
	 * Whether a token is a given keyword.
	 *
	 * @param array{kind:string,value:mixed,pos:int} $token The token.
	 * @param string                                 $value The keyword.
	 *
	 * @return boolean
	 */
	private function isKeyword(array $token, string $value): bool {
		return $token['kind'] === 'keyword' && $token['value'] === $value;

	}//end isKeyword()

	/**
	 * Parse `+` / `-` arithmetic.
	 *
	 * @return array<string,mixed>
	 */
	private function parseAddition(): array {
		$left = $this->parseMultiplication();
		while ($this->peek()['kind'] === 'op' && in_array($this->peek()['value'], ['+', '-'], true) === true) {
			$op = $this->next()['value'];
			$right = $this->parseMultiplication();
			$left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
		}

		return $left;
	}//end parseAddition()

	/**
	 * Parse `*` / `/` arithmetic.
	 *
	 * @return array<string,mixed>
	 */
	private function parseMultiplication(): array {
		$left = $this->parseRange();
		while ($this->peek()['kind'] === 'op' && in_array($this->peek()['value'], ['*', '/'], true) === true) {
			$op = $this->next()['value'];
			$right = $this->parseRange();
			$left = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
		}

		return $left;
	}//end parseMultiplication()

	/**
	 * Parse a `low..high` range, falling through to a primary.
	 *
	 * @return array<string,mixed>
	 */
	private function parseRange(): array {
		$left = $this->parsePrimary();
		if ($this->peek()['kind'] === 'range') {
			$this->next();
			$high = $this->parsePrimary();
			return ['type' => 'range', 'low' => $left, 'high' => $high];
		}

		return $left;
	}//end parseRange()

	/**
	 * Parse a primary: literal, field path, or parenthesised expression.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException On an unexpected token.
	 */
	private function parsePrimary(): array {
		$this->enterDepth();
		try {
			return $this->parsePrimaryInner();
		} finally {
			--$this->depth;
		}

	}//end parsePrimary()

	/**
	 * Inner body of {@see parsePrimary()} (depth-guarded by its caller).
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException On an unexpected token.
	 */
	private function parsePrimaryInner(): array {
		$tok = $this->next();

		$node = $this->primaryNode(token: $tok);
		if ($node === null) {
			throw new InvalidArgumentException(
				'Syntax error at position ' . $tok['pos'] . ': unexpected token "' . (string)$tok['value'] . '".'
			);
		}

		return $node;

	}//end parsePrimaryInner()

	/**
	 * Build the primary node a consumed token starts.
	 *
	 * @param array{kind:string,value:mixed,pos:int} $token The consumed token.
	 *
	 * @return array<string,mixed>|null Null when the token cannot start a primary.
	 */
	private function primaryNode(array $token): ?array {
		return match ($token['kind']) {
			'number' => ['type' => 'literal', 'value' => $token['value']],
			'string' => ['type' => 'literal', 'value' => (string)$token['value']],
			'ident' => ['type' => 'path', 'segments' => explode('.', (string)$token['value'])],
			'keyword' => $this->keywordPrimary(keyword: (string)$token['value']),
			'punct' => $this->groupPrimary(punct: (string)$token['value']),
			'op' => $this->unaryMinusPrimary(op: (string)$token['value']),
			default => null,
		};

	}//end primaryNode()

	/**
	 * Build a `true` / `false` / `null` literal or a `not` node.
	 *
	 * @param string $keyword The consumed keyword.
	 *
	 * @return array<string,mixed>|null Null for any other keyword.
	 */
	private function keywordPrimary(string $keyword): ?array {
		if (array_key_exists($keyword, self::KEYWORD_LITERALS) === true) {
			return ['type' => 'literal', 'value' => self::KEYWORD_LITERALS[$keyword]];
		}

		if ($keyword === 'not') {
			return ['type' => 'not', 'operand' => $this->parsePrimary()];
		}

		return null;

	}//end keywordPrimary()

	/**
	 * Parse a parenthesised expression after its `(`.
	 *
	 * @param string $punct The consumed punctuation.
	 *
	 * @return array<string,mixed>|null Null for any punctuation other than `(`.
	 */
	private function groupPrimary(string $punct): ?array {
		if ($punct !== '(') {
			return null;
		}

		$node = $this->parseOr();
		$this->expectPunct(value: ')');
		return $node;

	}//end groupPrimary()

	/**
	 * Parse a unary minus as `0 - operand`.
	 *
	 * @param string $op The consumed operator.
	 *
	 * @return array<string,mixed>|null Null for any operator other than `-`.
	 */
	private function unaryMinusPrimary(string $op): ?array {
		if ($op !== '-') {
			return null;
		}

		$operand = $this->parsePrimary();
		return ['type' => 'binary', 'op' => '-', 'left' => ['type' => 'literal', 'value' => 0], 'right' => $operand];

	}//end unaryMinusPrimary()

	/**
	 * Consume an expected punctuation token or fail.
	 *
	 * @param string $value The expected punctuation char.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the next token does not match.
	 */
	private function expectPunct(string $value): void {
		$tok = $this->next();
		if ($tok['kind'] !== 'punct' || $tok['value'] !== $value) {
			throw new InvalidArgumentException(
				'Syntax error at position ' . $tok['pos'] . ': expected "' . $value . '".'
			);
		}

	}//end expectPunct()
}//end class
