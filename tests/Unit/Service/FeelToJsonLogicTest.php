<?php

/**
 * Unit tests for FeelToJsonLogic.
 *
 * Every construct FeelParser's header lists, translated to JSONLogic, and the
 * refusals. A small JSONLogic reader, written to json-logic-php's semantics
 * for the operators the translator emits, checks that a translated condition
 * answers as ExpressionEvaluator does on the same record.
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
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\Buildiq\Service\FeelParser;
use OCA\Buildiq\Service\FeelToJsonLogic;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Service\FeelToJsonLogic
 * @uses   \OCA\Buildiq\Service\FeelParser
 * @uses   \OCA\Buildiq\Service\ExpressionEvaluator
 */
final class FeelToJsonLogicTest extends TestCase {

	private FeelToJsonLogic $translator;

	protected function setUp(): void {
		$this->translator = new FeelToJsonLogic(parser: new FeelParser());
	}//end setUp()

	/**
	 * Each construct and the JSONLogic it becomes.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function constructs(): array {
		return [
			'comparison ==' => ['status == \'open\'', ['and' => [['!==' => [['var' => 'json.status'], null]], ['==' => [['var' => 'json.status'], 'open']]]]],
			'comparison !=' => ['status != \'closed\'', ['!' => [['and' => [['!==' => [['var' => 'json.status'], null]], ['==' => [['var' => 'json.status'], 'closed']]]]]]],
			'comparison >' => ['bedrag > 1000', ['>' => [['var' => 'json.bedrag'], 1000]]],
			'comparison <=' => ['bedrag <= 10.5', ['<=' => [['var' => 'json.bedrag'], 10.5]]],
			'comparison < and >=' => ['a < 1 and b >= 2', ['and' => [['<' => [['var' => 'json.a'], 1]], ['>=' => [['var' => 'json.b'], 2]]]]],
			'range in a list' => ['age in (18..65)', ['and' => [['>=' => [['var' => 'json.age'], 18]], ['<=' => [['var' => 'json.age'], 65]]]]],
			'list' => [
				'code in (1, 2, \'x\')',
				['or' => [['and' => [['!==' => [['var' => 'json.code'], null]], ['==' => [['var' => 'json.code'], 1]]]], ['and' => [['!==' => [['var' => 'json.code'], null]], ['==' => [['var' => 'json.code'], 2]]]], ['and' => [['!==' => [['var' => 'json.code'], null]], ['==' => [['var' => 'json.code'], 'x']]]]]],
			],
			'list with a range' => [
				'n in (0, 5..9)',
				['or' => [['and' => [['!==' => [['var' => 'json.n'], null]], ['==' => [['var' => 'json.n'], 0]]]], ['and' => [['>=' => [['var' => 'json.n'], 5]], ['<=' => [['var' => 'json.n'], 9]]]]]],
			],
			'and chain flattened' => ['a and b and c', ['and' => [['var' => 'json.a'], ['var' => 'json.b'], ['var' => 'json.c']]]],
			'or chain flattened' => ['a or b or c', ['or' => [['var' => 'json.a'], ['var' => 'json.b'], ['var' => 'json.c']]]],
			'mixed and/or keeps precedence' => ['a or b and c', ['or' => [['var' => 'json.a'], ['and' => [['var' => 'json.b'], ['var' => 'json.c']]]]]],
			'not' => ['not approved', ['!' => [['var' => 'json.approved']]]],
			'is null' => ['manager is null', ['===' => [['var' => 'json.manager'], null]]],
			'is not null' => ['manager is not null', ['!==' => [['var' => 'json.manager'], null]]],
			'== null is strict' => ['manager == null', ['===' => [['var' => 'json.manager'], null]]],
			'!= null is strict' => ['null != manager', ['!' => [['===' => [null, ['var' => 'json.manager']]]]]],
			'two fields: both null or both set and equal' => [
				'a == b',
				['or' => [['and' => [['===' => [['var' => 'json.a'], null]], ['===' => [['var' => 'json.b'], null]]]], ['and' => [['!==' => [['var' => 'json.a'], null]], ['!==' => [['var' => 'json.b'], null]], ['==' => [['var' => 'json.a'], ['var' => 'json.b']]]]]]],
			],
			'arithmetic' => [
				'a + b * 2 - c / 4 > 10',
				['>' => [['-' => [['+' => [['var' => 'json.a'], ['*' => [['var' => 'json.b'], 2]]]], ['/' => [['var' => 'json.c'], 4]]]], 10]],
			],
			'unary minus' => ['saldo < -5', ['<' => [['var' => 'json.saldo'], ['-' => [0, 5]]]]],
			'literals' => ['true', true],
			'string literal' => ['\'ja\'', 'ja'],
			'null literal' => ['null', null],
			'nested path' => ['applicant.address.city == \'Utrecht\'', ['and' => [['!==' => [['var' => 'json.applicant.address.city'], null]], ['==' => [['var' => 'json.applicant.address.city'], 'Utrecht']]]]],
		];
	}//end constructs()

	/**
	 * @dataProvider constructs
	 */
	public function testEachConstructTranslates(string $expression, mixed $expected): void {
		$this->assertSame($expected, $this->translator->translate(expression: $expression));
	}//end testEachConstructTranslates()

