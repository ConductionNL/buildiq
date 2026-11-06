<?php

/**
 * Golden test for ManifestWidgetShape::appendTo().
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
use OCA\Buildiq\Support\WidgetSpec;
use PHPUnit\Framework\TestCase;

/**
 * Pins the updated page and the appended widget for pages that are empty,
 * filled or malformed, crossed with widget types, settings bags, ids and
 * titles, and three appends in a row onto the same page. Set GOLDEN_RECORD=1
 * to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Support\ManifestWidgetShape
 * @uses   \OCA\Buildiq\Support\WidgetSpec
 */
class ManifestWidgetShapeGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/manifest-widget-shape.golden.json';

	/**
	 * Append one widget through the public entry.
	 *
	 * @param array<string,mixed> $page The page.
	 * @param array<string,mixed> $w    Widget type, config, id and title.
	 *
	 * @return array{0: array<string,mixed>, 1: array<string,mixed>}
	 */
	private function append(array $page, array $w): array {
		return ManifestWidgetShape::appendTo(
			page: $page,
			spec: new WidgetSpec(type: $w['type'], config: $w['config'], id: $w['id'], title: $w['title'])
		);
	}//end append()

	/**
	 * Every case matches its recorded page and widget.
	 *
	 * @return void
	 */
	public function testAppendMatchesGolden(): void {
		$pages = [
			'empty' => [],
			'no config' => ['id' => 'home'],
			'config scalar' => ['id' => 'home', 'config' => 'x'],
			'filled' => ['id' => 'home', 'config' => [
				'widgets' => [['id' => 'stat'], ['id' => 'open-cases'], 'junk', ['title' => 'no id']],
				'layout' => [['id' => 'stat-2'], ['widgetId' => 'open-cases-2'], 7],
				'columns' => 3,
			]],
			'widgets keyed' => ['config' => ['widgets' => ['a' => ['id' => 'chart']], 'layout' => 'bad']],
		];
		$types = ['stat', 'chart', 'my_custom-type', ''];
		$configs = [
			'none' => [],
			'binding' => ['register' => 'zaken', 'schema' => 'case', 'filter' => ['s' => 1], 'metric' => 'count', 'other' => 1],
			'nested set' => ['register' => 'zaken', 'schema' => 'case', 'source' => ['x' => 1], 'dataSource' => ['y' => 2]],
			'blank register' => ['register' => ' ', 'schema' => 'case', 'title' => ' Cfg title ', 'label' => 'Cfg label'],
			'no schema' => ['register' => 'zaken', 'schema' => '', 'label' => ' Label only '],
			'odd title' => ['title' => 5, 'label' => ['x']],
		];
		$ids = ['', 'Open Cases!', 'stat'];
		$titles = ['', '  Überzicht  '];

		$actual = [];
		foreach ($pages as $pn => $page) {
			$full = in_array($pn, ['empty', 'filled'], true);
			foreach ($types as $type) {
				foreach ($configs as $cn => $config) {
					foreach (($full === true ? $ids : ['']) as $id) {
						foreach (($full === true ? $titles : ['']) as $title) {
							$w = ['type' => $type, 'config' => $config, 'id' => $id, 'title' => $title];
							$actual["$pn / '$type' / $cn / id '$id' / title '$title'"] = $this->append(page: $page, w: $w);
						}
					}
				}
			}

			$chain = $page;
			$widgets = [];
			foreach ([1, 2, 3] as $i) {
				[$chain, $widgets[]] = $this->append(page: $chain, w: ['type' => 'stat', 'config' => [], 'id' => '', 'title' => 'Loans']);
			}

			$actual["$pn / three in a row"] = [$chain, $widgets];
		}//end foreach

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($actual as $name => $result) {
			self::assertSame($expected[$name] ?? null, json_decode((string)json_encode($result), true), $name);
		}

		self::assertSame(array_keys($expected), array_keys($actual));
	}//end testAppendMatchesGolden()
}//end class
