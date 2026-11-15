<?php

/**
 * ManifestWidgetShape::appendTo() id derivation edge cases.
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

use OCA\Buildiq\Support\ManifestWidgetShape;
use OCA\Buildiq\Support\WidgetSpec;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fallback id, the id length cap and collision suffixes.
 *
 * @covers \OCA\Buildiq\Support\ManifestWidgetShape
 * @uses   \OCA\Buildiq\Support\WidgetSpec
 */
class ManifestWidgetShapeIdEdgesTest extends TestCase {

	/**
	 * With no usable id, title or type, the widget is called `widget`.
	 *
	 * @return void
	 */
	public function testNoSluggableSourceFallsBackToWidget(): void {
		[$page, $widget] = ManifestWidgetShape::appendTo(page: [], spec: new WidgetSpec(type: '***', config: [], id: '!!', title: '??'));

		self::assertSame(['id' => 'widget', 'title' => '??', 'type' => '***'], $widget);
		self::assertSame('widget', $page['config']['layout'][0]['widgetId']);
	}//end testNoSluggableSourceFallsBackToWidget()

	/**
	 * A long title is cut to the id length cap without a trailing hyphen.
	 *
	 * @return void
	 */
	public function testALongTitleIsCutToTheIdCap(): void {
		$title = str_repeat('a', 47) . ' bcd';

		[, $widget] = ManifestWidgetShape::appendTo(page: [], spec: new WidgetSpec(type: 'stat', config: [], title: $title));

		self::assertSame(str_repeat('a', 47), $widget['id']);
		self::assertSame($title, $widget['title']);
	}//end testALongTitleIsCutToTheIdCap()

	/**
	 * A taken id gets the first free numeric suffix, counting layout rows too.
	 *
	 * @return void
	 */
	public function testACollisionTakesTheFirstFreeSuffix(): void {
		$page = [
			'config' => [
				'widgets' => [['id' => 'open-cases'], ['id' => 'open-cases-2']],
				'layout' => [['id' => 'open-cases-3', 'widgetId' => 'open-cases-3']],
			],
		];

		[$page, $widget] = ManifestWidgetShape::appendTo(page: $page, spec: new WidgetSpec(type: 'stat', config: [], title: 'Open cases'));

		self::assertSame('open-cases-4', $widget['id']);
		self::assertCount(3, $page['config']['widgets']);
		self::assertSame(
			['id' => 'open-cases-4', 'widgetId' => 'open-cases-4', 'gridX' => 6, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2],
			$page['config']['layout'][1]
		);
	}//end testACollisionTakesTheFirstFreeSuffix()
}//end class
