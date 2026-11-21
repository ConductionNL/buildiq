<?php

/**
 * Tests for AppDemoRecordsService.
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

use OCA\Buildiq\Service\AppDemoRecordsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Demo records are made from the app's schemas, listed on the app, and removed exactly.
 *
 * @covers \OCA\Buildiq\Service\AppDemoRecordsService
 * @uses   \OCA\Buildiq\Service\DemoRecordValues
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class AppDemoRecordsServiceTest extends TestCase {

	/**
	 * The Application's uuid.
	 *
	 * @var string
	 */
	private const APP = 'a1b2c3d4-0000-4000-8000-000000000001';

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The saves made, as [object, schema] pairs.
	 *
	 * @var list<array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saved = [];

	/**
	 * Wire register `meldingen` with schemas `report` (fillable) and `case` (a required relation).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved = [];
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$counter = 0;
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options) use (&$counter): ObjectEntityInterface {
				$this->saved[] = [$object, $options->target->schema];
				$counter++;
				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getUuid')->willReturn('demo-' . $counter);
				return $entity;
			}
		);
	}//end setUp()

	/**
	 * The service over register `meldingen`.
	 *
	 * @return AppDemoRecordsService
	 */
	private function service(): AppDemoRecordsService {
		$register = $this->createMock(originalClassName: Register::class);
		$register->method('getSchemas')->willReturn([1, 2, 3]);
		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			static function (string|int $id) use ($register): Register {
				if ($id !== 'meldingen') {
					throw new RuntimeException('no register');
				}

				return $register;
			}
		);

		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				return match ($id) {
					1 => $this->schema(slug: 'report', properties: ['title' => ['type' => 'string']], required: ['title']),
					2 => $this->schema(slug: 'case', properties: ['client' => ['type' => 'string', 'format' => 'uuid']], required: ['client']),
					default => throw new RuntimeException('gone'),
				};
			}
		);

		return new AppDemoRecordsService(objectService: $this->objects, registerMapper: $registers, schemaMapper: $schemas, logger: new NullLogger());
	}//end service()

	/**
	 * A schema mock.
	 *
	 * @param string               $slug       Its slug.
	 * @param array<string, mixed> $properties Its properties.
	 * @param list<string>         $required   Its required names.
	 *
	 * @return Schema
	 */
	private function schema(string $slug, array $properties, array $required): Schema {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getProperties')->willReturn($properties);
		$schema->method('getRequired')->willReturn($required);
		return $schema;
	}//end schema()

	/**
	 * Filling makes three records per fillable schema, skips the one it cannot fill, and lists them on the app.
	 *
	 * @return void
	 */
	public function testFillingMakesRecordsAndListsThemOnTheApp(): void {
		$result = $this->service()->fill(applicationUuid: self::APP, application: ['slug' => 'meldingen'], register: 'meldingen');

		$this->assertSame(expected: ['created' => 3, 'skipped' => ['case'], 'failed' => 0, 'total' => 3], actual: $result);
		$this->assertSame(expected: ['report', 'report', 'report', 'built-app'], actual: array_column($this->saved, 1));
		$this->assertSame(expected: ['title' => 'Demo title 2'], actual: $this->saved[1][0]);
		$this->assertSame(
			expected: [
				['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'demo-1'],
				['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'demo-2'],
				['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'demo-3'],
			],
			actual: $this->saved[3][0]['demoRecords']
		);
	}//end testFillingMakesRecordsAndListsThemOnTheApp()

	/**
	 * A second fill keeps the earlier demo records listed.
	 *
	 * @return void
	 */
	public function testASecondFillAddsToTheList(): void {
		$application = ['slug' => 'meldingen', 'demoRecords' => [['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'old-1']]];

		$result = $this->service()->fill(applicationUuid: self::APP, application: $application, register: 'meldingen');

		$listed = $this->saved[3][0]['demoRecords'];
		$this->assertSame(expected: 4, actual: count($listed));
		$this->assertSame(expected: 'old-1', actual: $listed[0]['uuid']);
		$this->assertSame(expected: 4, actual: $result['total']);
	}//end testASecondFillAddsToTheList()

	/**
	 * A refused record is counted, and the app is not saved when nothing was made.
	 *
	 * @return void
	 */
	public function testARefusedRecordIsCountedAndNothingIsListed(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->exactly(count: 3))->method('saveObject')->willThrowException(new RuntimeException('invalid'));
		$this->objects = $objects;

		$result = $this->service()->fill(applicationUuid: self::APP, application: [], register: 'meldingen');

		$this->assertSame(expected: ['created' => 0, 'skipped' => ['case'], 'failed' => 3, 'total' => 0], actual: $result);
	}//end testARefusedRecordIsCountedAndNothingIsListed()

	/**
	 * An unreadable register makes nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterMakesNothing(): void {
		$this->objects->expects($this->never())->method('saveObject');

		$result = $this->service()->fill(applicationUuid: self::APP, application: [], register: 'other');

		$this->assertSame(expected: ['created' => 0, 'skipped' => [], 'failed' => 0, 'total' => 0], actual: $result);
	}//end testAnUnreadableRegisterMakesNothing()

	/**
	 * Removing deletes exactly the listed records; one that cannot be deleted stays listed.
	 *
	 * @return void
	 */
	public function testRemovingDeletesTheListedRecordsOnly(): void {
		$deleted = [];
		$this->objects->method('deleteObject')->willReturnCallback(
			static function (ObjectDeletion $deletion) use (&$deleted): bool {
				if ($deletion->uuid === 'stuck') {
					throw new RuntimeException('locked');
				}

				$deleted[] = [$deletion->uuid, $deletion->target->schema];
				return true;
			}
		);
		$application = [
			'slug' => 'meldingen',
			'demoRecords' => [
				['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'demo-1'],
				['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'stuck'],
				['schema' => 'report'],
			],
		];

		$result = $this->service()->remove(applicationUuid: self::APP, application: $application);

		$this->assertSame(expected: ['removed' => 1, 'failed' => 1], actual: $result);
		$this->assertSame(expected: [['demo-1', 'report']], actual: $deleted);
		$this->assertSame(expected: [['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'stuck']], actual: $this->saved[0][0]['demoRecords']);
	}//end testRemovingDeletesTheListedRecordsOnly()

	/**
	 * Removing from an app without demo records deletes and saves nothing.
	 *
	 * @return void
	 */
	public function testRemovingWithoutDemoRecordsDoesNothing(): void {
		$this->objects->expects($this->never())->method('deleteObject');
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertSame(expected: ['removed' => 0, 'failed' => 0], actual: $this->service()->remove(applicationUuid: self::APP, application: []));
	}//end testRemovingWithoutDemoRecordsDoesNothing()

	/**
	 * The app and its production register are read from buildiq's register.
	 *
	 * @return void
	 */
	public function testTheAppAndItsProductionRegisterAreRead(): void {
		$stored = ['app-1' => ['slug' => 'meldingen', 'productionVersion' => 'v-1'], 'v-1' => ['register' => 'meldingen']];
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id) use ($stored): ?ObjectEntityInterface {
				if ($id === 'boom') {
					throw new RuntimeException('down');
				}

				if (isset($stored[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($stored[$id]);
				return $entity;
			}
		);
		$service = $this->service();

		$application = (array)$service->application(uuid: 'app-1');
		$this->assertSame(expected: 'meldingen', actual: $service->productionRegister(application: $application));
		$this->assertSame(expected: 'embedded', actual: $service->productionRegister(application: ['productionVersion' => ['register' => 'embedded']]));
		$this->assertSame(expected: '', actual: $service->productionRegister(application: []));
		$this->assertNull(actual: $service->application(uuid: 'boom'));
		$this->assertNull(actual: $service->application(uuid: ''));
	}//end testTheAppAndItsProductionRegisterAreRead()
}//end class
