<?php

/**
 * Unit tests for ManifestEntryList: an upsert replaces the entry with the same id, ignoring case, and appends any other.
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

use OCA\Buildiq\Support\ManifestEntryList;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestEntryList.
 *
 * @covers \OCA\Buildiq\Support\ManifestEntryList
 */
final class ManifestEntryListTest extends TestCase {

	/**
	 * An entry with a new id is appended and reported as not replaced.
	 *
	 * @return void
	 */
	public function testANewIdIsAppended(): void {
		$list = new ManifestEntryList(entries: [['id' => 'home', 'title' => 'Home']]);

		self::assertFalse($list->upsert(entry: ['id' => 'tools', 'title' => 'Tools']));
		self::assertSame(
			[['id' => 'home', 'title' => 'Home'], ['id' => 'tools', 'title' => 'Tools']],
			$list->entries()
		);
	}//end testANewIdIsAppended()

	/**
	 * An entry whose id matches one already there, in any case, replaces it in place.
	 *
	 * @return void
	 */
	public function testAnExistingIdIsReplacedInPlaceIgnoringCase(): void {
		$list = new ManifestEntryList(
			entries: [['id' => 'home'], ['id' => 'Tools', 'title' => 'Old'], ['id' => 'loans']]
		);

		self::assertTrue($list->upsert(entry: ['id' => 'tools', 'title' => 'New']));
		self::assertSame(
			[['id' => 'home'], ['id' => 'tools', 'title' => 'New'], ['id' => 'loans']],
			$list->entries()
		);
	}//end testAnExistingIdIsReplacedInPlaceIgnoringCase()

	/**
	 * Only the first entry with a matching id is replaced.
	 *
	 * @return void
	 */
	public function testOnlyTheFirstMatchIsReplaced(): void {
		$list = new ManifestEntryList(entries: [['id' => 'a', 'n' => 1], ['id' => 'A', 'n' => 2]]);

		self::assertTrue($list->upsert(entry: ['id' => 'a', 'n' => 3]));
		self::assertSame([['id' => 'a', 'n' => 3], ['id' => 'A', 'n' => 2]], $list->entries());
	}//end testOnlyTheFirstMatchIsReplaced()

	/**
	 * Entries that are not arrays are kept and never matched.
	 *
	 * @return void
	 */
	public function testNonArrayEntriesAreSkipped(): void {
		$list = new ManifestEntryList(entries: ['home', ['id' => 'tools']]);

		self::assertFalse($list->upsert(entry: ['id' => 'home']));
		self::assertSame(['home', ['id' => 'tools'], ['id' => 'home']], $list->entries());
	}//end testNonArrayEntriesAreSkipped()

	/**
	 * An entry without an id matches an existing entry without an id.
	 *
	 * @return void
	 */
	public function testAMissingIdMatchesAnotherMissingId(): void {
		$list = new ManifestEntryList(entries: [['title' => 'Untitled']]);

		self::assertTrue($list->upsert(entry: ['title' => 'Replaced']));
		self::assertSame([['title' => 'Replaced']], $list->entries());
	}//end testAMissingIdMatchesAnotherMissingId()

	/**
	 * An empty list gains its first entry.
	 *
	 * @return void
	 */
	public function testAnEmptyListGainsTheEntry(): void {
		$list = new ManifestEntryList(entries: []);

		self::assertFalse($list->upsert(entry: ['id' => 'x']));
		self::assertSame([['id' => 'x']], $list->entries());
	}//end testAnEmptyListGainsTheEntry()
}//end class
