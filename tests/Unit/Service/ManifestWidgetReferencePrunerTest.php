<?php

/**
 * Unit tests for ManifestWidgetReferencePruner: dropping removed widget ids from tab references.
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

use OCA\Buildiq\Service\ManifestWidgetReferencePruner;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ManifestWidgetReferencePruner.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Buildiq\Service\ManifestWidgetReferencePruner
 */
class ManifestWidgetReferencePrunerTest extends TestCase {

	/**
	 * Removed ids are dropped whether referenced by string or by a widgetId object; others are re-indexed in order.
	 *
	 * @return void
	 */
	public function testDropReferences(): void {
		$pruner = new ManifestWidgetReferencePruner();
		$removed = ['gone' => true, 'also' => true];

		$this->assertSame(
			['keep', ['widgetId' => 'kept'], ['label' => 'no id'], 7],
			$pruner->dropReferences(['gone', 'keep', ['widgetId' => 'also'], ['widgetId' => 'kept'], ['label' => 'no id'], 7], $removed)
		);
		$this->assertSame('not a list', $pruner->dropReferences('not a list', $removed));
		$this->assertNull($pruner->dropReferences(null, $removed));
	}//end testDropReferences()

	/**
	 * Uniform widgets are pruned under props.content.tabs, config widgets under content.tabs; nothing else moves.
	 *
	 * @return void
	 */
	public function testPruneTabReferencesInBothShapes(): void {
		$page = [
			'id' => 'home',
			'widgets' => [
				['props' => ['content' => ['tabs' => ['a', 'gone']]], 'content' => ['tabs' => ['gone']]],
				['props' => ['title' => 'no tabs']],
			],
			'config' => [
				'widgets' => [
					['content' => ['tabs' => [['widgetId' => 'gone'], ['widgetId' => 'b']]], 'props' => ['content' => ['tabs' => ['gone']]]],
				],
			],
		];

		$pruned = (new ManifestWidgetReferencePruner())->pruneTabReferences($page, ['gone' => true]);

		$this->assertSame(['a'], $pruned['widgets'][0]['props']['content']['tabs']);
		$this->assertSame(['gone'], $pruned['widgets'][0]['content']['tabs']);
		$this->assertSame(['props' => ['title' => 'no tabs']], $pruned['widgets'][1]);
		$this->assertSame([['widgetId' => 'b']], $pruned['config']['widgets'][0]['content']['tabs']);
		$this->assertSame(['gone'], $pruned['config']['widgets'][0]['props']['content']['tabs']);
		$this->assertSame('home', $pruned['id']);
	}//end testPruneTabReferencesInBothShapes()

	/**
	 * A page without widget lists is returned unchanged.
	 *
	 * @return void
	 */
	public function testAPageWithoutWidgetsIsUnchanged(): void {
		$page = ['id' => 'p', 'widgets' => 'none', 'config' => ['widgets' => null]];

		$this->assertSame($page, (new ManifestWidgetReferencePruner())->pruneTabReferences($page, ['p' => true]));
	}//end testAPageWithoutWidgetsIsUnchanged()
}//end class
