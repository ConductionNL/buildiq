<?php

/**
 * AppRecordsImporterTest
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppRecordsImporter;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Tests for writing an imported archive's records, schema by schema.
 *
 * @covers \OCA\Buildiq\Service\AppRecordsImporter
 */
class AppRecordsImporterTest extends TestCase {

	/**
	 * OpenRegister's importer.
	 *
	 * @var ObjectImporterInterface&MockObject
	 */
	private ObjectImporterInterface&MockObject $importer;

	/**
	 * The container the importer is resolved from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registers;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemas;

	/**
	 * The user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The new app's register.
	 *
	 * @var Register
	 */
	private Register $register;

	/**
	 * The owner.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $owner;

	/**
	 * Wire a register `vergunningen` with schemas 7 (`permit`) and 8 (`zaak`), and the owner `sanne`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->importer  = $this->createMock(ObjectImporterInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('has')->willReturnCallback(static fn (string $id): bool => $id === ObjectImporterInterface::class);
		$this->container->method('get')->willReturn($this->importer);

		$this->register = new Register();
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->registers->method('find')->willReturnCallback(
			function (string|int $id): Register {
				if ($id !== 'vergunningen') {
					throw new RuntimeException('no register');
				}

				return $this->register;
			}
		);

		$slugs = [7 => 'permit', 8 => 'zaak'];
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->method('find')->willReturnCallback(
			static function (string|int $id) use ($slugs): Schema {
				if (isset($slugs[$id]) === false) {
					throw new RuntimeException('no schema');
				}

				$schema = new Schema();
				$schema->setSlug($slugs[$id]);
				return $schema;
			}
		);

		$this->owner = $this->createMock(IUser::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'sanne' ? $this->owner : null));

	}//end setUp()

	/**
	 * The importer under test.
	 *
	 * @return AppRecordsImporter The importer.
	 */
	private function service(): AppRecordsImporter {
		return new AppRecordsImporter($this->registers, $this->schemas, $this->users, $this->container);

	}//end service()

	/**
	 * The install result of the app `vergunningen`, owned by `sanne`.
	 *
	 * @param array<string,mixed> $override Fields to replace.
	 *
	 * @return array<string,mixed> The install result.
	 */
	private static function installed(array $override = []): array {
		return array_merge(['slug' => 'vergunningen', 'register' => 'vergunningen', 'companionSchemas' => [7, 8], 'owner' => 'sanne'], $override);

	}//end installed()

	/**
	 * JSON Lines of `$count` permit records.
	 *
	 * @param int $count How many.
	 *
	 * @return string The file.
	 */
	private static function lines(int $count): string {
		$lines = [];
		for ($i = 1; $i <= $count; $i++) {
			$lines[] = (string)json_encode(['name' => 'permit ' . $i]);
		}

		return implode("\n", $lines) . "\n";

	}//end lines()

	/**
	 * Forty permits arrive through OpenRegister's register import, into the new register and schema, as the owner,
	 * from a JSON array file that is gone afterwards.
	 *
	 * @return void
	 */
	public function testFortyPermitsArriveThroughTheRegisterImport(): void {
		$seen = [];
		$this->importer->expects($this->once())->method('importFromJson')
			->willReturnCallback(
				function (ImportRequest $request) use (&$seen): array {
					$seen['path'] = $request->filePath;
					$seen['objects'] = json_decode((string)file_get_contents($request->filePath), true);
					$this->assertSame($this->register, $request->scope->register);
					$this->assertSame('permit', $request->scope->schema->getSlug());
					$this->assertSame($this->owner, $request->scope->currentUser);
					return ['JSON' => ['found' => 40, 'created' => array_fill(0, 40, []), 'updated' => [], 'errors' => []]];
				}
			);

		$report = $this->service()->import(['data/permit.jsonl' => self::lines(40)], self::installed());

		$this->assertSame(['imported' => 40, 'failed' => 0, 'schemas' => [['schema' => 'permit', 'imported' => 40, 'failed' => 0]]], $report);
		$this->assertCount(40, $seen['objects']);
		$this->assertSame(['name' => 'permit 40'], $seen['objects'][39]);
		$this->assertFileDoesNotExist($seen['path']);

	}//end testFortyPermitsArriveThroughTheRegisterImport()

