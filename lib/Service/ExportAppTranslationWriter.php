<?php

/**
 * Buildiq ExportAppTranslationWriter
 *
 * Writes a built app's own label translations into an exported tree as one
 * Nextcloud `l10n/<lang>.json` file per language.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-translations-travel-with-the-app-req-bqml-005
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Turns a manifest's `i18n` block into the exported app's `l10n/` files.
 *
 * The manifest keeps translations keyed by the text as written
 * (`i18n.labels.<lang>.<source text>`), the way Nextcloud's own catalogues
 * are keyed, so each language becomes one `{"translations": {...}}` file,
 * merged over the template's own strings for that language. The written
 * language gets no file of its own beyond the template's: its labels are
 * the source.
 *
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-translations-travel-with-the-app-req-bqml-005
 */
class ExportAppTranslationWriter {
	/**
	 * A Nextcloud language code: `nl`, `de_DE`, `pt_BR`, `zh_Hans`.
	 */
	private const LANGUAGE_CODE = '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/';

	/**
	 * Write one `l10n/<lang>.json` per translated language of the manifest.
	 *
	 * @param string              $rootDir The exported tree root.
	 * @param array<string,mixed> $i18n    The manifest's `i18n` block.
	 *
	 * @return array<int,string> The languages written, sorted.
	 *
	 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-translations-travel-with-the-app-req-bqml-005
	 */
	public function write(string $rootDir, array $i18n): array {
		$written = [];
		foreach ($this->catalogues(i18n: $i18n) as $language => $labels) {
			$path = $rootDir . '/l10n/' . $language . '.json';
			$template = $this->templateStrings(path: $path);
			$this->writeCatalogue(path: $path, translations: array_merge($template, $labels));
			$written[] = $language;
		}

		sort($written);
		return $written;
	}//end write()

	/**
	 * The non-empty label translations per declared extra language.
	 *
	 * A language must be declared in `i18n.languages`, be a Nextcloud language
	 * code and differ from the written language; a translation must be a
	 * non-empty string. Anything else is left out rather than exported.
	 *
	 * @param array<string,mixed> $i18n The manifest's `i18n` block.
	 *
	 * @return array<string,array<string,string>> Translations keyed by language, then by source text.
	 *
	 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-translations-travel-with-the-app-req-bqml-005
	 */
	public function catalogues(array $i18n): array {
		$source = (string)($i18n['sourceLanguage'] ?? '');
		$declared = array_filter((array)($i18n['languages'] ?? []), 'is_string');
		$labels = (array)($i18n['labels'] ?? []);

		$catalogues = [];
		foreach ($declared as $language) {
			if ($language === $source || preg_match(self::LANGUAGE_CODE, $language) !== 1) {
				continue;
			}

			$strings = [];
			foreach ((array)($labels[$language] ?? []) as $text => $translation) {
				if (is_string($translation) === true && trim($translation) !== '' && (string)$text !== '') {
					$strings[(string)$text] = $translation;
				}
			}

			if ($strings !== []) {
				ksort($strings, SORT_STRING);
				$catalogues[$language] = $strings;
			}
		}

		return $catalogues;
	}//end catalogues()

	/**
	 * The template's own strings for a language, empty when it ships none.
	 *
	 * @param string $path The catalogue file.
	 *
	 * @return array<string,string> The template's translations.
	 */
	private function templateStrings(string $path): array {
		if (is_file($path) === false) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false || is_array($decoded['translations'] ?? null) === false) {
			return [];
		}

		return array_filter($decoded['translations'], 'is_string');
	}//end templateStrings()

	/**
	 * Write a catalogue in the template's `{"translations": {...}}` shape.
	 *
	 * @param string               $path         The catalogue file.
	 * @param array<string,string> $translations The strings.
	 *
	 * @return void
	 */
	private function writeCatalogue(string $path, array $translations): void {
		$dir = dirname($path);
		if (is_dir($dir) === false) {
			mkdir($dir, 0o755, true);
		}

		$encoded = json_encode(
			['translations' => (object)$translations],
			(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
		file_put_contents($path, $encoded . "\n");
	}//end writeCatalogue()
}//end class
