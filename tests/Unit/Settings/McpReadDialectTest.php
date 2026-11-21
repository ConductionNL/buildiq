<?php

/**
 * The read-only x-openregister-mcp dialect on the merged buildiq register.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merges the base register with every register.d fragment the way
 * SettingsService does, then pins that exactly five schemas declare a
 * search + get, read-only dialect whose filters are real properties, that the
 * overlays re-declaring Application and exportJob keep it, and that the
 * derived catalogue has ten read tools and no write verb.
 *
 * @covers \OCA\Buildiq\Service\SettingsService
 */
final class McpReadDialectTest extends TestCase {

	private const ON = [
		'Application' => ['status', 'appType', 'slug'],
		'ApplicationVersion' => ['application', 'status', 'semver'],
		'ApplicationTemplate' => ['category', 'useCase', 'slug'],
		'exportJob' => ['status', 'target', 'applicationUuid'],
		'Automation' => ['applicationSlug', 'enabled', 'trigger'],
	];

	/**
	 * The merged register.
	 *
	 * @return array<string,mixed>
	 */
	private function merged(): array {
		$dir = __DIR__ . '/../../../lib/Settings';
		$config = json_decode((string)file_get_contents($dir . '/openbuild_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$files = glob($dir . '/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$config = $merge->invoke(null, $config, json_decode((string)file_get_contents($file), true));
		}

		return $config;
	}//end merged()

	/**
	 * Exactly the five schemas declare the dialect, read-only, with real filters.
	 *
	 * @return void
	 */
	public function testFiveSchemasDeclareAReadOnlyDialect(): void {
		$schemas = $this->merged()['components']['schemas'];
		$declared = array_keys(array_filter($schemas, static fn (array $schema): bool => isset($schema['x-openregister-mcp']) === true));
		sort($declared);
		$expected = array_keys(self::ON);
		sort($expected);
		self::assertSame($expected, $declared);

		foreach (self::ON as $name => $filters) {
			$dialect = $schemas[$name]['x-openregister-mcp'];
			self::assertTrue($dialect['enabled'], $name);
			self::assertSame(['search', 'get'], array_keys($dialect['tools']), $name);
			foreach ($dialect['tools'] as $verb => $config) {
				self::assertSame('read', $config['scope'], $name . ' ' . $verb);
				self::assertTrue($config['readOnlyHint'], $name . ' ' . $verb);
				self::assertGreaterThan(40, strlen($config['description']), $name . ' ' . $verb);
				self::assertSame([], array_diff(array_keys($config), ['description', 'scope', 'filters', 'readOnlyHint']), $name . ' ' . $verb);
			}

			self::assertSame($filters, $dialect['tools']['search']['filters'], $name);
			foreach ($filters as $filter) {
				self::assertArrayHasKey($filter, $schemas[$name]['properties'], $name . ' filter ' . $filter);
			}

			self::assertArrayNotHasKey('filters', $dialect['tools']['get'], $name);
		}
	}//end testFiveSchemasDeclareAReadOnlyDialect()

	/**
	 * The overlays that re-declare Application and exportJob keep the dialect and add their properties.
	 *
	 * @return void
	 */
	public function testTheOverlaysKeepTheDialect(): void {
		$schemas = $this->merged()['components']['schemas'];

		self::assertArrayHasKey('githubCredentialId', $schemas['exportJob']['properties']);
		self::assertSame(self::ON['exportJob'], $schemas['exportJob']['x-openregister-mcp']['tools']['search']['filters']);
		self::assertSame(self::ON['Application'], $schemas['Application']['x-openregister-mcp']['tools']['search']['filters']);
	}//end testTheOverlaysKeepTheDialect()

	/**
	 * exportJob carries a broker credential reference, never a token field.
	 *
	 * @return void
	 */
	public function testExportJobHoldsNoSecret(): void {
		$properties = $this->merged()['components']['schemas']['exportJob']['properties'];

		self::assertStringContainsString('never a token', $properties['githubCredentialId']['description']);
		foreach (array_keys($properties) as $name) {
			self::assertDoesNotMatchRegularExpression('/token|password|secret|pat$/i', $name);
		}
	}//end testExportJobHoldsNoSecret()

	/**
	 * The derived catalogue: ten read tools, no write verb.
	 *
	 * @return void
	 */
	public function testTheDerivedCatalogueHasTenReadTools(): void {
		$ids = [];
		foreach ($this->merged()['components']['schemas'] as $schema) {
			foreach (array_keys($schema['x-openregister-mcp']['tools'] ?? []) as $verb) {
				$ids[] = 'buildiq.' . strtolower((string)$schema['slug']) . '.' . $verb;
			}
		}

		self::assertCount(10, $ids);
		self::assertCount(10, array_unique($ids));
		foreach ($ids as $id) {
			self::assertMatchesRegularExpression('/\.(search|get)$/', $id);
		}
	}//end testTheDerivedCatalogueHasTenReadTools()

	/**
	 * The agent-plan schema is registered and closed to everyone but admins.
	 *
	 * @return void
	 */
	public function testTheAgentPlanSchemaIsAdminOnly(): void {
		$config = $this->merged();
		$schema = $config['components']['schemas']['AgentPlan'];

		self::assertSame('agent-plan', $schema['slug']);
		self::assertContains('agent-plan', $config['components']['registers']['buildiq']['schemas'] ?? reset($config['components']['registers'])['schemas']);
		foreach (['read', 'create', 'update', 'delete'] as $action) {
			self::assertSame(['admin'], $schema['authorization'][$action], $action);
		}

		self::assertArrayNotHasKey('x-openregister-mcp', $schema);
	}//end testTheAgentPlanSchemaIsAdminOnly()
}//end class
