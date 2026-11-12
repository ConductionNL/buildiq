<?php

/**
 * Buildiq AppRepoParser
 *
 * Turns a fetched GitHub app-repo file map (`path => contents`, exactly what a
 * GitHub contents-API fetch yields) into an in-memory `ApplicationTemplate`-shaped
 * array suitable for the existing `ApplicationsController::installFromTemplateArray`
 * clone seam (github-app-repo-format REQ-GARF-007). Pure function of the file map:
 * NO network I/O, NO OpenRegister writes, NO companion-schema namespacing or
 * manifest rewriting (that reuse lives in the clone seam it feeds).
 *
 * Import validation is STRICT and ALL-OR-NOTHING (REQ-GARF-008, design.md
 * Decision 4): any single malformed/missing file aborts the whole parse with a
 * stable per-file error code and produces no payload — the
 * `manifest-validation-discards-backend-delta` failure mode is explicitly
 * forbidden. The parser is hostile-input-safe (design.md Decision 8): every file
 * is size-bounded and depth-bounded before decode, schema filenames are validated
 * against the kebab-case slug pattern (path-traversal rejected), and the manifest
 * runs the same structural manifest guard the clone path relies on before use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\AppRepoParseException;

/**
 * Strict, all-or-nothing parser for the canonical GitHub app-repo layout.
 *
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */
class AppRepoParser {
	/**
	 * The descriptor filename that anchors a conforming repo.
	 */
	public const DESCRIPTOR_FILE = 'openbuild-app.json';

	/**
	 * The manifest filename carrying the ApplicationVersion.manifest blob.
	 */
	public const MANIFEST_FILE = 'manifest.json';

	/**
	 * The directory prefix each companion schema file lives under.
	 */
	public const SCHEMAS_PREFIX = 'schemas/';

	/**
	 * The only `formatVersion` major this Buildiq parses (OQ-1: reject unknown
	 * majors, tolerate unknown minors/keys for forward compatibility).
	 */
	private const SUPPORTED_FORMAT_MAJOR = 1;

	/**
	 * The `formatVersion` major introduced by app-repo-format-v2. Parsed in
	 * addition to major 1, which keeps its exact prior behaviour — a v1 repo is
	 * read today as it was before this change existed.
	 *
	 * @var int
	 */
	private const SUPPORTED_FORMAT_MAJOR_V2 = 2;

	/**
	 * V2 channel prefixes. Each is re-validated on read: the parser never trusts
	 * that a repository was written by a well-behaved serializer.
	 *
	 * @var string
	 */
	public const DATA_REGISTERS_PREFIX = 'data-registers/';

	/**
	 * V2 connector channel prefix.
	 *
	 * @var string
	 */
	public const CONNECTORS_PREFIX = 'connectors/';

	/**
	 * V2 automations channel prefix.
	 *
	 * @var string
	 */
	public const AUTOMATIONS_PREFIX = 'automations/';

	/**
	 * V2 skills channel prefix (hermiq's SkillBundleSerializer layout).
	 *
	 * @var string
	 */
	public const SKILLS_PREFIX = 'skills/';

	/**
	 * V2 flows channel prefix (app-repo-format-flow-agent-export).
	 *
	 * @var string
	 */
	public const FLOWS_PREFIX = 'flows/';

	/**
	 * V2 agents channel prefix (app-repo-format-flow-agent-export).
	 *
	 * @var string
	 */
	public const AGENTS_PREFIX = 'agents/';

	/**
	 * Hard cap on a single file's byte size before decode (hostile-input guard).
	 */
	public const MAX_FILE_BYTES = 1048576;

	/**
	 * Maximum JSON nesting depth accepted on decode (hostile-input guard).
	 */
	public const MAX_JSON_DEPTH = 64;

	/**
	 * The kebab-case slug pattern shared with the Application + schema slugs.
	 */
	public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * The app-type values the format recognises.
	 *
	 * @var array<int,string>
	 */
	private const APP_TYPES = ['virtual', 'hybrid'];

