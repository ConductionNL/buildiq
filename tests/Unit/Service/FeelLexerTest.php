<?php

/**
 * Unit tests for FeelLexer: tokenising FEEL-style expression source into kind/value/pos tokens.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\FeelLexer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FeelLexer.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\FeelLexer
 */
class FeelLexerTest extends TestCase {

	/**
	 * Reduce a token list to an array of [kind, value] pairs for terse assertions.
	 *
	 * @param array<int, array<string, mixed>> $tokens The tokens.
	 *
	 * @return array<int, array{0: string, 1: mixed}>
	 */
	private function pairs(array $tokens): array {
		return array_map(static fn (array $t): array => [$t['kind'], $t['value']], $tokens);
	}//end pairs()

	/**
	 * An empty source tokenises to a single eof token at position 0.
	 *
	 * @return void
	 */
	public function testEmptySourceYieldsOnlyEof(): void {
		$tokens = (new FeelLexer(''))->tokenize();

		$this->assertSame([['kind' => 'eof', 'value' => '', 'pos' => 0]], $tokens);
	}//end testEmptySourceYieldsOnlyEof()

	/**
	 * Whitespace is skipped and never emitted as a token.
	 *
	 * @return void
	 */
	public function testWhitespaceIsSkipped(): void {
		$tokens = (new FeelLexer("  \t a"))->tokenize();

		$this->assertSame(
			[['ident', 'a'], ['eof', '']],
			$this->pairs($tokens)
		);
		// The identifier keeps its real source position (after the whitespace).
		$this->assertSame(4, $tokens[0]['pos']);
	}//end testWhitespaceIsSkipped()

	/**
	 * A single-quoted string becomes one string token carrying the unquoted body.
	 *
	 * @return void
	 */
	public function testStringLiteralIsUnquoted(): void {
		$tokens = (new FeelLexer("'hello world'"))->tokenize();

		$this->assertSame([['string', 'hello world'], ['eof', '']], $this->pairs($tokens));
	}//end testStringLiteralIsUnquoted()

	/**
	 * An unterminated string literal reports a syntax error at its start position.
	 *
	 * @return void
	 */
	public function testUnterminatedStringThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Syntax error at position 0: unterminated string literal.');

		(new FeelLexer("'oops"))->tokenize();
	}//end testUnterminatedStringThrows()

	/**
	 * A run of digits is an int-valued number token; a decimal point makes it a float.
	 *
	 * @return void
	 */
	public function testIntegerAndFloatNumbers(): void {
		$int = (new FeelLexer('42'))->tokenize();
		$this->assertSame('number', $int[0]['kind']);
		$this->assertSame(42, $int[0]['value']);

		$float = (new FeelLexer('3.5'))->tokenize();
		$this->assertSame('number', $float[0]['kind']);
		$this->assertSame(3.5, $float[0]['value']);
	}//end testIntegerAndFloatNumbers()

	/**
	 * Two-character comparison operators are lexed as single op tokens.
	 *
	 * @return void
	 */
	public function testTwoCharOperators(): void {
		$tokens = (new FeelLexer('a == b != c <= d >= e'))->tokenize();

		$this->assertSame(
			[['ident', 'a'], ['op', '=='], ['ident', 'b'], ['op', '!='], ['ident', 'c'], ['op', '<='],
				['ident', 'd'], ['op', '>='], ['ident', 'e'], ['eof', '']],
			$this->pairs($tokens)
		);
	}//end testTwoCharOperators()

	/**
	 * Single-character operators and punctuation carry their own kind.
	 *
	 * @return void
	 */
	public function testSingleCharOperatorsAndPunctuation(): void {
		$tokens = (new FeelLexer('(1 + 2) < 3, 4'))->tokenize();

		$this->assertSame(
			[['punct', '('], ['number', 1], ['op', '+'], ['number', 2], ['punct', ')'], ['op', '<'],
				['number', 3], ['punct', ','], ['number', 4], ['eof', '']],
			$this->pairs($tokens)
		);
	}//end testSingleCharOperatorsAndPunctuation()

	/**
	 * Reserved words are keyword tokens (lower-cased); other words are identifiers (case kept).
	 *
	 * @return void
	 */
	public function testKeywordsVersusIdentifiers(): void {
		$tokens = (new FeelLexer('AND or Status in null'))->tokenize();

		$this->assertSame(
			[['keyword', 'and'], ['keyword', 'or'], ['ident', 'Status'], ['keyword', 'in'], ['keyword', 'null'],
				['eof', '']],
			$this->pairs($tokens)
		);
	}//end testKeywordsVersusIdentifiers()

	/**
	 * A dotted path is one identifier, but a `..` range separator ends the identifier and emits a range token.
	 *
	 * @return void
	 */
	public function testDottedPathAndRangeSeparator(): void {
		$path = (new FeelLexer('order.total'))->tokenize();
		$this->assertSame([['ident', 'order.total'], ['eof', '']], $this->pairs($path));

		$range = (new FeelLexer('1..5'))->tokenize();
		$this->assertSame([['number', 1], ['range', '..'], ['number', 5], ['eof', '']], $this->pairs($range));
	}//end testDottedPathAndRangeSeparator()

	/**
	 * A lone `=` is rejected with the equality hint.
	 *
	 * @return void
	 */
	public function testLoneEqualsThrowsWithHint(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('unknown operator "=" (use "==" for equality).');

		(new FeelLexer('a = b'))->tokenize();
	}//end testLoneEqualsThrowsWithHint()

	/**
	 * An unexpected character is rejected naming the character.
	 *
	 * @return void
	 */
	public function testUnexpectedCharacterThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('unexpected character "#".');

		(new FeelLexer('#'))->tokenize();
	}//end testUnexpectedCharacterThrows()
}//end class
