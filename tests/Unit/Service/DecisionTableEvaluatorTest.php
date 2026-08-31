<?php

/**
 * Unit tests for DecisionTableEvaluator.
 *
 * Covers REQ-BRE-002 / REQ-BRE-012: hit policies, overlap and unreachable
 * detection, default output, and FEEL cell-condition matching.
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

use OCA\Buildiq\Service\DecisionTableEvaluator;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see DecisionTableEvaluator}.
 */
final class DecisionTableEvaluatorTest extends TestCase {

	/**
	 * The evaluator under test.
	 *
	 * @var DecisionTableEvaluator
	 */
	private DecisionTableEvaluator $evaluator;

	/**
	 * The table handed to the shared evaluator by the last evaluate() call.
	 *
	 * @var array<string, mixed>
	 */
	private array $capturedTable = [];

	/**
	 * The input values handed to the shared evaluator by the last call.
	 *
	 * @var array<string, mixed>
	 */
	private array $capturedInputs = [];

	/**
	 * Build a fresh evaluator before each test.
	 *
	 * The shared evaluator is a mock in every test here, and deliberately so.
	 * The grammar and the hit policies belong to OpenRegister and are proven
	 * there, against the real class. What buildiq owns after this change is the
	 * adapter: resolving payload paths into named values, translating the table
	 * and its cell dialect, and mapping the answer back onto buildiq's result
	 * shape. That is what this suite asserts, and it is where a mistake in this
	 * change would actually live.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->evaluator = $this->evaluatorReturning(matchedRuleIds: []);

	}//end setUp()

	/**
	 * An evaluator whose shared collaborator reports the given matching rules.
	 *
	 * @param array<int, string> $matchedRuleIds The rule ids the shared evaluator matched.
	 *
	 * @return DecisionTableEvaluator The evaluator under test.
	 */
	private function evaluatorReturning(array $matchedRuleIds): DecisionTableEvaluator {
		$shared = $this->createMock(SharedEvaluator::class);
		$shared->method('evaluate')->willReturnCallback(
			function (array $decisionTable, array $inputs) use ($matchedRuleIds): array {
				$this->capturedTable  = $decisionTable;
				$this->capturedInputs = $inputs;

				return [
					'outputs' => [],
					'matchedRuleIds' => $matchedRuleIds,
					'hitPolicy' => $decisionTable['hitPolicy'],
				];
			}
		);

		return new DecisionTableEvaluator(new ExpressionEvaluator(), $shared);

	}//end evaluatorReturning()

	/**
	 * An evaluator whose shared collaborator refuses with the given code.
	 *
	 * @param string               $errorCode The refusal code.
	 * @param array<string, mixed> $details   Structured details on the refusal.
	 *
	 * @return DecisionTableEvaluator The evaluator under test.
	 */
	private function evaluatorRefusing(string $errorCode, array $details = []): DecisionTableEvaluator {
		$shared = $this->createMock(SharedEvaluator::class);
		$shared->method('evaluate')->willThrowException(new DecisionEvaluationException($errorCode, $details));

		return new DecisionTableEvaluator(new ExpressionEvaluator(), $shared);

	}//end evaluatorRefusing()

	/**
	 * A payload that satisfies the loan table's first rule.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function eligiblePayload(): array {
		return ['applicant' => ['age' => 25, 'monthlyIncome' => 3000, 'creditScore' => 700]];

	}//end eligiblePayload()

	/**
	 * The canonical loan-eligibility table.
	 *
	 * @return array<string,mixed>
	 */
	private function loanTable(): array {
		return [
			'hitPolicy' => 'first',
			'inputColumns' => [
				['name' => 'applicantAge', 'type' => 'integer', 'expressionPath' => 'applicant.age'],
				['name' => 'monthlyIncome', 'type' => 'number', 'expressionPath' => 'applicant.monthlyIncome'],
				['name' => 'creditScore', 'type' => 'integer', 'expressionPath' => 'applicant.creditScore'],
			],
			'outputColumns' => [
				['name' => 'decision', 'type' => 'string', 'defaultValue' => 'deny'],
				['name' => 'reason', 'type' => 'string', 'defaultValue' => 'Eligibility criteria not met'],
			],
			'rules' => [
				[
					'conditions' => ['applicantAge' => '>=18', 'monthlyIncome' => '>=2000', 'creditScore' => '>=600'],
					'values' => ['decision' => 'approve', 'reason' => 'All criteria met'],
					'label' => 'Standard approval',
				],
				[
					'conditions' => ['applicantAge' => '>=18', 'monthlyIncome' => '>=1500', 'creditScore' => '>=500'],
					'values' => ['decision' => 'review', 'reason' => 'Manual review required'],
					'label' => 'Marginal case',
				],
				[
					'conditions' => [],
					'values' => ['decision' => 'deny', 'reason' => 'Eligibility criteria not met'],
					'label' => 'Default deny',
				],
			],
		];

	}//end loanTable()

