<?php

/**
 * Buildiq WidgetIdAllocator unit tests
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\WidgetIdAllocator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see WidgetIdAllocator}.
 *
 * @covers \OCA\Buildiq\Support\WidgetIdAllocator
 */
final class WidgetIdAllocatorTest extends TestCase {

	/**
	 * The first source that slugs to something wins.
	 *
	 * @return void
	 */
	public function testFirstUsableSourceIsSlugged(): void {
		self::assertSame(
			'open-loans',
			WidgetIdAllocator::allocate(sources: ['  ', 'Open Loans!', 'stat'], existing: ['widgets' => [], 'layout' => []])
		);
	}//end testFirstUsableSourceIsSlugged()

	/**
	 * Ids held by a widget or only by a layout row are both taken.
	 *
	 * @return void
	 */
	public function testCollisionGetsTheNextFreeSuffix(): void {
		$existing = [
			'widgets' => [['id' => 'stat'], 'not-a-row'],
			'layout' => [['id' => 'x', 'widgetId' => 'stat-2']],
		];

		self::assertSame('stat-3', WidgetIdAllocator::allocate(sources: ['', '', 'stat'], existing: $existing));
	}//end testCollisionGetsTheNextFreeSuffix()

	/**
	 * Nothing usable falls back to `widget`, and long slugs are cut to 48 characters.
	 *
	 * @return void
	 */
	public function testFallbackAndLengthCap(): void {
		$empty = ['widgets' => [], 'layout' => []];

		self::assertSame('widget', WidgetIdAllocator::allocate(sources: ['', '!!!', ''], existing: $empty));
		self::assertSame(str_repeat('a', 48), WidgetIdAllocator::allocate(sources: [str_repeat('a', 60)], existing: $empty));
	}//end testFallbackAndLengthCap()
}//end class
