<?php

/**
 * Guards that every page an app template scaffolds declares a renderable page type.
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
 * A template page type must be one the manifest schema admits and the renderer resolves.
 *
 * `CnPageRenderer` looks the type up in the `pageTypes` registry; an unknown key
 * logs a console warning and renders NOTHING, so a scaffolded app ships a blank
 * page rather than a failure anyone would notice. Two templates shipped this way
 * (`checklist`, `kanban`) — types that were never in the registry nor in the
 * manifest v2 page-type enum.
 *
 * The list below mirrors `$defs.page.properties.type.enum` in
 * `@conduction/nextcloud-vue`'s app-manifest-v2 schema, which in turn mirrors the
 * built-in `defaultPageTypes` registry. `custom` is included: it resolves through
 * the component registry rather than the page-type map.
 */
class TemplatePageTypesTest extends TestCase {

	/**
	 * Page types the manifest schema admits and the library renders.
	 *
	 * @var array<int, string>
	 */
	private const RENDERABLE_PAGE_TYPES = [
		'index',
		'detail',
		'dashboard',
		'logs',
		'settings',
		'chat',
		'files',
		'form',
		'map',
		'roadmap',
		'search',
		'wiki',
		'custom',
	];

	/**
	 * Every page in every shipped template declares a renderable type.
	 *
	 * @return void
	 */
	public function testTemplatePagesDeclareRenderableTypes(): void {
		$violations = [];

		foreach ($this->templateFiles() as $file) {
			$decoded = json_decode(file_get_contents($file), true);
			if (is_array($decoded) === false) {
				continue;
			}

			foreach ($this->collectPages($decoded) as $page) {
				$type = ($page['type'] ?? null);
				if (is_string($type) === false || in_array($type, self::RENDERABLE_PAGE_TYPES, true) === true) {
					continue;
				}

				$violations[] = sprintf(
					'%s: page "%s" declares type "%s"',
					basename($file),
					(string)($page['id'] ?? '(no id)'),
					$type
				);
			}//end foreach
		}//end foreach

		self::assertSame(
			[],
			$violations,
			"Template pages with a type the renderer cannot resolve:\n" . implode("\n", $violations)
		);

	}//end testTemplatePagesDeclareRenderableTypes()

	/**
	 * Walk a decoded template and collect every entry of every `pages` array.
	 *
	 * @param mixed $node Current node in the decoded template.
	 *
	 * @return array<int, array<string, mixed>> Page definitions found beneath the node.
	 */
	private function collectPages($node): array {
		$pages = [];

		if (is_array($node) === false) {
			return $pages;
		}

		if (isset($node['pages']) === true && is_array($node['pages']) === true) {
			foreach ($node['pages'] as $page) {
				if (is_array($page) === true) {
					$pages[] = $page;
				}
			}
		}

		foreach ($node as $key => $value) {
			if ($key === 'pages' || is_array($value) === false) {
				continue;
			}

			$pages = array_merge($pages, $this->collectPages($value));
		}

		return $pages;
	}//end collectPages()

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
