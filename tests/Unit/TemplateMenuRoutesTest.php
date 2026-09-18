<?php

/**
 * Guards that every menu entry a shipped template declares names one of its pages.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://buildiq.nl
 *
 * @spec exclude mechanical template-integrity guard, not a product behaviour
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A menu entry's `route` is a page id, never a path.
 *
 * `CnAppNav` builds each entry's target as `{ name: item.route }`, so the value
 * has to be a page id: vue-router resolves route names, and a path put there
 * matches no name at all. All four shipped templates carried paths, so every app
 * made from "Use this template" opened with an empty navigation and a console
 * error per entry. The pages themselves were fine, which is why this survived:
 * deep-link to `/applications` and the app works, click through the menu and
 * there is no menu to click.
 *
 * Watched failing: with `permit-tracker.json` reverted to `"route":
 * "/applications"`, this test reports
 * `permit-tracker.json: menu entry "Applications" routes to "/applications",
 * which is not a page id`.
 */
class TemplateMenuRoutesTest extends TestCase {

	/**
	 * Every menu entry in every shipped template names a page of that template.
	 *
	 * @return void
	 */
	public function testTemplateMenuEntriesNameAPage(): void {
		$violations = [];

		foreach ($this->templateFiles() as $file) {
			$decoded = json_decode(file_get_contents($file), true);
			if (is_array($decoded['manifest'] ?? null) === false) {
				continue;
			}

			$manifest = $decoded['manifest'];
			$pageIds = [];
			foreach (($manifest['pages'] ?? []) as $page) {
				if (is_array($page) === true && is_string($page['id'] ?? null) === true) {
					$pageIds[] = $page['id'];
				}
			}

			foreach (($manifest['menu'] ?? []) as $item) {
				if (is_array($item) === false || is_string($item['route'] ?? null) === false) {
					continue;
				}

				if (in_array($item['route'], $pageIds, true) === true) {
					continue;
				}

				$violations[] = sprintf(
					'%s: menu entry "%s" routes to "%s", which is not a page id',
					basename($file),
					(string)($item['label'] ?? '(no label)'),
					$item['route']
				);
			}//end foreach
		}//end foreach

		self::assertSame(
			[],
			$violations,
			"Template menu entries that no page answers to:\n" . implode("\n", $violations)
		);

	}//end testTemplateMenuEntriesNameAPage()

	/**
	 * Every template offers at least one menu entry, so an app made from it
	 * opens with something to click.
	 *
	 * @return void
	 */
	public function testEveryTemplateShipsAMenu(): void {
		foreach ($this->templateFiles() as $file) {
			$decoded = json_decode(file_get_contents($file), true);
			$menu = ($decoded['manifest']['menu'] ?? []);

			self::assertNotEmpty(
				$menu,
				basename($file) . ' ships no menu, so an app made from it opens with an empty navigation.'
			);
		}

	}//end testEveryTemplateShipsAMenu()

	/**
	 * Collect the app templates this app ships.
	 *
	 * @return array<int, string> Absolute paths to template JSON files.
	 */
	private function templateFiles(): array {
		$files = (glob(dirname(__DIR__, 2) . '/lib/Settings/templates/*.json') ?: []);

		self::assertNotEmpty($files, 'Expected at least one app template to scan.');

		return $files;
	}//end templateFiles()

}//end class