	/**
	 * Parse a repo file map into the clone-seam template array.
	 *
	 * @param array<string,string> $files A `path => contents` map (bytes/string).
	 * @param array{owner:string,name:string}|null $repo Optional repo identity for `templateOrigin`.
	 *
	 * @return array<string,mixed> The `ApplicationTemplate`-shaped payload.
	 *
	 * @throws AppRepoParseException On any conforming-repo violation (all-or-nothing).
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function parse(array $files, ?array $repo = null): array {
		$descriptor = $this->parseDescriptor(files: $files);
		$manifest = $this->parseManifest(files: $files);
		$companions = $this->parseCompanionSchemas(files: $files);

		$payload = $this->descriptorFields(descriptor: $descriptor);
		$payload['manifest'] = $manifest;
		$payload['companionSchemas'] = $companions;
		$payload['templateOrigin'] = $this->templateOrigin(repo: $repo, version: $payload['version']);

		// App-repo-format-v2 channels, added only for a v2 repo so a v1 parse
		// result stays byte-identical to what it was before this change.
		if ($this->majorOf(formatVersion: (string)($descriptor['formatVersion'] ?? '')) === self::SUPPORTED_FORMAT_MAJOR_V2) {
			$payload['channels'] = $this->parseChannels(files: $files);
		}

		return $payload;
	}//end parse()

	/**
	 * The `templateOrigin` block naming where the template came from.
	 *
	 * @param array{owner:string,name:string}|null $repo    Optional repo identity.
	 * @param string                               $version The descriptor version.
	 *
	 * @return array{source:string,repo:string|null,version:string}
	 */
	private function templateOrigin(?array $repo, string $version): array {
		return [
			'source' => 'github',
			'repo' => $this->formatRepo(repo: $repo),
			'version' => $version,
		];
	}//end templateOrigin()

	/**
	 * The descriptor's identity fields, with their fallbacks, in payload order.
	 *
	 * @param array<string,mixed> $descriptor The decoded descriptor.
	 *
	 * @return array<string,string>
	 */
	private function descriptorFields(array $descriptor): array {
		$slug = (string)($descriptor['slug'] ?? '');
		$description = (string)($descriptor['description'] ?? '');

		return [
			'slug' => $slug,
			'title' => (string)($descriptor['name'] ?? $slug),
			'description' => $description,
			'useCase' => (string)($descriptor['useCase'] ?? $description),
			'category' => (string)($descriptor['category'] ?? ''),
			'version' => (string)($descriptor['version'] ?? ''),
		];
	}//end descriptorFields()

	/**
	 * Decode + validate the root descriptor (identity, formatVersion, appType).
	 *
	 * @param array<string,string> $files The repo file map.
	 *
	 * @return array<string,mixed> The decoded descriptor.
	 *
	 * @throws AppRepoParseException descriptor_missing | descriptor_unparseable |
	 *                               format_version_unsupported | app_type_unknown.
	 */
	private function parseDescriptor(array $files): array {
		$descriptor = $this->decodeDescriptor(files: $files);

		$this->assertFormatVersionSupported(descriptor: $descriptor);
		$this->assertAppTypeKnown(descriptor: $descriptor);
		$this->assertDescriptorSlug(descriptor: $descriptor);

		return $descriptor;
	}//end parseDescriptor()

	/**
	 * Require and decode the root descriptor.
	 *
	 * @param array<string,string> $files The repo file map.
	 *
	 * @return array<string,mixed> The decoded descriptor.
	 *
	 * @throws AppRepoParseException descriptor_missing | descriptor_unparseable.
	 */
	private function decodeDescriptor(array $files): array {
		if (array_key_exists(self::DESCRIPTOR_FILE, $files) === false) {
			throw new AppRepoParseException(
				errorCode: 'descriptor_missing',
				message: 'No openbuild-app.json at the repo root — not an Buildiq app repo.',
				filePath: self::DESCRIPTOR_FILE
			);
		}

		return $this->decodeObject(
			path: self::DESCRIPTOR_FILE,
			contents: $files[self::DESCRIPTOR_FILE],
			unparseableCode: 'descriptor_unparseable'
		);
	}//end decodeDescriptor()

