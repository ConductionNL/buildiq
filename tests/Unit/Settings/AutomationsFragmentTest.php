<?php

/**
 * Unit tests for the ADR-037 automation-designer register fragment.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Settings;

use OCA\OpenBuild\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the `40-automations.json` fragment parses and merges cleanly
 * alongside the pre-existing `10-business-rules.json` and
 * `20-data-registers.json` fragments (ADR-037 — disjoint schema keys union
 * additively, no collision).
 */
final class AutomationsFragmentTest extends TestCase {
	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load and decode a register.d fragment file.
	 *
	 * @param string $filename Basename under lib/Settings/register.d/.
	 *
	 * @return array<mixed>
	 */
	private function loadFragment(string $filename): array {
		$path = __DIR__ . '/../../../lib/Settings/register.d/' . $filename;
		$raw = file_get_contents($path);
		$this->assertIsString($raw, 'fragment file must be readable: ' . $filename);

		$decoded = json_decode($raw, true);
		$this->assertSame(JSON_ERROR_NONE, json_last_error(), 'fragment must be valid JSON: ' . $filename);
		$this->assertIsArray($decoded);

		return $decoded;
	}//end loadFragment()

	/**
	 * The fragment file itself is valid JSON declaring the Automation schema.
	 *
	 * @return void
	 */
	public function testFragmentParsesAndDeclaresAutomationSchema(): void {
		$fragment = $this->loadFragment('40-automations.json');

		$this->assertArrayHasKey('Automation', $fragment['components']['schemas']);
		$schema = $fragment['components']['schemas']['Automation'];
		$this->assertSame('automation', $schema['slug']);
		$this->assertContains('slug', $schema['required']);
		$this->assertContains('name', $schema['required']);
		$this->assertContains('applicationSlug', $schema['required']);
		$this->assertContains('versionUuid', $schema['required']);
		$this->assertContains('trigger', $schema['required']);
	}//end testFragmentParsesAndDeclaresAutomationSchema()

	/**
	 * Merging 10-business-rules.json, 20-data-registers.json and
	 * 40-automations.json in numeric-load order produces no key collisions —
	 * every schema from every fragment survives the union.
	 *
	 * @return void
	 */
	public function testMergesWithoutCollidingAgainstExistingFragments(): void {
		$businessRules = $this->loadFragment('10-business-rules.json');
		$dataRegisters = $this->loadFragment('20-data-registers.json');
		$automations = $this->loadFragment('40-automations.json');

		$merged = $this->merge([], $businessRules);
		$merged = $this->merge($merged, $dataRegisters);
		$merged = $this->merge($merged, $automations);

		$schemas = $merged['components']['schemas'];

		// Business-rules-engine schemas survive.
		$this->assertArrayHasKey('RuleSet', $schemas);
		$this->assertArrayHasKey('DecisionTable', $schemas);
		$this->assertArrayHasKey('ConditionActionRule', $schemas);
		$this->assertArrayHasKey('RuleExecutionLog', $schemas);
		$this->assertArrayHasKey('TestCase', $schemas);

		// The new Automation schema is present and untouched.
		$this->assertArrayHasKey('Automation', $schemas);
		$this->assertSame('automation', $schemas['Automation']['slug']);

		// No fragment silently dropped another's seed objects.
		if (isset($businessRules['components']['objects']) === true) {
			$this->assertArrayHasKey('objects', $merged['components']);
			$this->assertGreaterThanOrEqual(
				count($businessRules['components']['objects']),
				count($merged['components']['objects'])
			);
		}
	}//end testMergesWithoutCollidingAgainstExistingFragments()

	/**
	 * The Automation schema's slug does not collide with any pre-existing
	 * schema slug across the merged register (defence against a future
	 * fragment accidentally reusing `automation`).
	 *
	 * @return void
	 */
	public function testAutomationSlugIsUnique(): void {
		$businessRules = $this->loadFragment('10-business-rules.json');
		$dataRegisters = $this->loadFragment('20-data-registers.json');
		$automations = $this->loadFragment('40-automations.json');

		$merged = $this->merge([], $businessRules);
		$merged = $this->merge($merged, $dataRegisters);
		$merged = $this->merge($merged, $automations);

		$slugs = [];
		foreach ($merged['components']['schemas'] as $name => $schema) {
			$slug = (string)($schema['slug'] ?? '');
			$this->assertArrayNotHasKey(
				$slug,
				$slugs,
				'schema slug "' . $slug . '" is declared by both "' . ($slugs[$slug] ?? '') . '" and "' . $name . '"'
			);
			$slugs[$slug] = $name;
		}

		$this->assertArrayHasKey('automation', $slugs);
	}//end testAutomationSlugIsUnique()
}//end class
