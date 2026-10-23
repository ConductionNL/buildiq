<?php

/**
 * Buildiq StoredDecisionTables unit tests.
 *
 * A rule set's decision table lives in OpenRegister's decision-tables register
 * since decision 196. These tests pin the mapping between buildiq's authoring
 * shape and OpenRegister's stored shape, the key a table is held under, and
 * that a write replaces the row the key already names.
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
use OCA\Buildiq\Service\DecisionTableShape;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * Tests for {@see StoredDecisionTables} and {@see DecisionTableShape}.
 *
 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
 */
final class StoredDecisionTablesTest extends TestCase {

	/**
	 * @var DecisionTableStore&MockObject
	 */
	private DecisionTableStore&MockObject $store;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The mapping between the authoring and the stored shape.
	 *
	 * @var DecisionTableShape
	 */
	private DecisionTableShape $shape;

	/**
	 * The class under test.
	 *
	 * @var StoredDecisionTables
	 */
	private StoredDecisionTables $tables;

	/**
	 * Wire the class with doubles of OpenRegister's store and object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(DecisionTableStore::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->shape = new DecisionTableShape(new DecisionTableEvaluator(new ExpressionEvaluator(), $this->createMock(SharedEvaluator::class)));
		$this->tables = new StoredDecisionTables(
			$this->store,
			$this->objects,
			$this->shape,
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * The loan table as buildiq authored it (the seed of 10-business-rules.json).
	 *
	 * @return array<string,mixed>
	 */
	private function loanTable(): array {
		return [
			'ruleSetId' => 'loan-eligibility',
			'hitPolicy' => 'first',
			'inputColumns' => [
				['name' => 'applicantAge', 'type' => 'integer', 'expressionPath' => 'applicant.age'],
				['name' => 'monthlyIncome', 'type' => 'number', 'expressionPath' => 'applicant.monthlyIncome'],
			],
			'outputColumns' => [
				['name' => 'decision', 'type' => 'string', 'defaultValue' => 'deny'],
			],
			'rules' => [
				[
					'conditions' => ['applicantAge' => '18..65', 'monthlyIncome' => '==2000'],
					'values' => ['decision' => 'approve', 'reason' => 'All criteria met'],
					'label' => 'Standard approval',
					'priority' => 2,
				],
				['values' => ['decision' => 'deny'], 'label' => 'Default deny'],
			],
		];

	}//end loanTable()