	/**
	 * The table is translated into the shared evaluator's shape.
	 *
	 * @return void
	 */
	public function testTranslatesTheTableIntoTheSharedShape(): void {
		$this->evaluator->evaluate($this->loanTable(), $this->eligiblePayload());

		$this->assertSame('FIRST', $this->capturedTable['hitPolicy']);
		$this->assertSame(
			[
				['name' => 'applicantAge', 'type' => 'integer'],
				['name' => 'monthlyIncome', 'type' => 'number'],
				['name' => 'creditScore', 'type' => 'integer'],
			],
			$this->capturedTable['inputs']
		);
		$this->assertSame(
			[['name' => 'decision', 'type' => 'string'], ['name' => 'reason', 'type' => 'string']],
			$this->capturedTable['outputs']
		);

		// Rule ids are the rule's own index, so the winning row can be read
		// straight back out of buildiq's rules array.
		$this->assertSame(['0', '1', '2'], array_column($this->capturedTable['rules'], 'id'));
		$this->assertSame(['>=18', '>=2000', '>=600'], $this->capturedTable['rules'][0]['inputEntries']);
		$this->assertSame(['approve', 'All criteria met'], $this->capturedTable['rules'][0]['outputEntries']);

		// A rule with no conditions is a wildcard on every column.
		$this->assertSame(['-', '-', '-'], $this->capturedTable['rules'][2]['inputEntries']);

	}//end testTranslatesTheTableIntoTheSharedShape()

	/**
	 * buildiq's cell dialects are translated into the shared grammar.
	 *
	 * Measured against both evaluators, these three are the ones that differ.
	 * An untranslated `*` is the dangerous case: the shared grammar reads it as
	 * a literal, so the rule silently stops matching instead of erroring.
	 *
	 * @return void
	 */
	public function testTranslatesBuildiqCellDialects(): void {
		$table = [
			'hitPolicy' => 'first',
			'inputColumns' => [
				['name' => 'a', 'type' => 'number', 'expressionPath' => 'a'],
				['name' => 'b', 'type' => 'number', 'expressionPath' => 'b'],
				['name' => 'c', 'type' => 'string', 'expressionPath' => 'c'],
				['name' => 'd', 'type' => 'string', 'expressionPath' => 'd'],
				['name' => 'e', 'type' => 'number', 'expressionPath' => 'e'],
				['name' => 'f', 'type' => 'number', 'expressionPath' => 'f'],
			],
			'outputColumns' => [['name' => 'x', 'type' => 'string']],
			'rules' => [
				[
					'conditions' => ['a' => '==7', 'b' => '18..65', 'c' => '*', 'd' => 'any', 'e' => '>=18', 'f' => '[1..9]'],
					'values' => ['x' => 'hit'],
				],
			],
		];

		$this->evaluator->evaluate($table, ['a' => 7, 'b' => 30, 'c' => 'z', 'd' => 'z', 'e' => 20, 'f' => 5]);

		$this->assertSame(
			['=7', '[18..65]', '-', '-', '>=18', '[1..9]'],
			$this->capturedTable['rules'][0]['inputEntries'],
			'== becomes =, a bare range gains its brackets, * and any become -, the rest passes through'
		);

	}//end testTranslatesBuildiqCellDialects()

