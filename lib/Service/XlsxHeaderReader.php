<?php

/**
 * XlsxHeaderReader
 *
 * Reads the first row of an XLSX workbook's first sheet with PHP's own zip and
 * XML readers, so "Start from a spreadsheet" can check the header row before
 * anything is created. Entities and network access are never loaded.
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
use SimpleXMLElement;
use ZipArchive;

/**
 * The header row of an XLSX workbook.
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
final class XlsxHeaderReader {

	/**
	 * The relationships namespace of a workbook's sheet references.
	 *
	 * @var string
	 */
	private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/**
	 * The first row of the workbook's first sheet, as text.
	 *
	 * @param string $path The workbook file.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When the file is not a readable workbook.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
	 */
	public function header(string $path): array {
		$zip = new ZipArchive();
		if ($zip->open($path) !== true) {
			throw new InvalidArgumentException('The file is not a readable XLSX workbook.');
		}

		try {
			$sheet   = $this->firstSheetXml(zip: $zip);
			$strings = $this->sharedStrings(doc: $this->xml(xml: $zip->getFromName('xl/sharedStrings.xml')));
		} finally {
			$zip->close();
		}

		return $this->firstRowOf(xml: $sheet, strings: $strings);
	}//end header()

	/**
	 * The XML of the workbook's first sheet.
	 *
	 * @param ZipArchive $zip The opened workbook.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the workbook has no sheet.
	 */
	private function firstSheetXml(ZipArchive $zip): string {
		$target = $this->firstSheetTarget(
			workbook: $this->xml(xml: $zip->getFromName('xl/workbook.xml')),
			rels: $this->xml(xml: $zip->getFromName('xl/_rels/workbook.xml.rels'))
		);

		$sheet = $zip->getFromName('xl/' . $target);
		if (is_string($sheet) === false || $sheet === '') {
			throw new InvalidArgumentException('The workbook has no sheet to read.');
		}

		return $sheet;
	}//end firstSheetXml()

	/**
	 * Where the first sheet lives inside `xl/`, from the workbook and its relationships.
	 *
	 * @param SimpleXMLElement|null $workbook The workbook part.
	 * @param SimpleXMLElement|null $rels     The workbook's relationships.
	 *
	 * @return string The sheet's path inside `xl/`; `worksheets/sheet1.xml` when it is not named.
	 */
	private function firstSheetTarget(?SimpleXMLElement $workbook, ?SimpleXMLElement $rels): string {
		$sheets = [];
		if ($workbook !== null) {
			$sheets = $this->nodes(node: $workbook, path: '//*[local-name()="sheet"]');
		}

		$relId = '';
		if (($sheets[0] ?? null) !== null) {
			$relId = (string)($sheets[0]->attributes(self::RELATIONSHIPS)['id'] ?? '');
		}

		$relations = [];
		if ($rels !== null && preg_match('/^[A-Za-z0-9_-]+$/', $relId) === 1) {
			$relations = $this->nodes(node: $rels, path: '//*[local-name()="Relationship"][@Id="' . $relId . '"]');
		}

		if (($relations[0] ?? null) === null) {
			return 'worksheets/sheet1.xml';
		}

		return ltrim(str_replace('/xl/', '', (string)$relations[0]['Target']), '/');
	}//end firstSheetTarget()

	/**
	 * A workbook's shared strings, in order.
	 *
	 * @param SimpleXMLElement|null $doc The sharedStrings part, or null when there is none.
	 *
	 * @return list<string>
	 */
	private function sharedStrings(?SimpleXMLElement $doc): array {
		if ($doc === null) {
			return [];
		}

		$strings = [];
		foreach ($this->nodes(node: $doc, path: '//*[local-name()="si"]') as $item) {
			$strings[] = $this->textOf(node: $item);
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
	private function firstRowOf(string $xml, array $strings): array {
		$doc = $this->xml(xml: $xml);
		if ($doc === null) {
			throw new InvalidArgumentException('The workbook\'s sheet could not be read.');
		}

		$rows  = $this->nodes(node: $doc, path: '//*[local-name()="sheetData"]/*[local-name()="row"]');
		$cells = [];
		if (($rows[0] ?? null) !== null) {
			$cells = $this->nodes(node: $rows[0], path: './*[local-name()="c"]');
		}

		return array_values(array_map(fn (SimpleXMLElement $cell): string => $this->cellText(cell: $cell, strings: $strings), $cells));
	}//end firstRowOf()

	/**
	 * One cell as text: a shared string, an inline string or its value.
	 *
	 * @param SimpleXMLElement $cell    The cell.
	 * @param list<string>     $strings The workbook's shared strings.
	 *
	 * @return string
	 */
	private function cellText(SimpleXMLElement $cell, array $strings): string {
		$values = $this->nodes(node: $cell, path: './*[local-name()="v"]');
		$value  = (string)($values[0] ?? '');

		return match ((string)$cell['t']) {
			's' => ($strings[(int)$value] ?? ''),
			'inlineStr' => $this->textOf(node: $cell),
			default => $value,
		};
	}//end cellText()

	/**
	 * The text runs under a node, joined.
	 *
	 * @param SimpleXMLElement $node The node.
	 *
	 * @return string
	 */
	private function textOf(SimpleXMLElement $node): string {
		return implode('', array_map('strval', $this->nodes(node: $node, path: './/*[local-name()="t"]')));
	}//end textOf()

	/**
	 * The nodes an XPath expression finds; none when it finds nothing or fails.
	 *
	 * @param SimpleXMLElement $node The node to search from.
	 * @param string           $path The XPath expression.
	 *
	 * @return list<SimpleXMLElement>
	 */
	private function nodes(SimpleXMLElement $node, string $path): array {
		$found = $node->xpath($path);
		if (is_array($found) === false) {
			return [];
		}

		return array_values($found);
	}//end nodes()

	/**
	 * Parse an XML part without network or entity loading; a part with a DOCTYPE is refused.
	 *
	 * @param string|false $xml The XML, or false.
	 *
	 * @return SimpleXMLElement|null
	 */
	private function xml(string|false $xml): ?SimpleXMLElement {
		if (is_string($xml) === false || $xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$doc      = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($doc === false) {
			return null;
		}

		return $doc;
	}//end xml()
}//end class