	/**
	 * Refuse a descriptor whose formatVersion major this Buildiq cannot read.
	 *
	 * @param array<string,mixed> $descriptor The decoded descriptor.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException format_version_unsupported.
	 */
	private function assertFormatVersionSupported(array $descriptor): void {
		$formatVersion = (string)($descriptor['formatVersion'] ?? '');
		$major = $this->majorOf(formatVersion: $formatVersion);
		if (in_array($major, [self::SUPPORTED_FORMAT_MAJOR, self::SUPPORTED_FORMAT_MAJOR_V2], true) === false) {
			throw new AppRepoParseException(
				errorCode: 'format_version_unsupported',
				message: 'openbuild-app.json formatVersion "' . $formatVersion . '" is not supported by this Buildiq.',
				filePath: self::DESCRIPTOR_FILE
			);
		}
	}//end assertFormatVersionSupported()

	/**
	 * Refuse a descriptor whose appType is not one of virtual|hybrid.
	 *
	 * @param array<string,mixed> $descriptor The decoded descriptor.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException app_type_unknown.
	 */
	private function assertAppTypeKnown(array $descriptor): void {
		$appType = (string)($descriptor['appType'] ?? '');
		if (in_array($appType, self::APP_TYPES, true) === false) {
			throw new AppRepoParseException(
				errorCode: 'app_type_unknown',
				message: 'openbuild-app.json appType "' . $appType . '" is not one of virtual|hybrid.',
				filePath: self::DESCRIPTOR_FILE
			);
		}
	}//end assertAppTypeKnown()

