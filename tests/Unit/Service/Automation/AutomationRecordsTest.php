<?php

/**
 * Unit tests for AutomationRecords.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use JsonSerializable;
use OCA\Buildiq\Service\Automation\AutomationRecords;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every automation is read unscoped from the buildiq register as an array.
 *
 * @covers \OCA\Buildiq\Service\Automation\AutomationRecords
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class AutomationRecordsTest extends TestCase {

	/**
	 * The read asks for the automation schema of the buildiq register, unscoped,
	 * and returns each entry as an array whatever shape OpenRegister handed back.
	 *
	 * @return void
	 */
	public function testAllReadsTheAutomationSchemaUnscopedAndCoercesEveryEntry(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'a-2'];
			}
		};
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('findAll')
			->with(
				['filters' => ['register' => 'buildiq', 'schema' => 'automation'], 'limit' => 10000],
				false,
				false
			)
			->willReturn([['id' => 'a-1'], $entity, 'not-an-object']);

		$this->assertSame(
			[['id' => 'a-1'], ['id' => 'a-2'], []],
			AutomationRecords::all(objectService: $objects)
		);
	}//end testAllReadsTheAutomationSchemaUnscopedAndCoercesEveryEntry()

	/**
	 * No automations reads as an empty list.
	 *
	 * @return void
	 */
	public function testAllOfAnEmptyRegisterIsAnEmptyList(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([]);

		$this->assertSame([], AutomationRecords::all(objectService: $objects));
	}//end testAllOfAnEmptyRegisterIsAnEmptyList()

	/**
	 * A failing read reaches the caller, which decides how to report it.
	 *
	 * @return void
	 */
	public function testAllLetsAFailingReadThrough(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('register down'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('register down');
		AutomationRecords::all(objectService: $objects);
	}//end testAllLetsAFailingReadThrough()

	/**
	 * An entry exposing its data through getObject() reads as that data.
	 *
	 * @return void
	 */
	public function testToArrayFallsBackToGetObject(): void {
		$entry = new class {
			/**
			 * @return array<string,string>
			 */
			public function getObject(): array {
				return ['id' => 'a-3', 'name' => 'Fee'];
			}
		};

		$this->assertSame(['id' => 'a-3', 'name' => 'Fee'], AutomationRecords::toArray(object: $entry));
		$this->assertSame([], AutomationRecords::toArray(object: 42));
	}//end testToArrayFallsBackToGetObject()
}//end class
