<?php

/**
 * Buildiq FeelLexer
 *
 * Converts a FEEL-subset expression source into the token stream that
 * FeelParser consumes. One lexer reads one source string.
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
 * Lexer for the business-rules FEEL subset.
 *
 * Token shape: ['kind' => string, 'value' => mixed, 'pos' => int], where kind
 * is one of string, number, range, op, punct, ident, keyword or eof.
 */
final class FeelLexer {

	/**
	 * Operators spelled with two characters.
	 */
	private const TWO_CHAR_OPERATORS = ['==', '!=', '<=', '>='];

	/**
	 * Single-character operators and punctuation, mapped to their token kind.
	 */
	private const SINGLE_CHAR_KINDS = [
		'<' => 'op',
		'>' => 'op',
		'+' => 'op',
		'-' => 'op',
		'*' => 'op',
		'/' => 'op',
		'(' => 'punct',
		')' => 'punct',
		',' => 'punct',
	];

	/**
	 * Words that lex as keywords (matched case-insensitively).
	 */
	private const KEYWORDS = ['and', 'or', 'not', 'in', 'is', 'null', 'true', 'false'];

	/**
	 * The source length in bytes.
	 *
	 * @var integer
	 */
	private int $len;

	/**
	 * Cursor into the source.
	 *
	 * @var integer
	 */
	private int $cursor = 0;

	/**
	 * Build a lexer for one expression source.
	 *
	 * @param string $src The expression source.
	 */
	public function __construct(
		private readonly string $src,
	) {
		$this->len = strlen($src);

	}//end __construct()

	/**
	 * Convert the source into a token stream ending in an `eof` token.
	 *
	 * @return array<int,array{kind:string,value:mixed,pos:int}>
	 *
	 * @throws InvalidArgumentException On an unknown character or operator.
	 */
	public function tokenize(): array {
		$tokens = [];
		while ($this->cursor < $this->len) {
			$token = $this->nextToken();
			if ($token !== null) {
				$tokens[] = $token;
			}
		}

		$tokens[] = ['kind' => 'eof', 'value' => '', 'pos' => $this->len];
		return $tokens;

	}//end tokenize()

	/**
	 * Read the token at the cursor, or skip one whitespace character.
	 *
	 * @return array{kind:string,value:mixed,pos:int}|null Null for skipped whitespace.
	 */
	private function nextToken(): ?array {
		$ch = $this->src[$this->cursor];
		if (ctype_space($ch) === true) {
			++$this->cursor;
			return null;
		}

		if ($ch === "'") {
			return $this->lexString();
		}

		if (ctype_digit($ch) === true) {
			return $this->lexNumber();
		}

		return $this->lexSymbolOrWord(ch: $ch);

	}//end nextToken()

	/**
	 * Read a single-quoted string literal.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 *
	 * @throws InvalidArgumentException When the closing quote is missing.
	 */
	private function lexString(): array {
		$start = $this->cursor;
		++$this->cursor;
		$buf = '';
		while ($this->cursor < $this->len && $this->src[$this->cursor] !== "'") {
			$buf .= $this->src[$this->cursor];
			++$this->cursor;
		}

		if ($this->cursor >= $this->len) {
			throw new InvalidArgumentException('Syntax error at position ' . $start . ': unterminated string literal.');
		}

		++$this->cursor;
		return ['kind' => 'string', 'value' => $buf, 'pos' => $start];

	}//end lexString()

	/**
	 * Read an integer or decimal number literal; a `..` range separator is
	 * never swallowed as a decimal point.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 */
	private function lexNumber(): array {
		$start = $this->cursor;
		$buf = '';
		while ($this->cursor < $this->len
			&& (ctype_digit($this->src[$this->cursor]) === true || $this->isDecimalPointAt(pos: $this->cursor) === true)
		) {
			$buf .= $this->src[$this->cursor];
			++$this->cursor;
		}

		$value = (int)$buf;
		if (str_contains($buf, '.') === true) {
			$value = (float)$buf;
		}

		return ['kind' => 'number', 'value' => $value, 'pos' => $start];

	}//end lexNumber()

