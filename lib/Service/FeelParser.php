<?php

/**
 * OpenBuild FeelParser
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
 * @spec openspec/changes/business-rules-engine/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

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
class FeelParser
{

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
    public function parse(string $expression): array
    {
        $this->tokens = $this->tokenize(src: $expression);
        $this->cursor = 0;

        if ($this->tokens === []) {
            throw new InvalidArgumentException('Syntax error: empty expression.');
        }

        $node = $this->parseOr();

        if ($this->peek()['kind'] !== 'eof') {
            $tok = $this->peek();
            throw new InvalidArgumentException(
                'Syntax error at position '.$tok['pos'].': unexpected token "'.(string) $tok['value'].'".'
            );
        }

        return $node;

    }//end parse()

    /**
     * Lexer — convert the source string into a token stream.
     *
     * @param string $src The expression source.
     *
     * @return array<int,array{kind:string,value:mixed,pos:int}>
     *
     * @throws InvalidArgumentException On an unknown character or operator.
     */
    private function tokenize(string $src): array
    {
        $tokens = [];
        $len    = strlen($src);
        $i      = 0;

        while ($i < $len) {
            $ch = $src[$i];

            if (ctype_space($ch) === true) {
                ++$i;
                continue;
            }

            // Single-quoted string literal.
            if ($ch === "'") {
                $start = $i;
                ++$i;
                $buf = '';
                while ($i < $len && $src[$i] !== "'") {
                    $buf .= $src[$i];
                    ++$i;
                }

                if ($i >= $len) {
                    throw new InvalidArgumentException(
                        'Syntax error at position '.$start.': unterminated string literal.'
                    );
                }

                ++$i;
                $tokens[] = ['kind' => 'string', 'value' => $buf, 'pos' => $start];
                continue;
            }

            // Number literal (integer or decimal). A double-dot range separator
            // must not be swallowed as a decimal point.
            if (ctype_digit($ch) === true) {
                $start = $i;
                $buf   = '';
                while ($i < $len
                    && (ctype_digit($src[$i]) === true
                    || ($src[$i] === '.' && ($i + 1) < $len && $src[($i + 1)] !== '.'))
                ) {
                    $buf .= $src[$i];
                    ++$i;
                }

                $value = (int) $buf;
                if (str_contains($buf, '.') === true) {
                    $value = (float) $buf;
                }

                $tokens[] = ['kind' => 'number', 'value' => $value, 'pos' => $start];
                continue;
            }

            // Range separator `..`.
            if ($ch === '.' && ($i + 1) < $len && $src[($i + 1)] === '.') {
                $tokens[] = ['kind' => 'range', 'value' => '..', 'pos' => $i];
                $i       += 2;
                continue;
            }

            // Multi-character operators.
            $two = '';
            if (($i + 1) < $len) {
                $two = substr($src, $i, 2);
            }

            if (in_array($two, ['==', '!=', '<=', '>='], true) === true) {
                $tokens[] = ['kind' => 'op', 'value' => $two, 'pos' => $i];
                $i       += 2;
                continue;
            }

            // Single-character operators / punctuation.
            if (in_array($ch, ['<', '>', '+', '-', '*', '/', '(', ')', ','], true) === true) {
                $tokenKind = 'op';
                if (in_array($ch, ['(', ')', ','], true) === true) {
                    $tokenKind = 'punct';
                }

                $tokens[] = ['kind' => $tokenKind, 'value' => $ch, 'pos' => $i];
                ++$i;
                continue;
            }

            // A lone `=` is a common mistake (assignment vs equality).
            if ($ch === '=') {
                throw new InvalidArgumentException(
                    'Syntax error at position '.$i.': unknown operator "=" (use "==" for equality).'
                );
            }

            // Identifiers, keywords and dotted field paths.
            if (ctype_alpha($ch) === true || $ch === '_') {
                $start = $i;
                $buf   = '';
                while ($i < $len
                    && (ctype_alnum($src[$i]) === true || $src[$i] === '_' || $src[$i] === '.')
                ) {
                    // Stop a path on a `..` range separator.
                    if ($src[$i] === '.' && ($i + 1) < $len && $src[($i + 1)] === '.') {
                        break;
                    }

                    $buf .= $src[$i];
                    ++$i;
                }

                $lower = strtolower($buf);
                if (in_array($lower, ['and', 'or', 'not', 'in', 'is', 'null', 'true', 'false'], true) === true) {
                    $tokens[] = ['kind' => 'keyword', 'value' => $lower, 'pos' => $start];
                } else {
                    $tokens[] = ['kind' => 'ident', 'value' => $buf, 'pos' => $start];
                }

                continue;
            }//end if

            throw new InvalidArgumentException(
                'Syntax error at position '.$i.': unexpected character "'.$ch.'".'
            );
        }//end while

        $tokens[] = ['kind' => 'eof', 'value' => '', 'pos' => $len];
        return $tokens;

    }//end tokenize()

    /**
     * Peek at the current token without consuming it.
     *
     * @return array{kind:string,value:mixed,pos:int}
     */
    private function peek(): array
    {
        return $this->tokens[$this->cursor];

    }//end peek()

    /**
     * Consume and return the current token.
     *
     * @return array{kind:string,value:mixed,pos:int}
     */
    private function next(): array
    {
        $tok = $this->tokens[$this->cursor];
        ++$this->cursor;
        return $tok;

    }//end next()

    /**
     * Parse an `or`-precedence expression (lowest binding).
     *
     * @return array<string,mixed>
     */
    private function parseOr(): array
    {
        $left = $this->parseAnd();
        while ($this->peek()['kind'] === 'keyword' && $this->peek()['value'] === 'or') {
            $this->next();
            $right = $this->parseAnd();
            $left  = ['type' => 'logical', 'op' => 'or', 'left' => $left, 'right' => $right];
        }

        return $left;

    }//end parseOr()

    /**
     * Parse an `and`-precedence expression.
     *
     * @return array<string,mixed>
     */
    private function parseAnd(): array
    {
        $left = $this->parseNot();
        while ($this->peek()['kind'] === 'keyword' && $this->peek()['value'] === 'and') {
            $this->next();
            $right = $this->parseNot();
            $left  = ['type' => 'logical', 'op' => 'and', 'left' => $left, 'right' => $right];
        }

        return $left;

    }//end parseAnd()

    /**
     * Parse a `not`-prefixed expression.
     *
     * @return array<string,mixed>
     */
    private function parseNot(): array
    {
        if ($this->peek()['kind'] === 'keyword' && $this->peek()['value'] === 'not') {
            $this->next();
            return ['type' => 'not', 'operand' => $this->parseNot()];
        }

        return $this->parseComparison();

    }//end parseNot()

    /**
     * Parse a comparison, range-membership, list-membership or null check.
     *
     * @return array<string,mixed>
     */
    private function parseComparison(): array
    {
        $left = $this->parseAddition();

        $tok = $this->peek();

        // `is null` / `is not null`.
        if ($tok['kind'] === 'keyword' && $tok['value'] === 'is') {
            $this->next();
            $negated = false;
            if ($this->peek()['kind'] === 'keyword' && $this->peek()['value'] === 'not') {
                $this->next();
                $negated = true;
            }

            $nullTok = $this->next();
            if ($nullTok['kind'] !== 'keyword' || $nullTok['value'] !== 'null') {
                throw new InvalidArgumentException(
                    'Syntax error at position '.$nullTok['pos'].': expected "null" after "is".'
                );
            }

            return ['type' => 'is-null', 'operand' => $left, 'negated' => $negated];
        }

        // `in (a, b, c)`.
        if ($tok['kind'] === 'keyword' && $tok['value'] === 'in') {
            $this->next();
            $this->expectPunct(value: '(');
            $list = [];
            if ($this->peek()['value'] !== ')') {
                $list[] = $this->parseAddition();
                while ($this->peek()['kind'] === 'punct' && $this->peek()['value'] === ',') {
                    $this->next();
                    $list[] = $this->parseAddition();
                }
            }

            $this->expectPunct(value: ')');
            return ['type' => 'in', 'operand' => $left, 'list' => $list];
        }

        // Comparison operators.
        if ($tok['kind'] === 'op' && in_array($tok['value'], ['==', '!=', '<', '>', '<=', '>='], true) === true) {
            $this->next();
            $right = $this->parseAddition();

            // Range on the right-hand side: `age in 5..10` is written `age 5..10`
            // only inside list/range contexts; a bare `a..b` becomes a range node
            // handled in parsePrimary. Here we just build the comparison.
            return ['type' => 'binary', 'op' => $tok['value'], 'left' => $left, 'right' => $right];
        }

        return $left;

    }//end parseComparison()

    /**
     * Parse `+` / `-` arithmetic.
     *
     * @return array<string,mixed>
     */
    private function parseAddition(): array
    {
        $left = $this->parseMultiplication();
        while ($this->peek()['kind'] === 'op' && in_array($this->peek()['value'], ['+', '-'], true) === true) {
            $op    = $this->next()['value'];
            $right = $this->parseMultiplication();
            $left  = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;

    }//end parseAddition()

    /**
     * Parse `*` / `/` arithmetic.
     *
     * @return array<string,mixed>
     */
    private function parseMultiplication(): array
    {
        $left = $this->parseRange();
        while ($this->peek()['kind'] === 'op' && in_array($this->peek()['value'], ['*', '/'], true) === true) {
            $op    = $this->next()['value'];
            $right = $this->parseRange();
            $left  = ['type' => 'binary', 'op' => $op, 'left' => $left, 'right' => $right];
        }

        return $left;

    }//end parseMultiplication()

    /**
     * Parse a `low..high` range, falling through to a primary.
     *
     * @return array<string,mixed>
     */
    private function parseRange(): array
    {
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
    private function parsePrimary(): array
    {
        $tok = $this->next();

        switch ($tok['kind']) {
            case 'number':
                return ['type' => 'literal', 'value' => $tok['value']];
            case 'string':
                return ['type' => 'literal', 'value' => (string) $tok['value']];
            case 'ident':
                return ['type' => 'path', 'segments' => explode('.', (string) $tok['value'])];
            case 'keyword':
                if ($tok['value'] === 'true') {
                    return ['type' => 'literal', 'value' => true];
                }

                if ($tok['value'] === 'false') {
                    return ['type' => 'literal', 'value' => false];
                }

                if ($tok['value'] === 'null') {
                    return ['type' => 'literal', 'value' => null];
                }

                if ($tok['value'] === 'not') {
                    return ['type' => 'not', 'operand' => $this->parsePrimary()];
                }
                break;
            case 'punct':
                if ($tok['value'] === '(') {
                    $node = $this->parseOr();
                    $this->expectPunct(value: ')');
                    return $node;
                }
                break;
            case 'op':
                // Unary minus.
                if ($tok['value'] === '-') {
                    $operand = $this->parsePrimary();
                    return ['type' => 'binary', 'op' => '-', 'left' => ['type' => 'literal', 'value' => 0], 'right' => $operand];
                }
                break;
            default:
                break;
        }//end switch

        throw new InvalidArgumentException(
            'Syntax error at position '.$tok['pos'].': unexpected token "'.(string) $tok['value'].'".'
        );

    }//end parsePrimary()

    /**
     * Consume an expected punctuation token or fail.
     *
     * @param string $value The expected punctuation char.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the next token does not match.
     */
    private function expectPunct(string $value): void
    {
        $tok = $this->next();
        if ($tok['kind'] !== 'punct' || $tok['value'] !== $value) {
            throw new InvalidArgumentException(
                'Syntax error at position '.$tok['pos'].': expected "'.$value.'".'
            );
        }

    }//end expectPunct()
}//end class
