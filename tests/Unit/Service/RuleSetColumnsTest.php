<?php

/**
 * Unit tests for RuleSetColumns: what a rule set declares, and the check
 * another app's inputs pass (rules-evaluation-for-other-apps D2 step 2).
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
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\RuleSetColumns;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RuleSetColumns.
 */
final class RuleSetColumnsTest extends TestCase {

	/**
	 * The parking rule's declared inputs.
	 *
	 * @var list<array{name:string,path:string,type:string}>
	 */
	private const PARKING = [
		['name' => 'woonplaats', 'path' => 'woonplaats', 'type' => 'string'],
		['name' => 'auto', 'path' => 'auto', 'type' => 'boolean'],
		['name' => 'leeftijd', 'path' => 'aanvrager.leeftijd', 'type' => 'number'],
	];

	/**
	 * A table's columns are read with their path and type; a nameless column is skipped.
	 *
	 * @return void
	 */
	public function testTheColumnsOfATable(): void {
		$columns = RuleSetColumns::fromTable(
			table: [
				'inputColumns' => [
					['name' => 'woonplaats', 'type' => 'string'],
					['name' => 'leeftijd', 'expressionPath' => 'aanvrager.leeftijd', 'type' => 'number'],
					['type' => 'string'],
				],
				'outputColumns' => [['name' => 'soortVergunning', 'type' => 'string'], 'odd'],
				'rules' => [['conditions' => [], 'values' => ['soortVergunning' => 'bewoner']]],
			]
		);

		$this->assertSame(
			[
				'inputs' => [
					['name' => 'woonplaats', 'path' => 'woonplaats', 'type' => 'string'],
					['name' => 'leeftijd', 'path' => 'aanvrager.leeftijd', 'type' => 'number'],
				],
				'outputs' => [['name' => 'soortVergunning', 'type' => 'string']],
			],
			$columns
		);
		$this->assertSame(['inputs' => [], 'outputs' => []], RuleSetColumns::fromTable(table: []));
	}//end testTheColumnsOfATable()

	/**
	 * Undeclared inputs are dropped and a declared input that is missing is passed as null.
	 *
	 * @return void
	 */
	public function testUndeclaredInputsAreDroppedAndMissingOnesAreNull(): void {
		$checked = RuleSetColumns::checkInputs(
			columns: self::PARKING,
			inputs: ['woonplaats' => 'Zuiddrecht', 'bsn' => '999993653', 'aanvrager' => ['leeftijd' => 40, 'naam' => 'Jan']]
		);

		$this->assertSame(
			['woonplaats' => 'Zuiddrecht', 'auto' => null, 'aanvrager' => ['leeftijd' => 40, 'naam' => 'Jan']],
			$checked
		);
	}//end testUndeclaredInputsAreDroppedAndMissingOnesAreNull()

	/**
	 * A declared input of the wrong type refuses the whole set; null always fits.
	 *
	 * @return void
	 */
	public function testAnInputOfTheWrongTypeIsRefused(): void {
		$this->assertNull(RuleSetColumns::checkInputs(columns: self::PARKING, inputs: ['woonplaats' => 'Zuiddrecht', 'auto' => 'ja']));
		$this->assertNull(RuleSetColumns::checkInputs(columns: self::PARKING, inputs: ['aanvrager' => ['leeftijd' => '40']]));
		$this->assertNull(RuleSetColumns::checkInputs(columns: self::PARKING, inputs: ['woonplaats' => 12]));
		$this->assertSame(
			['woonplaats' => null, 'auto' => true, 'aanvrager' => null],
			RuleSetColumns::checkInputs(columns: self::PARKING, inputs: ['woonplaats' => null, 'auto' => true, 'aanvrager' => null])
		);
		$this->assertSame(
			['x' => ['any' => 'thing']],
			RuleSetColumns::checkInputs(columns: [['name' => 'x', 'path' => 'x', 'type' => 'custom']], inputs: ['x' => ['any' => 'thing']])
		);
	}//end testAnInputOfTheWrongTypeIsRefused()

	/**
	 * A rule set without declared inputs (condition-action) passes the inputs unchanged.
	 *
	 * @return void
	 */
	public function testWithoutDeclaredInputsNothingIsDropped(): void {
		$this->assertSame(['a' => 1, 'b' => 'two'], RuleSetColumns::checkInputs(columns: [], inputs: ['a' => 1, 'b' => 'two']));
	}//end testWithoutDeclaredInputsNothingIsDropped()
}//end class
