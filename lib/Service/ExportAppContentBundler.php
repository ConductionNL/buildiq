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

use Psr\Log\LoggerInterface;

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
	 * Constructor.
	 *
	 * @param ExportAppSourceResolver $sourceResolver Finds the application and version.
	 * @param ExportAppSchemaReader $schemaReader Reads the version's schemas and records.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ExportAppSourceResolver $sourceResolver,
		private readonly ExportAppSchemaReader $schemaReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Find the application and the version an export job names.
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
		return $this->sourceResolver->resolveSource(
			applicationUuid: $applicationUuid,
			semver: $semver,
			versionSlug: $versionSlug
		);
	}//end resolveSource()

	/**
	 * Write the application's content into an exported tree.
	 *
	 * @param string $rootDir The exported tree root (placeholders already resolved).
	 * @param array<string,mixed> $source From resolveSource(), plus `includeSeedData` (bool).
	 * @param string $appId The exported app id.
	 * @param string $semver The exported version.
	 *
	 * @return array{pages: int, menu: int, schemas: int, records: int} What was written.
	 *
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-companion-schemas-migrate-into-the-exported-app-s-own-namespace
	 * @spec openspec/specs/openbuild-exporter/spec.md#requirement-optional-seed-data-inclusion
	 */
	public function bundle(string $rootDir, array $source, string $appId, string $semver): array {
		$application = (array)($source['application'] ?? []);
		$version = (array)($source['version'] ?? []);
		$appSlug = (string)($application['slug'] ?? $appId);

		$registerSlug = (string)($version['register'] ?? '');
		if ($registerSlug === '') {
			$registerSlug = ApplicationVersionService::VERSION_REGISTER_PREFIX . $appSlug;
		}

		$schemas = $this->schemaReader->collectSchemas(
			registerSlug: $registerSlug,
			prefix: $this->schemaPrefix(appSlug: $appSlug, versionSlug: (string)($version['slug'] ?? ''))
		);

		$renames = [$registerSlug => $appId];
		foreach ($schemas as $entry) {
			$renames[$entry['sourceSlug']] = $entry['slug'];
		}

		$manifest = $this->schemaReader->renameSlugs(node: (array)($version['manifest'] ?? []), renames: $renames);
		$content = $this->readContent(schemas: $schemas, renames: $renames, withRecords: (($source['includeSeedData'] ?? false) === true));

		$this->writeAppManifest(rootDir: $rootDir, manifest: $manifest, semver: $semver);
		$this->writeAppRegister(rootDir: $rootDir, appId: $appId, application: $application, content: $content);
		$this->writePortableFiles(rootDir: $rootDir, application: $application, manifest: $manifest, semver: $semver, content: $content);

		$summary = [
			'pages' => $this->countList(value: ($manifest['pages'] ?? null)),
			'menu' => $this->countList(value: ($manifest['menu'] ?? null)),
			'schemas' => count($content['definitions']),
			'records' => array_sum(array_map('count', $content['records'])),
		];

		$this->writeReadme(rootDir: $rootDir, application: $application, semver: $semver, summary: $summary);

		return $summary;
	}//end bundle()

	/**
	 * Read the definitions, and the records when asked, keyed by exported slug.
	 *
	 * @param array<int,array{slug: string, registerId: int, schemaId: int, definition: array<mixed>}> $schemas From the reader.
	 * @param array<string,string> $renames Source slug to exported slug.
	 * @param bool $withRecords Whether to read the records.
	 *
	 * @return array{definitions: array<string,array<mixed>>, records: array<string,array<int,array{key: string, data: array<string,mixed>}>>}
	 */
	private function readContent(array $schemas, array $renames, bool $withRecords): array {
		$content = ['definitions' => [], 'records' => []];
		foreach ($schemas as $entry) {
			$content['definitions'][$entry['slug']] = $this->schemaReader->renameSlugs(node: $entry['definition'], renames: $renames);
			if ($withRecords === true) {
				$content['records'][$entry['slug']] = $this->schemaReader->collectRecords(
					registerId: $entry['registerId'],
					schemaId: $entry['schemaId'],
					renames: $renames
				);
			}
		}

		ksort($content['definitions']);
		ksort($content['records']);

		return $content;
	}//end readContent()

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
	 * @param array{definitions: array<string,array<mixed>>, records: array<string,array<int,mixed>>} $content From readContent().
	 *
	 * @return void
	 */
	private function writeAppRegister(string $rootDir, string $appId, array $application, array $content): void {
		$definitions = $content['definitions'];
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
		foreach ($content['records'] as $schemaSlug => $rows) {
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
	 * @param array{definitions: array<string,array<mixed>>, records: array<string,array<int,mixed>>} $content From readContent().
	 *
	 * @return void
	 */
	private function writePortableFiles(string $rootDir, array $application, array $manifest, string $semver, array $content): void {
		$definitions = $content['definitions'];
		$records = $content['records'];
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
	 *
	 * @return void
	 */
	private function writeReadme(string $rootDir, array $application, string $semver, array $summary): void {
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
		$recordLine = '- `data/`: ' . $summary['records'] . ' records, one JSON line per record.';
		if ($summary['records'] === 0) {
			$recordLine = '- No records. Export again with seed data switched on to include them.';
		}

		$lines[] = $recordLine;

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
}//end class
