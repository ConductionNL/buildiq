<?php

/**
 * Buildiq ExportAppContentBundler
 *
 * Puts the application itself into an exported tree: its manifest (pages and
 * menu), its schemas, and optionally its records. Without this step the export
 * is only the empty app template.
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
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves an exported application's version and writes its content into the tree.
 *
 * Two layouts are written side by side, because the archive serves two readers:
 *
 *   - The standalone app: `src/manifest.json` carries the real pages and menu,
 *     and `lib/Settings/<app_id>_register.json` carries the real schemas (and
 *     the records, when seed data is included) so the app's install step
 *     creates them in OpenRegister.
 *   - Another Buildiq instance: `openbuild-app.json`, `manifest.json`,
 *     `schemas/<slug>.json` and `data/<slug>.jsonl` at the archive root, the
 *     app repository layout AppRepoParser reads, plus a README that says what
 *     the archive holds.
 *
 * Buildiq namespaces a version's register (`openbuild-{app}-{version}`) and its
 * schema slugs (`{app}-{version}-{schema}`). Both are rewritten to the exported
 * app's own names: the register becomes the app id, a schema loses the prefix.
 *
 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
 */
class ExportAppContentBundler {
	/**
	 * Canonical slug of Buildiq's own register.
	 *
	 * @var string
	 */
	private const CANONICAL_REGISTER = 'buildiq';

	/**
	 * Most records exported per schema, so one export cannot dump a whole instance.
	 *
	 * @var integer
	 */
	private const MAX_RECORDS_PER_SCHEMA = 1000;

	/**
	 * Schema fields carried into the exported definition, with their getters.
	 *
	 * @var array<string,string>
	 */
	private const SCHEMA_FIELDS = [
		'title' => 'getTitle',
		'description' => 'getDescription',
		'version' => 'getVersion',
		'icon' => 'getIcon',
		'required' => 'getRequired',
		'properties' => 'getProperties',
		'configuration' => 'getConfiguration',
		'authorization' => 'getAuthorization',
	];

