<?php

/**
 * Unit tests for KeyedListMerger: patching, adding, removing and reordering id-keyed entries.
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

use OCA\Buildiq\Service\KeyedListMerger;
use OCA\Buildiq\Service\LayoutDeltaService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KeyedListMerger.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\KeyedListMerger
 * @uses   \OCA\Buildiq\Service\LayoutDeltaService
 */
class KeyedListMergerTest extends TestCase {

	/**
	 * An existing entry is merged through the delta service, a new one appended with its id, a removed one dropped.
	 *
	 * @return void
	 */
	public function testPatchMergesAddsAndRemoves(): void {
		$base = [
			['id' => 'a', 'title' => 'A', 'width' => 'small'],
			['id' => 'b', 'title' => 'B'],
			['id' => 'c', 'title' => 'C'],
		];
		$patch = [
			'a' => ['title' => 'A2'],
			'b' => ['$op' => 'remove'],
			'd' => ['title' => 'D'],
		];

		$merged = (new KeyedListMerger())->apply($base, $patch, [], new LayoutDeltaService());

		$this->assertSame(
			[
				['id' => 'a', 'title' => 'A2', 'width' => 'small'],
				['id' => 'c', 'title' => 'C'],
				['title' => 'D', 'id' => 'd'],
			],
			$merged
		);
	}//end testPatchMergesAddsAndRemoves()

	/**
	 * Entries without an id and non-array entries: the first survives in place, the second is dropped.
	 *
	 * @return void
	 */
	public function testIdlessEntriesAreCarriedAndNonArraysDropped(): void {
		$base = [['title' => 'no id'], 'scalar', ['id' => 'a']];
		$patch = ['__order' => ['a'], 7 => ['id' => 'x'], 'a' => 'not-an-array'];

		$merged = (new KeyedListMerger())->apply($base, $patch, [], new LayoutDeltaService());

		$this->assertSame([['title' => 'no id'], ['id' => 'a']], $merged);
	}//end testIdlessEntriesAreCarriedAndNonArraysDropped()

	/**
	 * Ordered ids come first, unknown ids are ignored, the rest keep their order; id-less entries do not survive a reorder.
	 *
	 * @return void
	 */
	public function testRequestedOrderComesFirst(): void {
		$base = [['id' => 'a'], ['id' => 'b'], ['note' => 'loose'], ['id' => 'c']];

		$merged = (new KeyedListMerger())->apply($base, [], ['c', 'ghost', 'a', 'c'], new LayoutDeltaService());

		// A repeated id is served once.
		$this->assertSame([['id' => 'c'], ['id' => 'a'], ['id' => 'b']], $merged);
	}//end testRequestedOrderComesFirst()

	/**
	 * The order inside a patch keeps only non-empty string ids; anything else yields no order.
	 *
	 * @return void
	 */
	public function testOrderForReadsOnlyStringIds(): void {
		$merger = new KeyedListMerger();

		$this->assertSame(['a', 'b'], $merger->orderFor(['__order' => ['a', '', 3, null, 'b']]));
		$this->assertSame([], $merger->orderFor(['__order' => 'a,b']));
		$this->assertSame([], $merger->orderFor([]));
	}//end testOrderForReadsOnlyStringIds()

	/**
	 * A patch entry with a kind or an id stands on its own; anything else is a patch.
	 *
	 * @return void
	 */
	public function testLooksLikeANewEntry(): void {
		$merger = new KeyedListMerger();

		$this->assertTrue($merger->looksLikeANewEntry(['kind' => null]));
		$this->assertTrue($merger->looksLikeANewEntry(['id' => 'x']));
		$this->assertFalse($merger->looksLikeANewEntry(['title' => 'x']));
	}//end testLooksLikeANewEntry()
}//end class
