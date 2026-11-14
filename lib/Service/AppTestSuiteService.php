<?php

/**
 * App Test Suite Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Runs the test suite of one version of a built app and answers a report: one
 * entry per check with its kind, name, outcome (passed, failed or skipped) and
 * a message (lifecycle-release-test-gate D1).
 *
 * - Structure: every page's register and schema exist, every index column and
 *   detail field names a property the page's schema has, and every menu item
 *   leads to a page of the version.
 * - Rule sets: the stored test cases of every rule set whose owner app is this
 *   app, through RuleSetVersioningService::runTestGate().
 * - Record tests: the app's record tests. OpenRegister cannot validate a record
 *   without saving it yet, so each is reported skipped with that reason, never
 *   passed.
 *
 * A schema that cannot be read fails its checks rather than passing them: a
 * check that silently did not run looks exactly like one that passed.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Kernel\Contracts\RuleEngineServiceInterface;
use Throwable;

/**
 * Builds the report of an app version's test suite.
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 */
class AppTestSuiteService {

	public const KIND_STRUCTURE = 'structure';
	public const KIND_RULE_SET = 'rule-set';
	public const KIND_RECORD = 'record';

	public const PASSED = 'passed';
	public const FAILED = 'failed';
	public const SKIPPED = 'skipped';

	/**
	 * The schema slug of a record test.
	 */
	public const RECORD_TEST_SCHEMA = 'app-record-test';

	/**
	 * Why a record test does not run yet (REQ-BQRT-003).
	 */
	public const RECORD_TEST_SKIP_REASON = 'OpenRegister cannot check a record without saving it yet, so this record test did not run.';

	/**
	 * Property lists already read in this run, by "register/schema"; null when
	 * the register holds no such schema.
	 *
	 * @var array<string, array<int, string>|null>
	 */
	private array $properties = [];

	/**
	 * Constructor.
	 *
	 * @param RegistrationFormTargetSchemaReader $schemas    Reads a schema of a register.
	 * @param RuleObjectReader                   $objects    Reads rule sets, their test cases and record tests.
	 * @param RuleSetVersioningService           $versioning Runs a rule set's test cases.
	 */
	public function __construct(
		private readonly RegistrationFormTargetSchemaReader $schemas,
		private readonly RuleObjectReader $objects,
		private readonly RuleSetVersioningService $versioning,
	) {
	}//end __construct()

	/**
	 * Run the suite of one version of an app.
	 *
	 * @param array<string, mixed> $application The built app (slug, uuid or id).
	 * @param array<string, mixed> $version     The version, with its manifest.
	 *
	 * @return array{passed: int, failed: int, skipped: int, checks: list<array{kind: string, name: string, outcome: string, message: string}>}
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-the-suite-checks-the-apps-structure-req-bqrt-001
	 */
	public function run(array $application, array $version): array {
		$this->properties = [];
		$manifest = $version['manifest'] ?? [];
		if (is_array($manifest) === false) {
			$manifest = [];
		}

		$checks = array_merge(
			$this->structureChecks(manifest: $manifest),
			$this->ruleSetChecks(appSlug: (string)($application['slug'] ?? '')),
			$this->recordChecks(appId: self::idOf(object: $application))
		);

		$counts = array_count_values(array_column($checks, 'outcome'));

		return [
			'passed' => (int)($counts[self::PASSED] ?? 0),
			'failed' => (int)($counts[self::FAILED] ?? 0),
			'skipped' => (int)($counts[self::SKIPPED] ?? 0),
			'checks' => $checks,
		];
	}//end run()

