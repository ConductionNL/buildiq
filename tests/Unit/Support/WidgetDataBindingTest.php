<?php

/**
 * Buildiq WidgetDataBinding unit tests
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

use OCA\Buildiq\Support\WidgetDataBinding;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see WidgetDataBinding}.
 *
 * @covers \OCA\Buildiq\Support\WidgetDataBinding
 */
final class WidgetDataBindingTest extends TestCase {

	/**
	 * A flat stat binding is copied into `source`, keeping the flat keys.
	 *
	 * @return void
	 */
	public function testStatGetsASourceBlock(): void {
		$config = ['register' => 'r', 'schema' => 's', 'metric' => 'count', 'title' => 'Loans'];

		self::assertSame(
			$config + ['source' => ['register' => 'r', 'schema' => 's', 'metric' => 'count']],
			WidgetDataBinding::apply(widgetConfig: $config, widgetType: 'stat')
		);
	}//end testStatGetsASourceBlock()

	/**
	 * A chart reads `dataSource`.
	 *
	 * @return void
	 */
	public function testChartGetsADataSourceBlock(): void {
		$out = WidgetDataBinding::apply(widgetConfig: ['register' => 'r', 'schema' => 's'], widgetType: 'chart');

		self::assertSame(['register' => 'r', 'schema' => 's'], $out['dataSource']);
	}//end testChartGetsADataSourceBlock()

	/**
	 * Flat types, existing blocks and incomplete bindings stay as written.
	 *
	 * @return void
	 */
	public function testLeftAloneWhenThereIsNothingToLift(): void {
		$flat = ['register' => 'r', 'schema' => 's'];
		$nested = ['register' => 'r', 'schema' => 's', 'source' => ['x' => 1]];
		$noSchema = ['register' => 'r', 'schema' => ''];

		self::assertSame($flat, WidgetDataBinding::apply(widgetConfig: $flat, widgetType: 'object-list'));
		self::assertSame($nested, WidgetDataBinding::apply(widgetConfig: $nested, widgetType: 'stat'));
		self::assertSame($noSchema, WidgetDataBinding::apply(widgetConfig: $noSchema, widgetType: 'gauge'));
	}//end testLeftAloneWhenThereIsNothingToLift()
}//end class
