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

use OCA\Buildiq\Support\RepoDescriptor;
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
		$run = new AppContentBundleRun(rootDir: $rootDir, appId: $appId, semver: $semver);
		$this->readSource(run: $run, source: $source);

		$this->writeAppManifest(run: $run);
		$this->writeAppRegister(run: $run);
		$this->writePortableFiles(run: $run);

		$summary = $this->summarise(run: $run);
		$this->writeReadme(run: $run, summary: $summary);

		return $summary;
	}//end bundle()

	/**
	 * Read the application, its rewritten manifest and its content into the run.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 * @param array<string,mixed> $source From resolveSource(), plus `includeSeedData` (bool).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RepoDescriptor is a pure function with no collaborators.
	 */
	private function readSource(AppContentBundleRun $run, array $source): void {
		$run->application = (array)($source['application'] ?? []);
		$version = (array)($source['version'] ?? []);
		$appSlug = (string)($run->application['slug'] ?? $run->appId);
		$registerSlug = $this->versionRegister(version: $version, appSlug: $appSlug);

		$schemas = $this->schemaReader->collectSchemas(
			registerSlug: $registerSlug,
			prefix: $this->schemaPrefix(appSlug: $appSlug, versionSlug: (string)($version['slug'] ?? ''))
		);
		$renames = $this->withSchemaRenames(renames: [$registerSlug => $run->appId], schemas: $schemas);

		$run->manifest = RepoDescriptor::publishableManifest(
			manifest: $this->schemaReader->renameSlugs(node: (array)($version['manifest'] ?? []), renames: $renames)
		);
		$run->content = $this->readContent(schemas: $schemas, renames: $renames, withRecords: (($source['includeSeedData'] ?? false) === true));
	}//end readSource()

	/**
	 * The register a version's schemas live in.
	 *
	 * @param array<string,mixed> $version The version record.
	 * @param string $appSlug The application slug.
	 *
	 * @return string The version's own register, or the default per-version register.
	 */
	private function versionRegister(array $version, string $appSlug): string {
		$registerSlug = (string)($version['register'] ?? '');
		if ($registerSlug === '') {
			$registerSlug = ApplicationVersionService::VERSION_REGISTER_PREFIX . $appSlug;
		}

		return $registerSlug;
	}//end versionRegister()

	/**
	 * Add each schema's source slug to exported slug rename.
	 *
	 * @param array<string,string> $renames The renames so far.
	 * @param array<int,array{slug: string, sourceSlug: string}> $schemas From the reader.
	 *
	 * @return array<string,string> Source slug to exported slug.
	 */
	private function withSchemaRenames(array $renames, array $schemas): array {
		foreach ($schemas as $entry) {
			$renames[$entry['sourceSlug']] = $entry['slug'];
		}

		return $renames;
	}//end withSchemaRenames()

	/**
	 * What the bundle wrote: pages, menu items, schemas and records.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 *
	 * @return array{pages: int, menu: int, schemas: int, records: int}
	 */
	private function summarise(AppContentBundleRun $run): array {
		return [
			'pages' => $this->countList(value: ($run->manifest['pages'] ?? null)),
			'menu' => $this->countList(value: ($run->manifest['menu'] ?? null)),
			'schemas' => count($run->content['definitions']),
			'records' => array_sum(array_map('count', $run->content['records'])),
		];
	}//end summarise()

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
	 * @param AppContentBundleRun $run The bundle in progress.
	 *
	 * @return void
	 */
	private function writeAppManifest(AppContentBundleRun $run): void {
		$path = $run->rootDir . '/src/manifest.json';
		$merged = $this->mergeManifest(base: $this->readJson(path: $path), manifest: $run->manifest);
		$merged['version'] = $run->semver;

		$this->writeJson(path: $path, data: $merged);
	}//end writeAppManifest()

	/**
	 * The template's manifest with everything but its identity taken from the version.
	 *
	 * @param array<string,mixed> $base The template's manifest.
	 * @param array<string,mixed> $manifest The rewritten manifest.
	 *
	 * @return array<string,mixed> The merged manifest.
	 */
	private function mergeManifest(array $base, array $manifest): array {
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

		return $merged;
	}//end mergeManifest()

	/**
	 * Fill the template's register file with the app's schemas and records.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 *
	 * @return void
	 */
	private function writeAppRegister(AppContentBundleRun $run): void {
		$path = $run->rootDir . '/lib/Settings/' . str_replace('-', '_', $run->appId) . '_register.json';
		$register = $this->describeRegister(run: $run, register: $this->readJson(path: $path));

		unset($register['components']['objects']);
		$objects = $this->registerObjects(run: $run);
		if ($objects !== []) {
			$register['components']['objects'] = $objects;
		}

		$this->writeJson(path: $path, data: $register);
	}//end writeAppRegister()

	/**
	 * The template's register file carrying the app's name, register and schemas.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 * @param array<string,mixed> $register The template's register file.
	 *
	 * @return array<string,mixed> The register file.
	 */
	private function describeRegister(AppContentBundleRun $run, array $register): array {
		$name = (string)($run->application['name'] ?? $run->appId);
		$description = (string)($run->application['description'] ?? '');

		$register['info']['title'] = $name;
		if ($description !== '') {
			$register['info']['description'] = $description;
		}

		$register['components']['registers'] = [
			$run->appId => $this->registerEntry(run: $run, version: (string)($register['info']['version'] ?? '0.1.0')),
		];
		// An app with no schemas gets an empty object, not the template's example.
		$register['components']['schemas'] = (object)$run->content['definitions'];

		return $register;
	}//end describeRegister()

	/**
	 * The exported app's own register entry.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 * @param string $version The register file's version.
	 *
	 * @return array{slug: string, title: string, description: string, version: string, schemas: array<int,string>}
	 */
	private function registerEntry(AppContentBundleRun $run, string $version): array {
		return [
			'slug' => $run->appId,
			'title' => (string)($run->application['name'] ?? $run->appId),
			'description' => (string)($run->application['description'] ?? ''),
			'version' => $version,
			'schemas' => array_keys($run->content['definitions']),
		];
	}//end registerEntry()

	/**
	 * The records as register objects, each naming its register, schema and key.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function registerObjects(AppContentBundleRun $run): array {
		$objects = [];
		foreach ($run->content['records'] as $schemaSlug => $rows) {
			foreach ($rows as $row) {
				$objects[] = array_merge(
					$row['data'],
					['@self' => ['register' => $run->appId, 'schema' => $schemaSlug, 'slug' => $row['key']]]
				);
			}
		}

		return $objects;
	}//end registerObjects()

	/**
	 * Write the root files another Buildiq instance imports.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 *
	 * @return void
	 */
	private function writePortableFiles(AppContentBundleRun $run): void {
		$this->writeJson(path: $run->rootDir . '/openbuild-app.json', data: $this->portableDescriptor(run: $run));
		$this->writeJson(path: $run->rootDir . '/manifest.json', data: $run->manifest);

		foreach ($run->content['definitions'] as $slug => $definition) {
			$this->writeJson(path: $run->rootDir . '/schemas/' . $slug . '.json', data: $definition);
		}

		foreach ($run->content['records'] as $slug => $rows) {
			$this->writeFile(path: $run->rootDir . '/data/' . $slug . '.jsonl', contents: $this->jsonLines(rows: $rows));
		}
	}//end writePortableFiles()

	/**
	 * The `openbuild-app.json` descriptor of the exported app.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 *
	 * @return array<string,mixed>
	 */
	private function portableDescriptor(AppContentBundleRun $run): array {
		$application = $run->application;
		return [
			'formatVersion' => AppRepoSerializer::FORMAT_VERSION,
			'slug' => (string)($application['slug'] ?? ''),
			'name' => (string)($application['name'] ?? ($application['slug'] ?? '')),
			'description' => (string)($application['description'] ?? ''),
			'category' => (string)($application['category'] ?? ($run->manifest['category'] ?? 'general')),
			'appType' => (string)($application['appType'] ?? 'virtual'),
			'version' => $run->semver,
			'channels' => ['schemas' => count($run->content['definitions']), 'records' => array_map('count', $run->content['records'])],
		];
	}//end portableDescriptor()

	/**
	 * One JSON line per record.
	 *
	 * @param array<int,array{key: string, data: array<string,mixed>}> $rows The records of one schema.
	 *
	 * @return string
	 */
	private function jsonLines(array $rows): string {
		$lines = '';
		foreach ($rows as $row) {
			$lines .= json_encode($row['data'], (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		}

		return $lines;
	}//end jsonLines()

	/**
	 * Replace the template README with a summary of this export.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 * @param array{pages: int, menu: int, schemas: int, records: int} $summary What was written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
	 */
	private function writeReadme(AppContentBundleRun $run, array $summary): void {
		$lines = array_merge(
			$this->readmeIntro(run: $run),
			$this->readmeContents(summary: $summary),
			$this->readmeUsage()
		);

		$this->writeFile(path: $run->rootDir . '/README.md', contents: implode("\n", $lines));
	}//end writeReadme()

	/**
	 * The README's title, description and source version.
	 *
	 * @param AppContentBundleRun $run The bundle in progress.
	 *
	 * @return array<int,string>
	 */
	private function readmeIntro(AppContentBundleRun $run): array {
		$name = (string)($run->application['name'] ?? ($run->application['slug'] ?? 'Buildiq app'));
		$description = trim((string)($run->application['description'] ?? ''));

		$lines = ['# ' . $name, ''];
		if ($description !== '') {
			$lines[] = $description;
			$lines[] = '';
		}

		$lines[] = 'Exported from Buildiq, version ' . $run->semver . '.';
		$lines[] = '';
		return $lines;
	}//end readmeIntro()

	/**
	 * The README section listing what the archive holds.
	 *
	 * @param array{pages: int, menu: int, schemas: int, records: int} $summary What was written.
	 *
	 * @return array<int,string>
	 */
	private function readmeContents(array $summary): array {
		return [
			'## What is in this archive',
			'',
			'- `manifest.json`: the app\'s ' . $summary['pages'] . ' pages and ' . $summary['menu'] . ' menu items.',
			'- `schemas/`: ' . $summary['schemas'] . ' schemas, one file each.',
			$this->readmeRecordLine(records: $summary['records']),
			'- `openbuild-app.json`: the app\'s Buildiq descriptor.',
			'- Everything else is a standalone Nextcloud app. `src/manifest.json` and'
				. ' `lib/Settings/*_register.json` hold the same pages, schemas and records.',
			'',
		];
	}//end readmeContents()

	/**
	 * The README line about the archive's records.
	 *
	 * @param int $records How many records were written.
	 *
	 * @return string
	 */
	private function readmeRecordLine(int $records): string {
		if ($records === 0) {
			return '- No records. Export again with seed data switched on to include them.';
		}

		return '- `data/`: ' . $records . ' records, one JSON line per record.';
	}//end readmeRecordLine()

	/**
	 * The README section on how to use the archive.
	 *
	 * @return array<int,string>
	 */
	private function readmeUsage(): array {
		return [
			'## Use it',
			'',
			'- As its own app: run `composer install`, `npm ci` and `npm run build`, then enable it.'
				. ' It needs OpenRegister.',
			'- Buildiq cannot import this archive yet.',
			'',
		];
	}//end readmeUsage()

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