	/**
	 * The structure checks of a manifest: pages first, in manifest order, then the menu.
	 *
	 * @param array<string, mixed> $manifest The version's manifest.
	 *
	 * @return list<array{kind: string, name: string, outcome: string, message: string}>
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-the-suite-checks-the-apps-structure-req-bqrt-001
	 */
	public function structureChecks(array $manifest): array {
		$checks = [];
		$pageIds = [];
		foreach ((array)($manifest['pages'] ?? []) as $page) {
			if (is_array($page) === false) {
				continue;
			}

			$pageIds[] = (string)($page['id'] ?? '');
			$checks = array_merge($checks, $this->pageChecks(page: $page));
		}

		foreach ($this->menuRoutes(items: (array)($manifest['menu'] ?? [])) as [$label, $route]) {
			$name = 'Menu item ' . $label;
			if (in_array($route, $pageIds, true) === true) {
				$checks[] = self::check(kind: self::KIND_STRUCTURE, name: $name, outcome: self::PASSED, message: '');
				continue;
			}

			$message = 'Leads to "' . $route . '", which is no page of this version.';
			$checks[] = self::check(kind: self::KIND_STRUCTURE, name: $name, outcome: self::FAILED, message: $message);
		}

		return $checks;
	}//end structureChecks()

	/**
	 * The checks of one page: its schema, then each column or field.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return list<array{kind: string, name: string, outcome: string, message: string}>
	 */
	private function pageChecks(array $page): array {
		$config = (array)($page['config'] ?? []);
		$register = trim((string)($config['register'] ?? ''));
		$schema = trim((string)($config['schema'] ?? ''));
		if ($register === '' || $schema === '') {
			return [];
		}

		$pageName = 'Page ' . (string)($page['id'] ?? '');
		$properties = $this->propertiesOf(register: $register, schema: $schema);
		if (is_string($properties) === true) {
			return [self::check(kind: self::KIND_STRUCTURE, name: $pageName, outcome: self::FAILED, message: $properties)];
		}

		$checks = [self::check(kind: self::KIND_STRUCTURE, name: $pageName, outcome: self::PASSED, message: '')];
		foreach (self::boundKeys(page: $page) as [$what, $key]) {
			$name = $pageName . ', ' . $what . ' ' . $key;
			if (in_array($key, $properties, true) === true) {
				$checks[] = self::check(kind: self::KIND_STRUCTURE, name: $name, outcome: self::PASSED, message: '');
				continue;
			}

			$checks[] = self::check(kind: self::KIND_STRUCTURE, name: $name, outcome: self::FAILED, message: 'No such property on ' . $schema . '.');
		}

		return $checks;
	}//end pageChecks()

	/**
	 * The property names of a schema, or why they cannot be had.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return array<int, string>|string The property names, or the failure message.
	 */
	private function propertiesOf(string $register, string $schema): array|string {
		$key = $register . '/' . $schema;
		if (array_key_exists($key, $this->properties) === false) {
			try {
				$found = $this->schemas->schemaOf(registerSlug: $register, schemaSlug: $schema);
			} catch (Throwable $e) {
				return 'The register ' . $register . ' or its schema ' . $schema . ' could not be read.';
			}

			$this->properties[$key] = null;
			if ($found !== null) {
				$this->properties[$key] = array_keys((array)($found->getProperties() ?? []));
			}
		}

		if ($this->properties[$key] === null) {
			return 'The register ' . $register . ' has no schema ' . $schema . '.';
		}

		return $this->properties[$key];
	}//end propertiesOf()

	/**
	 * The schema properties a page binds: an index page's columns, a detail
	 * page's fields and field widgets. Metadata (`@self.*`) is not a property.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return list<array{0: string, 1: string}> What binds it (column or field) and the key.
	 */
	private static function boundKeys(array $page): array {
		$config = (array)($page['config'] ?? []);
		$lists = [];
		if (($page['type'] ?? '') === 'index') {
			$lists['column'] = (array)($config['columns'] ?? []);
		}

		if (($page['type'] ?? '') === 'detail') {
			$lists['field'] = (array)($config['fields'] ?? []);
			foreach ((array)($config['fieldWidgets'] ?? []) as $widget) {
				$lists['field'][] = ['key' => (string)(((array)$widget)['id'] ?? '')];
			}
		}

		$keys = [];
		foreach ($lists as $what => $items) {
			foreach ($items as $item) {
				$key = $item;
				if (is_array($item) === true) {
					$key = ($item['key'] ?? '');
				}

				$key = trim((string)$key);
				if ($key !== '' && str_starts_with($key, '@') === false) {
					$keys[] = [$what, $key];
				}
			}
		}

		return $keys;
	}//end boundKeys()

