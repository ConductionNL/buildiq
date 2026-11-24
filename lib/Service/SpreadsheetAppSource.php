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
use ZipArchive;

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
	 * @param ContainerInterface $container Resolves OpenRegister's importer when OpenRegister is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Check a `fromFile` block and read its header row; nothing is written.
	 *
	 * @param mixed    $block        The `fromFile` block: `{name, mime?, content}` with base64 content.
	 * @param string[] $reservedSlugs Schema slugs the new app already uses.
	 *
	 * @return array{name:string,format:string,extension:string,bytes:string,columns:list<string>,schema:array<string,mixed>}
	 *
	 * @throws InvalidArgumentException When the file is missing, too large, of another type, or has no readable header row.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function read(mixed $block, array $reservedSlugs = []): array {
		if (is_array($block) === false || is_string($block['name'] ?? null) === false || is_string($block['content'] ?? null) === false) {
			throw new InvalidArgumentException('fromFile needs a file name and its contents.');
		}

		$name      = basename(trim($block['name']));
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (isset(self::FORMATS[$extension]) === false) {
			throw new InvalidArgumentException('Choose a CSV, XLS or XLSX file.');
		}

		if (strlen($block['content']) > (int)ceil(self::MAX_BYTES * 4 / 3) + 4) {
			throw new InvalidArgumentException(self::tooLarge());
		}

		$bytes = base64_decode($block['content'], true);
		if ($bytes === false || $bytes === '') {
			throw new InvalidArgumentException('The file is empty or could not be read.');
		}

		if (strlen($bytes) > self::MAX_BYTES) {
			throw new InvalidArgumentException(self::tooLarge());
		}

		$columns = self::checkedColumns(header: $this->headerRow(bytes: $bytes, extension: $extension));

		return [
			'name'      => $name,
			'format'    => self::FORMATS[$extension],
			'extension' => $extension,
			'bytes'     => $bytes,
			'columns'   => $columns,
			'schema'    => self::schemaFor(name: $name, columns: $columns, reservedSlugs: $reservedSlugs),
		];
	}//end read()

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
		$path     = tempnam(sys_get_temp_dir(), 'buildiq-sheet-');
		if ($path === false) {
			throw new RuntimeException('No temporary file for the spreadsheet.');
		}

		$typed = $path . '.' . $file['extension'];
		try {
			file_put_contents($typed, $file['bytes']);
			$request = new ImportRequest(
				filePath: $typed,
				scope: new ImportScope(register: $register, schema: $schema, currentUser: $user)
			);
			if ($file['format'] === 'csv') {
				$result = $importer->importFromCsv($request);
			} else {
				$result = $importer->importFromExcel($request);
			}
		} finally {
			@unlink($typed);
			@unlink($path);
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
			$this->importer()->softDeleteByImportJobId($importJobId);
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
		if (interface_exists(ObjectImporterInterface::class) === true && $this->container->has(ObjectImporterInterface::class) === true) {
			$importer = $this->container->get(ObjectImporterInterface::class);
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

		$sheets = [$result];
		if (array_key_exists('created', $result) === false && array_key_exists('errors', $result) === false) {
			$sheets = array_filter($result, 'is_array');
		}

		foreach ($sheets as $sheet) {
			$summary['created'] += self::countOf(value: ($sheet['created'] ?? 0));
			foreach ((array)($sheet['errors'] ?? []) as $error) {
				$summary['errors'][] = $error;
			}

			if (is_string($sheet['importJobId'] ?? null) === true) {
				$summary['importJobId'] = $sheet['importJobId'];
			}
		}

		if (is_string($result['importJobId'] ?? null) === true) {
			$summary['importJobId'] = $result['importJobId'];
		}

		return $summary;
	}//end summarise()

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
	 * The header row of a CSV or Excel file.
	 *
	 * @param string $bytes     The file contents.
	 * @param string $extension The file extension.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the file cannot be read.
	 */
	private function headerRow(string $bytes, string $extension): array {
		if ($extension === 'csv') {
			return self::csvHeader(bytes: $bytes);
		}

		if ($extension === 'xlsx') {
			return self::xlsxHeader(bytes: $bytes);
		}

		return self::xlsHeader(bytes: $bytes);
	}//end headerRow()

	/**
	 * The first row of a CSV file; the separator is a comma, semicolon or tab.
	 *
	 * @param string $bytes The file contents.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public static function csvHeader(string $bytes): array {
		if (str_starts_with($bytes, "\xEF\xBB\xBF") === true) {
			$bytes = substr($bytes, 3);
		}

		if (mb_check_encoding($bytes, 'UTF-8') === false || str_contains($bytes, "\0") === true) {
			throw new InvalidArgumentException('The file is not a readable CSV file.');
		}

		$line = strtok($bytes, "\r\n");
		if ($line === false) {
			return [];
		}

		$separator = ',';
		$best      = substr_count($line, ',');
		foreach ([';', "\t"] as $candidate) {
			if (substr_count($line, $candidate) > $best) {
				$best      = substr_count($line, $candidate);
				$separator = $candidate;
			}
		}

		return array_map('strval', str_getcsv($line, $separator, '"', ''));
	}//end csvHeader()

	/**
	 * The first row of an XLSX workbook's first sheet.
	 *
	 * @param string $bytes The file contents.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the file is not a readable workbook.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public static function xlsxHeader(string $bytes): array {
		$path = tempnam(sys_get_temp_dir(), 'buildiq-xlsx-');
		if ($path === false) {
			throw new InvalidArgumentException('The file could not be read.');
		}

		$zip = new ZipArchive();
		try {
			file_put_contents($path, $bytes);
			if ($zip->open($path) !== true) {
				throw new InvalidArgumentException('The file is not a readable XLSX workbook.');
			}

			$sheet = self::firstSheetXml(zip: $zip);
			$strings = self::sharedStrings(xml: $zip->getFromName('xl/sharedStrings.xml'));
			$zip->close();
		} finally {
			@unlink($path);
		}

		return self::firstRowOf(xml: $sheet, strings: $strings);
	}//end xlsxHeader()

	/**
	 * The XML of a workbook's first sheet.
	 *
	 * @param ZipArchive $zip The opened workbook.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the workbook has no sheet.
	 */
	private static function firstSheetXml(ZipArchive $zip): string {
		$target = 'worksheets/sheet1.xml';
		$workbook = self::xml(xml: $zip->getFromName('xl/workbook.xml'));
		$rels     = self::xml(xml: $zip->getFromName('xl/_rels/workbook.xml.rels'));
		if ($workbook !== null && $rels !== null) {
			$first = ($workbook->xpath('//*[local-name()="sheet"]') ?: [])[0] ?? null;
			$relId = (string)($first?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
			foreach (($rels->xpath('//*[local-name()="Relationship"]') ?: []) as $rel) {
				if ((string)$rel['Id'] === $relId && $relId !== '') {
					$target = ltrim(str_replace('/xl/', '', (string)$rel['Target']), '/');
				}
			}
		}

		$sheet = $zip->getFromName('xl/' . $target);
		if (is_string($sheet) === false || $sheet === '') {
			throw new InvalidArgumentException('The workbook has no sheet to read.');
		}

		return $sheet;
	}//end firstSheetXml()

	/**
	 * A workbook's shared strings, in order.
	 *
	 * @param string|false $xml The sharedStrings part, or false when there is none.
	 *
	 * @return list<string>
	 */
	private static function sharedStrings(string|false $xml): array {
		$doc = self::xml(xml: $xml);
		if ($doc === null) {
			return [];
		}

		$strings = [];
		foreach (($doc->xpath('//*[local-name()="si"]') ?: []) as $item) {
			$parts = $item->xpath('.//*[local-name()="t"]') ?: [];
			$strings[] = implode('', array_map('strval', $parts));
		}

		return $strings;
	}//end sharedStrings()

	/**
	 * The cells of a sheet's first row, as text.
	 *
	 * @param string       $xml     The sheet XML.
	 * @param list<string> $strings The workbook's shared strings.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the sheet is not readable XML.
	 */
	private static function firstRowOf(string $xml, array $strings): array {
		$doc = self::xml(xml: $xml);
		if ($doc === null) {
			throw new InvalidArgumentException('The workbook\'s sheet could not be read.');
		}

		$row = ($doc->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [])[0] ?? null;
		if ($row === null) {
			return [];
		}

		$cells = [];
		foreach (($row->xpath('./*[local-name()="c"]') ?: []) as $cell) {
			$type  = (string)$cell['t'];
			$value = (string)(($cell->xpath('./*[local-name()="v"]') ?: [''])[0]);
			if ($type === 's') {
				$value = ($strings[(int)$value] ?? '');
			} else if ($type === 'inlineStr') {
				$value = implode('', array_map('strval', ($cell->xpath('.//*[local-name()="t"]') ?: [])));
			}

			$cells[] = $value;
		}

		return $cells;
	}//end firstRowOf()

	/**
	 * Parse an XML part without network or entity loading.
	 *
	 * @param string|false $xml The XML, or false.
	 *
	 * @return \SimpleXMLElement|null
	 */
	private static function xml(string|false $xml): ?\SimpleXMLElement {
		if (is_string($xml) === false || $xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$doc      = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($doc === false) {
			return null;
		}

		return $doc;
	}//end xml()

	/**
	 * The first row of an XLS (BIFF) workbook, read with the spreadsheet library OpenRegister ships.
	 *
	 * @param string $bytes The file contents.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the library is not loaded or the file cannot be read.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public static function xlsHeader(string $bytes): array {
		$reader = '\\PhpOffice\\PhpSpreadsheet\\Reader\\Xls';
		if (class_exists($reader) === false) {
			throw new InvalidArgumentException('XLS files cannot be read on this instance. Save the file as XLSX or CSV.');
		}

		$path = tempnam(sys_get_temp_dir(), 'buildiq-xls-');
		if ($path === false) {
			throw new InvalidArgumentException('The file could not be read.');
		}

		try {
			file_put_contents($path, $bytes);
			$sheet = (new $reader())->load($path)->getSheet(0);
			$row   = ($sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', '', false, false)[0] ?? []);
		} catch (Throwable $exception) {
			throw new InvalidArgumentException('The file is not a readable XLS workbook.', 0, $exception);
		} finally {
			@unlink($path);
		}

		return array_map('strval', $row);
	}//end xlsHeader()

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

			$key = mb_strtolower($column);
			if (isset($seen[$key]) === true) {
				throw new InvalidArgumentException(sprintf('The column "%s" appears twice in the header row.', $column));
			}

			$seen[$key] = true;
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
		$stem = (string)pathinfo($name, PATHINFO_FILENAME);
		$slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($stem)), '-');
		if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
			$slug = self::FALLBACK_SLUG;
		}

		$slug = substr($slug, 0, 48);
		if (in_array($slug, $reservedSlugs, true) === true) {
			$slug .= '-data';
		}

		$properties = [];
		foreach ($columns as $column) {
			$properties[$column] = ['type' => 'string', 'title' => $column];
		}

		$title = trim($stem);
		if ($title === '') {
			$title = 'Records';
		}

		return [
			'slug'          => $slug,
			'title'         => $title,
			'description'   => 'Made from the spreadsheet ' . $name . '.',
			'properties'    => $properties,
			'configuration' => ['exportable' => true, 'objectNameField' => $columns[0]],
		];
	}//end schemaFor()

	/**
	 * The refusal of a file over the limit.
	 *
	 * @return string
	 */
	private static function tooLarge(): string {
		return sprintf('The file is larger than %d MB.', (int)(self::MAX_BYTES / 1048576));
	}//end tooLarge()
}//end class
