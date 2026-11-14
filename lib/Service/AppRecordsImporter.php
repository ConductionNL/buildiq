<?php

/**
 * AppRecordsImporter
 *
 * Writes the records an exported archive carries (`data/<schema>.jsonl`) into a
 * newly imported app, through OpenRegister's register import, schema by schema.
 * A schema that fails is reported with the number of records that did not
 * arrive; it never undoes the app or the other schemas' records.
 *
 * @category  Service
 * @package   OCA\Buildiq\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git_id>
 *
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportScope;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Imports an archive's records into the app it was imported as.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author  Conduction b.v. <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
 */
class AppRecordsImporter {

	/**
	 * The records folder of an exported archive.
	 *
	 * @var string
	 */
	public const DATA_PREFIX = AppPackageImportService::DATA_PREFIX;

	/**
	 * The extension of a records file: one JSON object per line.
	 *
	 * @var string
	 */
	public const EXTENSION = '.jsonl';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper     $registers Finds the new app's register.
	 * @param SchemaMapper       $schemas   Finds the new app's schemas.
	 * @param IUserManager       $users     Finds the owner the records are written as.
	 * @param ContainerInterface $container Resolves OpenRegister's importer when OpenRegister is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly IUserManager $users,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The records files among an archive's entries, by schema slug.
	 *
	 * @param array<string,string> $files The archive's read entries, by path.
	 *
	 * @return array<string,string> The JSON Lines contents, by schema slug.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
	 */
	public static function recordFiles(array $files): array {
		$records = [];
		foreach ($files as $path => $contents) {
			$path = (string)$path;
			if (str_starts_with($path, self::DATA_PREFIX) === false || str_ends_with($path, self::EXTENSION) === false) {
				continue;
			}

			$slug = substr($path, strlen(self::DATA_PREFIX), -strlen(self::EXTENSION));
			if ($slug === '' || str_contains($slug, '/') === true) {
				continue;
			}

			$records[$slug] = $contents;
		}

		ksort($records);
		return $records;
	}//end recordFiles()

	/**
	 * Import the archive's records into the installed app, schema by schema.
	 *
	 * @param array<string,string> $files     The archive's read entries, by path.
	 * @param array<string,mixed>  $installed The install result: `register` (slug), `companionSchemas` (ids) and the owner under `owner`.
	 *
	 * @return array{imported:int,failed:int,schemas:list<array{schema:string,imported:int,failed:int,error?:string}>} Per schema,
	 *                                                                                                              what arrived and what not.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
	 */
	public function import(array $files, array $installed): array {
		$target = $this->target(installed: $installed);
		$report = ['imported' => 0, 'failed' => 0, 'schemas' => []];
		foreach (self::recordFiles(files: $files) as $slug => $contents) {
			$line = $this->importSchema(slug: $slug, contents: $contents, target: $target);
			$report['imported'] += $line['imported'];
			$report['failed']   += $line['failed'];
			$report['schemas'][] = $line;
		}

		return $report;
	}//end import()

	/**
	 * Import one schema's records.
	 *
	 * @param string              $slug     The schema slug the file is named after.
	 * @param string              $contents The file's JSON Lines.
	 * @param array<string,mixed> $target   The importer, register, schemas by slug and user (see target()).
	 *
	 * @return array{schema:string,imported:int,failed:int,error?:string} What arrived.
	 */
	private function importSchema(string $slug, string $contents, array $target): array {
		$objects = self::parseLines(contents: $contents);
		if ($objects === null) {
			return self::failure(slug: $slug, count: self::countLines(contents: $contents), error: 'invalid_records');
		}

		if ($objects === []) {
			return ['schema' => $slug, 'imported' => 0, 'failed' => 0];
		}

		$error = self::missing(target: $target, slug: $slug);
		if ($error !== null) {
			return self::failure(slug: $slug, count: count($objects), error: $error);
		}

		try {
			$summary = $this->write(objects: $objects, target: $target, schema: $target['schemas'][$slug]);
		} catch (Throwable $exception) {
			return self::failure(slug: $slug, count: count($objects), error: 'import_failed');
		}

		$imported = min(count($objects), self::countIn(summary: $summary, key: 'created') + self::countIn(summary: $summary, key: 'updated'));
		$line     = ['schema' => $slug, 'imported' => $imported, 'failed' => (count($objects) - $imported)];
		if ($line['failed'] > 0) {
			$line['error'] = 'records_refused';
		}

		return $line;
	}//end importSchema()