	/**
	 * The menu items that name a route, with their children, as label and route.
	 *
	 * @param array<int|string, mixed> $items The menu items.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function menuRoutes(array $items): array {
		$routes = [];
		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$route = trim((string)($item['route'] ?? ''));
			if ($route !== '') {
				$routes[] = [(string)($item['label'] ?? ($item['id'] ?? $route)), $route];
			}

			$routes = array_merge($routes, $this->menuRoutes(items: (array)($item['children'] ?? [])));
		}

		return $routes;
	}//end menuRoutes()

	/**
	 * The test cases of the rule sets the app owns, each passed or failed.
	 * Rule sets of other apps are not read.
	 *
	 * @param string $appSlug The app's slug.
	 *
	 * @return list<array{kind: string, name: string, outcome: string, message: string}>
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-the-suite-runs-the-apps-rule-set-tests-req-bqrt-002
	 */
	public function ruleSetChecks(string $appSlug): array {
		if ($appSlug === '') {
			return [];
		}

		$checks = [];
		foreach ($this->objects->findScoped(schema: RuleEngineServiceInterface::RULE_SET_SCHEMA, filters: ['ownerApp' => $appSlug]) as $ruleSet) {
			if ((string)($ruleSet['ownerApp'] ?? '') !== $appSlug) {
				continue;
			}

			$slug = (string)($ruleSet['slug'] ?? '');
			$cases = $this->objects->findScoped(schema: 'rule-test-case', filters: ['ruleSetId' => $slug]);
			try {
				$failures = $this->versioning->runTestGate($slug, $cases);
			} catch (Throwable $e) {
				$checks[] = self::check(kind: self::KIND_RULE_SET, name: $slug, outcome: self::FAILED, message: 'The test cases could not be run.');
				continue;
			}

			foreach ($cases as $case) {
				$caseName = (string)($case['name'] ?? 'unnamed');
				$outcome = self::PASSED;
				$message = '';
				if (in_array($caseName, $failures, true) === true) {
					$outcome = self::FAILED;
					$message = 'The rule set does not give the expected result.';
				}

				$checks[] = self::check(kind: self::KIND_RULE_SET, name: $slug . ': ' . $caseName, outcome: $outcome, message: $message);
			}
		}

		return $checks;
	}//end ruleSetChecks()

	/**
	 * The app's record tests. Each is skipped with its reason until
	 * OpenRegister can check a record without saving it; none is passed.
	 *
	 * @param string $appId The app's uuid.
	 *
	 * @return list<array{kind: string, name: string, outcome: string, message: string}>
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	public function recordChecks(string $appId): array {
		if ($appId === '') {
			return [];
		}

		$checks = [];
		foreach ($this->objects->findScoped(schema: self::RECORD_TEST_SCHEMA, filters: ['application' => $appId]) as $test) {
			$name = (string)($test['schema'] ?? '') . ': ' . (string)($test['name'] ?? 'unnamed');
			$checks[] = self::check(kind: self::KIND_RECORD, name: $name, outcome: self::SKIPPED, message: self::RECORD_TEST_SKIP_REASON);
		}

		return $checks;
	}//end recordChecks()

	/**
	 * The uuid of an object array: `id`, else `uuid`, else `@self.id`.
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string
	 */
	private static function idOf(array $object): string {
		$self = (array)($object['@self'] ?? []);

		return trim((string)($object['id'] ?? ($object['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()

	/**
	 * One report entry.
	 *
	 * @param string $kind    structure, rule-set or record.
	 * @param string $name    What was checked.
	 * @param string $outcome passed, failed or skipped.
	 * @param string $message Why, when it did not pass.
	 *
	 * @return array{kind: string, name: string, outcome: string, message: string}
	 */
	private static function check(string $kind, string $name, string $outcome, string $message): array {
		return ['kind' => $kind, 'name' => $name, 'outcome' => $outcome, 'message' => $message];
	}//end check()
}//end class
