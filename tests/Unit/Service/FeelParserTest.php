<?php

/**
 * Unit tests for FeelParser.
 *
 * Covers REQ-BRE-011: valid expressions, invalid operators, range parsing,
 * null checks, list membership and operator precedence.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\FeelParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see FeelParser}.
 */
final class FeelParserTest extends TestCase {

	/**
	 * The parser under test.
	 *
	 * @var FeelParser
	 */
	private FeelParser $parser;

	/**
	 * Build a fresh parser before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->parser = new FeelParser();

	}//end setUp()

	/**
	 * A simple comparison parses to a binary node.
	 *
	 * @return void
	 */
	public function testParsesComparison(): void {
		$ast = $this->parser->parse('age >= 18');
		$this->assertSame('binary', $ast['type']);
		$this->assertSame('>=', $ast['op']);
		$this->assertSame(['age'], $ast['left']['segments']);
		$this->assertSame(18, $ast['right']['value']);

	}//end testParsesComparison()

	/**
	 * Dotted field paths split into segments.
	 *
	 * @return void
	 */
	public function testParsesFieldPath(): void {
		$ast = $this->parser->parse('applicant.age > 0');
		$this->assertSame(['applicant', 'age'], $ast['left']['segments']);

	}//end testParsesFieldPath()

	/**
	 * `and` binds tighter than `or` (precedence).
	 *
	 * @return void
	 */
	public function testOperatorPrecedence(): void {
		// a or b and c  ===  a or (b and c)
		$ast = $this->parser->parse('a == 1 or b == 2 and c == 3');
		$this->assertSame('logical', $ast['type']);
		$this->assertSame('or', $ast['op']);
		$this->assertSame('logical', $ast['right']['type']);
		$this->assertSame('and', $ast['right']['op']);

	}//end testOperatorPrecedence()

	/**
	 * A range literal parses to a range node.
	 *
	 * @return void
	 */
	public function testParsesRange(): void {
		$ast = $this->parser->parse('5..10');
		$this->assertSame('range', $ast['type']);
		$this->assertSame(5, $ast['low']['value']);
		$this->assertSame(10, $ast['high']['value']);

	}//end testParsesRange()

	/**
	 * List membership parses to an `in` node.
	 *
	 * @return void
	 */
	public function testParsesListMembership(): void {
		$ast = $this->parser->parse("status in ('open', 'pending', 'new')");
		$this->assertSame('in', $ast['type']);
		$this->assertCount(3, $ast['list']);
		$this->assertSame('open', $ast['list'][0]['value']);

	}//end testParsesListMembership()

	/**
	 * Null checks parse to is-null nodes, honouring negation.
	 *
	 * @return void
	 */
	public function testParsesNullChecks(): void {
		$ast = $this->parser->parse('email is null');
		$this->assertSame('is-null', $ast['type']);
		$this->assertFalse($ast['negated']);

		$ast2 = $this->parser->parse('email is not null');
		$this->assertTrue($ast2['negated']);

	}//end testParsesNullChecks()

	/**
	 * A lone `=` is rejected with a positional message.
	 *
	 * @return void
	 */
	public function testRejectsSingleEquals(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('use "==" for equality');
		$this->parser->parse('age = 18');

	}//end testRejectsSingleEquals()

	/**
	 * An empty expression is a syntax error.
	 *
	 * @return void
	 */
	public function testRejectsEmptyExpression(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->parser->parse('   ');

	}//end testRejectsEmptyExpression()

	/**
	 * An unterminated string literal is a syntax error.
	 *
	 * @return void
	 */
	public function testRejectsUnterminatedString(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->parser->parse("name == 'unclosed");

	}//end testRejectsUnterminatedString()

	/**
	 * Parenthesised groups override precedence.
	 *
	 * @return void
	 */
	public function testParenthesisedGroup(): void {
		$ast = $this->parser->parse('(a == 1 or b == 2) and c == 3');
		$this->assertSame('logical', $ast['type']);
		$this->assertSame('and', $ast['op']);
		$this->assertSame('logical', $ast['left']['type']);
		$this->assertSame('or', $ast['left']['op']);

	}//end testParenthesisedGroup()

	/**
	 * DoS guard: an expression longer than the maximum source length is rejected
	 * before tokenising.
	 *
	 * @return void
	 */
	public function testRejectsOverLengthExpression(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('maximum length');
		$this->parser->parse(str_repeat('a', 5000));

	}//end testRejectsOverLengthExpression()

	/**
	 * DoS guard: an expression nested beyond the maximum depth is rejected
	 * before the PHP call stack is exhausted.
	 *
	 * @return void
	 */
	public function testRejectsOverDeepNesting(): void {
		$deep = str_repeat('(', 200) . '1' . str_repeat(')', 200);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('depth');
		$this->parser->parse($deep);

	}//end testRejectsOverDeepNesting()

	/**
	 * DoS guard: an expression with more than the maximum token count is
	 * rejected (this also bounds the AST node count). The chain stays flat so
	 * the depth guard does not trip first.
	 *
	 * @return void
	 */
	public function testRejectsTooManyTokens(): void {
		$flat = '1' . str_repeat(' + 1', 300);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('too many tokens');
		$this->parser->parse($flat);

	}//end testRejectsTooManyTokens()
}//end class
