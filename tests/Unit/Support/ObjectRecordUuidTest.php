<?php

/**
 * ObjectRecord::uuid() answers what IconService's own extractUuid() answered before.
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

use OCA\Buildiq\Support\ObjectRecord;
use PHPUnit\Framework\TestCase;

/**
 * The fixture holds the recorded inputs and the old extractUuid() answers.
 *
 * @covers \OCA\Buildiq\Support\ObjectRecord
 */
class ObjectRecordUuidTest extends TestCase {

	/**
	 * Every recorded record answers the recorded uuid.
	 *
	 * @return void
	 */
	public function testUuidAnswersAsTheFormerCopy(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../Fixtures/object-record-uuid-golden.json'), true);
		$this->assertNotEmpty(actual: $golden['inputs']);
		foreach ($golden['inputs'] as $name => $record) {
			$this->assertSame(expected: $golden['IconService::extractUuid'][$name], actual: ObjectRecord::uuid(record: $record), message: $name);
		}
	}//end testUuidAnswersAsTheFormerCopy()
}//end class