	/**
	 * Read a range separator, an operator, punctuation or a word.
	 *
	 * @param string $ch The character at the cursor.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 *
	 * @throws InvalidArgumentException On an unknown character or operator.
	 */
	private function lexSymbolOrWord(string $ch): array {
		if ($this->isRangeAt(pos: $this->cursor) === true) {
			return $this->consume(token: $this->tokenAt(kind: 'range', value: '..'), width: 2);
		}

		$two = $this->peekTwo();
		if (in_array($two, self::TWO_CHAR_OPERATORS, true) === true) {
			return $this->consume(token: $this->tokenAt(kind: 'op', value: $two), width: 2);
		}

		if (array_key_exists($ch, self::SINGLE_CHAR_KINDS) === true) {
			return $this->consume(token: $this->tokenAt(kind: self::SINGLE_CHAR_KINDS[$ch], value: $ch), width: 1);
		}

		return $this->lexWord(ch: $ch);

	}//end lexSymbolOrWord()

	/**
	 * Read an identifier, keyword or dotted field path.
	 *
	 * @param string $ch The character at the cursor.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 *
	 * @throws InvalidArgumentException On a lone `=` or any other unknown character.
	 */
	private function lexWord(string $ch): array {
		// A lone `=` is a common mistake (assignment vs equality).
		if ($ch === '=') {
			throw new InvalidArgumentException(
				'Syntax error at position ' . $this->cursor . ': unknown operator "=" (use "==" for equality).'
			);
		}

		if (ctype_alpha($ch) === true || $ch === '_') {
			return $this->lexIdentifier();
		}

		throw new InvalidArgumentException(
			'Syntax error at position ' . $this->cursor . ': unexpected character "' . $ch . '".'
		);

	}//end lexWord()

	/**
	 * Read identifier characters up to a non-identifier character or a `..`.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 */
	private function lexIdentifier(): array {
		$start = $this->cursor;
		$buf = '';
		while ($this->cursor < $this->len && $this->isIdentifierChar(ch: $this->src[$this->cursor]) === true) {
			// Stop a path on a `..` range separator.
			if ($this->isRangeAt(pos: $this->cursor) === true) {
				break;
			}

			$buf .= $this->src[$this->cursor];
			++$this->cursor;
		}

		return $this->wordToken(word: $buf, start: $start);

	}//end lexIdentifier()

	/**
	 * Classify a word as a keyword (lower-cased) or an identifier (as written).
	 *
	 * @param string  $word  The word as written.
	 * @param integer $start The word's position.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 */
	private function wordToken(string $word, int $start): array {
		$lower = strtolower($word);
		if (in_array($lower, self::KEYWORDS, true) === true) {
			return ['kind' => 'keyword', 'value' => $lower, 'pos' => $start];
		}

		return ['kind' => 'ident', 'value' => $word, 'pos' => $start];

	}//end wordToken()

	/**
	 * Whether a character may appear in an identifier or dotted path.
	 *
	 * @param string $ch The character.
	 *
	 * @return boolean
	 */
	private function isIdentifierChar(string $ch): bool {
		return ctype_alnum($ch) === true || $ch === '_' || $ch === '.';

	}//end isIdentifierChar()

	/**
	 * Whether a `..` range separator starts at a position.
	 *
	 * @param integer $pos The position.
	 *
	 * @return boolean
	 */
	private function isRangeAt(int $pos): bool {
		return $this->src[$pos] === '.' && ($pos + 1) < $this->len && $this->src[($pos + 1)] === '.';

	}//end isRangeAt()

	/**
	 * Whether a position holds a decimal point (a `.` not followed by `.`).
	 *
	 * @param integer $pos The position.
	 *
	 * @return boolean
	 */
	private function isDecimalPointAt(int $pos): bool {
		return $this->src[$pos] === '.' && ($pos + 1) < $this->len && $this->src[($pos + 1)] !== '.';

	}//end isDecimalPointAt()

	/**
	 * The two characters at the cursor, or '' when fewer than two remain.
	 *
	 * @return string
	 */
	private function peekTwo(): string {
		if (($this->cursor + 1) < $this->len) {
			return substr($this->src, $this->cursor, 2);
		}

		return '';

	}//end peekTwo()

	/**
	 * Build a token positioned at the cursor.
	 *
	 * @param string $kind  The token kind.
	 * @param string $value The token value.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 */
	private function tokenAt(string $kind, string $value): array {
		return ['kind' => $kind, 'value' => $value, 'pos' => $this->cursor];

	}//end tokenAt()

	/**
	 * Move the cursor past a token and return it.
	 *
	 * @param array{kind:string,value:mixed,pos:int} $token The token at the cursor.
	 * @param integer                                $width The token's width in characters.
	 *
	 * @return array{kind:string,value:mixed,pos:int}
	 */
	private function consume(array $token, int $width): array {
		$this->cursor += $width;
		return $token;

	}//end consume()
}//end class
