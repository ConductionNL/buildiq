<?php

/**
 * Golden test for ExpressionEvaluator's comparison and arithmetic results.
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

use OCA\Buildiq\Service\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Pins the value and PHP type of every binary operator over ints, floats,
 * numeric and non-numeric strings, null, arrays and booleans, every other
 * node type (not, range, in, is-null, logical), plus the division-by-zero,
 * unknown-operator and unknown-node-type errors.
 *
 * @covers \OCA\Buildiq\Service\ExpressionEvaluator
 * @uses   \OCA\Buildiq\Service\FeelParser
 * @uses \OCA\Buildiq\Service\FeelLexer
 */
final class ExpressionEvaluatorBinaryGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../Fixtures/expression-evaluator-binary-golden.json';

	/**
	 * Every recorded expression evaluates, or fails, as recorded.
	 *
	 * @return void
	 */
	public function testBinaryOperatorsEvaluateAsRecorded(): void {
		$fixture = json_decode((string)file_get_contents(self::FIXTURE), true);
		$evaluator = new ExpressionEvaluator();

		foreach ($fixture['cases'] as $expression => $expected) {
			$this->assertSame($expected, $this->outcome(evaluator: $evaluator, expression: (string)$expression, context: $fixture['context']), 'Expression: ' . $expression);
		}

	}//end testBinaryOperatorsEvaluateAsRecorded()

	/**
	 * Evaluate one case; `raw …` cases are hand-built nodes the parser never emits.
	 *
	 * @param ExpressionEvaluator $evaluator  The evaluator.
	 * @param string              $expression The expression or case name.
	 * @param array<string,mixed> $context    The data payload.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(ExpressionEvaluator $evaluator, string $expression, array $context): array {
		try {
			$rawNodes = [
				'raw %' => ['type' => 'binary', 'op' => '%', 'left' => ['type' => 'literal', 'value' => 1], 'right' => ['type' => 'literal', 'value' => 2]],
				'raw mystery' => ['type' => 'mystery'],
				'raw untyped' => [],
			];
			if (array_key_exists($expression, $rawNodes) === true) {
				$value = $evaluator->evaluate($rawNodes[$expression], []);
			} else {
				$value = $evaluator->evaluateExpression($expression, $context);
			}
		} catch (Throwable $e) {
			return ['error' => get_class($e) . ': ' . $e->getMessage()];
		}

		return ['type' => gettype($value), 'value' => $value];

	}//end outcome()
}//end class
