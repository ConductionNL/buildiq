<?php

/**
 * Unit tests for SvgRasteriser (pages-installable-offline-and-mobile T02).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Icon
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

namespace OCA\Buildiq\Tests\Unit\Service\Icon;

use OCA\Buildiq\Service\Icon\SvgRasteriser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see SvgRasteriser}.
 *
 * @covers \OCA\Buildiq\Service\Icon\SvgRasteriser
 */
class SvgRasteriserTest extends TestCase {

	/**
	 * It reports what this server can do: Imagick with an SVG reader.
	 *
	 * @return void
	 */
	public function testAvailableFollowsImagickSvgSupport(): void {
		$expected = class_exists('Imagick') === true && \Imagick::queryFormats('SVG') !== [];

		self::assertSame($expected, (new SvgRasteriser())->available());
	}//end testAvailableFollowsImagickSvgSupport()

	/**
	 * Empty input or a size below one pixel makes no PNG.
	 *
	 * @return void
	 */
	public function testNothingToRasteriseGivesNull(): void {
		$rasteriser = new SvgRasteriser();

		self::assertSame([null, null], [$rasteriser->toPng(svg: '  '), $rasteriser->toPng(svg: '<svg/>', size: 0)]);
	}//end testNothingToRasteriseGivesNull()

	/**
	 * Without SVG support no PNG is made; with it, a 180-pixel PNG.
	 *
	 * @return void
	 */
	public function testASquarePngWhenTheServerCanRasterise(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#1f6feb"/></svg>';
		$rasteriser = new SvgRasteriser();
		$png = $rasteriser->toPng(svg: $svg);

		if ($rasteriser->available() === false) {
			self::assertNull($png);
			return;
		}

		self::assertIsString($png);
		self::assertSame("\x89PNG", substr($png, 0, 4));
		self::assertSame([180, 180], array_slice(getimagesizefromstring($png), 0, 2));
	}//end testASquarePngWhenTheServerCanRasterise()
}//end class