	/**
	 * Refuse a descriptor whose slug is not kebab-case.
	 *
	 * @param array<string,mixed> $descriptor The decoded descriptor.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException app_type_unknown (the code the format has always used here).
	 */
	private function assertDescriptorSlug(array $descriptor): void {
		$slug = (string)($descriptor['slug'] ?? '');
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			throw new AppRepoParseException(
				errorCode: 'app_type_unknown',
				message: 'openbuild-app.json slug "' . $slug . '" is not a valid kebab-case slug.',
				filePath: self::DESCRIPTOR_FILE
			);
		}
	}//end assertDescriptorSlug()

	/**
	 * Decode + structurally validate the manifest blob.
	 *
	 * @param array<string,string> $files The repo file map.
	 *
	 * @return array<string,mixed> The validated manifest blob.
	 *
	 * @throws AppRepoParseException manifest_missing | manifest_unparseable | manifest_invalid.
	 */
	private function parseManifest(array $files): array {
		$this->assertManifestPresent(files: $files);

		$manifest = $this->decodeObject(
			path: self::MANIFEST_FILE,
			contents: $files[self::MANIFEST_FILE],
			unparseableCode: 'manifest_unparseable'
		);

		$this->validateManifest(manifest: $manifest);

		return $manifest;
	}//end parseManifest()

	/**
	 * Refuse a file map without a root manifest.
	 *
	 * @param array<string,string> $files The repo file map.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException manifest_missing.
	 */
	private function assertManifestPresent(array $files): void {
		if (array_key_exists(self::MANIFEST_FILE, $files) === false) {
			throw new AppRepoParseException(
				errorCode: 'manifest_missing',
				message: 'manifest.json is required.',
				filePath: self::MANIFEST_FILE
			);
		}
	}//end assertManifestPresent()

	/**
	 * Structural manifest validation — the same guard the clone path relies on
	 * before a manifest is handed to installFromTemplateArray. A conforming
	 * app-manifest (app-manifest.schema.json v2.14.0+) is a non-empty JSON object
	 * carrying at least a string `version` and a `pages` array.
	 *
	 * @param array<string,mixed> $manifest The decoded manifest.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException manifest_invalid naming the failing property path.
	 */
	private function validateManifest(array $manifest): void {
		$problem = $this->manifestProblem(manifest: $manifest);
		if ($problem !== null) {
			throw new AppRepoParseException(
				errorCode: 'manifest_invalid',
				message: $problem,
				filePath: self::MANIFEST_FILE
			);
		}
	}//end validateManifest()

	/**
	 * The first structural problem of a decoded manifest, if any.
	 *
	 * @param array<string,mixed> $manifest The decoded manifest.
	 *
	 * @return string|null The refusal message, or null for a valid manifest.
	 */
	private function manifestProblem(array $manifest): ?string {
		if ($manifest === []) {
			return 'manifest.json is an empty object; a virtual app manifest must declare version + pages.';
		}

		if (is_string($manifest['version'] ?? null) === false) {
			return 'manifest.json is missing a string `version` property.';
		}

		if (is_array($manifest['pages'] ?? null) === false) {
			return 'manifest.json is missing a `pages` array.';
		}

		return null;
	}//end manifestProblem()

	/**
	 * Collect + validate `schemas/*.json` into the companionSchemas array.
	 *
	 * @param array<string,string> $files The repo file map.
	 *
	 * @return array<int,array<string,mixed>> Parsed companion schema blobs.
	 *
	 * @throws AppRepoParseException schema_unparseable | schema_invalid | schema_slug_duplicate.
	 */

	/**
	 * Parse the app-repo-format-v2 channels: data-registers, connectors,
	 * automations, skills, flows and agents.
	 *
	 * Deliberately LENIENT where `parseCompanionSchemas()` is strict. A companion
	 * schema is load-bearing — a malformed one means the app cannot work, so it
	 * aborts the whole parse. A channel entry is additive configuration: dropping
	 * one unreadable connector must not make an otherwise-valid repository
	 * unimportable. Bad entries are skipped, and every path is re-validated
	 * because the parser never trusts that the repo was written by a well-behaved
	 * serializer.
	 *
	 * @param array<string,string> $files The repo file map.
	 *
	 * @return array<string,mixed> The parsed channels.
	 *
	 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
	 */
	private function parseChannels(array $files): array {
		return (new AppRepoChannelReader(files: $files))->read();
	}//end parseChannels()

	/**
	 * Collect the companion JSON-schema files shipped alongside an app-repo-format-v2 descriptor.
	 *
	 * Iterates `$files` in sorted path order so a duplicate slug always names the same pair,
	 * and validates each entry's base name, JSON body and declared slug.
	 *
	 * @param array<string, string> $files Repo file map, keyed by path, valued by raw contents.
	 *
	 * @return array<int, array<string, mixed>> The decoded companion schemas, in path order.
	 *
	 * @throws AppRepoParseException When a schema file has an invalid base name or slug, is not a
	 *                               JSON-schema object, or duplicates an already-seen slug.
	 */
	private function parseCompanionSchemas(array $files): array {
		$companions = [];
		$slugClaims = new SchemaSlugClaims();

		foreach ($this->companionSchemaPaths(files: $files) as $path) {
			$schema = $this->readCompanionSchema(path: $path, contents: $files[$path]);
			$slugClaims->claim(slug: $schema['slug'], path: $path);
			$companions[] = $schema;
		}

		return $companions;
	}//end parseCompanionSchemas()

	/**
	 * The `schemas/*.json` paths of a file map, in sorted order so a
	 * duplicate always names a stable pair.
	 *
	 * @param array<string,string> $files The repo file map.
	 *
	 * @return array<int,string>
	 */
	private function companionSchemaPaths(array $files): array {
		$paths = array_keys($files);
		sort($paths);

		return array_values(
			array_filter(
				$paths,
				static fn (string $path): bool => str_starts_with($path, self::SCHEMAS_PREFIX) === true && str_ends_with($path, '.json') === true
			)
		);
	}//end companionSchemaPaths()

	/**
	 * Validate one companion schema file and stamp its resolved slug.
	 *
	 * @param string $path     The repo-relative schema path.
	 * @param string $contents The raw file contents.
	 *
	 * @return array<string,mixed> The decoded schema with `slug` set.
	 *
	 * @throws AppRepoParseException schema_invalid | schema_unparseable.
	 */
	private function readCompanionSchema(string $path, string $contents): array {
		$base = $this->companionBaseName(path: $path);

		$schema = $this->decodeObject(
			path: $path,
			contents: $contents,
			unparseableCode: 'schema_unparseable'
		);

		$this->assertJsonSchemaObject(path: $path, schema: $schema);

		$slug = (string)($schema['slug'] ?? $base);
		$this->assertCompanionSlug(path: $path, slug: $slug);

		$schema['slug'] = $slug;
		return $schema;
	}//end readCompanionSchema()

	/**
	 * The kebab-case base name of a companion schema path.
	 *
	 * @param string $path The repo-relative schema path.
	 *
	 * @return string
	 *
	 * @throws AppRepoParseException schema_invalid when nested or not kebab-case.
	 */
	private function companionBaseName(string $path): string {
		$base = substr($path, strlen(self::SCHEMAS_PREFIX), -strlen('.json'));
		if (str_contains($base, '/') === true || preg_match(self::SLUG_PATTERN, $base) !== 1) {
			throw new AppRepoParseException(
				errorCode: 'schema_invalid',
				message: 'schema file base name "' . $base . '" is not a valid kebab-case slug.',
				filePath: $path
			);
		}

		return $base;
	}//end companionBaseName()

	/**
	 * Refuse a companion schema without a `properties` object.
	 *
	 * @param string              $path   The repo-relative schema path.
	 * @param array<string,mixed> $schema The decoded schema.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException schema_invalid.
	 */
	private function assertJsonSchemaObject(string $path, array $schema): void {
		if (is_array($schema['properties'] ?? null) === false) {
			throw new AppRepoParseException(
				errorCode: 'schema_invalid',
				message: 'schema file "' . $path . '" is not a JSON-schema object (missing `properties`).',
				filePath: $path
			);
		}
	}//end assertJsonSchemaObject()

	/**
	 * Refuse a companion schema whose resolved slug is not kebab-case.
	 *
	 * @param string $path The repo-relative schema path.
	 * @param string $slug The declared (or base-name) slug.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException schema_invalid.
	 */
	private function assertCompanionSlug(string $path, string $slug): void {
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			throw new AppRepoParseException(
				errorCode: 'schema_invalid',
				message: 'schema file "' . $path . '" declares an invalid slug "' . $slug . '".',
				filePath: $path
			);
		}
	}//end assertCompanionSlug()

	/**
	 * Size-bound, depth-bound, and JSON-decode a file into an object array.
	 *
	 * @param string $path The repo-relative file path (for the error).
	 * @param string $contents The raw file bytes.
	 * @param string $unparseableCode The error code to raise on a decode failure.
	 *
	 * @return array<string,mixed> The decoded object (associative array).
	 *
	 * @throws AppRepoParseException When too large, unparseable, or not a JSON object.
	 */
	private function decodeObject(string $path, string $contents, string $unparseableCode): array {
		if (strlen($contents) > self::MAX_FILE_BYTES) {
			throw new AppRepoParseException(
				errorCode: $unparseableCode,
				message: $path . ' exceeds the maximum allowed size and was rejected before decode.',
				filePath: $path
			);
		}

		try {
			$decoded = json_decode($contents, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new AppRepoParseException(
				errorCode: $unparseableCode,
				message: $path . ' is not valid JSON: ' . $e->getMessage(),
				filePath: $path,
				previous: $e
			);
		}

		if (is_array($decoded) === false || array_is_list($decoded) === true) {
			throw new AppRepoParseException(
				errorCode: $unparseableCode,
				message: $path . ' is not a JSON object.',
				filePath: $path
			);
		}

		return $decoded;
	}//end decodeObject()

	/**
	 * Extract the numeric major from a `MAJOR.MINOR` (or `MAJOR`) formatVersion.
	 *
	 * @param string $formatVersion The descriptor's formatVersion string.
	 *
	 * @return int The major, or -1 when unparseable (rejected by the caller).
	 */
	private function majorOf(string $formatVersion): int {
		if (preg_match('/^([0-9]+)/', $formatVersion, $matches) !== 1) {
			return -1;
		}

		return (int)$matches[1];
	}//end majorOf()

	/**
	 * Format the repo identity for `templateOrigin.repo` (`owner/name` or null).
	 *
	 * @param array{owner:string,name:string}|null $repo Optional repo identity.
	 *
	 * @return string|null
	 */
	private function formatRepo(?array $repo): ?string {
		if ($repo === null) {
			return null;
		}

		return $this->ownerSlashName(owner: (string)$repo['owner'], name: (string)$repo['name']);
	}//end formatRepo()

	/**
	 * Join a repo owner and name as `owner/name`, or null when either is empty.
	 *
	 * @param string $owner The repo owner.
	 * @param string $name  The repo name.
	 *
	 * @return string|null
	 */
	private function ownerSlashName(string $owner, string $name): ?string {
		if ($owner === '' || $name === '') {
			return null;
		}

		return $owner . '/' . $name;
	}//end ownerSlashName()
}//end class
