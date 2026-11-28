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
 * @spec openspec/changes/archive/2026-11-24-lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
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
use Closure;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
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
	 * OpenRegister's importer: a fake in the ImportRequest form (`main`) or the named-parameter
	 * form (`development` and earlier releases), whichever the test picks.
	 *
	 * Fakes rather than a mock of ObjectImporterInterface: which interface is loaded depends on
	 * the OpenRegister installed beside the tests, and the importer must work against both.
	 *
	 * @var object
	 */
	private object $importer;

	/**
	 * Every import call, as `[path, register, schema, user, objects]`.
	 *
	 * @var list<array{0:string,1:mixed,2:mixed,3:mixed,4:mixed}>
	 */
	private array $calls = [];

	/**
	 * What the fake importer answers, given `(path, register, schema, user)`.
	 *
	 * @var Closure
	 */
	private Closure $answer;

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
		$this->answer = static fn (): array => [];
		$this->useImporter(form: 'request');
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('has')->willReturnCallback(static fn (string $id): bool => $id === ObjectImporterInterface::class);
		$this->container->method('get')->willReturnCallback(fn (): object => $this->importer);

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
	 * Install a fake importer of one form. Both record the call and hand it to `$this->answer`.
	 *
	 * @param string $form `request` for `importFromJson(ImportRequest)`, `named` for the named-parameter form.
	 *
	 * @return void
	 */
	private function useImporter(string $form): void {
		$record = function (string $path, mixed $register, mixed $schema, mixed $user): array {
			$this->calls[] = [$path, $register, $schema, $user, json_decode((string)file_get_contents($path), true)];
			return ($this->answer)($path, $register, $schema, $user);
		};

		if ($form === 'request') {
			$this->importer = new class($record) {
				/**
				 * @param Closure $record Records and answers the call.
				 */
				public function __construct(private readonly Closure $record) {
				}

				/**
				 * @param ImportRequest $request        The import.
				 * @param bool          $_rbac          Unused.
				 * @param bool          $_multitenancy  Unused.
				 *
				 * @return array<string,mixed> The answer.
				 */
				public function importFromJson(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array {
					return ($this->record)($request->filePath, $request->scope->register, $request->scope->schema, $request->scope->currentUser);
				}
			};
			return;
		}

		$this->importer = new class($record) {
			/**
			 * @param Closure $record Records and answers the call.
			 */
			public function __construct(private readonly Closure $record) {
			}

			/**
			 * The named-parameter form of OpenRegister before the ImportRequest contract.
			 *
			 * @param string     $filePath      The file.
			 * @param mixed      $register      The register.
			 * @param mixed      $schema        The schema.
			 * @param bool       $validation    Unused.
			 * @param bool       $events        Unused.
			 * @param bool       $_rbac         Unused.
			 * @param bool       $_multitenancy Unused.
			 * @param bool       $publish       Unused.
			 * @param IUser|null $currentUser   The user.
			 *
			 * @return array<string,mixed> The answer.
			 */
			public function importFromJson(
				string $filePath,
				mixed $register = null,
				mixed $schema = null,
				bool $validation = false,
				bool $events = false,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $publish = false,
				?IUser $currentUser = null,
			): array {
				return ($this->record)($filePath, $register, $schema, $currentUser);
			}
		};

	}//end useImporter()

	/**
	 * Both forms of OpenRegister's import contract.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function forms(): array {
		return ['ImportRequest form' => ['request'], 'named-parameter form' => ['named']];

	}//end forms()

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
	 * @param string $form The import contract form (see forms()).
	 *
	 * @return void
	 */
	#[DataProvider('forms')]
	public function testFortyPermitsArriveThroughTheRegisterImport(string $form): void {
		$this->useImporter(form: $form);
		$this->answer = static fn (): array => ['JSON' => ['found' => 40, 'created' => array_fill(0, 40, []), 'updated' => [], 'errors' => []]];

		$report = $this->service()->import(['data/permit.jsonl' => self::lines(40)], self::installed());

		$this->assertSame(['imported' => 40, 'failed' => 0, 'schemas' => [['schema' => 'permit', 'imported' => 40, 'failed' => 0]]], $report);
		$this->assertCount(expectedCount: 1, haystack: $this->calls);
		[$path, $register, $schema, $user, $objects] = $this->calls[0];
		$this->assertSame(expected: $this->register, actual: $register);
		$this->assertSame(expected: 'permit', actual: $schema->getSlug());
		$this->assertSame(expected: $this->owner, actual: $user);
		$this->assertCount(expectedCount: 40, haystack: $objects);
		$this->assertSame(expected: ['name' => 'permit 40'], actual: $objects[39]);
		$this->assertFileDoesNotExist(filename: $path);

	}//end testFortyPermitsArriveThroughTheRegisterImport()

	/**
	 * One schema failing is reported with its count and does not stop the other schema's records.
	 *
	 * @param string $form The import contract form (see forms()).
	 *
	 * @return void
	 */
	#[DataProvider('forms')]
	public function testOneFailingSchemaDoesNotStopTheOthers(string $form): void {
		$this->useImporter(form: $form);
		$this->answer = static function (string $path, mixed $register, mixed $schema): array {
			if ($schema->getSlug() === 'permit') {
				throw new RuntimeException('database gone');
			}

			return ['JSON' => ['created' => [[]], 'updated' => [[]], 'errors' => []]];
		};

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
		$this->assertCount(expectedCount: 2, haystack: $this->calls);

	}//end testOneFailingSchemaDoesNotStopTheOthers()

	/**
	 * Records the import refused count as failed for their schema.
	 *
	 * @return void
	 */
	public function testRefusedRecordsCountAsFailed(): void {
		$this->answer = static fn (): array => ['JSON' => ['created' => [[]], 'updated' => [], 'errors' => [[], []]]];

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
		$report = $this->service()->import(['data/other.jsonl' => self::lines(4)], self::installed());

		$this->assertSame([['schema' => 'other', 'imported' => 0, 'failed' => 4, 'error' => 'unknown_schema']], $report['schemas']);

		$this->assertSame(expected: [], actual: $this->calls, message: 'The importer was asked.');

	}//end testAFileForAnUnknownSchemaFails()

	/**
	 * A line that is not a JSON object fails the whole file, counting every record in it.
	 *
	 * @return void
	 */
	public function testABrokenLineFailsItsFile(): void {
		$report = $this->service()->import(['data/permit.jsonl' => "{\"name\":\"a\"}\n[1,2]\n\n{\"name\":\"c\"}\n"], self::installed());

		$this->assertSame([['schema' => 'permit', 'imported' => 0, 'failed' => 3, 'error' => 'invalid_records']], $report['schemas']);

		$this->assertSame(expected: [], actual: $this->calls, message: 'The importer was asked.');

	}//end testABrokenLineFailsItsFile()

	/**
	 * An empty records file is reported as nothing imported and nothing failed.
	 *
	 * @return void
	 */
	public function testAnEmptyFileImportsNothing(): void {
		$report = $this->service()->import(['data/permit.jsonl' => "\n\n"], self::installed());

		$this->assertSame(['imported' => 0, 'failed' => 0, 'schemas' => [['schema' => 'permit', 'imported' => 0, 'failed' => 0]]], $report);

		$this->assertSame(expected: [], actual: $this->calls, message: 'The importer was asked.');

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
