<?php

/**
 * OpenBuild ExpressionEvaluator
 *
 * Resolves a FeelParser AST against a runtime context (the input payload).
 * Field paths are looked up with dot-notation (`applicant.age` →
 * `$context['applicant']['age']`); missing fields resolve to null so that
 * `is null` checks behave intuitively. Comparison, range, list-membership,
 * logical and arithmetic nodes evaluate to PHP scalars.
 *
 * The evaluator is pure: it never mutates the context or performs I/O.
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
 * @spec openspec/changes/business-rules-engine/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use InvalidArgumentException;

/**
 * Evaluates parsed FEEL-subset expressions against a data payload.
 */
class ExpressionEvaluator
{

    /**
     * Maximum AST recursion depth during evaluation (DoS hardening,
     * harden-xss-dos-csrf). The parser's token cap already bounds the AST size,
     * so this is a defence-in-depth backstop that trips only on a
     * pathologically deep tree; it is set well above any tree a within-limits
     * expression can produce.
     */
    private const MAX_EVAL_DEPTH = 512;

    /**
     * Parser used to compile string expressions on demand.
     *
     * @var FeelParser
     */
    private FeelParser $parser;

    /**
     * Current recursion depth of {@see evaluate()}.
     *
     * @var integer
     */
    private int $depth = 0;

    /**
     * Constructor.
     *
     * @param FeelParser|null $parser Optional injected parser (defaults to a fresh one).
     *
     * @return void
     */
    public function __construct(?FeelParser $parser=null)
    {
        $this->parser = ($parser ?? new FeelParser());

    }//end __construct()

    /**
     * Compile and evaluate a string expression against a context.
     *
     * @param string              $expression The FEEL-subset source.
     * @param array<string,mixed> $context    The data payload.
     *
     * @return mixed The evaluation result.
     *
     * @throws InvalidArgumentException On a syntax or evaluation error.
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    public function evaluateExpression(string $expression, array $context): mixed
    {
        $node = $this->parser->parse(expression: $expression);
        return $this->evaluate(node: $node, context: $context);

    }//end evaluateExpression()

    /**
     * Evaluate a parsed AST node against a context.
     *
     * @param array<string,mixed> $node    The AST node.
     * @param array<string,mixed> $context The data payload.
     *
     * @return mixed The evaluation result.
     *
     * @throws InvalidArgumentException On an unknown node type.
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    public function evaluate(array $node, array $context): mixed
    {
        ++$this->depth;
        try {
            if ($this->depth > self::MAX_EVAL_DEPTH) {
                throw new InvalidArgumentException(
                    'Expression is too deeply nested to evaluate (max depth '.self::MAX_EVAL_DEPTH.').'
                );
            }

            return $this->evaluateNode(node: $node, context: $context);
        } finally {
            --$this->depth;
        }

    }//end evaluate()

    /**
     * Dispatch a single AST node (depth-guarded by {@see evaluate()}).
     *
     * @param array<string,mixed> $node    The AST node.
     * @param array<string,mixed> $context The data payload.
     *
     * @return mixed The evaluation result.
     *
     * @throws InvalidArgumentException On an unknown node type.
     */
    private function evaluateNode(array $node, array $context): mixed
    {
        switch (($node['type'] ?? '')) {
            case 'literal':
                return $node['value'];

            case 'path':
                return $this->resolvePath(segments: $node['segments'], context: $context);

            case 'logical':
                return $this->evaluateLogical(node: $node, context: $context);

            case 'not':
                $operandValue = $this->evaluate(node: $node['operand'], context: $context);
                return ($this->truthy(value: $operandValue) === false);

            case 'binary':
                return $this->evaluateBinary(node: $node, context: $context);

            case 'range':
                // A bare range used as a boolean is meaningless; the engine
                // only consumes ranges via the column-condition matcher. When
                // evaluated standalone, return the [low, high] pair.
                return [
                    'low'  => $this->evaluate(node: $node['low'], context: $context),
                    'high' => $this->evaluate(node: $node['high'], context: $context),
                ];

            case 'in':
                return $this->evaluateIn(node: $node, context: $context);

            case 'is-null':
                $value  = $this->evaluate(node: $node['operand'], context: $context);
                $isNull = ($value === null);
                if ($node['negated'] === true) {
                    return ($isNull === false);
                }
                return $isNull;

            default:
                throw new InvalidArgumentException('Unknown AST node type: '.(string) ($node['type'] ?? 'null'));
        }//end switch

    }//end evaluateNode()

