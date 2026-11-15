<?php

/**
 * Unit tests for CanonicalJson: maps are key-sorted at every depth, lists keep their order.
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

use OCA\Buildiq\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CanonicalJson.
 *
 * @covers \OCA\Buildiq\Support\CanonicalJson
 */
final class CanonicalJsonTest extends TestCase {

	/**
	 * Nested maps are sorted by key, including maps inside lists.
	 *
	 * @return void
	 */
	public function testMapsAreSortedAtEveryDepth(): void {
		$sorted = CanonicalJson::sortKeys(
			value: ['b' => 1, 'a' => ['z' => true, 'y' => [['d' => 1, 'c' => 2]]]]
		);

		self::assertSame(
			'{"a":{"y":[{"c":2,"d":1}],"z":true},"b":1}',
			json_encode($sorted)
		);
	}//end testMapsAreSortedAtEveryDepth()

	/**
	 * A list keeps its element order.
	 *
	 * @return void
	 */
	public function testListsKeepTheirOrder(): void {
		self::assertSame(['c', 'a', 'b'], CanonicalJson::sortKeys(value: ['c', 'a', 'b']));
	}//end testListsKeepTheirOrder()

	/**
	 * Scalars and null pass through unchanged.
	 *
	 * @return void
	 */
	public function testScalarsPassThrough(): void {
		self::assertSame('x', CanonicalJson::sortKeys(value: 'x'));
		self::assertSame(3, CanonicalJson::sortKeys(value: 3));
		self::assertNull(CanonicalJson::sortKeys(value: null));
		self::assertSame([], CanonicalJson::sortKeys(value: []));
	}//end testScalarsPassThrough()
}//end class
