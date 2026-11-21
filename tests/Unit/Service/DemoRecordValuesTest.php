<?php

/**
 * Tests for DemoRecordValues.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DemoRecordValues;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * A demo record validates against the schema it was generated from.
 *
 * @covers \OCA\Buildiq\Service\DemoRecordValues
 */
final class DemoRecordValuesTest extends TestCase {

	/**
	 * The `report` schema of the scenario's `meldingen` app.
	 *
	 * @var array<string, mixed>
	 */
	private const REPORT = [
		'title' => ['type' => 'string', 'title' => 'Title', 'minLength' => 3, 'maxLength' => 40],
		'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
		'reportedOn' => ['type' => 'string', 'format' => 'date'],
		'reportedAt' => ['type' => 'string', 'format' => 'date-time'],
		'contact' => ['type' => 'string', 'format' => 'email'],
		'link' => ['type' => 'string', 'format' => 'uri'],
		'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2],
		'amount' => ['type' => 'number', 'exclusiveMinimum' => 10],
		'urgent' => ['type' => 'boolean'],
		'tags' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'string']],
		'address' => [
			'type' => 'object',
			'required' => ['street'],
			'properties' => ['street' => ['type' => 'string'], 'note' => ['type' => 'string']],
		],
		'code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
		'owner' => ['type' => 'string', 'format' => 'uuid'],
		'number' => ['type' => 'string', 'readOnly' => true],
		'kind' => ['type' => 'string', 'const' => 'melding'],
	];

	/**
	 * The required properties of `report`.
	 *
	 * @var list<string>
	 */
	private const REQUIRED = ['title', 'status', 'priority', 'amount', 'tags', 'address', 'kind'];

	/**
	 * Validation errors of a record against the `report` schema, '' when it holds.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string
	 */
	private static function errors(array $record): string {
		$schema = ['type' => 'object', 'required' => self::REQUIRED, 'properties' => self::REPORT];
		$result = (new Validator())->validate(
			data: json_decode(json: (string)json_encode(value: $record)),
			schema: json_decode(json: (string)json_encode(value: $schema))
		);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode(value: (new ErrorFormatter())->format(error: $result->error()));
	}//end errors()

	/**
	 * Every demo record of `report` validates against it, for several record numbers.
	 *
	 * @return void
	 */
	public function testEachDemoRecordValidatesAgainstItsSchema(): void {
		foreach ([1, 2, 3, 30] as $n) {
			$record = DemoRecordValues::record(properties: self::REPORT, required: self::REQUIRED, n: $n);
			$this->assertIsArray(actual: $record);
			$this->assertSame(expected: '', actual: self::errors(record: $record), message: 'record ' . $n);
		}
	}//end testEachDemoRecordValidatesAgainstItsSchema()

	/**
	 * The values follow the type, format, choice list and bounds, and vary by number.
	 *
	 * @return void
	 */
	public function testTheValuesFollowTheProperty(): void {
		$first = (array)DemoRecordValues::record(properties: self::REPORT, required: self::REQUIRED, n: 1);
		$second = (array)DemoRecordValues::record(properties: self::REPORT, required: self::REQUIRED, n: 2);

		$this->assertSame(expected: 'Demo Title 1', actual: $first['title']);
		$this->assertSame(expected: ['open', 'closed'], actual: [$first['status'], $second['status']]);
		$this->assertSame(expected: '2026-01-01', actual: $first['reportedOn']);
		$this->assertSame(expected: '2026-01-02T09:00:00+00:00', actual: $second['reportedAt']);
		$this->assertSame(expected: 'demo1@example.com', actual: $first['contact']);
		$this->assertSame(expected: 'https://example.com/demo-1', actual: $first['link']);
		$this->assertSame(expected: [1, 2], actual: [$first['priority'], $second['priority']]);
		$this->assertSame(expected: 11.0, actual: $first['amount']);
		$this->assertSame(expected: [true, false], actual: [$first['urgent'], $second['urgent']]);
		$this->assertSame(expected: ['Demo tags 1', 'Demo tags 1'], actual: $first['tags']);
		$this->assertSame(expected: ['street' => 'Demo street 1', 'note' => 'Demo note 1'], actual: $first['address']);
		$this->assertSame(expected: 'melding', actual: $first['kind']);
	}//end testTheValuesFollowTheProperty()

	/**
	 * An optional property no value can be made for is left out.
	 *
	 * @return void
	 */
	public function testAnOptionalUnfillablePropertyIsLeftOut(): void {
		$record = (array)DemoRecordValues::record(properties: self::REPORT, required: self::REQUIRED, n: 1);

		foreach (['code', 'owner', 'number'] as $name) {
			$this->assertArrayNotHasKey(key: $name, array: $record);
		}
	}//end testAnOptionalUnfillablePropertyIsLeftOut()

	/**
	 * A required property no value can be made for gives no record at all.
	 *
	 * @return void
	 */
	public function testARequiredUnfillablePropertyGivesNoRecord(): void {
		$properties = [
			'name' => ['type' => 'string'],
			'client' => ['type' => 'string', 'format' => 'uuid', 'x-openregister-relation' => ['target' => 'client']],
		];

		$this->assertNull(actual: DemoRecordValues::record(properties: $properties, required: ['name', 'client'], n: 1));
	}//end testARequiredUnfillablePropertyGivesNoRecord()

	/**
	 * A text is cut to its maximum and padded to its minimum length.
	 *
	 * @return void
	 */
	public function testATextKeepsItsLengthBounds(): void {
		$properties = [
			'short' => ['type' => 'string', 'maxLength' => 4],
			'long' => ['type' => 'string', 'minLength' => 20],
		];
		$record = (array)DemoRecordValues::record(properties: $properties, required: [], n: 1);

		$this->assertSame(expected: 'Demo', actual: $record['short']);
		$this->assertSame(expected: 20, actual: strlen($record['long']));
	}//end testATextKeepsItsLengthBounds()

	/**
	 * Optional lists and objects are not filled; a required empty object is an object.
	 *
	 * @return void
	 */
	public function testOptionalListsAndObjectsAreNotFilled(): void {
		$properties = [
			'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
			'meta' => ['type' => 'object'],
			'extra' => ['type' => 'object'],
			'empty' => ['type' => 'array'],
		];
		$record = (array)DemoRecordValues::record(properties: $properties, required: ['extra', 'empty'], n: 1);

		$this->assertSame(expected: ['extra', 'empty'], actual: array_keys($record));
		$this->assertSame(expected: '{}', actual: json_encode(value: $record['extra']));
		$this->assertSame(expected: [], actual: $record['empty']);
	}//end testOptionalListsAndObjectsAreNotFilled()

	/**
	 * A required list of items no value can be made for gives no record.
	 *
	 * @return void
	 */
	public function testARequiredListOfUnfillableItemsGivesNoRecord(): void {
		$properties = ['files' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'file']]];

		$this->assertNull(actual: DemoRecordValues::record(properties: $properties, required: ['files'], n: 1));
	}//end testARequiredListOfUnfillableItemsGivesNoRecord()
}//end class