    /**
     * Resolve a dot-notation field path against the context.
     *
     * @param array<int,string>   $segments The path segments.
     * @param array<string,mixed> $context  The data payload.
     *
     * @return mixed The resolved value, or null when any segment is missing.
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    private function resolvePath(array $segments, array $context): mixed
    {
        $cursor = $context;
        foreach ($segments as $segment) {
            if (is_array($cursor) === true && array_key_exists($segment, $cursor) === true) {
                $cursor = $cursor[$segment];
                continue;
            }

            return null;
        }

        return $cursor;

    }//end resolvePath()

    /**
     * Evaluate a logical `and` / `or` node with short-circuiting.
     *
     * @param array<string,mixed> $node    The logical node.
     * @param array<string,mixed> $context The data payload.
     *
     * @return bool
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    private function evaluateLogical(array $node, array $context): bool
    {
        $leftValue = $this->evaluate(node: $node['left'], context: $context);
        $left      = $this->truthy(value: $leftValue);

        if ($node['op'] === 'and') {
            if ($left === false) {
                return false;
            }

            $rightValue = $this->evaluate(node: $node['right'], context: $context);
            return $this->truthy(value: $rightValue);
        }

        if ($left === true) {
            return true;
        }

        $rightValue = $this->evaluate(node: $node['right'], context: $context);
        return $this->truthy(value: $rightValue);

    }//end evaluateLogical()

    /**
     * Evaluate a binary comparison or arithmetic node.
     *
     * @param array<string,mixed> $node    The binary node.
     * @param array<string,mixed> $context The data payload.
     *
     * @return mixed bool for comparisons, int|float for arithmetic.
     *
     * @throws InvalidArgumentException On division by zero.
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    private function evaluateBinary(array $node, array $context): mixed
    {
        $left  = $this->evaluate(node: $node['left'], context: $context);
        $right = $this->evaluate(node: $node['right'], context: $context);
        $op    = $node['op'];

        switch ($op) {
            case '==':
                return $this->looseEquals(left: $left, right: $right);
            case '!=':
                return ($this->looseEquals(left: $left, right: $right) === false);
            case '<':
                return ($left < $right);
            case '>':
                return ($left > $right);
            case '<=':
                return ($left <= $right);
            case '>=':
                return ($left >= $right);
            case '+':
                return ($this->num(value: $left) + $this->num(value: $right));
            case '-':
                return ($this->num(value: $left) - $this->num(value: $right));
            case '*':
                return ($this->num(value: $left) * $this->num(value: $right));
            case '/':
                $divisor = $this->num(value: $right);
                if ($divisor === 0 || $divisor === 0.0) {
                    throw new InvalidArgumentException('Division by zero in expression.');
                }
                return ($this->num(value: $left) / $divisor);
            default:
                throw new InvalidArgumentException('Unknown binary operator: '.(string) $op);
        }//end switch

    }//end evaluateBinary()

    /**
     * Evaluate an `in (...)` list/range membership node.
     *
     * @param array<string,mixed> $node    The `in` node.
     * @param array<string,mixed> $context The data payload.
     *
     * @return bool
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    private function evaluateIn(array $node, array $context): bool
    {
        $needle = $this->evaluate(node: $node['operand'], context: $context);
        foreach ($node['list'] as $entry) {
            if (($entry['type'] ?? '') === 'range') {
                $low  = $this->evaluate(node: $entry['low'], context: $context);
                $high = $this->evaluate(node: $entry['high'], context: $context);
                if ($needle >= $low && $needle <= $high) {
                    return true;
                }

                continue;
            }

            $candidate = $this->evaluate(node: $entry, context: $context);
            if ($this->looseEquals(left: $needle, right: $candidate) === true) {
                return true;
            }
        }//end foreach

        return false;

    }//end evaluateIn()

    /**
     * Loose equality that tolerates numeric-string vs numeric payloads while
     * avoiding PHP's `==` operator (banned by the coding standard).
     *
     * @param mixed $left  Left operand.
     * @param mixed $right Right operand.
     *
     * @return bool
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    private function looseEquals(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) === true && is_numeric($right) === true) {
            return (((float) $left <=> (float) $right) === 0);
        }

        if (is_scalar($left) === true && is_scalar($right) === true) {
            return ((string) $left === (string) $right);
        }

        return ($left === $right);

    }//end looseEquals()

    /**
     * Coerce a value to a number for arithmetic.
     *
     * @param mixed $value The value to coerce.
     *
     * @return int|float
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    private function num(mixed $value): int|float
    {
        if (is_int($value) === true || is_float($value) === true) {
            return $value;
        }

        if (is_numeric($value) === true) {
            return ($value + 0);
        }

        return 0;

    }//end num()

    /**
     * Coerce a value to a boolean using PHP truthiness, treating null as false.
     *
     * @param mixed $value The value to test.
     *
     * @return bool
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#2.2
     */
    private function truthy(mixed $value): bool
    {
        return (bool) $value;

    }//end truthy()
}//end class
