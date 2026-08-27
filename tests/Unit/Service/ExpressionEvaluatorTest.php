<?php

/**
 * Unit tests for ExpressionEvaluator.
 *
 * Covers REQ-BRE-011: comparison, range, list, logical, arithmetic and null
 * evaluation plus dot-notation field-path resolution against a payload.
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
use OCA\Buildiq\Service\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ExpressionEvaluator}.
 */
final class ExpressionEvaluatorTest extends TestCase {

	/**
	 * The evaluator under test.
	 *
	 * @var ExpressionEvaluator
	 */
	private ExpressionEvaluator $evaluator;

	/**
	 * Build a fresh evaluator before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->evaluator = new ExpressionEvaluator();

	}//end setUp()

	/**
	 * Comparison against a present field resolves correctly.
	 *
	 * @return void
	 */
	public function testComparisonTrue(): void {
		$this->assertTrue($this->evaluator->evaluateExpression('age >= 18', ['age' => 25]));

	}//end testComparisonTrue()

	/**
	 * Comparison failing the threshold returns false.
	 *
	 * @return void
	 */
	public function testComparisonFalse(): void {
		$this->assertFalse($this->evaluator->evaluateExpression('age >= 18', ['age' => 17]));

	}//end testComparisonFalse()

	/**
	 * Dot-notation resolves nested payload fields.
	 *
	 * @return void
	 */
	public function testNestedPathResolution(): void {
		$context = ['applicant' => ['age' => 40, 'income' => 3000]];
		$this->assertTrue($this->evaluator->evaluateExpression('applicant.income > 2000', $context));

	}//end testNestedPathResolution()

	/**
	 * A missing field resolves to null so `is null` is true.
	 *
	 * @return void
	 */
	public function testIsNullOnMissingField(): void {
		$this->assertTrue($this->evaluator->evaluateExpression('email is null', []));
		$this->assertFalse($this->evaluator->evaluateExpression('email is not null', []));

	}//end testIsNullOnMissingField()

	/**
	 * Logical and/or short-circuit correctly.
	 *
	 * @return void
	 */
	public function testLogicalCombination(): void {
		$context = ['age' => 20, 'income' => 1000];
		$this->assertFalse($this->evaluator->evaluateExpression('age >= 18 and income >= 2000', $context));
		$this->assertTrue($this->evaluator->evaluateExpression('age >= 18 or income >= 2000', $context));

	}//end testLogicalCombination()

	/**
	 * List membership matches against literal candidates.
	 *
	 * @return void
	 */
	public function testListMembership(): void {
		$this->assertTrue(
			$this->evaluator->evaluateExpression("status in ('open', 'pending')", ['status' => 'pending'])
		);
		$this->assertFalse(
			$this->evaluator->evaluateExpression("status in ('open', 'pending')", ['status' => 'closed'])
		);

	}//end testListMembership()

	/**
	 * Range membership via `in` matches inclusive bounds.
	 *
	 * @return void
	 */
	public function testRangeMembership(): void {
		$this->assertTrue($this->evaluator->evaluateExpression('age in (18..65)', ['age' => 40]));
		$this->assertFalse($this->evaluator->evaluateExpression('age in (18..65)', ['age' => 70]));

	}//end testRangeMembership()

	/**
	 * Arithmetic evaluates with correct precedence.
	 *
	 * @return void
	 */
	public function testArithmetic(): void {
		$this->assertSame(7, $this->evaluator->evaluateExpression('1 + 2 * 3', []));
		$this->assertTrue($this->evaluator->evaluateExpression('total - discount > 100', ['total' => 200, 'discount' => 50]));

	}//end testArithmetic()

	/**
	 * Division by zero is rejected.
	 *
	 * @return void
	 */
	public function testDivisionByZero(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->evaluator->evaluateExpression('amount / 0', ['amount' => 10]);

	}//end testDivisionByZero()

	/**
	 * `not` negates a sub-expression.
	 *
	 * @return void
	 */
	public function testNotNegation(): void {
		$this->assertTrue($this->evaluator->evaluateExpression('not (age >= 18)', ['age' => 10]));

	}//end testNotNegation()

	/**
	 * DoS backstop: a hand-built AST nested beyond the evaluator's maximum depth
	 * is refused rather than recursing until the stack is exhausted.
	 *
	 * @return void
	 */
	public function testRejectsDeeplyNestedAst(): void {
		// Raise Xdebug's dev-only stack-depth abort so the evaluator's OWN depth
		// guard is what trips (production has no Xdebug nesting limit).
		if (extension_loaded('xdebug') === true) {
			@ini_set('xdebug.max_nesting_level', '100000');
		}

		$node = ['type' => 'literal', 'value' => true];
		for ($i = 0; $i < 600; $i++) {
			$node = ['type' => 'not', 'operand' => $node];
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('too deeply nested');
		$this->evaluator->evaluate($node, []);

	}//end testRejectsDeeplyNestedAst()
}//end class
