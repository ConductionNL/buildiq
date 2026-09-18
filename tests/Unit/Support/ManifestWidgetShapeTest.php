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
	 * A stat tile written with a flat register and schema gets the nested
	 * `content.source` block its component actually reads.
	 *
	 * `CnStatWidget::fetchValue()` begins `const s = this.content.source || {}`
	 * and returns early when `!s.register || !s.schema` — there is no fallback
	 * to the flat keys. Measured on the live instance on 2026-09-18: all three
	 * data tiles the copilot proposed rendered an em dash, and the same tile
	 * written with a `source` block rendered the real count.
	 *
	 * @return void
	 */
	public function testAStatTileGetsTheNestedSourceBlockItsComponentReads(): void {
		[, $widget] = ManifestWidgetShape::appendTo(
			page: ['id' => 'overview', 'type' => 'dashboard'],
			widgetType: 'stat',
			widgetConfig: [
				'register' => 'openbuild-tool-library-development',
				'schema' => 'tool-library-development-loan',
				'aggregate' => 'count',
				'filter' => ['returned' => false],
				'icon' => 'hammer-wrench',
			],
			widgetId: 'loans-open',
			title: 'Open loans'
		);

		self::assertSame(
			[
				'register' => 'openbuild-tool-library-development',
				'schema' => 'tool-library-development-loan',
				'filter' => ['returned' => false],
				'aggregate' => 'count',
			],
			$widget['content']['source']
		);
		// The flat keys stay: object-list reads them, and nothing that reads
		// `source` is troubled by their being there too.
		self::assertSame('openbuild-tool-library-development', $widget['content']['register']);
		self::assertSame('hammer-wrench', $widget['content']['icon']);
	}//end testAStatTileGetsTheNestedSourceBlockItsComponentReads()

	/**
	 * An object list is left flat, because that is the shape its own component
	 * reads. The binding block is per widget type, not a rule applied to all.
	 *
	 * @return void
	 */
	public function testAnObjectListKeepsItsFlatBinding(): void {
		[, $widget] = ManifestWidgetShape::appendTo(
			page: ['id' => 'overview', 'type' => 'dashboard'],
			widgetType: 'object-list',
			widgetConfig: ['register' => 'openbuild-tool-library-development', 'schema' => 'tool-library-development-loan'],
			title: 'Recent loans'
		);

		self::assertArrayNotHasKey('source', $widget['content']);
		self::assertArrayNotHasKey('dataSource', $widget['content']);
		self::assertSame('tool-library-development-loan', $widget['content']['schema']);
	}//end testAnObjectListKeepsItsFlatBinding()

	/**
	 * A tile that names no register and no schema is handed back untouched:
	 * a binding is lifted, never invented.
	 *
	 * @return void
	 */
	public function testATileWithNoBindingIsUntouched(): void {
		[, $widget] = ManifestWidgetShape::appendTo(
			page: ['id' => 'overview', 'type' => 'dashboard'],
			widgetType: 'stat',
			widgetConfig: ['label' => 'Manual number', 'value' => 7],
			title: 'Manual'
		);

		self::assertArrayNotHasKey('source', $widget['content']);
	}//end testATileWithNoBindingIsUntouched()

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
