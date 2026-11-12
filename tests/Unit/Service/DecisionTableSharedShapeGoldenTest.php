<?php

/**
 * Golden test for the table DecisionTableEvaluator hands to the shared evaluator.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DecisionTableEvaluator;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shared-evaluator table (hit policy, input and output columns,
 * rule entries and priorities), the resolved inputs and the result for
 * tables crossed with payloads that resolve all, some or none of the input
 * columns. Set GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\DecisionTableEvaluator
 * @uses   \OCA\Buildiq\Service\ExpressionEvaluator
 * @uses   \OCA\Buildiq\Service\FeelParser
 * @uses \OCA\Buildiq\Service\FeelLexer
 */
class DecisionTableSharedShapeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/decision-table-shared-shape.golden.json';

	/**
	 * Tables by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function tables(): array {
		$inputs = [
			['name' => 'age', 'type' => 'number'],
			['name' => 'city', 'expressionPath' => 'address.city'],
			['name' => ''],
			['type' => 'number'],
			['name' => 'tier', 'type' => 'string'],
		];
		$outputs = [
			['name' => 'discount', 'type' => 'number'],
			['name' => ''],
			['name' => 'label'],
		];
		$rules = [
			['conditions' => ['age' => '>= 18', 'city' => '"Utrecht"', 'tier' => '-'], 'values' => ['discount' => 10, 'label' => 'adult'], 'priority' => 2],
			['conditions' => ['age' => '18..65', 'city' => 'any', 'tier' => '==gold'], 'values' => ['discount' => 20], 'priority' => '5'],
			['conditions' => ['age' => '[1..5]', 'city' => '*', 'tier' => ''], 'values' => ['label' => 'toddler']],
			['conditions' => ['age' => '  ', 'tier' => 'ANY'], 'values' => []],
			['conditions' => [], 'values' => ['discount' => 0, 'label' => 'default']],
			['values' => ['discount' => null]],
		];

		return [
			'full first' => ['inputColumns' => $inputs, 'outputColumns' => $outputs, 'rules' => $rules, 'hitPolicy' => 'first'],
			'collect' => ['inputColumns' => $inputs, 'outputColumns' => $outputs, 'rules' => $rules, 'hitPolicy' => ' collect '],
			'rule order' => ['inputColumns' => $inputs, 'outputColumns' => $outputs, 'rules' => $rules, 'hitPolicy' => 'rule-order'],
			'unknown policy' => ['inputColumns' => $inputs, 'outputColumns' => $outputs, 'rules' => $rules, 'hitPolicy' => 'random'],
			'keyed rules' => ['inputColumns' => $inputs, 'outputColumns' => $outputs, 'rules' => ['a' => $rules[0], 'b' => $rules[3]], 'hitPolicy' => 'priority'],
			'no outputs' => ['inputColumns' => $inputs, 'rules' => $rules],
			'no inputs' => ['outputColumns' => $outputs, 'rules' => $rules, 'hitPolicy' => 'unique'],
			'no rules' => ['inputColumns' => $inputs, 'outputColumns' => $outputs],
			'empty' => [],
		];
	}//end tables()

	/**
	 * Payloads by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function payloads(): array {
		return [
			'all' => ['age' => 30, 'address' => ['city' => 'Utrecht'], 'tier' => 'gold'],
			'no city' => ['age' => 3, 'tier' => 'silver'],
			'only tier' => ['tier' => 'gold'],
			'none' => [],
			'falsy' => ['age' => 0, 'address' => ['city' => ''], 'tier' => false],
		];
	}//end payloads()

	/**
	 * Every case matches its recorded shared table, inputs and result.
	 *
	 * @return void
	 */
	public function testSharedShapeMatchesGolden(): void {
		$actual = [];
		foreach ($this->tables() as $tn => $table) {
			foreach ($this->payloads() as $pn => $payload) {
				foreach (['no match' => [], 'first rule' => ['0'], 'two rules' => ['0', '2']] as $mn => $matched) {
					$captured = [];
					$shared = $this->createMock(SharedEvaluator::class);
					$shared->method('evaluate')->willReturnCallback(
						static function (array $decisionTable, array $inputs) use (&$captured, $matched): array {
							$captured = ['decisionTable' => $decisionTable, 'inputs' => $inputs];
							return ['outputs' => [], 'matchedRuleIds' => $matched, 'hitPolicy' => $decisionTable['hitPolicy']];
						}
					);
					$evaluator = new DecisionTableEvaluator(new ExpressionEvaluator(), $shared);
					$result = $evaluator->evaluate($table, $payload);
					$actual["$tn / $pn / $mn"] = ['shared' => $captured, 'result' => $result];
				}
			}
		}

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($actual as $name => $result) {
			self::assertSame($expected[$name] ?? null, json_decode((string)json_encode($result), true), $name);
		}

		self::assertSame(array_keys($expected), array_keys($actual));
	}//end testSharedShapeMatchesGolden()
}//end class
