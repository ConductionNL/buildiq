<?php

/**
 * SpreadsheetAppSourceTest
 *
 * @category  Tests
 * @package   OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\SpreadsheetAppSource;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectImporterInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use ZipArchive;

// OpenRegister's import contract; guarded, so a real OpenRegister beside the tests wins.
require_once __DIR__ . '/../../stubs/openregister-import.stub.php';

/**
 * Reading, checking and importing the wizard's spreadsheet.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 */
class SpreadsheetAppSourceTest extends TestCase {

	/**
	 * OpenRegister's importer.
	 *
	 * @var ObjectImporterInterface&MockObject
	 */
	private ObjectImporterInterface&MockObject $importer;

	/**
	 * The source under test, with the importer in its container.
	 *
	 * @var SpreadsheetAppSource
	 */
	private SpreadsheetAppSource $source;

	/**
	 * Wire the importer into a container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->importer = $this->createMock(originalClassName: ObjectImporterInterface::class);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === ObjectImporterInterface::class);
		$container->method('get')->willReturnCallback(fn (): object => $this->importer);
		$this->source = new SpreadsheetAppSource(container: $container);
	}//end setUp()

	/**
	 * A CSV file of 40 rows with the columns name, address and status.
	 *
	 * @return string
	 */
	public static function permitsCsv(): string {
		$lines = ['name,address,status'];
		for ($i = 1; $i <= 40; $i++) {
			$lines[] = sprintf('Permit %d,"Main street %d, Utrecht",open', $i, $i);
		}

		return implode("\n", $lines) . "\n";
	}//end permitsCsv()

	/**
	 * The wizard's `fromFile` block for a file.
	 *
	 * @param string $name  The file name.
	 * @param string $bytes The contents.
	 *
	 * @return array<string,string>
	 */
	private static function block(string $name, string $bytes): array {
		return ['name' => $name, 'mime' => 'text/csv', 'content' => base64_encode($bytes)];
	}//end block()