	/**
	 * Hand one schema's objects to OpenRegister's register import as a JSON array file.
	 *
	 * @param list<array<string,mixed>> $objects The records.
	 * @param array<string,mixed>       $target  The importer, register and user (see target()).
	 * @param SchemaInterface           $schema  The schema the records belong to.
	 *
	 * @return array<string,mixed> The import's summary for the schema.
	 */
	private function write(array $objects, array $target, SchemaInterface $schema): array {
		$path = tempnam(sys_get_temp_dir(), 'buildiq-records-');
		if ($path === false) {
			throw new \RuntimeException('No temporary file for the records.');
		}

		try {
			file_put_contents($path, (string)json_encode($objects));
			$result = $target['importer']->importFromJson(
				request: new ImportRequest(
					filePath: $path,
					scope: new ImportScope(register: $target['register'], schema: $schema, currentUser: $target['user'])
				)
			);
		} finally {
			@unlink($path);
		}

		$summary = ($result['JSON'] ?? $result);
		if (is_array($summary) === false) {
			return [];
		}

		return $summary;
	}//end write()

	/**
	 * Resolve where the records go: the importer, the new register, its schemas by slug and the owner.
	 *
	 * Anything that cannot be resolved is left null; each schema then fails with the reason.
	 *
	 * @param array<string,mixed> $installed The install result.
	 *
	 * @return array{importer:?ObjectImporterInterface,register:?RegisterInterface,schemas:array<string,SchemaInterface>,user:?\OCP\IUser} The target.
	 */
	private function target(array $installed): array {
		$target = ['importer' => null, 'register' => null, 'schemas' => [], 'user' => null];
		if (interface_exists(ObjectImporterInterface::class) === true && $this->container->has(ObjectImporterInterface::class) === true) {
			$target['importer'] = $this->container->get(ObjectImporterInterface::class);
		}

		$target['user'] = $this->users->get((string)($installed['owner'] ?? ''));
		try {
			$target['register'] = $this->registers->find(id: (string)($installed['register'] ?? ''), _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			$target['register'] = null;
		}

		foreach ((array)($installed['companionSchemas'] ?? []) as $schemaId) {
			try {
				$schema = $this->schemas->find(id: $schemaId, _rbac: false, _multitenancy: false);
			} catch (Throwable $exception) {
				continue;
			}

			$target['schemas'][(string)$schema->getSlug()] = $schema;
		}

		return $target;
	}//end target()

	/**
	 * Why a schema's records cannot be written, or null when they can.
	 *
	 * @param array<string,mixed> $target The target (see target()).
	 * @param string              $slug   The schema slug.
	 *
	 * @return string|null The error code.
	 */
	private static function missing(array $target, string $slug): ?string {
		if ($target['importer'] === null) {
			return 'import_unavailable';
		}

		if ($target['register'] === null) {
			return 'unknown_register';
		}

		if ($target['user'] === null) {
			return 'unknown_owner';
		}

		if (isset($target['schemas'][$slug]) === false) {
			return 'unknown_schema';
		}

		return null;
	}//end missing()

	/**
	 * The records of a JSON Lines file, or null when a line is not a JSON object.
	 *
	 * @param string $contents The file.
	 *
	 * @return list<array<string,mixed>>|null The records.
	 */
	private static function parseLines(string $contents): ?array {
		$objects = [];
		foreach (preg_split('/\r\n|\n|\r/', $contents) as $line) {
			if (trim($line) === '') {
				continue;
			}

			$object = json_decode($line, true);
			if (is_array($object) === false || array_is_list($object) === true) {
				return null;
			}

			$objects[] = $object;
		}

		return $objects;
	}//end parseLines()

	/**
	 * How many non-empty lines a file has: the records that did not arrive when it cannot be read.
	 *
	 * @param string $contents The file.
	 *
	 * @return int The count.
	 */
	private static function countLines(string $contents): int {
		return count(array_filter(preg_split('/\r\n|\n|\r/', $contents), static fn (string $line): bool => trim($line) !== ''));
	}//end countLines()

	/**
	 * The size of one list in an import summary.
	 *
	 * @param array<string,mixed> $summary The summary.
	 * @param string              $key     The list.
	 *
	 * @return int The count.
	 */
	private static function countIn(array $summary, string $key): int {
		$value = ($summary[$key] ?? []);
		if (is_int($value) === true) {
			return $value;
		}

		if (is_array($value) === false) {
			return 0;
		}

		return count($value);
	}//end countIn()

	/**
	 * A schema whose records did not arrive.
	 *
	 * @param string $slug  The schema slug.
	 * @param int    $count The records that did not arrive.
	 * @param string $error The reason.
	 *
	 * @return array{schema:string,imported:int,failed:int,error:string} The report line.
	 */
	private static function failure(string $slug, int $count, string $error): array {
		return ['schema' => $slug, 'imported' => 0, 'failed' => $count, 'error' => $error];
	}//end failure()
}//end class
