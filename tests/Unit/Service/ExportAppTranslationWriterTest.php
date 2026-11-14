<?php

/**
 * Unit tests for ExportAppTranslationWriter.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ExportAppTranslationWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A built app's label translations become the exported app's l10n files.
 */
#[CoversClass(ExportAppTranslationWriter::class)]
final class ExportAppTranslationWriterTest extends TestCase {
	/**
	 * The scratch tree of the current test.
	 *
	 * @var string
	 */
	private string $root = '';

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/bq-l10n-test-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/l10n', 0o755, true);
		file_put_contents(
			$this->root . '/l10n/nl.json',
			json_encode(['translations' => ['Configuration' => 'Configuratie', 'Permits' => 'Template value']])
		);
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
		parent::tearDown();
	}//end tearDown()

	/**
	 * Dutch is merged over the template's Dutch strings; German gets a new file.
	 */
	public function testWritesOneCataloguePerLanguageMergedWithTheTemplate(): void {
		$written = (new ExportAppTranslationWriter())->write(rootDir: $this->root, i18n: $this->i18n());

		self::assertSame(['de', 'nl'], $written);
		self::assertSame(
			['Configuration' => 'Configuratie', 'Permits' => 'Vergunningen', 'Applicant' => 'Aanvrager'],
			$this->catalogue('nl')
		);
		self::assertSame(['Permits' => 'Genehmigungen'], $this->catalogue('de'));
		self::assertFileDoesNotExist($this->root . '/l10n/en.json', 'the written language is the source, not a catalogue');
	}//end testWritesOneCataloguePerLanguageMergedWithTheTemplate()

	/**
	 * Undeclared languages, the written language, bad codes and empty values are left out.
	 */
	public function testCataloguesKeepOnlyDeclaredLanguagesAndRealTranslations(): void {
		$catalogues = (new ExportAppTranslationWriter())->catalogues(i18n: [
			'sourceLanguage' => 'en',
			'languages' => ['en', 'nl', '../evil', 'fr'],
			'labels' => [
				'en' => ['Permits' => 'Permits'],
				'nl' => ['Permits' => 'Vergunningen', 'Applicant' => '  ', 'Status' => 3],
				'../evil' => ['Permits' => 'x'],
				'fr' => [],
				'it' => ['Permits' => 'Permessi'],
			],
		]);

		self::assertSame(['nl' => ['Permits' => 'Vergunningen']], $catalogues);
	}//end testCataloguesKeepOnlyDeclaredLanguagesAndRealTranslations()

	/**
	 * A manifest without an i18n block writes nothing.
	 */
	public function testNoI18nWritesNothing(): void {
		self::assertSame([], (new ExportAppTranslationWriter())->write(rootDir: $this->root, i18n: []));
		self::assertSame(['Configuration' => 'Configuratie', 'Permits' => 'Template value'], $this->catalogue('nl'));
	}//end testNoI18nWritesNothing()

	/**
	 * An i18n block with English as the written language and Dutch and German labels.
	 *
	 * @return array<string,mixed>
	 */
	private function i18n(): array {
		return [
			'sourceLanguage' => 'en',
			'languages' => ['nl', 'de'],
			'labels' => [
				'nl' => ['Permits' => 'Vergunningen', 'Applicant' => 'Aanvrager'],
				'de' => ['Permits' => 'Genehmigungen'],
			],
		];
	}//end i18n()

	/**
	 * Decode one written catalogue's translations.
	 *
	 * @param string $language The language.
	 *
	 * @return array<string,string>
	 */
	private function catalogue(string $language): array {
		$path = $this->root . '/l10n/' . $language . '.json';
		self::assertFileExists($path);
		return json_decode((string)file_get_contents($path), true)['translations'];
	}//end catalogue()
}//end class
