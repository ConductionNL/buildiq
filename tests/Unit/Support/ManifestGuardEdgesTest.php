<?php

/**
 * ManifestRoute and ManifestDataBinding guard edge cases.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\ManifestDataBinding;
use OCA\Buildiq\Support\ManifestRoute;
use PHPUnit\Framework\TestCase;

/**
 * Pins the overlong menu-target refusal and the data-register slug reader.
 *
 * @covers \OCA\Buildiq\Support\ManifestRoute
 * @covers \OCA\Buildiq\Support\ManifestDataBinding
 */
class ManifestGuardEdgesTest extends TestCase {

	/**
	 * A bare page id longer than the route cap is refused as a menu target.
	 *
	 * @return void
	 */
	public function testAnOverlongBarePageIdIsNotAMenuTarget(): void {
		self::assertTrue(ManifestRoute::isValidMenuTarget(route: str_repeat('a', 256)));
		self::assertFalse(ManifestRoute::isValidMenuTarget(route: str_repeat('a', 257)));
	}//end testAnOverlongBarePageIdIsNotAMenuTarget()

	/**
	 * Bindings that are not a list read as no data registers.
	 *
	 * @return void
	 */
	public function testNonArrayBindingsHaveNoDataRegisters(): void {
		self::assertSame([], ManifestDataBinding::dataRegisterSlugs(bindings: null));
		self::assertSame([], ManifestDataBinding::dataRegisterSlugs(bindings: 'permits-db'));
	}//end testNonArrayBindingsHaveNoDataRegisters()

	/**
	 * Register slugs are trimmed, deduplicated and listed in first-seen order.
	 *
	 * @return void
	 */
	public function testRegisterSlugsAreTrimmedAndUnique(): void {
		self::assertSame(
			['permits-db', 'crm'],
			ManifestDataBinding::dataRegisterSlugs(
				bindings: [['register' => ' permits-db '], ['register' => 'crm'], ['register' => 'permits-db'], ['register' => '   '], ['register' => 5]]
			)
		);
	}//end testRegisterSlugsAreTrimmedAndUnique()
}//end class
