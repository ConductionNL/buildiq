<?php

/**
 * Unit tests for DataRegisterExportBundler.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DataRegisterExportBundler;
use OCA\Buildiq\Service\RegisterRowReader;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the reference-only files an export writes for bound data registers: skipped bindings, schema files,
 * seed data only on request, and the failures that leave a file out.
 *
 * @covers \OCA\Buildiq\Service\DataRegisterExportBundler
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class DataRegisterExportBundlerTest extends TestCase {

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var RegisterRowReader&MockObject
	 */
	private RegisterRowReader&MockObject $rowReader;

	/**
	 * The export root of the test.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Fresh collaborators and an empty export root per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->rowReader = $this->createMock(RegisterRowReader::class);
		$this->root = sys_get_temp_dir().'/buildiq-drb-'.bin2hex(random_bytes(6));
		mkdir($this->root);
	}//end setUp()

	/**
	 * Remove the export root.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$dir = $this->root.'/lib/Settings/data-registers';
		foreach ((glob($dir.'/*') ?: []) as $file) {
			unlink($file);
		}

		foreach ([$dir, $this->root.'/lib/Settings', $this->root.'/lib', $this->root] as $path) {
			if (is_dir($path) === true) {
				rmdir($path);
			}
		}
	}//end tearDown()

	/**
	 * No bindings, non-array bindings and slug-less bindings write nothing and read nothing.
	 *
	 * @return void
	 */
	public function testEmptyOrUnusableBindingsWriteNothing(): void {
		$this->registerMapper->expects($this->never())->method('find');

		$this->bundler()->bundle($this->root, []);
		$this->bundler()->bundle($this->root, ['nope', ['register' => ''], ['includeData' => true]]);

		$this->assertDirectoryDoesNotExist($this->root.'/lib');
	}//end testEmptyOrUnusableBindingsWriteNothing()

	/**
	 * A binding whose register does not resolve is logged and skipped.
	 *
	 * @return void
	 */
	public function testADanglingRegisterIsSkipped(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('no such register'));
		$this->logger->expects($this->once())->method('info')->with(
			$this->logicalAnd($this->stringContains('binding "shared"'), $this->stringContains('no such register'))
		);

		$this->bundler()->bundle($this->root, [['register' => 'shared']]);

		$this->assertDirectoryDoesNotExist($this->root.'/lib');
	}//end testADanglingRegisterIsSkipped()

	/**
	 * The schema file holds every resolvable schema with a slug; no seed data without includeData.
	 *
	 * @return void
	 */
	public function testTheSchemaFileHoldsTheResolvableSchemas(): void {
		$register = $this->register([1, 2, 3]);
		$this->registerMapper->method('find')->with('shared')->willReturn($register);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (int $id): Schema {
				return match ($id) {
					1 => $this->schema('person', 'Person', 'A person', ['name'], ['name' => ['type' => 'string']]),
					2 => throw new RuntimeException('schema gone'),
					default => $this->schema('', 'Nameless', '', [], []),
				};
			}
		);
		$this->logger->expects($this->once())->method('debug')
			->with('Buildiq export: could not resolve schema 2 in data register "shared": schema gone');
		$this->rowReader->expects($this->never())->method('rowsInRegister');

		$this->bundler()->bundle($this->root, [['register' => 'shared']]);

		$file = $this->root.'/lib/Settings/data-registers/shared.schema.json';
		$written = json_decode((string)file_get_contents($file), true);
		$this->assertStringContainsString('"shared"', $written['_comment']);
		$this->assertSame(
			['person' => ['title' => 'Person', 'description' => 'A person', 'type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]]],
			$written['components']['schemas']
		);
		$this->assertStringEndsWith("}\n", (string)file_get_contents($file));
		$this->assertFileDoesNotExist($this->root.'/lib/Settings/data-registers/shared.seed-data.json');
	}//end testTheSchemaFileHoldsTheResolvableSchemas()

	/**
	 * With includeData the rows are written as a seed-data fixture, read without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testIncludeDataWritesTheRows(): void {
		$register = $this->register([]);
		$this->registerMapper->method('find')->willReturn($register);
		$this->rowReader->expects($this->once())->method('rowsInRegister')
			->with($register, false, false)
			->willReturn([['id' => 'r1', 'name' => 'Ada'], ['id' => 'r2', 'name' => 'Grace']]);

		$this->bundler()->bundle($this->root, [['register' => 'shared', 'includeData' => true]]);

		$seed = json_decode((string)file_get_contents($this->root.'/lib/Settings/data-registers/shared.seed-data.json'), true);
		$this->assertSame([['id' => 'r1', 'name' => 'Ada'], ['id' => 'r2', 'name' => 'Grace']], $seed['objects']);
		$this->assertStringContainsString('includeData', $seed['_comment']);
		$schemaFile = json_decode((string)file_get_contents($this->root.'/lib/Settings/data-registers/shared.schema.json'), true);
		$this->assertSame([], $schemaFile['components']['schemas']);
	}//end testIncludeDataWritesTheRows()

	/**
	 * Rows that cannot be read leave the seed file out, with a warning, but keep the schema file.
	 *
	 * @return void
	 */
	public function testUnreadableRowsLeaveTheSeedFileOut(): void {
		$this->registerMapper->method('find')->willReturn($this->register([]));
		$this->rowReader->method('rowsInRegister')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->logicalAnd($this->stringContains('data register "shared"'), $this->stringContains('NOT written: db down'))
		);

		$this->bundler()->bundle($this->root, [['register' => 'shared', 'includeData' => true]]);

		$this->assertFileExists($this->root.'/lib/Settings/data-registers/shared.schema.json');
		$this->assertFileDoesNotExist($this->root.'/lib/Settings/data-registers/shared.seed-data.json');
	}//end testUnreadableRowsLeaveTheSeedFileOut()

	/**
	 * A payload that cannot be encoded is not written, with a warning naming the path.
	 *
	 * @return void
	 */
	public function testAnUnencodablePayloadIsNotWritten(): void {
		$this->registerMapper->method('find')->willReturn($this->register([1]));
		$this->schemaMapper->method('find')->willReturn($this->schema('bad', "\xB1\x31", '', [], []));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->logicalAnd($this->stringContains('failed to encode JSON'), $this->stringEndsWith('shared.schema.json'))
		);

		$this->bundler()->bundle($this->root, [['register' => 'shared']]);

		$this->assertDirectoryExists($this->root.'/lib/Settings/data-registers');
		$this->assertFileDoesNotExist($this->root.'/lib/Settings/data-registers/shared.schema.json');
	}//end testAnUnencodablePayloadIsNotWritten()

	/**
	 * The bundler under test.
	 *
	 * @return DataRegisterExportBundler The bundler.
	 */
	private function bundler(): DataRegisterExportBundler {
		return new DataRegisterExportBundler($this->registerMapper, $this->schemaMapper, $this->logger, $this->rowReader);
	}//end bundler()

	/**
	 * A register mock with the given schema ids.
	 *
	 * @param array<int, int> $schemas The schema ids.
	 *
	 * @return Register&MockObject The register.
	 */
	private function register(array $schemas): Register&MockObject {
		$register = $this->createMock(Register::class);
		$register->method('getSchemas')->willReturn($schemas);
		return $register;
	}//end register()

	/**
	 * A schema mock.
	 *
	 * @param string               $slug        The slug.
	 * @param string               $title       The title.
	 * @param string               $description The description.
	 * @param array<int, string>   $required    The required fields.
	 * @param array<string, mixed> $properties  The properties.
	 *
	 * @return Schema&MockObject The schema.
	 */
	private function schema(string $slug, string $title, string $description, array $required, array $properties): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getTitle')->willReturn($title);
		$schema->method('getDescription')->willReturn($description);
		$schema->method('getRequired')->willReturn($required);
		$schema->method('getProperties')->willReturn($properties);
		return $schema;
	}//end schema()
}//end class
