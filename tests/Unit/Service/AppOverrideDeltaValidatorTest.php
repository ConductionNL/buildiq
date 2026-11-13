<?php

/**
 * Unit tests for AppOverrideDeltaValidator: delta shape violations and the blank-app guard.
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

use OCA\Buildiq\Service\AppOverrideDeltaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AppOverrideDeltaValidator.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\AppOverrideDeltaValidator
 */
class AppOverrideDeltaValidatorTest extends TestCase {

	/**
	 * An empty delta and a well-formed keyed delta have no violations.
	 *
	 * @return void
	 */
	public function testAWellFormedDeltaHasNoViolations(): void {
		$validator = new AppOverrideDeltaValidator();

		$this->assertSame([], $validator->validateDeltaShape([]));
		$this->assertSame(
			[],
			$validator->validateDeltaShape(
				[
					'pages' => [['id' => 'home', 'widgets' => [['id' => 'w1'], 'scalar']], ['$op' => 'remove']],
					'menu' => ['__order' => ['home'], 'home' => ['$op' => 'remove']],
					'name' => 'App',
				]
			)
		);
	}//end testAWellFormedDeltaHasNoViolations()

	/**
	 * A list at the top level is refused outright, without inspecting its entries.
	 *
	 * @return void
	 */
	public function testATopLevelListIsRefused(): void {
		$this->assertSame(
			['delta must be a plain object, not a list'],
			(new AppOverrideDeltaValidator())->validateDeltaShape([['id' => ''], ['$op' => 'drop']])
		);
	}//end testATopLevelListIsRefused()

	/**
	 * Unknown markers, a scalar order and id-less list entries are reported with their paths, in walk order.
	 *
	 * @return void
	 */
	public function testViolationsCarryTheirPaths(): void {
		$violations = (new AppOverrideDeltaValidator())->validateDeltaShape(
			[
				'menu' => ['$op' => 'delete', '__order' => 'home'],
				'pages' => [
					['title' => 'no id', 'config' => ['$op' => ['x']]],
					['id' => 5],
					['$op' => 'remove', 'inner' => ['$op' => 1]],
				],
			]
		);

		$this->assertSame(
			[
				'$.menu.$op has unknown marker "delete" (only "remove" is supported)',
				'$.menu.__order must be an array or object of ids',
				'$.pages[0] entry is missing a string "id" (page/widget entries must carry ids for keyed merge)',
				'$.pages[0].config.$op has unknown marker "non-scalar" (only "remove" is supported)',
				'$.pages[1] entry is missing a string "id" (page/widget entries must carry ids for keyed merge)',
				'$.pages[2].inner.$op has unknown marker "1" (only "remove" is supported)',
			],
			$violations
		);
	}//end testViolationsCarryTheirPaths()

	/**
	 * A delta blanks the app only when it touches pages or menu and leaves neither renderable.
	 *
	 * @return void
	 */
	public function testWouldBlankApp(): void {
		$validator = new AppOverrideDeltaValidator();

		$this->assertFalse($validator->wouldBlankApp([]));
		$this->assertFalse($validator->wouldBlankApp(['name' => 'x']));
		$this->assertTrue($validator->wouldBlankApp(['pages' => []]));
		$this->assertTrue($validator->wouldBlankApp(['pages' => null, 'menu' => []]));
		$this->assertTrue($validator->wouldBlankApp(['pages' => ['__order' => ['a'], 'a' => ['$op' => 'remove']]]));
		$this->assertFalse($validator->wouldBlankApp(['pages' => ['a' => ['$op' => 'remove'], 'b' => ['title' => 'B']]]));
		$this->assertFalse($validator->wouldBlankApp(['pages' => [], 'menu' => 'something']));
		$this->assertFalse($validator->wouldBlankApp(['menu' => ['x']]));
	}//end testWouldBlankApp()
}//end class