	public function testALoneRangeIsRefusedNamingIt(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot translate the range 5..10 on its own: use it in a list, as in "x in (5..10)".');

		$this->translator->translate(expression: '5..10');
	}//end testALoneRangeIsRefusedNamingIt()

	public function testAnUnknownNodeIsRefusedNamingIt(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot translate the expression part "call".');

		$this->translator->translateNode(node: ['type' => 'call', 'name' => 'now']);
	}//end testAnUnknownNodeIsRefusedNamingIt()

	public function testAnUnknownOperatorIsRefusedNamingIt(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot translate the operator "%".');

		$this->translator->translateNode(node: ['type' => 'binary', 'op' => '%', 'left' => ['type' => 'literal', 'value' => 1], 'right' => ['type' => 'literal', 'value' => 2]]);
	}//end testAnUnknownOperatorIsRefusedNamingIt()

	public function testAnExpressionThatDoesNotParseIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->translator->translate(expression: 'bedrag >');
	}//end testAnExpressionThatDoesNotParseIsRefused()

	/**
	 * Records the agreement test reads conditions against.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function records(): array {
		return [
			['bedrag' => 500, 'status' => 'open', 'age' => 30, 'manager' => null, 'code' => '2', 'saldo' => -10, 'a' => 3, 'b' => 4, 'c' => 8],
			['bedrag' => 1500, 'status' => 'closed', 'age' => 70, 'manager' => 'jan', 'code' => 'x', 'saldo' => 0, 'a' => 0, 'b' => 0, 'c' => 0],
			['bedrag' => '1000.0', 'status' => '', 'age' => 18, 'manager' => '', 'code' => 7, 'a' => 1, 'b' => 2],
			[],
		];
	}//end records()

	public function testTranslatedConditionsAnswerAsTheRulesEngineDoes(): void {
		$evaluator  = new ExpressionEvaluator(new FeelParser());
		$conditions = [
			'bedrag > 1000', 'bedrag >= 1000', 'bedrag == 1000', 'status == \'open\'', 'status != \'open\'', 'age in (18..65)',
			'code in (1, 2, \'x\')', 'manager is null', 'manager is not null', 'manager == null', 'not (bedrag > 1000)',
			'bedrag > 1000 or status == \'open\' and age < 40', 'saldo < -5', 'a + b * 2 - c / 4 > 5', 'a and b', 'age in (0, 65..99)',
			'saldo == 0', 'a == c', 'manager != \'\'', 'code in (null, 7)',
		];

		foreach ($conditions as $condition) {
			$logic = $this->translator->translate(expression: $condition);
			foreach (self::records() as $index => $record) {
				$this->assertSame(
					(bool) $evaluator->evaluateExpression($condition, $record),
					self::truthy(value: self::apply(logic: $logic, data: ['json' => $record])),
					sprintf('"%s" on record %d', $condition, $index)
				);
			}
		}
	}//end testTranslatedConditionsAnswerAsTheRulesEngineDoes()

	/**
	 * JSONLogic's truthiness, as json-logic-php has it: an empty list is false.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function truthy(mixed $value): bool {
		if (is_array($value) === true) {
			return $value !== [];
		}

		return (bool) $value;
	}//end truthy()

	/**
	 * Apply JSONLogic as json-logic-php does, for the operators the translator emits.
	 *
	 * @param mixed                $logic The rule.
	 * @param array<string, mixed> $data  The data.
	 *
	 * @return mixed
	 */
	private static function apply(mixed $logic, array $data): mixed {
		if (is_array($logic) === false || count($logic) !== 1 || is_string(array_key_first($logic)) === false) {
			return $logic;
		}

		$operator = array_key_first($logic);
		$raw      = (array) $logic[$operator];

		if ($operator === 'var') {
			$cursor = $data;
			foreach (explode('.', (string) $logic['var']) as $segment) {
				if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
					return null;
				}

				$cursor = $cursor[$segment];
			}

			return $cursor;
		}

		if ($operator === 'and' || $operator === 'or') {
			$current = null;
			foreach ($raw as $operand) {
				$current = self::apply(logic: $operand, data: $data);
				if (self::truthy(value: $current) === ($operator === 'or')) {
					return $current;
				}
			}

			return $current;
		}

		$values = array_map(static fn (mixed $operand): mixed => self::apply(logic: $operand, data: $data), $raw);

		return match ($operator) {
			'==' => $values[0] == $values[1],
			'!=' => $values[0] != $values[1],
			'===' => $values[0] === $values[1],
			'!==' => $values[0] !== $values[1],
			'<' => $values[0] < $values[1],
			'>' => $values[0] > $values[1],
			'<=' => $values[0] <= $values[1],
			'>=' => $values[0] >= $values[1],
			'!' => self::truthy(value: $values[0]) === false,
			'+' => array_sum(array_map('floatval', $values)),
			'-' => floatval($values[0]) - floatval($values[1]),
			'*' => array_product(array_map('floatval', $values)),
			'/' => floatval($values[0]) / floatval($values[1]),
			default => throw new InvalidArgumentException('The reader does not know '.$operator),
		};
	}//end apply()
}//end class
