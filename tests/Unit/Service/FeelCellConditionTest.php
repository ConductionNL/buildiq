<?php

/**
 * FeelCellCondition unit test.
 *
 * The PHP mirror of `isCellConditionValid()` reads the same fixture table as
 * `tests/utils/feelCell.spec.js`, so the two checks cannot drift.
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

use OCA\Buildiq\Service\FeelCellCondition;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Service\FeelCellCondition
 */
final class FeelCellConditionTest extends TestCase {

	/**
	 * Every row of the shared fixture table.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function fixtureCases(): array {
		$table = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/feel-cell-conditions.json'), true);
		$cases = [];
		foreach ($table['cases'] as $index => $case) {
			$cases[$index . ': ' . var_export($case['cell'], true)] = [$case['cell'], $case['valid']];
		}

		return $cases;
	}//end fixtureCases()

	/**
	 * The mirror agrees with the fixture table on every row.
	 *
	 * @param mixed   $cell  The cell source.
	 * @param boolean $valid Whether the editor accepts it.
	 *
	 * @return void
	 *
	 * @dataProvider fixtureCases
	 */
	public function testItAgreesWithTheSharedFixture(mixed $cell, bool $valid): void {
		$this->assertSame($valid, (new FeelCellCondition())->isValid(raw: $cell));
	}//end testItAgreesWithTheSharedFixture()

	/**
	 * A nested value is never a cell.
	 *
	 * @return void
	 */
	public function testANestedValueIsRefused(): void {
		$this->assertFalse((new FeelCellCondition())->isValid(raw: ['>= 18']));
	}//end testANestedValueIsRefused()
}//end class
