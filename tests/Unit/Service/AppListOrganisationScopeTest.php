<?php

/**
 * The app list page keeps another organisation's scoped app out of view.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The app list page (`VirtualApps` in src/manifest.json) and the dashboard read
 * Application objects through OpenRegister's objects API, not through a buildiq
 * door, so the organisation guard cannot run there. What keeps another
 * organisation's app off that page is OpenRegister's organisation filter: with
 * multitenancy on, a member of organisation B gets an empty list and a 404 on an
 * Application held by organisation A (checked live, T02, 25 Nov 2026).
 *
 * This test holds the three things that filter relies on, so a later change
 * cannot quietly list a scoped app to a stranger:
 * - the Application schema grants no cross-organisation read except for apps
 *   whose scope is `all` (or absent, which means `all`);
 * - no page in src/manifest.json that lists Application objects asks
 *   OpenRegister to drop its organisation filter or its access rules;
 * - no frontend file that reads Application objects does so either.
 *
 * @coversNothing
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class AppListOrganisationScopeTest extends TestCase {

	/**
	 * The slug of the Application schema in the buildiq register.
	 */
	private const APPLICATION_SCHEMA = 'built-app';

	/**
	 * Keys that ask OpenRegister to read past its organisation filter or its
	 * access rules.
	 *
	 * @var array<int, string>
	 */
	private const BYPASS_KEYS = ['_multitenancy', '_rbac', 'multitenancy', 'crossOrganisation'];

	/**
	 * The Application schema shares rows across organisations only for apps
	 * whose scope is `all`.
	 *
	 * @return void
	 */
	public function testTheApplicationSchemaSharesOnlyAllScopedAppsAcrossOrganisations(): void {
		$schema = $this->applicationSchema();
		$this->assertNotSame([], $schema, 'The merged register has no Application schema (slug built-app).');

		$authorization = ($schema['authorization'] ?? []);
		foreach ($authorization as $action => $rules) {
			foreach ((array)$rules as $rule) {
				if (is_array($rule) === false || ($rule['crossOrganisation'] ?? false) !== true) {
					continue;
				}

				$this->assertTrue(
					$this->ruleLimitsToSharedApps(rule: $rule),
					'Application.' . $action . ' grants a cross-organisation rule that would expose a `holder` app: ' . json_encode($rule)
				);
			}
		}
	}//end testTheApplicationSchemaSharesOnlyAllScopedAppsAcrossOrganisations()

	/**
	 * The limit check itself: a cross-organisation rule must pin the scope to
	 * `all` or to absent; anything wider is refused.
	 *
	 * @return void
	 */
	public function testTheRuleCheckRefusesAWiderCrossOrganisationRule(): void {
		$this->assertTrue($this->ruleLimitsToSharedApps(rule: ['crossOrganisation' => true, 'match' => ['organisationScope' => 'all']]));
		$this->assertTrue($this->ruleLimitsToSharedApps(rule: ['crossOrganisation' => true, 'match' => ['organisationScope' => ['$exists' => false]]]));
		$this->assertFalse($this->ruleLimitsToSharedApps(rule: ['crossOrganisation' => true]));
		$this->assertFalse($this->ruleLimitsToSharedApps(rule: ['crossOrganisation' => true, 'match' => ['status' => 'published']]));
		$this->assertFalse($this->ruleLimitsToSharedApps(rule: ['crossOrganisation' => true, 'match' => ['organisationScope' => 'holder']]));
		$this->assertFalse($this->ruleLimitsToSharedApps(rule: ['crossOrganisation' => true, 'match' => ['organisationScope' => ['$ne' => 'holder']]]));
	}//end testTheRuleCheckRefusesAWiderCrossOrganisationRule()

	/**
	 * The app list page reads the Application schema from the buildiq register,
	 * and no manifest page that lists Application objects drops OpenRegister's
	 * organisation filter.
	 *
	 * @return void
	 */
	public function testNoManifestPageReadsApplicationsPastTheOrganisationFilter(): void {
		$manifest = json_decode((string)file_get_contents($this->root() . '/src/manifest.json'), true);
		$this->assertIsArray($manifest);

		$listPage = null;
		foreach (($manifest['pages'] ?? []) as $page) {
			if (($page['id'] ?? '') === 'VirtualApps') {
				$listPage = $page;
			}
		}

		$this->assertIsArray($listPage, 'The app list page VirtualApps is missing from src/manifest.json.');
		$this->assertSame('buildiq', ($listPage['config']['register'] ?? null));
		$this->assertSame(self::APPLICATION_SCHEMA, ($listPage['config']['schema'] ?? null));

		$offenders = [];
		$this->collectBypasses(node: $manifest, path: 'manifest', offenders: $offenders);
		$this->assertSame([], $offenders, 'A manifest node lists Application objects past the organisation filter.');
	}//end testNoManifestPageReadsApplicationsPastTheOrganisationFilter()

	/**
	 * No frontend file that reads Application objects asks OpenRegister to drop
	 * its organisation filter or its access rules.
	 *
	 * @return void
	 */
	public function testNoFrontendReaderOfApplicationsDropsTheOrganisationFilter(): void {
		$offenders = [];
		foreach ($this->frontendFiles() as $path) {
			$source = (string)file_get_contents($path);
			if (str_contains($source, self::APPLICATION_SCHEMA) === false) {
				continue;
			}

			if (preg_match('/_multitenancy|_rbac/', $source) === 1) {
				$offenders[] = substr($path, strlen($this->root()) + 1);
			}
		}

		$this->assertSame([], $offenders, 'These frontend files read Application objects and drop the organisation filter.');
	}//end testNoFrontendReaderOfApplicationsDropsTheOrganisationFilter()

	/**
	 * Whether a cross-organisation rule only matches apps shared with every
	 * organisation: its match pins `organisationScope` to `all` or to absent.
	 *
	 * @param array<string, mixed> $rule The read rule.
	 *
	 * @return bool
	 */
	private function ruleLimitsToSharedApps(array $rule): bool {
		$scope = ($rule['match']['organisationScope'] ?? null);
		if ($scope === 'all') {
			return true;
		}

		return is_array($scope) === true && $scope === ['$exists' => false];
	}//end ruleLimitsToSharedApps()

	/**
	 * Walk a manifest node and record each node that names the Application
	 * schema beside a key that drops OpenRegister's filter.
	 *
	 * @param mixed                $node      The node.
	 * @param string               $path      Where the node sits.
	 * @param array<int, string>   $offenders The offending paths, filled in place.
	 *
	 * @return void
	 */
	private function collectBypasses(mixed $node, string $path, array &$offenders): void {
		if (is_array($node) === false) {
			return;
		}

		$namesApplication = (($node['schema'] ?? null) === self::APPLICATION_SCHEMA || ($node['schemaSlug'] ?? null) === self::APPLICATION_SCHEMA);
		if ($namesApplication === true) {
			$found = array_intersect(self::BYPASS_KEYS, $this->keysBelow(node: $node));
			if ($found !== []) {
				$offenders[] = $path . ' (' . implode(', ', $found) . ')';
			}
		}

		foreach ($node as $key => $child) {
			$this->collectBypasses(node: $child, path: $path . '.' . $key, offenders: $offenders);
		}
	}//end collectBypasses()

	/**
	 * Every key in a node and in the nodes below it.
	 *
	 * @param array<mixed> $node The node.
	 *
	 * @return array<int, string>
	 */
	private function keysBelow(array $node): array {
		$keys = [];
		foreach ($node as $key => $child) {
			$keys[] = (string)$key;
			if (is_array($child) === true) {
				$keys = array_merge($keys, $this->keysBelow(node: $child));
			}
		}

		return $keys;
	}//end keysBelow()

	/**
	 * The Application schema of the register as SettingsService imports it: the
	 * base register with every register.d fragment merged on in file-name order.
	 *
	 * @return array<string, mixed>
	 */
	private function applicationSchema(): array {
		$settings = $this->root() . '/lib/Settings';
		$config = json_decode((string)file_get_contents($settings . '/openbuild_register.json'), true);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$merge->setAccessible(true);

		$fragments = glob($settings . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$data = json_decode((string)file_get_contents($fragment), true);
			if (is_array($data) === true) {
				$config = $merge->invoke(null, $config, $data);
			}
		}

		foreach (($config['components']['schemas'] ?? []) as $schema) {
			if (($schema['slug'] ?? null) === self::APPLICATION_SCHEMA) {
				return $schema;
			}
		}

		return [];
	}//end applicationSchema()

	/**
	 * The .js, .ts and .vue files under src/.
	 *
	 * @return array<int, string>
	 */
	private function frontendFiles(): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/src', \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if (preg_match('/\.(js|ts|vue)$/', $file->getFilename()) === 1) {
				$files[] = $file->getPathname();
			}
		}

		sort($files);
		return $files;
	}//end frontendFiles()

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()
}//end class