	/**
	 * One schema failing is reported with its count and does not stop the other schema's records.
	 *
	 * @return void
	 */
	public function testOneFailingSchemaDoesNotStopTheOthers(): void {
		$this->importer->expects($this->exactly(2))->method('importFromJson')
			->willReturnCallback(
				static function (ImportRequest $request): array {
					if ($request->scope->schema->getSlug() === 'permit') {
						throw new RuntimeException('database gone');
					}

					return ['JSON' => ['created' => [[]], 'updated' => [[]], 'errors' => []]];
				}
			);

		$report = $this->service()->import(
			['data/zaak.jsonl' => self::lines(2), 'data/permit.jsonl' => self::lines(3)],
			self::installed()
		);

		$this->assertSame(
			[
				'imported' => 2,
				'failed' => 3,
				'schemas' => [
					['schema' => 'permit', 'imported' => 0, 'failed' => 3, 'error' => 'import_failed'],
					['schema' => 'zaak', 'imported' => 2, 'failed' => 0],
				],
			],
			$report
		);

	}//end testOneFailingSchemaDoesNotStopTheOthers()

	/**
	 * Records the import refused count as failed for their schema.
	 *
	 * @return void
	 */
	public function testRefusedRecordsCountAsFailed(): void {
		$this->importer->method('importFromJson')->willReturn(['JSON' => ['created' => [[]], 'updated' => [], 'errors' => [[], []]]]);

		$report = $this->service()->import(['data/permit.jsonl' => self::lines(3)], self::installed());

		$this->assertSame([['schema' => 'permit', 'imported' => 1, 'failed' => 2, 'error' => 'records_refused']], $report['schemas']);
		$this->assertSame(2, $report['failed']);

	}//end testRefusedRecordsCountAsFailed()

	/**
	 * A records file for a schema the new app does not have fails alone, without asking the importer.
	 *
	 * @return void
	 */
	public function testAFileForAnUnknownSchemaFails(): void {
		$this->importer->expects($this->never())->method('importFromJson');

		$report = $this->service()->import(['data/other.jsonl' => self::lines(4)], self::installed());

		$this->assertSame([['schema' => 'other', 'imported' => 0, 'failed' => 4, 'error' => 'unknown_schema']], $report['schemas']);

	}//end testAFileForAnUnknownSchemaFails()

	/**
	 * A line that is not a JSON object fails the whole file, counting every record in it.
	 *
	 * @return void
	 */
	public function testABrokenLineFailsItsFile(): void {
		$this->importer->expects($this->never())->method('importFromJson');

		$report = $this->service()->import(['data/permit.jsonl' => "{\"name\":\"a\"}\n[1,2]\n\n{\"name\":\"c\"}\n"], self::installed());

		$this->assertSame([['schema' => 'permit', 'imported' => 0, 'failed' => 3, 'error' => 'invalid_records']], $report['schemas']);

	}//end testABrokenLineFailsItsFile()

	/**
	 * An empty records file is reported as nothing imported and nothing failed.
	 *
	 * @return void
	 */
	public function testAnEmptyFileImportsNothing(): void {
		$this->importer->expects($this->never())->method('importFromJson');

		$report = $this->service()->import(['data/permit.jsonl' => "\n\n"], self::installed());

		$this->assertSame(['imported' => 0, 'failed' => 0, 'schemas' => [['schema' => 'permit', 'imported' => 0, 'failed' => 0]]], $report);

	}//end testAnEmptyFileImportsNothing()

	/**
	 * Without OpenRegister's importer, an unknown register or an unknown owner, every schema fails with the reason.
	 *
	 * @return void
	 */
	public function testAMissingTargetFailsEverySchemaWithItsReason(): void {
		$files = ['data/permit.jsonl' => self::lines(1)];

		$this->assertSame('unknown_register', $this->service()->import($files, self::installed(['register' => 'gone']))['schemas'][0]['error']);
		$this->assertSame('unknown_owner', $this->service()->import($files, self::installed(['owner' => 'nobody']))['schemas'][0]['error']);

		$bare = $this->createMock(ContainerInterface::class);
		$bare->method('has')->willReturn(false);
		$bare->expects($this->never())->method('get');
		$service = new AppRecordsImporter($this->registers, $this->schemas, $this->users, $bare);
		$this->assertSame(
			[['schema' => 'permit', 'imported' => 0, 'failed' => 1, 'error' => 'import_unavailable']],
			$service->import($files, self::installed())['schemas']
		);

	}//end testAMissingTargetFailsEverySchemaWithItsReason()

	/**
	 * Only `data/<slug>.jsonl` files directly in `data/` are records, ordered by schema slug.
	 *
	 * @return void
	 */
	public function testOnlyRecordFilesAreRead(): void {
		$files = [
			'data/zaak.jsonl' => 'z',
			'data/permit.jsonl' => 'p',
			'data/nested/x.jsonl' => 'n',
			'data/.jsonl' => 'e',
			'data/readme.txt' => 't',
			'schemas/permit.json' => '{}',
		];

		$this->assertSame(['permit' => 'p', 'zaak' => 'z'], AppRecordsImporter::recordFiles($files));

	}//end testOnlyRecordFilesAreRead()
}//end class
