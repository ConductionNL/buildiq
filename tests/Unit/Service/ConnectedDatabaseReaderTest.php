<?php

/**
 * Unit tests for ConnectedDatabaseReader.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\ConnectedDatabaseReader;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ConnectedDatabaseReader.
 *
 * @covers \OCA\Buildiq\Service\ConnectedDatabaseReader
 */
class ConnectedDatabaseReaderTest extends TestCase {

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registers;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * @var SourceMapperInterface&MockObject
	 */
	private SourceMapperInterface&MockObject $sources;

	/**
	 * Reader under test.
	 */
	private ConnectedDatabaseReader $reader;

	/**
	 * Set up the mappers: register `permits-db` (schemas 11 and 12) on source 7.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->sources = $this->createMock(SourceMapperInterface::class);
		$this->reader = new ConnectedDatabaseReader(registers: $this->registers, schemas: $this->schemas, sources: $this->sources);

		$this->schemas->method('find')->willReturnCallback(
			static function (int|string $id): Schema {
				$tables = [
					11 => ['slug' => 'permits', 'title' => 'Permits', 'properties' => ['number' => ['type' => 'string']]],
					12 => ['slug' => 'applicants', 'title' => '', 'properties' => ['name' => ['type' => 'string']]],
				];
				if (isset($tables[(int)$id]) === false) {
					throw new RuntimeException('no such schema');
				}

				$schema = new Schema();
				$schema->setSlug($tables[(int)$id]['slug']);
				$schema->setTitle($tables[(int)$id]['title']);
				$schema->setProperties($tables[(int)$id]['properties']);
				return $schema;
			}
		);
	}//end setUp()

	/**
	 * A register on source `$sourceId`, with schemas 11, 12 and an unreadable 99.
	 *
	 * @param string|null $sourceId The register's source.
	 * @param string      $title    The register's title.
	 *
	 * @return void
	 */
	private function givenRegister(?string $sourceId, string $title='Permits database'): void {
		$register = new Register();
		$register->setSlug('permits-db');
		$register->setTitle($title);
		$register->setSchemas([11, 12, 99]);
		$register->setSource($sourceId);
		$this->registers->method('find')->with('permits-db')->willReturn($register);
	}//end givenRegister()

	/**
	 * A source of `$type` whose auth configuration is `$authConfig`.
	 *
	 * @param string     $type       The source type.
	 * @param array|null $authConfig The auth configuration.
	 *
	 * @return void
	 */
	private function givenSource(string $type, ?array $authConfig): void {
		$source = new Source();
		$source->setType($type);
		$source->setAuthConfig($authConfig);
		$this->sources->expects(self::once())->method('findForSystem')->with('7')->willReturn($source);
	}//end givenSource()

	/**
	 * A writable database gives its tables in the order ticked, with the register's title as label.
	 *
	 * @return void
	 */
	public function testAWritableDatabaseGivesTheTickedTablesInOrder(): void {
		$this->givenRegister(sourceId: '7');
		$this->givenSource(type: 'database', authConfig: ['writable' => true]);

		$read = $this->reader->read(register: ' permits-db ', tables: ['applicants', 'permits', 'applicants']);

		self::assertSame('permits-db', $read['register']);
		self::assertSame('Permits database', $read['label']);
		self::assertTrue($read['writable']);
		self::assertSame(['applicants', 'permits'], array_column($read['schemas'], 'slug'));
		self::assertSame(['number' => ['type' => 'string']], $read['schemas'][1]['properties']);
		self::assertSame('Permits', $read['schemas'][1]['title']);
	}//end testAWritableDatabaseGivesTheTickedTablesInOrder()

	/**
	 * A database whose source does not say `writable: true` is read only, and an untitled register is labelled by its slug.
	 *
	 * @return void
	 */
	public function testADatabaseWithoutWritesIsReadOnly(): void {
		$this->givenRegister(sourceId: '7', title: '');
		$this->givenSource(type: 'database', authConfig: ['writable' => 'yes']);

		$read = $this->reader->read(register: 'permits-db', tables: ['permits']);

		self::assertFalse($read['writable']);
		self::assertSame('permits-db', $read['label']);
	}//end testADatabaseWithoutWritesIsReadOnly()

	/**
	 * A register on a source that is not a database is refused by name.
	 *
	 * @return void
	 */
	public function testARegisterOnAnotherSourceTypeIsRefused(): void {
		$this->givenRegister(sourceId: '7');
		$this->givenSource(type: 'api', authConfig: ['writable' => true]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The register "permits-db" is not a connected database.');
		$this->reader->read(register: 'permits-db', tables: ['permits']);
	}//end testARegisterOnAnotherSourceTypeIsRefused()

	/**
	 * A register with no source at all is refused without asking for one.
	 *
	 * @return void
	 */
	public function testARegisterWithoutASourceIsRefused(): void {
		$this->givenRegister(sourceId: null);
		$this->sources->expects(self::never())->method('findForSystem');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The register "permits-db" is not a connected database.');
		$this->reader->read(register: 'permits-db', tables: ['permits']);
	}//end testARegisterWithoutASourceIsRefused()

	/**
	 * A source that cannot be loaded counts as no database.
	 *
	 * @return void
	 */
	public function testASourceThatCannotBeLoadedIsRefused(): void {
		$this->givenRegister(sourceId: '7');
		$this->sources->method('findForSystem')->willThrowException(new RuntimeException('db down'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('is not a connected database');
		$this->reader->read(register: 'permits-db', tables: ['permits']);
	}//end testASourceThatCannotBeLoadedIsRefused()

	/**
	 * A register the caller cannot read is refused by name, without touching sources.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsRefused(): void {
		$this->registers->method('find')->willThrowException(new RuntimeException('not found'));
		$this->sources->expects(self::never())->method('findForSystem');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The register "publications" does not exist or cannot be read.');
		$this->reader->read(register: 'publications', tables: ['permits']);
	}//end testAnUnreadableRegisterIsRefused()

	/**
	 * A table that is not one of the register's schemas is refused by name.
	 *
	 * @return void
	 */
	public function testATableOutsideTheRegisterIsRefused(): void {
		$this->givenRegister(sourceId: '7');
		$this->givenSource(type: 'database', authConfig: ['writable' => true]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The table "salaries" is not part of the register "permits-db".');
		$this->reader->read(register: 'permits-db', tables: ['permits', 'salaries']);
	}//end testATableOutsideTheRegisterIsRefused()

	/**
	 * Ticking nothing is refused.
	 *
	 * @return void
	 */
	public function testNoTableIsRefused(): void {
		$this->givenRegister(sourceId: '7');
		$this->givenSource(type: 'database', authConfig: null);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Choose at least one table of the database.');
		$this->reader->read(register: 'permits-db', tables: ['', ' ']);
	}//end testNoTableIsRefused()

	/**
	 * An empty register slug is refused before any lookup.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterIsRefused(): void {
		$this->registers->expects(self::never())->method('find');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Choose a connected database.');
		$this->reader->read(register: '  ', tables: ['permits']);
	}//end testAnEmptyRegisterIsRefused()
}//end class
