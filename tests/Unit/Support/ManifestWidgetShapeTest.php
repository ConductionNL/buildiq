<?php

/**
 * Unit tests for ManifestWidgetShape.
 *
 * Pins the widget entry the builder tools append against the shared fixture
 * `tests/Fixtures/copilot-widget-shape.json`, which the vitest spec of the
 * same name runs through the canonical `validateManifest()`. Together the two
 * say: this is the shape PHP writes, and that shape validates.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\ManifestWidgetShape;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestWidgetShape.
 */
class ManifestWidgetShapeTest extends TestCase {

	/**
	 * Building the fixture's three widgets reproduces the fixture exactly.
	 *
	 * @return void
	 */
	public function testAppendToReproducesTheSharedFixture(): void {
		$fixture = json_decode(
			(string)file_get_contents(__DIR__ . '/../../Fixtures/copilot-widget-shape.json'),
			true
		);
		$expected = $fixture['page'];

		$page = ['id' => 'overview', 'route' => '/', 'type' => 'dashboard', 'title' => 'Overview', 'config' => []];

		[$page] = ManifestWidgetShape::appendTo(
			page: $page,
			widgetType: 'stat',
			widgetConfig: ['register' => 'tool-library', 'schema' => 'loan'],
			widgetId: 'tools-out-on-loan',
			title: 'Tools out on loan'
		);
		[$page] = ManifestWidgetShape::appendTo(
			page: $page,
			widgetType: 'chart',
			widgetConfig: ['chartKind' => 'bar'],
			title: 'Loans per month'
		);
		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Overdue');

		self::assertSame($expected, $page);
	}//end testAppendToReproducesTheSharedFixture()

	/**
	 * Every widget carries the three non-empty strings the canonical validator
	 * demands, even when the caller supplies neither an id nor a title.
	 *
	 * @return void
	 */
	public function testEveryWidgetCarriesIdTitleAndType(): void {
		$page = ['id' => 'home', 'type' => 'dashboard'];

		[$page, $widget] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stats-block', widgetConfig: []);

		self::assertSame('stats-block', $widget['id']);
		self::assertSame('Stats block', $widget['title']);
		self::assertSame('stats-block', $widget['type']);
		self::assertSame([$widget], $page['config']['widgets']);
	}//end testEveryWidgetCarriesIdTitleAndType()

	/**
	 * A title on the settings bag is used rather than a label made from the type.
	 *
	 * @return void
	 */
	public function testTitleIsTakenFromTheWidgetConfigWhenNotGivenSeparately(): void {
		[, $widget] = ManifestWidgetShape::appendTo(
			page: ['id' => 'home'],
			widgetType: 'stat',
			widgetConfig: ['title' => 'Tools out on loan']
		);

		self::assertSame('Tools out on loan', $widget['title']);
		self::assertSame('tools-out-on-loan', $widget['id']);
	}//end testTitleIsTakenFromTheWidgetConfigWhenNotGivenSeparately()

	/**
	 * Two widgets that would derive the same id get distinct ones.
	 *
	 * @return void
	 */
	public function testIdsAreUniqueWithinThePage(): void {
		$page = ['id' => 'home'];
		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Loans');
		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Loans');
		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Loans');

		$ids = array_column($page['config']['widgets'], 'id');

		self::assertSame(['loans', 'loans-2', 'loans-3'], $ids);
		self::assertSame($ids, array_unique($ids));
	}//end testIdsAreUniqueWithinThePage()

	/**
	 * An id already spoken for by a layout row is not handed to a new widget.
	 *
	 * @return void
	 */
	public function testAnIdHeldOnlyByALayoutRowIsNotReused(): void {
		$page = [
			'id' => 'home',
			'config' => ['layout' => [['id' => 'loans', 'widgetId' => 'loans', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2]]],
		];

		[, $widget] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Loans');

		self::assertSame('loans-2', $widget['id']);
	}//end testAnIdHeldOnlyByALayoutRowIsNotReused()

	/**
	 * Each widget gets a layout row, so it is placed rather than merely stored.
	 *
	 * @return void
	 */
	public function testEachWidgetGetsALayoutRow(): void {
		$page = ['id' => 'home'];
		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'One');
		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Two');
		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Three');

		$layout = $page['config']['layout'];

		self::assertCount(3, $layout);
		self::assertSame(['one', 'two', 'three'], array_column($layout, 'widgetId'));
		self::assertSame([0, 6, 0], array_column($layout, 'gridX'));
		self::assertSame([0, 0, 2], array_column($layout, 'gridY'));
	}//end testEachWidgetGetsALayoutRow()

	/**
	 * The settings bag lands on `content`, and on `props` as well for a chart.
	 *
	 * @return void
	 */
	public function testTheSettingsBagLandsOnTheKeyTheRendererReads(): void {
		[, $stat] = ManifestWidgetShape::appendTo(
			page: ['id' => 'home'],
			widgetType: 'stat',
			widgetConfig: ['register' => 'tool-library']
		);
		[, $chart] = ManifestWidgetShape::appendTo(
			page: ['id' => 'home'],
			widgetType: 'chart',
			widgetConfig: ['chartKind' => 'bar']
		);

		self::assertSame(['register' => 'tool-library'], $stat['content']);
		self::assertArrayNotHasKey('props', $stat);
		self::assertArrayNotHasKey('config', $stat);
		self::assertSame(['chartKind' => 'bar'], $chart['content']);
		self::assertSame(['chartKind' => 'bar'], $chart['props']);
	}//end testTheSettingsBagLandsOnTheKeyTheRendererReads()

	/**
	 * An existing page's widgets and layout survive an append.
	 *
	 * @return void
	 */
	public function testExistingWidgetsAreKept(): void {
		$page = [
			'id' => 'home',
			'config' => [
				'widgets' => [['id' => 'kept', 'title' => 'Kept', 'type' => 'stat']],
				'layout' => [['id' => 'kept', 'widgetId' => 'kept', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 6, 'gridHeight' => 2]],
			],
		];

		[$page] = ManifestWidgetShape::appendTo(page: $page, widgetType: 'stat', widgetConfig: [], title: 'Added');

		self::assertCount(2, $page['config']['widgets']);
		self::assertSame('kept', $page['config']['widgets'][0]['id']);
		self::assertSame('added', $page['config']['widgets'][1]['id']);
		self::assertSame(6, $page['config']['layout'][1]['gridX']);
	}//end testExistingWidgetsAreKept()
}//end class
