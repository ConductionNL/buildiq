<?php

/**
 * The addWidget tool's published enum is the handler's own allow-list.
 *
 * The catalogue the copilot shows the model declared `widgetType` as any
 * string up to 48 characters, while `AddWidgetHandler` only accepts a fixed
 * list. That is the same shape as the route defect this change set exists to
 * fix: the plan validator has nothing to measure the argument against, the
 * review screen enables Confirm, and the executor refuses the write after the
 * reader has committed.
 *
 * Unlike a route, an unknown widget type cannot be normalised: there is no way
 * to tell which of the real types a model meant by "kpi". So it is rejected at
 * plan time instead, which is the other half of the same rule, and the model
 * is told the list up front.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Mcp
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

namespace OCA\Buildiq\Tests\Unit\Mcp;

use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Mcp\Handler\AddWidgetHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests the published widgetType enum.
 */
class WidgetTypeParityTest extends TestCase {

	/**
	 * The descriptor for addWidget, read off the published catalogue.
	 *
	 * @return array<string, mixed>
	 */
	private function addWidgetDescriptor(): array {
		$reflection = new \ReflectionClass(BuildiqToolProvider::class);
		foreach ((array)$reflection->getConstant('TOOL_DESCRIPTORS') as $descriptor) {
			if (($descriptor['id'] ?? '') === 'buildiq.addWidget') {
				return $descriptor;
			}
		}

		self::fail('buildiq.addWidget is missing from the tool catalogue');
	}//end addWidgetDescriptor()

	/**
	 * The published enum names every type the handler accepts, and nothing
	 * else. A type in one list and not the other is a plan accepted at review
	 * and refused at execute, or a usable type the model is never offered.
	 *
	 * @return void
	 */
	public function testThePublishedEnumIsTheHandlersAllowList(): void {
		$descriptor = $this->addWidgetDescriptor();
		$enum = ($descriptor['inputSchema']['properties']['widgetType']['enum'] ?? null);

		self::assertIsArray($enum, 'widgetType must publish an enum');
		self::assertSame(AddWidgetHandler::ALLOWED_WIDGET_TYPES, $enum);
	}//end testThePublishedEnumIsTheHandlersAllowList()

	/**
	 * A type the handler really accepts is in the enum, and one it does not is
	 * not. Guards the assertion above against both lists being empty.
	 *
	 * @return void
	 */
	public function testTheEnumIsNeitherEmptyNorAllInclusive(): void {
		$enum = (array)($this->addWidgetDescriptor()['inputSchema']['properties']['widgetType']['enum'] ?? []);

		self::assertContains('stat', $enum);
		self::assertContains('object-list', $enum);
		self::assertNotContains('kpi', $enum, 'a type the handler refuses must not be offered');
	}//end testTheEnumIsNeitherEmptyNorAllInclusive()
}//end class
