<?php

/**
 * The shipped demo dataset must not carry buildiq's own bookkeeping.
 *
 * `lib/Settings/buildiq_mock_register.json` is generated, and the generator
 * used to write three objects for every schema in the register. That register
 * holds the schemas buildiq keeps about ITSELF: the apps it has built, their
 * versions, the slug route index, the template store and the export jobs it
 * has run. Importing the dataset therefore put three apps in the Apps list and
 * on the dashboard that cannot be opened, because their `applicationVersion`
 * rows point at no application and carry a manifest with no pages.
 *
 * 🔴 EVERY ONE OF THOSE OBJECTS SATISFIED ITS SCHEMA, so the generator's own
 * `--check` was green on the dataset that broke the demo. Conformance is about
 * an object's shape, and nothing in a schema says whether its rows are content
 * somebody authors or bookkeeping the app writes. That is what this file
 * asserts instead.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Guards the generated demo dataset against ADR-111's own blind spot.
 *
 * @coversNothing
 */
class DemoDatasetExcludesControlPlaneTest extends TestCase {

	/**
	 * The generated dataset the setup walkthrough imports.
	 *
	 * @var string
	 */
	private const DATASET = '/lib/Settings/buildiq_mock_register.json';

	/**
	 * The schemas that must never appear in it, by slug.
	 *
	 * 🔴 NAMED HERE AS WELL AS READ FROM THE DESCRIPTORS, DELIBERATELY. The
	 * assertions below derive the forbidden set from the `x-openregister-demo-data`
	 * declarations, which is the single source the generator and the runtime
	 * guard also read. A test that ONLY reads that set passes when every
	 * declaration is deleted, because then nothing is forbidden and nothing can
	 * be found. This list is the control that makes the derived set falsifiable.
	 *
	 * @var array<int, string>
	 */
	private const MUST_BE_DECLARED = [
		'built-app',
		'applicationVersion',
		'built-app-route',
		'application-template',
		'export-job',
	];

	/**
	 * Repository root.
	 *
	 * @return string The absolute path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}

	/**
	 * Decode a JSON file below the repository root.
	 *
	 * @param string $relative Path relative to the root.
	 *
	 * @return array<string, mixed> The decoded document.
	 */
	private function read(string $relative): array {
		$raw = file_get_contents($this->root() . $relative);
		self::assertIsString($raw, $relative . ' is unreadable');

		$data = json_decode($raw, true);
		self::assertIsArray($data, $relative . ' is not valid JSON');

		return $data;
	}

	/**
	 * Every schema the app declares as carrying no demo data, slug => reason.
	 *
	 * Read from the app's own descriptors, the same way the generator and
	 * `DemoDataService` read them, so the three cannot disagree.
	 *
	 * @return array<string, string> Slug (and definition key) to declared reason.
	 */
	private function declaredExclusions(): array {
		$paths = array_merge(
			(glob($this->root() . '/lib/Settings/*.json') ?: []),
			(glob($this->root() . '/lib/Settings/register.d/*.json') ?: [])
		);

		$excluded = [];
		foreach ($paths as $path) {
			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false || (($data['x-openregister']['type'] ?? '') === 'mock')) {
				continue;
			}

			$schemas = ($data['components']['schemas'] ?? []);
			if (is_array($schemas) === false) {
				continue;
			}

			foreach ($schemas as $key => $schema) {
				if (is_array($schema) === false || array_key_exists('x-openregister-demo-data', $schema) === false) {
					continue;
				}

				$reason = $schema['x-openregister-demo-data'];
				$excluded[(string)$key] = is_string($reason) === true ? $reason : '';
				if (is_string(($schema['slug'] ?? null)) === true && $schema['slug'] !== '') {
					$excluded[$schema['slug']] = is_string($reason) === true ? $reason : '';
				}
			}
		}

		return $excluded;
	}

	/**
	 * 🔴 THE ASSERTION THAT GOES RED ON THE DATASET SHIPPED BEFORE THIS CHANGE.
	 * It carried three objects for each of built-app, applicationVersion,
	 * built-app-route, application-template and export-job, and one of the
	 * built-app rows was the app with slug `ccdc` that opened to an empty
	 * detail page on the demo instance on 2026-09-18.
	 *
	 * @return void
	 */
	public function testTheDatasetCarriesNoObjectForASchemaBuildiqWritesItself(): void {
		$excluded = $this->declaredExclusions();
		$dataset  = $this->read(self::DATASET);

		$offending = [];
		foreach (($dataset['components']['objects'] ?? []) as $object) {
			$schema = ($object['@self']['schema'] ?? null);
			if (is_string($schema) === true && array_key_exists($schema, $excluded) === true) {
				$offending[$schema] = (($offending[$schema] ?? 0) + 1);
			}
		}

		self::assertSame(
			[],
			$offending,
			'The demo dataset declares objects for schemas buildiq writes itself. '
			. 'Regenerate it: python3 vendor/conduction/hydra-gates/scripts/lib/generate_mock_register.py .'
		);
	}

	/**
	 * The control for the assertion above: the exclusions have to exist.
	 *
	 * Without this, deleting every `x-openregister-demo-data` key would turn
	 * the test above green while putting the broken rows straight back.
	 *
	 * @return void
	 */
	public function testTheControlPlaneSchemasStillDeclareThatTheyCarryNoDemoData(): void {
		$excluded = $this->declaredExclusions();

		foreach (self::MUST_BE_DECLARED as $slug) {
			self::assertArrayHasKey(
				$slug,
				$excluded,
				$slug . ' must declare x-openregister-demo-data: it is a schema buildiq writes itself'
			);
			self::assertNotSame('', $excluded[$slug], $slug . ' must say WHY it carries no demo data');
		}
	}

	/**
	 * And the dataset must still show the app's content, or the offer is empty.
	 *
	 * ADR-111 rule 1 wants three objects per schema so a list reads as a list.
	 * Removing the control plane must not remove the point of the dataset.
	 *
	 * @return void
	 */
	public function testTheDatasetStillCarriesTheAppsContentSchema(): void {
		$dataset = $this->read(self::DATASET);

		$counts = [];
		foreach (($dataset['components']['objects'] ?? []) as $object) {
			$schema = (string)($object['@self']['schema'] ?? '');
			$counts[$schema] = (($counts[$schema] ?? 0) + 1);
		}

		self::assertArrayHasKey('hello-message', $counts, 'the demo dataset offers nothing to look at');
		self::assertGreaterThanOrEqual(3, $counts['hello-message'], 'ADR-111 rule 1 asks for three');
	}
}