	/**
	 * The header row becomes one exportable schema with a text field per column.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function readMakesASchemaFromTheHeaderRow(): void {
		$file = $this->source->read(block: self::block(name: 'Permits 2026.csv', bytes: self::permitsCsv()));

		self::assertSame(expected: 'csv', actual: $file['format']);
		self::assertSame(expected: ['name', 'address', 'status'], actual: $file['columns']);
		self::assertSame(expected: 'permits-2026', actual: $file['schema']['slug']);
		self::assertSame(expected: 'Permits 2026', actual: $file['schema']['title']);
		self::assertSame(expected: ['name', 'address', 'status'], actual: array_keys($file['schema']['properties']));
		self::assertSame(expected: ['type' => 'string', 'title' => 'address'], actual: $file['schema']['properties']['address']);
		self::assertTrue(condition: $file['schema']['configuration']['exportable']);
		self::assertSame(expected: self::permitsCsv(), actual: $file['bytes']);
	}//end readMakesASchemaFromTheHeaderRow()

	/**
	 * A semicolon file with a byte order mark reads the same, and a slug the app already uses gets a suffix.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function readHandlesSemicolonsTheByteOrderMarkAndATakenSlug(): void {
		$file = $this->source->read(
			self::block(name: 'hello-message.csv', bytes: "\xEF\xBB\xBFname;address;status\r\nA;B;C\r\n"),
			['hello-message']
		);

		self::assertSame(expected: ['name', 'address', 'status'], actual: $file['columns']);
		self::assertSame(expected: 'hello-message-data', actual: $file['schema']['slug']);
	}//end readHandlesSemicolonsTheByteOrderMarkAndATakenSlug()

	/**
	 * A file name without letters gives the fallback slug.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function aFileNameWithoutLettersGivesTheFallbackSlug(): void {
		self::assertSame(expected: 'records', actual: SpreadsheetAppSource::schemaFor(name: '2026.csv', columns: ['name'])['slug']);
	}//end aFileNameWithoutLettersGivesTheFallbackSlug()

	/**
	 * The first row of an XLSX workbook, from shared and inline strings.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function readTakesTheHeaderRowOfAnXlsxWorkbook(): void {
		$file = $this->source->read(block: self::block(name: 'permits.xlsx', bytes: self::xlsx()));

		self::assertSame(expected: 'excel', actual: $file['format']);
		self::assertSame(expected: ['name', 'address', 'status'], actual: $file['columns']);
	}//end readTakesTheHeaderRowOfAnXlsxWorkbook()

	/**
	 * Files that cannot be used are refused with a reason, before anything is written.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function unreadableFilesAreRefused(): void {
		$cases = [
			'fromFile needs a file name and its contents.' => ['name' => 'x.csv'],
			'Choose a CSV, XLS or XLSX file.' => self::block(name: 'permits.pdf', bytes: 'x'),
			'The file is empty or could not be read.' => ['name' => 'permits.csv', 'content' => '***'],
			'The file is larger than 10 MB.' => ['name' => 'permits.csv', 'content' => str_repeat('A', 14000000)],
			'The file has no header row. Put the column names in the first row.' => self::block(name: 'permits.csv', bytes: ",,\nA,B,C\n"),
			'Column 2 has no name in the header row.' => self::block(name: 'permits.csv', bytes: "name,,status\n"),
			'The column "Name" appears twice in the header row.' => self::block(name: 'permits.csv', bytes: "name,Name\n"),
			'The file is not a readable CSV file.' => self::block(name: 'permits.csv', bytes: "\x00\x01\x02\xFF\xFE"),
			'The file is not a readable XLSX workbook.' => self::block(name: 'permits.xlsx', bytes: 'not a zip'),
		];

		foreach ($cases as $message => $block) {
			try {
				$this->source->read(block: $block);
				self::fail(message: 'Expected a refusal: ' . $message);
			} catch (InvalidArgumentException $e) {
				self::assertSame(expected: $message, actual: $e->getMessage());
			}
		}
	}//end unreadableFilesAreRefused()

	/**
	 * An XLS file needs the spreadsheet library OpenRegister ships; without it the maker is told to save as XLSX or CSV.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function anXlsFileWithoutTheLibraryIsRefused(): void {
		if (class_exists('\\PhpOffice\\PhpSpreadsheet\\Reader\\Xls') === true) {
			self::markTestSkipped(message: 'The spreadsheet library is loaded.');
		}

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'XLS files cannot be read on this instance. Save the file as XLSX or CSV.');
		$this->source->read(block: self::block(name: 'permits.xls', bytes: "\xD0\xCF\x11\xE0"));
	}//end anXlsFileWithoutTheLibraryIsRefused()

	/**
	 * A CSV file goes to OpenRegister's CSV import with the register, the schema and the maker.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function importHandsTheFileToOpenRegistersCsvImport(): void {
		$file = $this->source->read(block: self::block(name: 'permits.csv', bytes: self::permitsCsv()));
		$register = new Register();
		$schema = new Schema();
		$seen = null;
		$this->importer->expects(self::once())->method('importFromCsv')->willReturnCallback(
			static function (ImportRequest $request) use (&$seen): array {
				$seen = [
					'contents' => file_get_contents($request->filePath),
					'extension' => pathinfo($request->filePath, PATHINFO_EXTENSION),
					'register' => $request->scope->register,
					'schema' => $request->scope->schema,
				];
				return ['permits' => ['created' => 40, 'updated' => 0, 'errors' => []], 'importJobId' => 'job-1'];
			}
		);
		$this->importer->expects(self::never())->method('importFromExcel');

		$summary = $this->source->import(file: $file, register: $register, schema: $schema, user: null);

		self::assertSame(expected: ['created' => 40, 'errors' => [], 'importJobId' => 'job-1'], actual: $summary);
		self::assertSame(expected: self::permitsCsv(), actual: $seen['contents']);
		self::assertSame(expected: 'csv', actual: $seen['extension']);
		self::assertSame(expected: $register, actual: $seen['register']);
		self::assertSame(expected: $schema, actual: $seen['schema']);
	}//end importHandsTheFileToOpenRegistersCsvImport()

	/**
	 * An Excel file goes to OpenRegister's Excel import.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function importHandsAWorkbookToOpenRegistersExcelImport(): void {
		$file = $this->source->read(block: self::block(name: 'permits.xlsx', bytes: self::xlsx()));
		$this->importer->expects(self::once())->method('importFromExcel')->willReturn(['created' => [['id' => 1]], 'errors' => []]);

		self::assertSame(expected: 1, actual: $this->source->import(file: $file, register: new Register(), schema: new Schema(), user: null)['created']);
	}//end importHandsAWorkbookToOpenRegistersExcelImport()

	/**
	 * Without OpenRegister's importer the import fails with a reason.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function importWithoutTheImporterFails(): void {
		$file = $this->source->read(block: self::block(name: 'permits.csv', bytes: self::permitsCsv()));
		$source = new SpreadsheetAppSource(container: $this->createMock(originalClassName: ContainerInterface::class));

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'OpenRegister\'s import is not available on this instance.');
		$source->import(file: $file, register: new Register(), schema: new Schema(), user: null);
	}//end importWithoutTheImporterFails()

	/**
	 * Sheet-keyed summaries add up, errors are kept and the job id is found.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function summariseAddsUpSheets(): void {
		$summary = SpreadsheetAppSource::summarise([
			'Sheet1' => ['created' => 3, 'errors' => [['row' => 2, 'error' => 'bad']], 'importJobId' => 'job-2'],
			'Sheet2' => ['created' => 2, 'errors' => []],
		]);

		self::assertSame(expected: ['created' => 5, 'errors' => [['row' => 2, 'error' => 'bad']], 'importJobId' => 'job-2'], actual: $summary);
		self::assertSame(expected: ['created' => 0, 'errors' => [], 'importJobId' => null], actual: SpreadsheetAppSource::summarise('nothing'));
	}//end summariseAddsUpSheets()

	/**
	 * Undo hands the job to OpenRegister's rollback, and reports a failure as false.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function undoRollsTheImportJobBack(): void {
		$this->importer->expects(self::exactly(count: 2))->method('softDeleteByImportJobId')->with('job-1')
			->willReturnOnConsecutiveCalls(['deleted' => 40], self::throwException(exception: new RuntimeException('gone')));

		self::assertTrue(condition: $this->source->undo(importJobId: 'job-1'));
		self::assertFalse(condition: $this->source->undo(importJobId: 'job-1'));
	}//end undoRollsTheImportJobBack()

	/**
	 * A minimal XLSX workbook whose first row is name (shared), address (shared) and status (inline).
	 *
	 * @return string
	 */
	public static function xlsx(): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'test-xlsx-');
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
		$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
		$zip->addFromString(
			'xl/workbook.xml',
			'<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><sheets><sheet name="Permits" sheetId="1" r:id="rId1"/></sheets></workbook>'
		);
		$zip->addFromString(
			'xl/_rels/workbook.xml.rels',
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="worksheet" Target="worksheets/permits.xml"/></Relationships>'
		);
		$zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="' . $main . '"><si><t>name</t></si><si><r><t>add</t></r><r><t>ress</t></r></si></sst>');
		$zip->addFromString(
			'xl/worksheets/permits.xml',
			'<worksheet xmlns="' . $main . '"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c>'
			. '<c r="C1" t="inlineStr"><is><t>status</t></is></c></row><row r="2"><c r="A2" t="s"><v>0</v></c></row></sheetData></worksheet>'
		);
		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end xlsx()
}//end class