	/**
	 * Object keys that belong to the source instance, not to the record.
	 *
	 * @var array<int,string>
	 */
	private const INSTANCE_KEYS = ['@self', 'id', 'uuid'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService Reads the application, its versions and its records.
	 * @param RegisterMapper $registerMapper Resolves the version's register.
	 * @param SchemaMapper $schemaMapper Resolves the register's schemas.
	 * @param RegisterSlugResolverInterface $slugResolver Which slug Buildiq's own register answers to here.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterSlugResolverInterface $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find the application and the version an export job names.
	 *
	 * The version is picked by slug when the job names one, else by semver
	 * (the production version first when several share it), else the
	 * production version, else the first version found.
	 *
	 * @param string $applicationUuid The application UUID.
	 * @param string $semver The version semver on the job.
	 * @param string $versionSlug The version slug on the job, '' when absent.
	 *
	 * @return array{application: array<string,mixed>, version: array<string,mixed>}|null Null when the application is unknown.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-export-targets-a-specific-application-version
	 */
	public function resolveSource(string $applicationUuid, string $semver, string $versionSlug = ''): ?array {
		$application = $this->loadApplication(applicationUuid: $applicationUuid);
		if ($application === null) {
			return null;
		}

		$versions = $this->loadVersions(applicationUuid: $applicationUuid);
		$version = $this->pickVersion(
			versions: $versions,
			productionUuid: (string)($application['productionVersion'] ?? ''),
			semver: $semver,
			versionSlug: $versionSlug
		);

		return [
			'application' => $application,
			'version' => ($version ?? []),
		];
	}//end resolveSource()

	/**
	 * Write the application's content into an exported tree.
	 *
	 * @param string $rootDir The exported tree root (placeholders already resolved).
	 * @param array{application: array<string,mixed>, version: array<string,mixed>} $source From resolveSource().
	 * @param string $appId The exported app id.
	 * @param string $semver The exported version.
	 * @param bool $includeSeedData Whether to include the records.
	 *
	 * @return array{pages: int, menu: int, schemas: int, records: int} What was written.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-optional-seed-data-inclusion
	 */
	public function bundle(string $rootDir, array $source, string $appId, string $semver, bool $includeSeedData): array {
		$application = $source['application'];
		$version = $source['version'];
		$appSlug = (string)($application['slug'] ?? $appId);
		$versionSlug = (string)($version['slug'] ?? '');

		$registerSlug = (string)($version['register'] ?? '');
		if ($registerSlug === '') {
			$registerSlug = 'openbuild-' . $appSlug;
		}

		$schemas = $this->collectSchemas(
			registerSlug: $registerSlug,
			prefix: $this->schemaPrefix(appSlug: $appSlug, versionSlug: $versionSlug)
		);

		$renames = [$registerSlug => $appId];
		foreach ($schemas as $entry) {
			$renames[$entry['sourceSlug']] = $entry['slug'];
		}

		$manifest = [];
		if (is_array($version['manifest'] ?? null) === true) {
			$manifest = $this->renameSlugs(node: $version['manifest'], renames: $renames);
		}

		$definitions = [];
		$records = [];
		foreach ($schemas as $entry) {
			$definitions[$entry['slug']] = $this->renameSlugs(node: $entry['definition'], renames: $renames);
			if ($includeSeedData === true) {
				$records[$entry['slug']] = $this->collectRecords(
					registerId: $entry['registerId'],
					schemaId: $entry['schemaId'],
					renames: $renames
				);
			}
		}

		ksort($definitions);
		ksort($records);

		$this->writeAppManifest(rootDir: $rootDir, manifest: $manifest, semver: $semver);
		$this->writeAppRegister(
			rootDir: $rootDir,
			appId: $appId,
			application: $application,
			definitions: $definitions,
			records: $records
		);
		$this->writePortableFiles(
			rootDir: $rootDir,
			application: $application,
			manifest: $manifest,
			semver: $semver,
			definitions: $definitions,
			records: $records
		);

		$summary = [
			'pages' => $this->countList(value: ($manifest['pages'] ?? null)),
			'menu' => $this->countList(value: ($manifest['menu'] ?? null)),
			'schemas' => count($definitions),
			'records' => array_sum(array_map('count', $records)),
		];

		$this->writeReadme(rootDir: $rootDir, application: $application, semver: $semver, summary: $summary, records: $records);

		return $summary;
	}//end bundle()

	/**
	 * Load the application record.
	 *
	 * @param string $applicationUuid The application UUID.
	 *
	 * @return array<string,mixed>|null The record, or null when it cannot be read.
	 */
	private function loadApplication(string $applicationUuid): ?array {
		try {
			$object = $this->objectService->find($applicationUuid, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq export: could not load application ' . $applicationUuid . ': ' . $e->getMessage());
			return null;
		}

		if ($object === null) {
			return null;
		}

		$data = $this->normalise(object: $object);
		if ($data === []) {
			return null;
		}

		return $data;
	}//end loadApplication()

	/**
	 * Load every version row of an application.
	 *
	 * Fetched unfiltered and matched here, because OpenRegister does not
	 * reliably filter on the `application` relation (the pattern
	 * GitHubAppSyncService::resolveVersion() already uses).
	 *
	 * @param string $applicationUuid The application UUID.
	 *
	 * @return array<int,array<string,mixed>> The versions.
	 */
	private function loadVersions(string $applicationUuid): array {
		$resolution = $this->slugResolver->resolve(canonical: self::CANONICAL_REGISTER);
		if ($resolution->isResolved() === false) {
			$this->logger->warning('Buildiq export: Buildiq\'s own register is not on this instance.');
			return [];
		}

		try {
			$results = $this->objectService->searchObjectsBySlug(
				(string)$resolution->slug,
				'applicationVersion',
				['_limit' => 1000],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq export: could not list application versions: ' . $e->getMessage());
			return [];
		}

		$versions = [];
		foreach ((array)$results as $result) {
			$row = $this->normalise(object: $result);
			if ((string)($row['application'] ?? '') === $applicationUuid) {
				$versions[] = $row;
			}
		}

		return $versions;
	}//end loadVersions()

	/**
	 * Pick the version an export job means.
	 *
	 * @param array<int,array<string,mixed>> $versions The application's versions.
	 * @param string $productionUuid The application's production version UUID.
	 * @param string $semver The semver on the job.
	 * @param string $versionSlug The version slug on the job.
	 *
	 * @return array<string,mixed>|null The version, or null when there are none.
	 */
	private function pickVersion(array $versions, string $productionUuid, string $semver, string $versionSlug): ?array {
		if ($versions === []) {
			return null;
		}

		// Production first, so a semver shared by two versions resolves to the published one.
		usort(
			$versions,
			fn (array $left, array $right): int => (int)($this->uuidOf(object: $right) === $productionUuid)
				- (int)($this->uuidOf(object: $left) === $productionUuid)
		);

		$candidates = [
			static fn (array $row): bool => $versionSlug !== '' && (string)($row['slug'] ?? '') === $versionSlug,
			static fn (array $row): bool => $semver !== '' && (string)($row['semver'] ?? '') === $semver,
		];
		foreach ($candidates as $matches) {
			foreach ($versions as $version) {
				if ($matches($version) === true) {
					return $version;
				}
			}
		}

		// Sorted production-first, so this is the production version when there is one.
		return $versions[0];
	}//end pickVersion()

	/**
	 * The slug prefix Buildiq puts on a version's schemas.
	 *
	 * @param string $appSlug The application slug.
	 * @param string $versionSlug The version slug.
	 *
	 * @return string The prefix, '' when the version has no slug.
	 */
	private function schemaPrefix(string $appSlug, string $versionSlug): string {
		if ($versionSlug === '') {
			return '';
		}

		return $appSlug . '-' . $versionSlug . '-';
	}//end schemaPrefix()

	/**
	 * Read the schemas of the version's register.
	 *
	 * @param string $registerSlug The version's register slug.
	 * @param string $prefix The schema slug prefix to strip.
	 *
	 * @return array<int,array{sourceSlug: string, slug: string, registerId: int, schemaId: int, definition: array<string,mixed>}>
	 */
	private function collectSchemas(string $registerSlug, string $prefix): array {
		try {
			$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('Buildiq export: register "' . $registerSlug . '" not found, no schemas exported: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((array)$register->getSchemas() as $schemaId) {
			try {
				$schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->info('Buildiq export: schema ' . ((string)$schemaId) . ' not found: ' . $e->getMessage());
				continue;
			}

			$sourceSlug = (string)$schema->getSlug();
			if ($sourceSlug === '') {
				continue;
			}

			$slug = $sourceSlug;
			if ($prefix !== '' && str_starts_with($sourceSlug, $prefix) === true && strlen($sourceSlug) > strlen($prefix)) {
				$slug = substr($sourceSlug, strlen($prefix));
			}

			$out[] = [
				'sourceSlug' => $sourceSlug,
				'slug' => $slug,
				'registerId' => (int)$register->getId(),
				'schemaId' => (int)$schema->getId(),
				'definition' => $this->schemaDefinition(schema: $schema, slug: $slug),
			];
		}//end foreach

		return $out;
	}//end collectSchemas()

	/**
	 * Reduce a serialised schema to the portable definition.
	 *
	 * Each field is read through its getter. Some are magic on the entity, so a
	 * getter the entity does not answer is skipped rather than fatal.
	 *
	 * @param object $schema The schema entity.
	 * @param string $slug The exported slug.
	 *
	 * @return array<string,mixed> The definition.
	 */
	private function schemaDefinition(object $schema, string $slug): array {
		$definition = ['slug' => $slug, 'type' => 'object'];
		foreach (self::SCHEMA_FIELDS as $field => $getter) {
			try {
				$value = $schema->$getter();
			} catch (Throwable $e) {
				continue;
			}

			if ($value === null || $value === '' || $value === []) {
				continue;
			}

			$definition[$field] = $value;
		}

		if (isset($definition['version']) === false) {
			$definition['version'] = '0.1.0';
		}

		$definition['required'] = array_values((array)($definition['required'] ?? []));
		$definition['properties'] = (array)($definition['properties'] ?? []);

		return $definition;
	}//end schemaDefinition()

	/**
	 * Read a schema's records, stripped of their source identity.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId The schema id.
	 * @param array<string,string> $renames Source slug to exported slug.
	 *
	 * @return array<int,array<string,mixed>> The records, in a stable order.
	 */
	private function collectRecords(int $registerId, int $schemaId, array $renames): array {
		try {
			$results = $this->objectService->searchObjects(
				query: [
					'@self' => ['register' => $registerId, 'schema' => $schemaId],
					'_limit' => self::MAX_RECORDS_PER_SCHEMA,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq export: could not read records of schema ' . $schemaId . ': ' . $e->getMessage());
			return [];
		}

		$records = [];
		foreach ((array)$results as $result) {
			$row = $this->normalise(object: $result);
			if ($row === []) {
				continue;
			}

			$key = (string)($row['slug'] ?? '');
			if ($key === '') {
				$key = $this->uuidOf(object: $row);
			}

			foreach (self::INSTANCE_KEYS as $instanceKey) {
				unset($row[$instanceKey]);
			}

			$record = $this->renameSlugs(node: $row, renames: $renames);
			$records[$key . "\0" . count($records)] = ['key' => $key, 'data' => $record];
		}

		ksort($records);

		return array_values($records);
	}//end collectRecords()

	/**
	 * Replace every string that is exactly a source slug, or a `#/components/schemas/<slug>` reference.
	 *
	 * @param array<mixed> $node The structure to rewrite.
	 * @param array<string,string> $renames Source slug to exported slug.
	 *
	 * @return array<mixed> The rewritten structure.
	 */
	private function renameSlugs(array $node, array $renames): array {
		foreach ($node as $key => $value) {
			if (is_array($value) === true) {
				$node[$key] = $this->renameSlugs(node: $value, renames: $renames);
				continue;
			}

			if (is_string($value) === false) {
				continue;
			}

			if (isset($renames[$value]) === true) {
				$node[$key] = $renames[$value];
				continue;
			}

			$slash = strrpos($value, '/');
			if ($slash !== false && isset($renames[substr($value, $slash + 1)]) === true) {
				$node[$key] = substr($value, 0, $slash + 1) . $renames[substr($value, $slash + 1)];
			}
		}//end foreach

		return $node;
	}//end renameSlugs()

	/**
	 * Merge the version's manifest into the template's `src/manifest.json`.
	 *
	 * The template supplies the app identity (id, namespace, name, licence,
	 * author); the version supplies everything the app shows.
	 *
	 * @param string $rootDir The tree root.
	 * @param array<string,mixed> $manifest The rewritten manifest.
	 * @param string $semver The exported version.
	 *
	 * @return void
	 */
	private function writeAppManifest(string $rootDir, array $manifest, string $semver): void {
		$path = $rootDir . '/src/manifest.json';
		$base = $this->readJson(path: $path);

		$identity = ['$comment', 'id', 'namespace', 'name', 'description', 'license', 'author'];
		$merged = $base;
		foreach ($manifest as $key => $value) {
			if (in_array($key, $identity, true) === true) {
				continue;
			}

			$merged[$key] = $value;
		}

		// The template's empty `navigation` placeholder predates `menu`; keeping
		// both gives the runtime two answers for one question.
		if (isset($manifest['menu']) === true && ($merged['navigation'] ?? null) === []) {
			unset($merged['navigation']);
		}

		$merged['version'] = $semver;

		$this->writeJson(path: $path, data: $merged);
	}//end writeAppManifest()

	/**
	 * Fill the template's register file with the app's schemas and records.
	 *
	 * @param string $rootDir The tree root.
	 * @param string $appId The exported app id.
	 * @param array<string,mixed> $application The application record.
	 * @param array<string,array<string,mixed>> $definitions Schema definitions by slug.
	 * @param array<string,array<int,array<string,mixed>>> $records Records by schema slug.
	 *
	 * @return void
	 */
	private function writeAppRegister(string $rootDir, string $appId, array $application, array $definitions, array $records): void {
		$path = $rootDir . '/lib/Settings/' . str_replace('-', '_', $appId) . '_register.json';
		$register = $this->readJson(path: $path);

		$name = (string)($application['name'] ?? $appId);
		$description = (string)($application['description'] ?? '');

		$register['info']['title'] = $name;
		if ($description !== '') {
			$register['info']['description'] = $description;
		}

		$register['components']['registers'] = [
			$appId => [
				'slug' => $appId,
				'title' => $name,
				'description' => $description,
				'version' => (string)($register['info']['version'] ?? '0.1.0'),
				'schemas' => array_keys($definitions),
			],
		];
		// An app with no schemas gets an empty object, not the template's example.
		$register['components']['schemas'] = (object)$definitions;

		unset($register['components']['objects']);
		$objects = [];
		foreach ($records as $schemaSlug => $rows) {
			foreach ($rows as $row) {
				$objects[] = array_merge(
					$row['data'],
					['@self' => ['register' => $appId, 'schema' => $schemaSlug, 'slug' => $row['key']]]
				);
			}
		}

		if ($objects !== []) {
			$register['components']['objects'] = $objects;
		}

		$this->writeJson(path: $path, data: $register);
	}//end writeAppRegister()

	/**
	 * Write the root files another Buildiq instance imports.
	 *
	 * @param string $rootDir The tree root.
	 * @param array<string,mixed> $application The application record.
	 * @param array<string,mixed> $manifest The rewritten manifest.
	 * @param string $semver The exported version.
	 * @param array<string,array<string,mixed>> $definitions Schema definitions by slug.
	 * @param array<string,array<int,array<string,mixed>>> $records Records by schema slug.
	 *
	 * @return void
	 */
	private function writePortableFiles(
		string $rootDir,
		array $application,
		array $manifest,
		string $semver,
		array $definitions,
		array $records,
	): void {
		$descriptor = [
			'formatVersion' => AppRepoSerializer::FORMAT_VERSION,
			'slug' => (string)($application['slug'] ?? ''),
			'name' => (string)($application['name'] ?? ($application['slug'] ?? '')),
			'description' => (string)($application['description'] ?? ''),
			'category' => (string)($application['category'] ?? ($manifest['category'] ?? 'general')),
			'appType' => (string)($application['appType'] ?? 'virtual'),
			'version' => $semver,
			'channels' => [
				'schemas' => count($definitions),
				'records' => array_map('count', $records),
			],
		];

		$this->writeJson(path: $rootDir . '/openbuild-app.json', data: $descriptor);
		$this->writeJson(path: $rootDir . '/manifest.json', data: $manifest);

		foreach ($definitions as $slug => $definition) {
			$this->writeJson(path: $rootDir . '/schemas/' . $slug . '.json', data: $definition);
		}

		foreach ($records as $slug => $rows) {
			$lines = '';
			foreach ($rows as $row) {
				$lines .= json_encode($row['data'], (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
			}

			$this->writeFile(path: $rootDir . '/data/' . $slug . '.jsonl', contents: $lines);
		}
	}//end writePortableFiles()

	/**
	 * Replace the template README with a summary of this export.
	 *
	 * @param string $rootDir The tree root.
	 * @param array<string,mixed> $application The application record.
	 * @param string $semver The exported version.
	 * @param array{pages: int, menu: int, schemas: int, records: int} $summary What was written.
	 * @param array<string,array<int,array<string,mixed>>> $records Records by schema slug.
	 *
	 * @return void
	 */
	private function writeReadme(string $rootDir, array $application, string $semver, array $summary, array $records): void {
		$name = (string)($application['name'] ?? ($application['slug'] ?? 'Buildiq app'));
		$description = trim((string)($application['description'] ?? ''));

		$lines = ['# ' . $name, ''];
		if ($description !== '') {
			$lines[] = $description;
			$lines[] = '';
		}

		$lines[] = 'Exported from Buildiq, version ' . $semver . '.';
		$lines[] = '';
		$lines[] = '## What is in this archive';
		$lines[] = '';
		$lines[] = '- `manifest.json`: the app\'s ' . $summary['pages'] . ' pages and ' . $summary['menu'] . ' menu items.';
		$lines[] = '- `schemas/`: ' . $summary['schemas'] . ' schemas, one file each.';
		if ($records === []) {
			$lines[] = '- No records. Export again with seed data switched on to include them.';
		} else {
			$lines[] = '- `data/`: ' . $summary['records'] . ' records, one JSON line per record.';
		}

		$lines[] = '- `openbuild-app.json`: the description Buildiq reads when you import this archive.';
		$lines[] = '- Everything else is a standalone Nextcloud app. `src/manifest.json` and'
			. ' `lib/Settings/*_register.json` hold the same pages, schemas and records.';
		$lines[] = '';
		$lines[] = '## Use it';
		$lines[] = '';
		$lines[] = '- In Buildiq: open Applications, choose Import application and pick this archive.';
		$lines[] = '- As its own app: run `composer install`, `npm ci` and `npm run build`, then enable it.'
			. ' It needs OpenRegister.';
		$lines[] = '';

		$this->writeFile(path: $rootDir . '/README.md', contents: implode("\n", $lines));
	}//end writeReadme()

	/**
	 * Read a JSON object file, empty array when absent or invalid.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string,mixed> The decoded object.
	 */
	private function readJson(string $path): array {
		if (is_file($path) === false) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end readJson()

	/**
	 * Write pretty JSON with a trailing newline.
	 *
	 * @param string $path The file.
	 * @param array<mixed> $data The data.
	 *
	 * @return void
	 */
	private function writeJson(string $path, array $data): void {
		$encoded = json_encode($data, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			$this->logger->warning('Buildiq export: could not encode ' . basename($path));
			return;
		}

		$this->writeFile(path: $path, contents: $encoded . "\n");
	}//end writeJson()

	/**
	 * Write a file, creating its directory.
	 *
	 * @param string $path The file.
	 * @param string $contents The contents.
	 *
	 * @return void
	 */
	private function writeFile(string $path, string $contents): void {
		$dir = dirname($path);
		if (is_dir($dir) === false) {
			mkdir($dir, 0o755, true);
		}

		file_put_contents($path, $contents);
	}//end writeFile()

	/**
	 * Count a list value, 0 when it is not a list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int The count.
	 */
	private function countList(mixed $value): int {
		if (is_array($value) === false) {
			return 0;
		}

		return count($value);
	}//end countList()

	/**
	 * The UUID of a serialised object.
	 *
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string The UUID, '' when absent.
	 */
	private function uuidOf(array $object): string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true && (string)($self['id'] ?? '') !== '') {
			return (string)$self['id'];
		}

		return (string)($object['id'] ?? ($object['uuid'] ?? ''));
	}//end uuidOf()

	/**
	 * Coerce an OpenRegister result to an array.
	 *
	 * @param mixed $object The result.
	 *
	 * @return array<string,mixed> The array, empty when it is neither.
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end normalise()
}//end class
