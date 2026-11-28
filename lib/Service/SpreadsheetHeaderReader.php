<?php

/**
 * SpreadsheetHeaderReader
 *
 * Reads the header row of an uploaded CSV, XLSX or XLS file for "Start from a
 * spreadsheet", so the wizard can check it before anything is created.
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
use RuntimeException;
use Throwable;

/**
 * The header row of a spreadsheet file.
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
final class SpreadsheetHeaderReader {

	/**
	 * The XLS reader of the spreadsheet library OpenRegister ships.
	 *
	 * @var string
	 */
	private const XLS_READER = '\\PhpOffice\\PhpSpreadsheet\\Reader\\Xls';

	/**
	 * The CSV separators tried after the comma, in order.
	 *
	 * @var list<string>
	 */
	private const SEPARATORS = [';', "\t"];

	/**
	 * The header row of a file of the given type.
	 *
	 * @param string $bytes     The file contents.
	 * @param string $extension `csv`, `xlsx` or `xls`.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the file cannot be read.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function header(string $bytes, string $extension): array {
		if ($extension === 'csv') {
			return $this->csv(bytes: $bytes);
		}

		$path = $this->tempFile(bytes: $bytes, extension: $extension);
		try {
			if ($extension === 'xlsx') {
				return (new XlsxHeaderReader())->header(path: $path);
			}

			return $this->xls(path: $path);
		} finally {
			$this->removeFile(path: $path);
		}
	}//end header()

	/**
	 * The first row of a CSV file; the separator is a comma, semicolon or tab.
	 *
	 * @param string $bytes The file contents.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the file is not UTF-8 text.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function csv(string $bytes): array {
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
		foreach (self::SEPARATORS as $candidate) {
			if (substr_count($line, $candidate) > substr_count($line, $separator)) {
				$separator = $candidate;
			}
		}

		return array_map('strval', str_getcsv($line, $separator, '"', ''));
	}//end csv()

	/**
	 * The first row of an XLS (BIFF) workbook, read with the spreadsheet library OpenRegister ships.
	 *
	 * @param string $path The workbook file.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the library is not loaded or the file cannot be read.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function xls(string $path): array {
		$reader = self::XLS_READER;
		if (class_exists($reader) === false) {
			throw new InvalidArgumentException('XLS files cannot be read on this instance. Save the file as XLSX or CSV.');
		}

		try {
			$sheet = (new $reader())->load($path)->getSheet(0);
			$rows  = $sheet->rangeToArray('A1:' . $sheet->getHighestColumn() . '1', '', false, false);
		} catch (Throwable $exception) {
			throw new InvalidArgumentException('The file is not a readable XLS workbook.', 0, $exception);
		}

		return array_values(array_map('strval', ($rows[0] ?? [])));
	}//end xls()

	/**
	 * Write the bytes to a new temporary file with the given extension.
	 *
	 * @param string $bytes     The contents.
	 * @param string $extension The file extension.
	 *
	 * @return string The file's path.
	 *
	 * @throws RuntimeException When no temporary file can be made.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function tempFile(string $bytes, string $extension): string {
		$base = tempnam(sys_get_temp_dir(), 'buildiq-sheet-');
		if ($base === false) {
			throw new RuntimeException('No temporary file for the spreadsheet.');
		}

		$path = $base . '.' . $extension;
		if (rename($base, $path) === false || file_put_contents($path, $bytes) === false) {
			$this->removeFile(path: $base);
			throw new RuntimeException('No temporary file for the spreadsheet.');
		}

		return $path;
	}//end tempFile()

	/**
	 * Remove a temporary file when it is there.
	 *
	 * @param string $path The file's path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function removeFile(string $path): void {
		if (is_file($path) === true) {
			unlink($path);
		}
	}//end removeFile()
}//end class
