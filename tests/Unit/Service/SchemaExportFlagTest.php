<?php

/**
 * Unit tests for SchemaExportFlag: a schema buildiq creates is exportable
 * unless it already says whether it is.
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
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\SchemaExportFlag;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SchemaExportFlag.
 *
 * @covers \OCA\Buildiq\Service\SchemaExportFlag
 */
final class SchemaExportFlagTest extends TestCase {

	/**
	 * A blob without configuration gets a block holding only the flag.
	 *
	 * @return void
	 */
	public function testASilentBlobIsMarkedExportable(): void {
		$blob = ['slug' => 'permit', 'properties' => []];

		$marked = SchemaExportFlag::mark(blob: $blob);

		self::assertSame(expected: ['exportable' => true], actual: $marked['configuration']);
		self::assertSame(expected: 'permit', actual: $marked['slug']);
	}//end testASilentBlobIsMarkedExportable()

	/**
	 * The blob's other configuration keys are kept beside the flag.
	 *
	 * @return void
	 */
	public function testOtherConfigurationKeysAreKept(): void {
		$marked = SchemaExportFlag::mark(blob: ['configuration' => ['objectNameField' => 'title']]);

		self::assertSame(expected: ['objectNameField' => 'title', 'exportable' => true], actual: $marked['configuration']);
	}//end testOtherConfigurationKeysAreKept()

	/**
	 * A template that switched export off keeps it off, in either place.
	 *
	 * @return void
	 */
	public function testAFlagAlreadySetIsLeftAsItIs(): void {
		$inConfiguration = ['configuration' => ['exportable' => false]];
		$topLevel        = ['exportable' => false];

		self::assertSame(expected: $inConfiguration, actual: SchemaExportFlag::mark(blob: $inConfiguration));
		self::assertSame(expected: $topLevel, actual: SchemaExportFlag::mark(blob: $topLevel));
	}//end testAFlagAlreadySetIsLeftAsItIs()

	/**
	 * A configuration that is not a list of keys is replaced by the flag.
	 *
	 * @return void
	 */
	public function testANonArrayConfigurationBecomesTheFlag(): void {
		$marked = SchemaExportFlag::mark(blob: ['configuration' => null]);

		self::assertSame(expected: ['exportable' => true], actual: $marked['configuration']);
	}//end testANonArrayConfigurationBecomesTheFlag()
}//end class