	/**
	 * Input values are resolved from the payload by expressionPath.
	 *
	 * This stays buildiq's job: the shared evaluator takes named values and
	 * knows nothing about dot-notation paths into a payload.
	 *
	 * @return void
	 */
	public function testResolvesInputValuesFromThePayloadByExpressionPath(): void {
		$this->evaluator->evaluate($this->loanTable(), $this->eligiblePayload());

		$this->assertSame(
			['applicantAge' => 25, 'monthlyIncome' => 3000, 'creditScore' => 700],
			$this->capturedInputs
		);

	}//end testResolvesInputValuesFromThePayloadByExpressionPath()

	/**
	 * The winning rule's own values and label come back.
	 *
	 * @return void
	 */
	public function testReturnsTheWinningRulesValues(): void {
		$result = $this->evaluatorReturning(matchedRuleIds: ['0'])
			->evaluate($this->loanTable(), $this->eligiblePayload());

		$this->assertSame('approve', $result['outputColumns']['decision']);
		$this->assertSame('All criteria met', $result['outputColumns']['reason']);
		$this->assertSame('Standard approval', $result['triggeredRuleId']);
		$this->assertSame([0], $result['matches']);

	}//end testReturnsTheWinningRulesValues()

	/**
	 * A second matching rule wins when the shared evaluator says so.
	 *
	 * @return void
	 */
	public function testReturnsTheSecondRuleWhenItIsTheWinner(): void {
		$result = $this->evaluatorReturning(matchedRuleIds: ['1'])
			->evaluate($this->loanTable(), $this->eligiblePayload());

		$this->assertSame('review', $result['outputColumns']['decision']);
		$this->assertSame('Marginal case', $result['triggeredRuleId']);

	}//end testReturnsTheSecondRuleWhenItIsTheWinner()

	/**
	 * No match returns the declared defaults and no rule id.
	 *
	 * The shared evaluator raises `no_rule_matched` where buildiq's contract is
	 * to answer with the output columns' defaults, so the refusal is caught and
	 * translated rather than propagated.
	 *
	 * @return void
	 */
	public function testReturnsDefaultsWhenNoRuleMatched(): void {
		$result = $this->evaluatorRefusing(errorCode: 'no_rule_matched')
			->evaluate($this->loanTable(), ['applicant' => ['age' => 15]]);

		$this->assertSame('deny', $result['outputColumns']['decision']);
		$this->assertSame('Eligibility criteria not met', $result['outputColumns']['reason']);
		$this->assertNull($result['triggeredRuleId']);
		$this->assertSame([], $result['matches']);

	}//end testReturnsDefaultsWhenNoRuleMatched()

	/**
	 * collect returns every matching rule's values, in order.
	 *
	 * @return void
	 */
	public function testCollectReturnsEveryMatchingRulesValues(): void {
		$table = $this->loanTable();
		$table['hitPolicy'] = 'collect';

		$result = $this->evaluatorReturning(matchedRuleIds: ['0', '1'])
			->evaluate($table, $this->eligiblePayload());

		$this->assertSame('COLLECT', $this->capturedTable['hitPolicy']);
		$this->assertCount(2, $result['outputColumns']['collected']);
		$this->assertSame('approve', $result['outputColumns']['collected'][0]['decision']);
		$this->assertSame('review', $result['outputColumns']['collected'][1]['decision']);
		$this->assertSame([0, 1], $result['matches']);

	}//end testCollectReturnsEveryMatchingRulesValues()