	/**
	 * The key carries the organisation, so two organisations' rule sets of the
	 * same slug never share a table; a rule set without one gets the shared key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testTheKeyCarriesTheOrganisation(): void {
		$this->assertSame('buildiq.loan-eligibility', $this->tables->keyFor('loan-eligibility', null));
		$this->assertSame(
			'buildiq.0b6f-org.loan-eligibility',
			$this->tables->keyOfRuleSet(['slug' => 'loan-eligibility', '@self' => ['organisation' => '0B6F-org']])
		);
		$this->assertNotSame(
			$this->tables->keyOfRuleSet(['slug' => 'x', '@self' => ['organisation' => 'a']]),
			$this->tables->keyOfRuleSet(['slug' => 'x', '@self' => ['organisation' => 'b']])
		);

	}//end testTheKeyCarriesTheOrganisation()

	/**
	 * The stored shape is what OpenRegister's validator and evaluator read:
	 * positional entries, cells in the shared grammar, upper-case hit policy,
	 * and every value name as an output column.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testTheStoredShapeIsOpenRegistersShape(): void {
		$stored = $this->shape->toStored('buildiq.loan-eligibility', 'Loan', $this->loanTable());

		$this->assertSame('buildiq.loan-eligibility', $stored['key']);
		$this->assertSame('FIRST', $stored['hitPolicy']);
		$this->assertSame('buildiq', $stored['owner']);
		$this->assertSame(['applicantAge', 'monthlyIncome'], array_column($stored['inputs'], 'name'));
		$this->assertSame('applicant.age', $stored['inputs'][0]['expressionPath']);
		$this->assertSame(['decision', 'reason'], array_column($stored['outputs'], 'name'));
		$this->assertSame('deny', $stored['outputs'][0]['defaultValue']);
		$this->assertSame(['[18..65]', '=2000'], $stored['rules'][0]['inputEntries']);
		$this->assertSame(['approve', 'All criteria met'], $stored['rules'][0]['outputEntries']);
		$this->assertSame(2, $stored['rules'][0]['priority']);
		$this->assertSame(['-', '-'], $stored['rules'][1]['inputEntries']);
		$this->assertSame(['deny', null], $stored['rules'][1]['outputEntries']);

	}//end testTheStoredShapeIsOpenRegistersShape()

	/**
	 * Reading a stored table back gives the evaluator what it read before the
	 * move: the same columns, defaults, labels and values; a wildcard cell is
	 * an absent condition and an absent value stays absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testAStoredTableReadsBackAsTheAuthoredTable(): void {
		$stored = $this->shape->toStored('buildiq.loan-eligibility', 'Loan', $this->loanTable());
		$back = $this->shape->fromStored($stored, 'loan-eligibility');

		$this->assertSame('loan-eligibility', $back['ruleSetId']);
		$this->assertSame('first', $back['hitPolicy']);
		$this->assertSame($this->loanTable()['inputColumns'], $back['inputColumns']);
		$this->assertSame('deny', $back['outputColumns'][0]['defaultValue']);
		$this->assertSame(['applicantAge' => '[18..65]', 'monthlyIncome' => '=2000'], $back['rules'][0]['conditions']);
		$this->assertSame(['decision' => 'approve', 'reason' => 'All criteria met'], $back['rules'][0]['values']);
		$this->assertSame('Standard approval', $back['rules'][0]['label']);
		$this->assertSame([], $back['rules'][1]['conditions']);
		$this->assertSame(['decision' => 'deny'], $back['rules'][1]['values']);

	}//end testAStoredTableReadsBackAsTheAuthoredTable()

	/**
	 * A rule set reads the table under its own key first, then a shared seed's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testARuleSetFallsBackToTheSharedSeedTable(): void {
		$asked = [];
		$stored = $this->shape->toStored('buildiq.loan-eligibility', 'Loan', $this->loanTable());
		$this->store->method('find')->willReturnCallback(
			static function (string $reference) use (&$asked, $stored): array {
				$asked[] = $reference;
				if ($reference === 'buildiq.loan-eligibility') {
					return $stored;
				}

				throw new UnexpectedValueException('No decision table has the key or id "' . $reference . '".');
			}
		);

		$found = $this->tables->forRuleSet(['slug' => 'loan-eligibility', '@self' => ['organisation' => 'org-a']]);

		$this->assertSame(['buildiq.org-a.loan-eligibility', 'buildiq.loan-eligibility'], $asked);
		$this->assertCount(1, $found);
		$this->assertSame('loan-eligibility', $found[0]['ruleSetId']);

	}//end testARuleSetFallsBackToTheSharedSeedTable()

	/**
	 * No table, or a table OpenRegister refuses to run, reads as no table.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testARuleSetWithoutATableHasNone(): void {
		$this->store->method('find')->willThrowException(new UnexpectedValueException('No decision table.'));

		$this->assertSame([], $this->tables->forRuleSet(['slug' => 'loan-eligibility']));

	}//end testARuleSetWithoutATableHasNone()

	/**
	 * A write replaces the row the key already names, never a second row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testAWriteReplacesTheRowTheKeyNames(): void {
		$other = $this->createMock(ObjectEntityInterface::class);
		$other->method('getObject')->willReturn(['key' => 'dossiq.lhs']);
		$other->method('getUuid')->willReturn('uuid-other');
		$mine = $this->createMock(ObjectEntityInterface::class);
		$mine->method('getObject')->willReturn(['key' => 'buildiq.org-a.loan-eligibility']);
		$mine->method('getUuid')->willReturn('uuid-mine');
		$this->objects->method('searchObjectsBySlug')->willReturn([$other, $mine]);

		$this->objects->expects($this->once())->method('saveObject')->with(
			$this->callback(static fn (array $row): bool => $row['key'] === 'buildiq.org-a.loan-eligibility' && $row['name'] === 'Loan eligibility'),
			$this->anything(),
			'decision-tables',
			'decision-table',
			'uuid-mine',
			false,
			false
		);

		$this->tables->save(
			['slug' => 'loan-eligibility', 'name' => 'Loan eligibility', '@self' => ['organisation' => 'org-a']],
			$this->loanTable()
		);

	}//end testAWriteReplacesTheRowTheKeyNames()

	/**
	 * OpenRegister's refusal (its validator runs on save) surfaces as a RuntimeException.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function testARefusedWriteSaysSo(): void {
		$this->objects->method('searchObjectsBySlug')->willReturn([]);
		$this->objects->method('saveObject')->willThrowException(new \Exception('cannot be run'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister refused the decision table "buildiq.loan-eligibility"');
		$this->tables->save(['slug' => 'loan-eligibility'], $this->loanTable());

	}//end testARefusedWriteSaysSo()
}//end class
