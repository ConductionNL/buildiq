<?php

/**
 * Application Tests Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the app's Tests tab reads and writes (lifecycle-release-test-gate D4):
 * the app and one of its versions by slug, the app's record tests, the rule
 * sets it owns, the latest test run per version, and the record tests a maker
 * adds, edits and removes.
 *
 * Reads and writes run as the system: the caller (ApplicationTestsController)
 * has already checked the caller's role on the app. Every record test read or
 * written is held to the app it is asked for, so an id from another app
 * answers "not found".
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

use InvalidArgumentException;
use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\RuleEngineServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The data behind the Tests tab.
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
 */
class ApplicationTestsService {

	/**
	 * Constructor.
	 *
	 * @param RuleObjectReader       $objects       Reads objects of the buildiq register.
	 * @param ObjectServiceInterface $objectService Writes record tests.
	 */
	public function __construct(
		private readonly RuleObjectReader $objects,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The app with this slug, or null.
	 *
	 * @param string $slug The app's slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
	 */
	public function application(string $slug): ?array {
		foreach ($this->objects->findAsSystem(schema: ApplicationVersionServiceInterface::APPLICATION_SCHEMA, filters: ['slug' => $slug]) as $app) {
			if ((string)($app['slug'] ?? '') === $slug) {
				return $app;
			}
		}

		return null;
	}//end application()

	/**
	 * The app's version with this slug, or null.
	 *
	 * @param array<string, mixed> $application The app.
	 * @param string               $slug        The version's slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
	 */
	public function version(array $application, string $slug): ?array {
		$appId = self::idOf(object: $application);
		$filters = ['application' => $appId, 'slug' => $slug];
		foreach ($this->objects->findAsSystem(schema: ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA, filters: $filters) as $version) {
			if ((string)($version['slug'] ?? '') === $slug && (string)($version['application'] ?? '') === $appId) {
				return $version;
			}
		}

		return null;
	}//end version()

	/**
	 * The Tests tab's content: record tests, owned rule sets and the latest run per version.
	 *
	 * @param array<string, mixed> $application The app.
	 *
	 * @return array{recordTests: list<array<string, mixed>>, ruleSets: list<array<string, mixed>>, latestRuns: array<string, array<string, mixed>>}
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
	 */
	public function overview(array $application): array {
		$appId = self::idOf(object: $application);
		$appSlug = (string)($application['slug'] ?? '');

		return [
			'recordTests' => $this->recordTests(appId: $appId),
			'ruleSets' => $this->ownedRows(
				rows: $this->objects->findAsSystem(schema: RuleEngineServiceInterface::RULE_SET_SCHEMA, filters: ['ownerApp' => $appSlug]),
				field: 'ownerApp',
				value: $appSlug
			),
			'latestRuns' => $this->latestRuns(appId: $appId),
		];
	}//end overview()

	/**
	 * Add a record test to the app, or change one of its record tests.
	 *
	 * @param array<string, mixed> $application The app.
	 * @param array<string, mixed> $input       name, schema, record, expect, expectedFields.
	 * @param string|null          $id          The record test to change; null adds one.
	 *
	 * @return array<string, mixed>|null The stored test; null when `$id` is not a test of this app.
	 *
	 * @throws InvalidArgumentException When the input is not a valid record test.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function saveRecordTest(array $application, array $input, ?string $id = null): ?array {
		$appId = self::idOf(object: $application);
		if ($id !== null && $this->recordTest(appId: $appId, id: $id) === null) {
			return null;
		}

		$saved = $this->objectService->saveObject(
			object: self::recordTestFrom(input: $input, appId: $appId),
			register: ApplicationVersionServiceInterface::REGISTER_SLUG,
			schema: AppTestSuiteService::RECORD_TEST_SCHEMA,
			uuid: $id,
			_rbac: false,
			_multitenancy: false
		);

		return ObjectRecord::toArray(object: $saved);
	}//end saveRecordTest()

	/**
	 * Remove one of the app's record tests.
	 *
	 * @param array<string, mixed> $application The app.
	 * @param string               $id          The record test.
	 *
	 * @return bool False when `$id` is not a test of this app.
	 *
	 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
	 */
	public function deleteRecordTest(array $application, string $id): bool {
		if ($this->recordTest(appId: self::idOf(object: $application), id: $id) === null) {
			return false;
		}

		return $this->objectService->deleteObject(
			uuid: $id,
			register: ApplicationVersionServiceInterface::REGISTER_SLUG,
			schema: AppTestSuiteService::RECORD_TEST_SCHEMA,
			_rbac: false,
			_multitenancy: false
		);
	}//end deleteRecordTest()

	/**
	 * A valid record test from the input, for this app.
	 *
	 * @param array<string, mixed> $input The input.
	 * @param string               $appId The app's uuid.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When a field is missing or of the wrong shape.
	 */
	private static function recordTestFrom(array $input, string $appId): array {
		$name = trim((string)($input['name'] ?? ''));
		$schema = trim((string)($input['schema'] ?? ''));
		$expect = (string)($input['expect'] ?? '');
		$record = $input['record'] ?? null;
		$fields = $input['expectedFields'] ?? [];

		if ($name === '' || $schema === '') {
			throw new InvalidArgumentException(message: 'A record test needs a name and a schema.');
		}

		if (in_array($expect, ['accept', 'refuse'], true) === false) {
			throw new InvalidArgumentException(message: 'A record test expects "accept" or "refuse".');
		}

		if (is_array($record) === false || ($record !== [] && array_is_list($record) === true)) {
			throw new InvalidArgumentException(message: 'The sample record must be an object.');
		}

		if (is_array($fields) === false) {
			throw new InvalidArgumentException(message: 'The fields a record is refused on must be a list.');
		}

		$fields = array_map(static fn (mixed $field): string => trim((string)$field), $fields);
		$fields = array_values(array_filter($fields, static fn (string $field): bool => $field !== ''));
		if ($expect === 'accept') {
			$fields = [];
		}

		return [
			'application' => $appId,
			'schema' => $schema,
			'name' => $name,
			'record' => (object)$record,
			'expect' => $expect,
			'expectedFields' => $fields,
		];
	}//end recordTestFrom()

	/**
	 * The app's record tests.
	 *
	 * @param string $appId The app's uuid.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function recordTests(string $appId): array {
		return $this->ownedRows(
			rows: $this->objects->findAsSystem(schema: AppTestSuiteService::RECORD_TEST_SCHEMA, filters: ['application' => $appId]),
			field: 'application',
			value: $appId
		);
	}//end recordTests()

	/**
	 * One record test of the app, or null.
	 *
	 * @param string $appId The app's uuid.
	 * @param string $id    The record test's uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function recordTest(string $appId, string $id): ?array {
		if ($appId === '' || $id === '') {
			return null;
		}

		foreach ($this->recordTests(appId: $appId) as $test) {
			if (self::idOf(object: $test) === $id) {
				return $test;
			}
		}

		return null;
	}//end recordTest()

	/**
	 * The latest run of each of the app's versions, by version uuid.
	 *
	 * @param string $appId The app's uuid.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function latestRuns(string $appId): array {
		$latest = [];
		$runs = $this->ownedRows(
			rows: $this->objects->findAsSystem(schema: ReleaseTestGate::TEST_RUN_SCHEMA, filters: ['application' => $appId]),
			field: 'application',
			value: $appId
		);
		foreach ($runs as $run) {
			$version = (string)($run['version'] ?? '');
			if ($version === '') {
				continue;
			}

			if (isset($latest[$version]) === false || strcmp((string)($run['ranAt'] ?? ''), (string)($latest[$version]['ranAt'] ?? '')) > 0) {
				$latest[$version] = $run;
			}
		}

		return $latest;
	}//end latestRuns()

	/**
	 * The rows whose field holds this value: the search's filter is not trusted alone.
	 *
	 * @param array<int, array<string, mixed>> $rows  The rows.
	 * @param string                           $field The field.
	 * @param string                           $value The value.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ownedRows(array $rows, string $field, string $value): array {
		if ($value === '') {
			return [];
		}

		return array_values(array_filter($rows, static fn (array $row): bool => (string)($row[$field] ?? '') === $value));
	}//end ownedRows()

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
}//end class