	/**
	 * A hit-policy violation keeps surfacing as a RuntimeException.
	 *
	 * The shared evaluator raises DecisionEvaluationException. buildiq's
	 * documented contract is a RuntimeException, and RuleEngineService catches
	 * on that, so the translation happens here rather than in the callers.
	 *
	 * @return void
	 */
	public function testUniqueViolationBecomesARuntimeException(): void {
		$table = $this->loanTable();
		$table['hitPolicy'] = 'unique';

		$evaluator = $this->evaluatorRefusing(
			errorCode: 'hit_policy_violation',
			details: ['matchedRuleIds' => ['0', '1']]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Hit policy "unique" violated: rules 0, 1 all matched.');

		$evaluator->evaluate($table, $this->eligiblePayload());

	}//end testUniqueViolationBecomesARuntimeException()

	/**
	 * rule-order is buildiq's spelling of FIRST.
	 *
	 * @return void
	 */
	public function testRuleOrderIsTranslatedToFirst(): void {
		$table = $this->loanTable();
		$table['hitPolicy'] = 'rule-order';

		$this->evaluator->evaluate($table, $this->eligiblePayload());

		$this->assertSame('FIRST', $this->capturedTable['hitPolicy']);

	}//end testRuleOrderIsTranslatedToFirst()

	/**
	 * An unrecognised hit policy still decides, as it always has.
	 *
	 * buildiq treated anything it did not recognise as `first`. The shared
	 * evaluator refuses what it does not implement, so without this fallback a
	 * table with a typo'd policy would start erroring instead of deciding.
	 *
	 * @return void
	 */
	public function testAnUnknownHitPolicyFallsBackToFirst(): void {
		$table = $this->loanTable();
		$table['hitPolicy'] = 'output-order';

		$this->evaluator->evaluate($table, $this->eligiblePayload());

		$this->assertSame('FIRST', $this->capturedTable['hitPolicy']);

	}//end testAnUnknownHitPolicyFallsBackToFirst()

	/**
	 * A column the payload does not carry drops out, with its rules.
	 *
	 * The shared evaluator coerces every declared input before matching, so one
	 * unresolved path would refuse the entire table with `type_mismatch`.
	 * buildiq's contract is that an unresolved column simply fails the
	 * conditions testing it and the table falls through, so the adapter drops
	 * the column and any rule that tests it before delegating.
	 *
	 * @return void
	 */
	public function testAnUnresolvedColumnDropsOutWithTheRulesThatTestIt(): void {
		// creditScore is absent from the payload entirely.
		$this->evaluator->evaluate(
			$this->loanTable(),
			['applicant' => ['age' => 25, 'monthlyIncome' => 3000]]
		);

		$this->assertSame(
			['applicantAge', 'monthlyIncome'],
			array_column($this->capturedTable['inputs'], 'name'),
			'the unresolved column is not declared to the shared evaluator'
		);
		$this->assertArrayNotHasKey('creditScore', $this->capturedInputs);

		// Rules 0 and 1 both test creditScore, so neither can match. Only the
		// catch-all survives, and it keeps its wildcard on the columns that remain.
		$this->assertSame(['2'], array_column($this->capturedTable['rules'], 'id'));
		$this->assertSame(['-', '-'], $this->capturedTable['rules'][0]['inputEntries']);

	}//end testAnUnresolvedColumnDropsOutWithTheRulesThatTestIt()

	/**
	 * An unresolved column still lets a wildcard rule through.
	 *
	 * @return void
	 */
	public function testAWildcardRuleSurvivesAnUnresolvedColumn(): void {
		$result = $this->evaluatorReturning(matchedRuleIds: ['2'])
			->evaluate($this->loanTable(), ['applicant' => ['age' => 25, 'monthlyIncome' => 3000]]);

		$this->assertSame('deny', $result['outputColumns']['decision']);
		$this->assertSame('Default deny', $result['triggeredRuleId']);

	}//end testAWildcardRuleSurvivesAnUnresolvedColumn()

	/**
	 * detectIssues flags an unreachable rule shadowed by a don't-care rule.
	 *
	 * @return void
	 */
	public function testDetectUnreachable(): void {
		$table = [
			'hitPolicy' => 'first',
			'rules' => [
				['conditions' => [], 'values' => ['x' => 1], 'label' => 'catch-all'],
				['conditions' => ['age' => '>=18'], 'values' => ['x' => 2], 'label' => 'never'],
			],
		];
		$issues = $this->evaluator->detectIssues($table);
		$this->assertNotEmpty($issues['unreachable']);

	}//end testDetectUnreachable()

	/**
	 * A well-formed table with disjoint literals reports no overlaps.
	 *
	 * @return void
	 */
	public function testNoIssuesOnDisjointLiterals(): void {
		$table = [
			'hitPolicy' => 'first',
			'rules' => [
				['conditions' => ['status' => 'open'], 'values' => ['x' => 1], 'label' => 'a'],
				['conditions' => ['status' => 'closed'], 'values' => ['x' => 2], 'label' => 'b'],
			],
		];
		$issues = $this->evaluator->detectIssues($table);
		$this->assertEmpty($issues['overlaps']);

	}//end testNoIssuesOnDisjointLiterals()
}//end class
