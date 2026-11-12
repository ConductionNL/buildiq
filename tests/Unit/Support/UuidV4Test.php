<?php

/**
 * Unit tests for UuidV4: each value is a random RFC 4122 version 4 UUID.
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

use OCA\Buildiq\Support\UuidV4;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UuidV4.
 *
 * @covers \OCA\Buildiq\Support\UuidV4
 */
final class UuidV4Test extends TestCase {

	/**
	 * Every value has the version 4 nibble and the RFC 4122 variant bits.
	 *
	 * @return void
	 */
	public function testEveryValueIsAVersion4Uuid(): void {
		$generator = new UuidV4();
		for ($i = 0; $i < 200; $i++) {
			self::assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
				$generator->generate()
			);
		}
	}//end testEveryValueIsAVersion4Uuid()

	/**
	 * Values do not repeat.
	 *
	 * @return void
	 */
	public function testValuesAreDistinct(): void {
		$generator = new UuidV4();
		$values    = [];
		for ($i = 0; $i < 200; $i++) {
			$values[] = $generator->generate();
		}

		self::assertCount(200, array_unique($values));
	}//end testValuesAreDistinct()
}//end class
