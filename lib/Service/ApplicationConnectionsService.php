<?php

/**
 * Application Connections Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The connections of one app built in buildiq (built-app-connections-page,
 * REQ-BIQ-CONN-005): the integriq sources its manifest binds as data sources,
 * and the integriq `app_connection` rows whose `app` is the app's slug, one
 * row each with a health status and when it was last checked. The caller has
 * already been checked; this class reads integriq in system context and
 * returns names, statuses and messages only, never a source's configuration
 * or credentials.
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
 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists an app's sources and integrations with their health.
 *
 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
 */
class ApplicationConnectionsService {

	/**
	 * The statuses integriq gives a connection; anything else reads as not checked.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['configured', 'limited', 'unconfigured', 'simulated', 'disabled', 'unavailable', 'error'];

	/**
	 * The most rows read from one integriq schema.
	 *
	 * @var integer
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppManager            $appManager    Whether integriq is installed, and under which id.
	 * @param LoggerInterface        $logger        Logs reads that fail.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The app's connections.
	 *
	 * @param string               $appSlug  The application's slug.
	 * @param array<string, mixed> $manifest The application's manifest.
	 *
	 * @return array{healthNeedsIntegriq: bool, connections: list<array<string, mixed>>}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a pure lookup.
	 *
	 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
	 */
	public function list(string $appSlug, array $manifest): array {
		$paths = self::endpointPaths(manifest: $manifest);
		$register = FleetAppId::resolve(appManager: $this->appManager, canonical: 'integriq');
		if ($register === null || FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: 'integriq') === false) {
			return ['healthNeedsIntegriq' => true, 'connections' => self::bareSources(paths: $paths)];
		}

		$integrations = array_values(
			array_filter(
				$this->read(register: $register, schema: 'app_connection', filters: ['app' => $appSlug]),
				static fn (array $row): bool => ($row['app'] ?? null) === $appSlug
			)
		);

