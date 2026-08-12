<?php

/**
 * OpenBuild AppRepoParser
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
 * @package  OCA\OpenBuild\Service
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

namespace OCA\OpenBuild\Service;

use OCA\OpenBuild\Exception\AppRepoParseException;

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
	 * The only `formatVersion` major this OpenBuild parses (OQ-1: reject unknown
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
	 * The connector kinds a v2 repository may carry, mirroring the serializer's
	 * enum so an unknown directory is ignored rather than trusted.
	 *
	 * @var array<int,string>
	 */
	private const CONNECTOR_KINDS = ['source', 'mapping', 'synchronization', 'job'];

	/**
	 * Hard cap on a single file's byte size before decode (hostile-input guard).
	 */
	private const MAX_FILE_BYTES = 1048576;

	/**
	 * Maximum JSON nesting depth accepted on decode (hostile-input guard).
	 */
	private const MAX_JSON_DEPTH = 64;

	/**
	 * The kebab-case slug pattern shared with the Application + schema slugs.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

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

		$slug = (string)($descriptor['slug'] ?? '');
		$name = (string)($descriptor['name'] ?? $slug);
		$description = (string)($descriptor['description'] ?? '');
		$useCase = (string)($descriptor['useCase'] ?? $description);
		$category = (string)($descriptor['category'] ?? '');
		$version = (string)($descriptor['version'] ?? '');

		$payload = [
			'slug' => $slug,
			'title' => $name,
			'description' => $description,
			'useCase' => $useCase,
			'category' => $category,
			'version' => $version,
			'manifest' => $manifest,
			'companionSchemas' => $companions,
			'templateOrigin' => [
				'source' => 'github',
				'repo' => $this->formatRepo(repo: $repo),
				'version' => $version,
			],
		];

		// App-repo-format-v2 channels, added only for a v2 repo so a v1 parse
		// result stays byte-identical to what it was before this change.
		if ($this->majorOf(formatVersion: (string)($descriptor['formatVersion'] ?? '')) === self::SUPPORTED_FORMAT_MAJOR_V2) {
			$payload['channels'] = $this->parseChannels(files: $files);
		}

		return $payload;
	}//end parse()

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
		if (array_key_exists(self::DESCRIPTOR_FILE, $files) === false) {
			throw new AppRepoParseException(
				errorCode: 'descriptor_missing',
				message: 'No openbuild-app.json at the repo root — not an OpenBuild app repo.',
				filePath: self::DESCRIPTOR_FILE
			);
		}

		$descriptor = $this->decodeObject(
			path: self::DESCRIPTOR_FILE,
			contents: $files[self::DESCRIPTOR_FILE],
			unparseableCode: 'descriptor_unparseable'
		);

		$formatVersion = (string)($descriptor['formatVersion'] ?? '');
		$major = $this->majorOf(formatVersion: $formatVersion);
		if (in_array($major, [self::SUPPORTED_FORMAT_MAJOR, self::SUPPORTED_FORMAT_MAJOR_V2], true) === false) {
			throw new AppRepoParseException(
				errorCode: 'format_version_unsupported',
				message: 'openbuild-app.json formatVersion "' . $formatVersion . '" is not supported by this OpenBuild.',
				filePath: self::DESCRIPTOR_FILE
			);
		}

		$appType = (string)($descriptor['appType'] ?? '');
		if (in_array($appType, self::APP_TYPES, true) === false) {
			throw new AppRepoParseException(
				errorCode: 'app_type_unknown',
				message: 'openbuild-app.json appType "' . $appType . '" is not one of virtual|hybrid.',
				filePath: self::DESCRIPTOR_FILE
			);
		}

		$slug = (string)($descriptor['slug'] ?? '');
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			throw new AppRepoParseException(
				errorCode: 'app_type_unknown',
				message: 'openbuild-app.json slug "' . $slug . '" is not a valid kebab-case slug.',
				filePath: self::DESCRIPTOR_FILE
			);
		}

		return $descriptor;
	}//end parseDescriptor()

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
		if (array_key_exists(self::MANIFEST_FILE, $files) === false) {
			throw new AppRepoParseException(
				errorCode: 'manifest_missing',
				message: 'manifest.json is required.',
				filePath: self::MANIFEST_FILE
			);
		}

		$manifest = $this->decodeObject(
			path: self::MANIFEST_FILE,
			contents: $files[self::MANIFEST_FILE],
			unparseableCode: 'manifest_unparseable'
		);

		$this->validateManifest(manifest: $manifest);

		return $manifest;
	}//end parseManifest()

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
		if ($manifest === []) {
			throw new AppRepoParseException(
				errorCode: 'manifest_invalid',
				message: 'manifest.json is an empty object; a virtual app manifest must declare version + pages.',
				filePath: self::MANIFEST_FILE
			);
		}

		if (is_string($manifest['version'] ?? null) === false) {
			throw new AppRepoParseException(
				errorCode: 'manifest_invalid',
				message: 'manifest.json is missing a string `version` property.',
				filePath: self::MANIFEST_FILE
			);
		}

		if (is_array($manifest['pages'] ?? null) === false) {
			throw new AppRepoParseException(
				errorCode: 'manifest_invalid',
				message: 'manifest.json is missing a `pages` array.',
				filePath: self::MANIFEST_FILE
			);
		}
	}//end validateManifest()

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
	 * automations and skills.
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
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
	 */
	private function parseChannels(array $files): array {
		$channels = [
			'dataRegisters' => [],
			'connectors' => [],
			'automations' => [],
			'skills' => [],
		];

		$paths = array_keys($files);
		sort($paths);

		foreach ($paths as $path) {
			if (str_contains($path, '..') === true || str_starts_with($path, '/') === true) {
				// Never rewrite to a safe form — drop it.
				continue;
			}

			if (str_starts_with($path, self::DATA_REGISTERS_PREFIX) === true && str_ends_with($path, '.json') === true) {
				$slug = substr($path, strlen(self::DATA_REGISTERS_PREFIX), -strlen('.json'));
				if (preg_match(self::SLUG_PATTERN, $slug) === 1) {
					$blob = $this->decodeChannelEntry(contents: $files[$path]);
					if ($blob !== null) {
						$channels['dataRegisters'][$slug] = $blob;
					}
				}

				continue;
			}

			if (str_starts_with($path, self::CONNECTORS_PREFIX) === true && str_ends_with($path, '.json') === true) {
				$rest = substr($path, strlen(self::CONNECTORS_PREFIX), -strlen('.json'));
				$parts = explode('/', $rest);
				if (count($parts) === 2
					&& in_array($parts[0], self::CONNECTOR_KINDS, true) === true
					&& preg_match(self::SLUG_PATTERN, $parts[1]) === 1
				) {
					$blob = $this->decodeChannelEntry(contents: $files[$path]);
					if ($blob !== null) {
						$channels['connectors'][$parts[0]][$parts[1]] = $blob;
					}
				}

				continue;
			}

			if (str_starts_with($path, self::AUTOMATIONS_PREFIX) === true && str_ends_with($path, '.json') === true) {
				$slug = substr($path, strlen(self::AUTOMATIONS_PREFIX), -strlen('.json'));
				if (preg_match(self::SLUG_PATTERN, $slug) === 1) {
					$blob = $this->decodeChannelEntry(contents: $files[$path]);
					if ($blob !== null) {
						$channels['automations'][$slug] = $blob;
					}
				}

				continue;
			}

			if (str_starts_with($path, self::SKILLS_PREFIX) === true) {
				$rest = substr($path, strlen(self::SKILLS_PREFIX));
				$parts = explode('/', $rest, 2);
				if (count($parts) === 2 && preg_match(self::SLUG_PATTERN, $parts[0]) === 1 && $parts[1] !== '') {
					// Skills are carried verbatim (hermiq's SkillBundleSerializer
					// layout) — SKILL.md is markdown, not JSON, so no decode here.
					$channels['skills'][$parts[0]][$parts[1]] = (string)$files[$path];
				}
			}
		}//end foreach

		return $channels;
	}//end parseChannels()

	/**
	 * Decode one channel entry, returning null rather than throwing.
	 *
	 * @param string $contents The raw contents.
	 *
	 * @return array<string,mixed>|null The decoded object, or null when unreadable.
	 */
	private function decodeChannelEntry(string $contents): ?array {
		if (strlen($contents) > self::MAX_FILE_BYTES) {
			return null;
		}

		$decoded = json_decode($contents, true, self::MAX_JSON_DEPTH);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end decodeChannelEntry()

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
		$seenSlugs = [];

		// Deterministic iteration so a duplicate names a stable pair.
		$paths = array_keys($files);
		sort($paths);

		foreach ($paths as $path) {
			if (str_starts_with($path, self::SCHEMAS_PREFIX) === false || str_ends_with($path, '.json') === false) {
				continue;
			}

			$base = substr($path, strlen(self::SCHEMAS_PREFIX), -strlen('.json'));
			if (str_contains($base, '/') === true || preg_match(self::SLUG_PATTERN, $base) !== 1) {
				throw new AppRepoParseException(
					errorCode: 'schema_invalid',
					message: 'schema file base name "' . $base . '" is not a valid kebab-case slug.',
					filePath: $path
				);
			}

			$schema = $this->decodeObject(
				path: $path,
				contents: $files[$path],
				unparseableCode: 'schema_unparseable'
			);

			if (is_array($schema['properties'] ?? null) === false) {
				throw new AppRepoParseException(
					errorCode: 'schema_invalid',
					message: 'schema file "' . $path . '" is not a JSON-schema object (missing `properties`).',
					filePath: $path
				);
			}

			$slug = (string)($schema['slug'] ?? $base);
			if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
				throw new AppRepoParseException(
					errorCode: 'schema_invalid',
					message: 'schema file "' . $path . '" declares an invalid slug "' . $slug . '".',
					filePath: $path
				);
			}

			if (isset($seenSlugs[$slug]) === true) {
				throw new AppRepoParseException(
					errorCode: 'schema_slug_duplicate',
					message: 'schema slug "' . $slug . '" is declared by both ' . $seenSlugs[$slug] . ' and ' . $path . '.',
					filePath: $path
				);
			}

			$schema['slug'] = $slug;
			$seenSlugs[$slug] = $path;
			$companions[] = $schema;
		}//end foreach

		return $companions;
	}//end parseCompanionSchemas()

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

		$owner = (string)$repo['owner'];
		$name = (string)$repo['name'];
		if ($owner === '' || $name === '') {
			return null;
		}

		return $owner . '/' . $name;
	}//end formatRepo()
}//end class
