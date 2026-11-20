<?php

/**
 * Unit tests for ExportAppSchemaReader.
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

use OCA\Buildiq\Service\ExportAppSchemaReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the schema and record reads of an app export: missing registers and schemas, prefix stripping, the
 * definition defaults, record keys and ordering, and slug renames.
 *
 * @covers \OCA\Buildiq\Service\ExportAppSchemaReader
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class ExportAppSchemaReaderTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

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
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A register that cannot be found exports no schemas and says so.
	 *
	 * @return void
	 */
	public function testAMissingRegisterExportsNothing(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('info')
			->with('Buildiq export: register "demo" not found, no schemas exported: gone');

		$this->assertSame([], $this->reader()->collectSchemas('demo', ''));
	}//end testAMissingRegisterExportsNothing()

	/**
	 * Missing and slug-less schemas are skipped; the prefix is stripped only when something remains.
	 *
	 * @return void
	 */
	public function testSchemasAreReadWithThePrefixStripped(): void {
		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(40);
		$register->method('getSchemas')->willReturn([1, 2, 3, 4, 5]);
		$this->registerMapper->method('find')->with('demo')->willReturn($register);

		$schemas = [
			1 => $this->schema(id: 1, slug: 'demo-message', title: 'Message', version: '1.2.0', required: ['a' => 'body']),
			3 => $this->schema(id: 3, slug: ''),
			4 => $this->schema(id: 4, slug: 'demo-'),
			5 => $this->schema(id: 5, slug: 'other-thing'),
		];
		$this->schemaMapper->method('find')->willReturnCallback(
			static function (int $id) use ($schemas): Schema {
				if (isset($schemas[$id]) === false) {
					throw new RuntimeException('no schema '.$id);
				}

				return $schemas[$id];
			}
		);
		$this->logger->expects($this->once())->method('info')
			->with('Buildiq export: schema 2 not found: no schema 2');

		$out = $this->reader()->collectSchemas('demo', 'demo-');

		$this->assertSame(['demo-message', 'demo-', 'other-thing'], array_column($out, 'sourceSlug'));
		$this->assertSame(['message', 'demo-', 'other-thing'], array_column($out, 'slug'));
		$this->assertSame([40, 40, 40], array_column($out, 'registerId'));
		$this->assertSame([1, 4, 5], array_column($out, 'schemaId'));
		$this->assertSame(
			[
				'slug' => 'message',
				'type' => 'object',
				'title' => 'Message',
				'version' => '1.2.0',
				'required' => ['body'],
				'properties' => ['body' => ['type' => 'string']],
			],
			$out[0]['definition']
		);
	}//end testSchemasAreReadWithThePrefixStripped()

	/**
	 * A definition without a version gets 0.1.0; empty fields and throwing getters are left out.
	 *
	 * @return void
	 */
	public function testTheDefinitionDefaultsAndSkipsUnreadableFields(): void {
		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(9);
		$register->method('getSchemas')->willReturn([1]);
		$this->registerMapper->method('find')->willReturn($register);

		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(1);
		$schema->method('getSlug')->willReturn('thing');
		$schema->method('getTitle')->willThrowException(new RuntimeException('broken'));
		$schema->method('getDescription')->willReturn('');
		$schema->method('getVersion')->willReturn('');
		$schema->method('getRequired')->willReturn([]);
		$schema->method('getProperties')->willReturn([]);
		$schema->method('getConfiguration')->willReturn(['x' => 1]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$out = $this->reader()->collectSchemas('demo', '');

		$this->assertSame(
			['slug' => 'thing', 'type' => 'object', 'configuration' => ['x' => 1], 'version' => '0.1.0', 'required' => [], 'properties' => []],
			$out[0]['definition']
		);
	}//end testTheDefinitionDefaultsAndSkipsUnreadableFields()

	/**
	 * Records are keyed by slug or uuid, stripped of instance keys, renamed and sorted by key.
	 *
	 * @return void
	 */
	public function testRecordsAreKeyedStrippedRenamedAndSorted(): void {
		$this->objectService->expects($this->once())->method('searchObjects')->willReturnCallback(
			function (...$args): array {
				$this->assertSame(['@self' => ['register' => 40, 'schema' => 7], '_limit' => 1000], $args[0]);
				return [
					['slug' => 'zeta', 'id' => 'u-1', 'uuid' => 'u-1', '@self' => ['id' => 'u-1'], 'schema' => 'demo-message'],
					['@self' => ['id' => 'self-id'], 'body' => 'A'],
					['id' => 'plain-id', 'body' => 'B'],
					['uuid' => 'only-uuid', 'body' => 'C'],
					'not-an-object',
					['slug' => 'alpha', 'ref' => '/api/schemas/demo-message', 'nested' => ['demo-message', 3]],
				];
			}
		);

		$out = $this->reader()->collectRecords(40, 7, ['demo-message' => 'message']);

		$this->assertSame(['alpha', 'only-uuid', 'plain-id', 'self-id', 'zeta'], array_column($out, 'key'));
		$this->assertSame(['slug' => 'alpha', 'ref' => '/api/schemas/message', 'nested' => ['message', 3]], $out[0]['data']);
		$this->assertSame(['body' => 'C'], $out[1]['data']);
		$this->assertSame(['slug' => 'zeta', 'schema' => 'message'], $out[4]['data']);
	}//end testRecordsAreKeyedStrippedRenamedAndSorted()

	/**
	 * A search that throws yields no records and a warning.
	 *
	 * @return void
	 */
	public function testAFailedRecordReadYieldsNothing(): void {
		$this->objectService->method('searchObjects')->willThrowException(new RuntimeException('timeout'));
		$this->logger->expects($this->once())->method('warning')
			->with('Buildiq export: could not read records of schema 7: timeout');

		$this->assertSame([], $this->reader()->collectRecords(40, 7, []));
	}//end testAFailedRecordReadYieldsNothing()

	/**
	 * Renames touch whole values and trailing path segments only, leaving other scalars alone.
	 *
	 * @return void
	 */
	public function testRenameSlugsReplacesWholeValuesAndLastSegments(): void {
		$renames = ['old' => 'new'];

		$this->assertSame(
			['a' => 'new', 'b' => 'x/y/new', 'c' => 'older', 'd' => 'old/x', 'e' => 5, 'f' => ['g' => 'new']],
			$this->reader()->renameSlugs(['a' => 'old', 'b' => 'x/y/old', 'c' => 'older', 'd' => 'old/x', 'e' => 5, 'f' => ['g' => 'old']], $renames)
		);
	}//end testRenameSlugsReplacesWholeValuesAndLastSegments()

	/**
	 * The reader under test.
	 *
	 * @return ExportAppSchemaReader The reader.
	 */
	private function reader(): ExportAppSchemaReader {
		return new ExportAppSchemaReader($this->objectService, $this->registerMapper, $this->schemaMapper, $this->logger);
	}//end reader()

	/**
	 * A schema mock with the given values.
	 *
	 * @param int                $id       The schema id.
	 * @param string             $slug     The slug.
	 * @param string             $title    The title.
	 * @param string             $version  The version.
	 * @param array<int|string, string> $required The required list.
	 *
	 * @return Schema&MockObject The schema.
	 */
	private function schema(int $id, string $slug, string $title = '', string $version = '', array $required = []): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn($id);
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getTitle')->willReturn($title);
		$schema->method('getVersion')->willReturn($version);
		$schema->method('getRequired')->willReturn($required);
		$schema->method('getProperties')->willReturn(['body' => ['type' => 'string']]);
		return $schema;
	}//end schema()
}//end class