		return [
			'healthNeedsIntegriq' => false,
			'connections' => array_merge(
				$this->sources(register: $register, paths: $paths, integrations: $integrations),
				array_map(static fn (array $row): array => self::integrationRow(row: $row), $integrations)
			),
		];
	}//end list()

	/**
	 * The integriq endpoint paths the manifest binds as data sources, anywhere in it.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 *
	 * @return list<string> Distinct paths without leading or trailing slashes, sorted.
	 *
	 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
	 */
	public static function endpointPaths(array $manifest): array {
		$paths = [];
		$walk = static function (mixed $node) use (&$walk, &$paths): void {
			if (is_array($node) === false) {
				return;
			}

			$connector = ($node['connector'] ?? null);
			if (is_array($connector) === true && is_string($connector['endpointPath'] ?? null) === true) {
				$path = trim($connector['endpointPath'], " \t\n\r\0\x0B/");
				if ($path !== '') {
					$paths[$path] = true;
				}
			}

			foreach ($node as $child) {
				$walk($child);
			}
		};
		$walk($manifest);

		$paths = array_keys($paths);
		sort($paths);

		return array_map('strval', $paths);
	}//end endpointPaths()

	/**
	 * The source rows when integriq is not there: the paths, without health.
	 *
	 * @param list<string> $paths The endpoint paths.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function bareSources(array $paths): array {
		return array_map(
			static fn (string $path): array => self::row(kind: 'source', id: $path, name: $path, health: [], linkId: null),
			$paths
		);
	}//end bareSources()

	/**
	 * One source row per integriq source the paths resolve to; a path whose
	 * endpoint or source cannot be found is listed by its path.
	 *
	 * @param string                           $register     The integriq register.
	 * @param list<string>                     $paths        The endpoint paths.
	 * @param list<array<string, mixed>>       $integrations This app's connection rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function sources(string $register, array $paths, array $integrations): array {
		if ($paths === []) {
			return [];
		}

		$targets = [];
		foreach ($this->read(register: $register, schema: 'endpoint', filters: []) as $endpoint) {
			$path = trim((string)($endpoint['endpoint'] ?? ''), '/');
			if ($path !== '' && ($endpoint['targetType'] ?? '') === 'api') {
				$targets[$path] = (string)($endpoint['targetId'] ?? '');
			}
		}

		$sources = [];
		foreach ($this->read(register: $register, schema: 'source', filters: []) as $source) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($source[$key] ?? null);
				if ((is_string($value) === true && $value !== '') || is_int($value) === true) {
					$sources[(string)$value] = $source;
				}
			}
		}

		$rows = [];
		foreach ($paths as $path) {
			$source = ($sources[($targets[$path] ?? '')] ?? null);
			if ($source === null) {
				$rows[$path] = self::row(kind: 'source', id: $path, name: $path, health: [], linkId: null);
				continue;
			}

			$uuid = (string)(ObjectRecord::uuid(record: $source) ?? ($source['uuid'] ?? $source['id'] ?? ''));
			$rows[$uuid] = self::row(
				kind: 'source',
				id: $uuid,
				name: (string)($source['name'] ?? $path),
				health: self::healthOf(uuid: $uuid, integrations: $integrations),
				linkId: $uuid
			);
		}

		return array_values($rows);
	}//end sources()

	/**
	 * The health integriq recorded for a source on one of this app's connection rows, if any.
	 *
	 * @param string                     $uuid         The source uuid.
	 * @param list<array<string, mixed>> $integrations This app's connection rows.
	 *
	 * @return array<string, mixed> The row, or [] when none checks this source.
	 */
	private static function healthOf(string $uuid, array $integrations): array {
		foreach ($integrations as $row) {
			if ($uuid !== '' && ($row['source'] ?? null) === $uuid) {
				return $row;
			}
		}

		return [];
	}//end healthOf()

	/**
	 * The row of one integriq connection.
	 *
	 * @param array<string, mixed> $row The `app_connection` row.
	 *
	 * @return array<string, mixed>
	 */
	private static function integrationRow(array $row): array {
		$id = (string)(ObjectRecord::uuid(record: $row) ?? ($row['slug'] ?? ''));

		return self::row(kind: 'integration', id: $id, name: (string)($row['title'] ?? ($row['key'] ?? $id)), health: $row, linkId: $id);
	}//end integrationRow()

	/**
	 * One row of the page: kind, name, status (null when not checked), message, last check and link id.
	 *
	 * @param string               $kind   `source` or `integration`.
	 * @param string               $id     The row id.
	 * @param string               $name   The name shown.
	 * @param array<string, mixed> $health The connection row carrying status, statusMessage and checkedAt.
	 * @param string|null          $linkId The integriq object the row links to.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $kind, string $id, string $name, array $health, ?string $linkId): array {
		$status = ($health['status'] ?? null);
		if (in_array($status, self::STATUSES, true) === false) {
			$status = null;
		}

		$message = null;
		$checkedAt = null;
		if ($status !== null) {
			$message = self::textOrNull(value: ($health['statusMessage'] ?? null));
			$checkedAt = self::textOrNull(value: ($health['checkedAt'] ?? null));
		}

		return [
			'kind' => $kind,
			'id' => $id,
			'name' => $name,
			'status' => $status,
			'statusMessage' => $message,
			'checkedAt' => $checkedAt,
			'linkId' => $linkId,
		];
	}//end row()

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function textOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end textOrNull()

	/**
	 * Rows of one integriq schema, read in system context; a failed read is no rows.
	 *
	 * @param string                $register The integriq register.
	 * @param string                $schema   The schema slug.
	 * @param array<string, string> $filters  Extra filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion.
	 */
	private function read(string $register, string $schema, array $filters): array {
		try {
			$results = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters), 'limit' => self::READ_LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: could not read integriq ' . $schema . ' rows: ' . $e->getMessage());
			return [];
		}

		$rows = [];
		foreach ($results as $result) {
			$rows[] = ObjectRecord::toArray(object: $result);
		}

		return $rows;
	}//end read()
}//end class
