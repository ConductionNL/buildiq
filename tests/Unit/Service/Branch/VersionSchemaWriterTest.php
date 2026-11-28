<?php

/**
 * Unit tests for VersionSchemaWriter: the breaking changes a merge would make, and a register that is no version.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Branch;

use OCA\Buildiq\Service\Branch\VersionSchemaWriter;
use OCA\Buildiq\Service\Branch\VersionState;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * A merge that removes a schema or a field, changes a field's type or makes a
 * field required names each breaking change in one plain sentence.
 *
 * @covers \OCA\Buildiq\Service\Branch\VersionSchemaWriter
 * @uses   \OCA\Buildiq\Service\Branch\VersionState
 */
class VersionSchemaWriterTest extends TestCase {

	/**
	 * Every kind of breaking change, in schema and property order.
	 *
	 * @return void
	 */
	public function testEachBreakingChangeIsNamed(): void {
		$current = [
			'meeting' => [
				'properties' => ['title' => ['type' => 'string'], 'room' => ['type' => 'string'], 'seats' => ['type' => 'integer']],
				'required'   => ['title'],
			],
			'vote'    => ['properties' => ['choice' => ['type' => 'string']]],
		];
		$merged  = [
			'meeting' => [
				'properties' => ['title' => ['type' => 'string'], 'seats' => ['type' => 'string'], 'date' => ['type' => 'string']],
				'required'   => ['title', 'date'],
			],
		];

		$this->assertSame(
			expected: [
				'The field "meeting.room" is removed.',
				'The field "meeting.seats" changes type.',
				'The field "meeting.date" becomes required.',
				'The schema "vote" is removed.',
			],
			actual: VersionSchemaWriter::breakingChanges(current: $current, merged: $merged)
		);
	}//end testEachBreakingChangeIsNamed()

	/**
	 * Adding schemas, fields and optional fields breaks nothing.
	 *
	 * @return void
	 */
	public function testAdditionsBreakNothing(): void {
		$current = ['meeting' => ['properties' => ['title' => ['type' => 'string']], 'required' => ['title']]];
		$merged  = [
			'meeting' => ['properties' => ['title' => ['type' => 'string'], 'room' => ['type' => 'string']], 'required' => ['title']],
			'vote'    => ['properties' => []],
		];

		$this->assertSame(expected: [], actual: VersionSchemaWriter::breakingChanges(current: $current, merged: $merged));
	}//end testAdditionsBreakNothing()

	/**
	 * A register that is no version is not written to.
	 *
	 * @return void
	 */
	public function testARegisterThatIsNoVersionIsNotWritten(): void {
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->expects(self::never())->method('find');
		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->expects(self::never())->method('createFromArray');
		$writer = new VersionSchemaWriter(
			registerMapper: $registers,
			schemaMapper: $schemas,
			state: new VersionState(registerMapper: $registers, schemaMapper: $schemas),
		);

		$writer->write(version: ['register' => 'decidiq'], schemas: ['meeting' => ['title' => 'Meeting']]);
	}//end testARegisterThatIsNoVersionIsNotWritten()
}//end class
