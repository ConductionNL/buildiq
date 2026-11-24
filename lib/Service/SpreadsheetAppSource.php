<?php

/**
 * SpreadsheetAppSource
 *
 * "Start from a spreadsheet" in the create-app wizard: checks the uploaded file
 * (CSV, XLS or XLSX) before anything is created, turns its header row into one
 * schema, and loads its rows as records through OpenRegister's register import.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportScope;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads an uploaded spreadsheet for the wizard and imports its rows.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author  Conduction b.v. <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 */
class SpreadsheetAppSource {

	/**
	 * The largest file the wizard takes, in bytes (10 MiB).
	 *
	 * @var int
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * The most columns a header row may have.
	 *
	 * @var int
	 */
	public const MAX_COLUMNS = 200;

	/**
	 * The formats the wizard takes, by file extension.
	 *
	 * @var array<string,string>
	 */
	public const FORMATS = ['csv' => 'csv', 'xlsx' => 'excel', 'xls' => 'excel'];

	/**
	 * The schema slug when the file name gives none.
	 *
	 * @var string
	 */
	public const FALLBACK_SLUG = 'records';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface      $container Resolves OpenRegister's importer when OpenRegister is installed.
	 * @param SpreadsheetHeaderReader $headers   Reads a file's header row and keeps its temporary files.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SpreadsheetHeaderReader $headers = new SpreadsheetHeaderReader(),
	) {
	}//end __construct()

	/**
	 * Check a `fromFile` block and read its header row; nothing is written.
	 *
	 * @param mixed    $block         The `fromFile` block: `{name, mime?, content}` with base64 content.
	 * @param string[] $reservedSlugs Schema slugs the new app already uses.
	 *
	 * @return array{name:string,format:string,extension:string,bytes:string,columns:list<string>,schema:array<string,mixed>}
	 *
	 * @throws InvalidArgumentException When the file is missing, too large, of another type, or has no readable header row.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function read(mixed $block, array $reservedSlugs = []): array {
		$file    = $this->decode(block: $block);
		$columns = self::checkedColumns(header: $this->headers->header(bytes: $file['bytes'], extension: $file['extension']));

		return $file + [
			'format'  => self::FORMATS[$file['extension']],
			'columns' => $columns,
			'schema'  => self::schemaFor(name: $file['name'], columns: $columns, reservedSlugs: $reservedSlugs),
		];
	}//end read()

	/**
	 * The file name, extension and bytes of a `fromFile` block, once its type and size are checked.
	 *
	 * @param mixed $block The `fromFile` block.
	 *
	 * @return array{name:string,extension:string,bytes:string}
	 *
	 * @throws InvalidArgumentException When the block has no file, the type is not taken, or the file is empty or too large.
	 */
	private function decode(mixed $block): array {
		if (is_array($block) === false || is_string($block['name'] ?? null) === false || is_string($block['content'] ?? null) === false) {
			throw new InvalidArgumentException('fromFile needs a file name and its contents.');
		}

		$name      = basename(trim($block['name']));
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (isset(self::FORMATS[$extension]) === false) {
			throw new InvalidArgumentException('Choose a CSV, XLS or XLSX file.');
		}

		// Base64 is 4 characters per 3 bytes: refuse an oversized file before decoding it.
		if (strlen($block['content']) > ((int)ceil(self::MAX_BYTES * 4 / 3) + 4)) {
			throw new InvalidArgumentException(self::tooLarge());
		}

		$bytes = base64_decode($block['content'], true);
		if ($bytes === false || $bytes === '') {
			throw new InvalidArgumentException('The file is empty or could not be read.');
		}

		if (strlen($bytes) > self::MAX_BYTES) {
			throw new InvalidArgumentException(self::tooLarge());
		}

		return ['name' => $name, 'extension' => $extension, 'bytes' => $bytes];
	}//end decode()

	/**
	 * Load the file's rows as records of its schema through OpenRegister's register import.
	 *
	 * @param array<string,mixed> $file     The file as read() returned it.
	 * @param RegisterInterface   $register The register the records go into.
	 * @param SchemaInterface     $schema   The schema made from the header row.
	 * @param IUser|null          $user     The maker the records are written as.
	 *
	 * @return array{created:int,errors:list<mixed>,importJobId:?string}
	 *
	 * @throws RuntimeException When OpenRegister's import is not available or fails.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function import(array $file, RegisterInterface $register, SchemaInterface $schema, ?IUser $user): array {
		$importer = $this->importer();
		$path     = $this->headers->tempFile(bytes: (string)$file['bytes'], extension: (string)$file['extension']);
		try {
			$request = new ImportRequest(
				filePath: $path,
				scope: new ImportScope(register: $register, schema: $schema, currentUser: $user)
			);
			$result  = match ($file['format']) {
				'csv' => $importer->importFromCsv(request: $request),
				default => $importer->importFromExcel(request: $request),
			};
		} finally {
			$this->headers->removeFile(path: $path);
		}

		return self::summarise(result: $result);
	}//end import()

	/**
	 * Undo an import by its job id, as part of the wizard's rollback.
	 *
	 * @param string $importJobId The job the import reported.
	 *
	 * @return bool True when the records were removed, false when that failed.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function undo(string $importJobId): bool {
		try {
			$this->importer()->softDeleteByImportJobId(importJobId: $importJobId);
			return true;
		} catch (Throwable $exception) {
			return false;
		}
	}//end undo()

	/**
	 * OpenRegister's register import.
	 *
	 * @return ObjectImporterInterface
	 *
	 * @throws RuntimeException When OpenRegister does not offer it.
	 */
	private function importer(): ObjectImporterInterface {
		if (interface_exists(ObjectImporterInterface::class) === true && $this->container->has(id: ObjectImporterInterface::class) === true) {
			$importer = $this->container->get(id: ObjectImporterInterface::class);
			if ($importer instanceof ObjectImporterInterface) {
				return $importer;
			}
		}

		throw new RuntimeException('OpenRegister\'s import is not available on this instance.');
	}//end importer()

	/**
	 * One import summary from OpenRegister's result, which is flat or keyed by sheet.
	 *
	 * @param mixed $result The importer's result.
	 *
	 * @return array{created:int,errors:list<mixed>,importJobId:?string}
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public static function summarise(mixed $result): array {
		$summary = ['created' => 0, 'errors' => [], 'importJobId' => null];
		if (is_array($result) === false) {
			return $summary;
		}

		$sheets = array_filter($result, 'is_array');
		if (array_key_exists('created', $result) === true || array_key_exists('errors', $result) === true) {
			$sheets = [$result];
		}

		foreach ($sheets as $sheet) {
			$summary['created'] += self::countOf(value: ($sheet['created'] ?? 0));
			array_push($summary['errors'], ...array_values((array)($sheet['errors'] ?? [])));
			$summary['importJobId'] = self::jobIdOf(summary: $sheet, current: $summary['importJobId']);
		}

		$summary['importJobId'] = self::jobIdOf(summary: $result, current: $summary['importJobId']);
		return $summary;
	}//end summarise()

	/**
	 * The import job a summary names, else the one found before.
	 *
	 * @param array<mixed> $summary The summary or sheet.
	 * @param string|null  $current The job id found so far.
	 *
	 * @return string|null
	 */
	private static function jobIdOf(array $summary, ?string $current): ?string {
		if (is_string($summary['importJobId'] ?? null) === true) {
			return $summary['importJobId'];
		}

		return $current;
	}//end jobIdOf()

	/**
	 * A count OpenRegister reports as a number or as the list of created records.
	 *
	 * @param mixed $value The reported value.
	 *
	 * @return int
	 */
	private static function countOf(mixed $value): int {
		if (is_array($value) === true) {
			return count($value);
		}

		return (int)$value;
	}//end countOf()

	/**
	 * The header row as field names: trimmed, trailing empty cells dropped, none empty or repeated.
	 *
	 * @param list<string> $header The raw header cells.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the header row is not readable as field names.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public static function checkedColumns(array $header): array {
		$columns = array_map(static fn (string $cell): string => trim($cell), $header);
		while ($columns !== [] && end($columns) === '') {
			array_pop($columns);
		}

		if ($columns === []) {
			throw new InvalidArgumentException('The file has no header row. Put the column names in the first row.');
		}

		if (count($columns) > self::MAX_COLUMNS) {
			throw new InvalidArgumentException(sprintf('The file has more than %d columns.', self::MAX_COLUMNS));
		}

		$seen = [];
		foreach ($columns as $index => $column) {
			if ($column === '') {
				throw new InvalidArgumentException(sprintf('Column %d has no name in the header row.', ($index + 1)));
			}

			if (isset($seen[mb_strtolower($column)]) === true) {
				throw new InvalidArgumentException(sprintf('The column "%s" appears twice in the header row.', $column));
			}

			$seen[mb_strtolower($column)] = true;
		}

		return array_values($columns);
	}//end checkedColumns()

	/**
	 * The schema the header row makes: one text field per column, exportable.
	 *
	 * @param string       $name          The file name.
	 * @param list<string> $columns       The checked header row.
	 * @param string[]     $reservedSlugs Schema slugs the new app already uses.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public static function schemaFor(string $name, array $columns, array $reservedSlugs = []): array {
		$stem       = trim((string)pathinfo($name, PATHINFO_FILENAME));
		$properties = [];
		foreach ($columns as $column) {
			$properties[$column] = ['type' => 'string', 'title' => $column];
		}

		$title = $stem;
		if ($title === '') {
			$title = 'Records';
		}

		return [
			'slug'          => self::slugFor(stem: $stem, reservedSlugs: $reservedSlugs),
			'title'         => $title,
			'description'   => 'Made from the spreadsheet ' . $name . '.',
			'properties'    => $properties,
			'configuration' => ['exportable' => true, 'objectNameField' => $columns[0]],
		];
	}//end schemaFor()

	/**
	 * The schema slug a file name gives: lower-kebab, starting with a letter, not one the app already uses.
	 *
	 * @param string   $stem          The file name without its extension.
	 * @param string[] $reservedSlugs Schema slugs the new app already uses.
	 *
	 * @return string
	 */
	private static function slugFor(string $stem, array $reservedSlugs): string {
		$slug = substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($stem)), '-'), 0, 48);
		if (preg_match('/^[a-z]/', $slug) !== 1) {
			$slug = self::FALLBACK_SLUG;
		}

		if (in_array($slug, $reservedSlugs, true) === true) {
			$slug .= '-data';
		}

		return $slug;
	}//end slugFor()

	/**
	 * The refusal of a file over the limit.
	 *
	 * @return string
	 */
	private static function tooLarge(): string {
		return sprintf('The file is larger than %d MB.', (int)(self::MAX_BYTES / 1048576));
	}//end tooLarge()
}//end class
