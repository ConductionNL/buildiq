<?php

/**
 * Unit tests for ExportContents: the context, registers, flows and source an export packs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Kernel\Values
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Kernel\Values;

use OCA\Buildiq\Kernel\Values\ExportContents;
use PHPUnit\Framework\TestCase;

/**
 * Construction, defaults and immutability of ExportContents.
 *
 * @covers \OCA\Buildiq\Kernel\Values\ExportContents
 */
final class ExportContentsTest extends TestCase {

	/**
	 * Every part is kept exactly as given, including its keys.
	 *
	 * @return void
	 */
	public function testKeepsEveryPartAsGiven(): void {
		$contents = new ExportContents(
			context: ['application' => ['slug' => 'pet-store']],
			dataRegisters: ['pets' => ['schemas' => []]],
			flows: [3 => ['id' => 'flow-a']],
			source: ['repo' => 'ConductionNL/pet-store']
		);

		$this->assertSame(expected: ['application' => ['slug' => 'pet-store']], actual: $contents->context);
		$this->assertSame(expected: ['pets' => ['schemas' => []]], actual: $contents->dataRegisters);
		$this->assertSame(expected: [3 => ['id' => 'flow-a']], actual: $contents->flows);
		$this->assertSame(expected: ['repo' => 'ConductionNL/pet-store'], actual: $contents->source);
	}//end testKeepsEveryPartAsGiven()

	/**
	 * Registers and flows default to empty lists and the source to null, not to an empty array.
	 *
	 * @return void
	 */
	public function testOnlyTheContextIsRequired(): void {
		$contents = new ExportContents(context: []);

		$this->assertSame(expected: [], actual: $contents->dataRegisters);
		$this->assertSame(expected: [], actual: $contents->flows);
		$this->assertNull(actual: $contents->source);
	}//end testOnlyTheContextIsRequired()

	/**
	 * A ExportContents cannot be changed after construction.
	 *
	 * @return void
	 */
	public function testPropertiesAreReadOnly(): void {
		$value = new ExportContents(context: []);

		$this->expectException(exception: \Error::class);
		$this->expectExceptionMessage(message: 'Cannot modify readonly property');

		$value->flows = ['x'];
	}//end testPropertiesAreReadOnly()
}//end class
