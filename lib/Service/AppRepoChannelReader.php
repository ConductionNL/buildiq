<?php

/**
 * Buildiq AppRepoChannelReader
 *
 * Reads the app-repo-format-v2 channels (data-registers, connectors,
 * automations, skills, flows and agents) out of one repo file map for
 * AppRepoParser.
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
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Lenient channel reader: an unsafe path, an unknown key or an unreadable
 * entry is skipped, never rewritten and never fatal.
 *
 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-apprepoparser-maps-a-repo-file-set-onto-the-clone-seam
 */
final class AppRepoChannelReader {

	/**
	 * The UUID pattern flow and agent entries are filed under — mirrors
	 * `AppRepoSerializer::isSafeUuid()`. Flows and agents have no slug
	 * (`FlowAndAgentExportBundler`'s own docblock: "the Flow entity has no
	 * slug"), so, unlike every other channel, these are UUID-keyed rather than
	 * slug-keyed.
	 *
	 * @var string
	 */
	private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

	/**
	 * The connector kinds a v2 repository may carry, mirroring the serializer's
	 * enum so an unknown directory is ignored rather than trusted.
	 *
	 * @var array<int,string>
	 */
	private const CONNECTOR_KINDS = ['source', 'mapping', 'synchronization', 'job'];

	/**
	 * Channels stored as `<prefix><key>.json`, mapped to the channel name and
	 * the pattern the key must match.
	 *
	 * @var array<string,array{channel:string,pattern:string}>
	 */
	private const KEYED_CHANNELS = [
		AppRepoParser::DATA_REGISTERS_PREFIX => ['channel' => 'dataRegisters', 'pattern' => AppRepoParser::SLUG_PATTERN],
		AppRepoParser::AUTOMATIONS_PREFIX => ['channel' => 'automations', 'pattern' => AppRepoParser::SLUG_PATTERN],
		AppRepoParser::FLOWS_PREFIX => ['channel' => 'flows', 'pattern' => self::UUID_PATTERN],
		AppRepoParser::AGENTS_PREFIX => ['channel' => 'agents', 'pattern' => self::UUID_PATTERN],
	];

	/**
	 * The channels read so far.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $channels = [
		'dataRegisters' => [],
		'connectors' => [],
		'automations' => [],
		'skills' => [],
		'flows' => [],
		'agents' => [],
	];

	/**
	 * Build a reader for one repo file map.
	 *
	 * @param array<string,string> $files The repo file map.
	 */
	public function __construct(
		private readonly array $files,
	) {

	}//end __construct()

	/**
	 * Read every channel entry, in sorted path order.
	 *
	 * @return array<string,mixed> The parsed channels.
	 *
	 * @spec openspec/specs/github-app-repo-format/spec.md#requirement-apprepoparser-maps-a-repo-file-set-onto-the-clone-seam
	 */
	public function read(): array {
		$paths = array_keys($this->files);
		sort($paths);

		foreach ($paths as $path) {
			$this->readPath(path: $path);
		}

		return $this->channels;

	}//end read()

	/**
	 * File one path under the channel its prefix names, if any.
	 *
	 * @param string $path The repo-relative path.
	 *
	 * @return void
	 */
	private function readPath(string $path): void {
		if ($this->isUnsafePath(path: $path) === true) {
			// Never rewrite to a safe form — drop it.
			return;
		}

		$prefix = $this->keyedPrefixOf(path: $path);
		if ($prefix !== null) {
			$this->readKeyedEntry(path: $path, prefix: $prefix);
			return;
		}

		$this->readUnkeyedEntry(path: $path);

	}//end readPath()

	/**
	 * File a connector or skill path; any other path is ignored.
	 *
	 * @param string $path The repo-relative path.
	 *
	 * @return void
	 */
	private function readUnkeyedEntry(string $path): void {
		if ($this->isJsonUnder(path: $path, prefix: AppRepoParser::CONNECTORS_PREFIX) === true) {
			$this->readConnector(path: $path);
			return;
		}

		if (str_starts_with($path, AppRepoParser::SKILLS_PREFIX) === true) {
			$this->readSkillFile(path: $path);
		}

	}//end readUnkeyedEntry()

	/**
	 * Whether a path escapes the repo (a `..` segment or an absolute path).
	 *
	 * @param string $path The repo-relative path.
	 *
	 * @return boolean
	 */
	private function isUnsafePath(string $path): bool {
		return str_contains($path, '..') === true || str_starts_with($path, '/') === true;

	}//end isUnsafePath()

	/**
	 * The keyed-channel prefix a `.json` path sits under, if any.
	 *
	 * @param string $path The repo-relative path.
	 *
	 * @return string|null
	 */
	private function keyedPrefixOf(string $path): ?string {
		foreach (array_keys(self::KEYED_CHANNELS) as $prefix) {
			if ($this->isJsonUnder(path: $path, prefix: $prefix) === true) {
				return $prefix;
			}
		}

		return null;

	}//end keyedPrefixOf()

	/**
	 * File a `<prefix><key>.json` entry whose key matches its channel's pattern.
	 *
	 * @param string $path   The repo-relative path.
	 * @param string $prefix The keyed-channel prefix.
	 *
	 * @return void
	 */
	private function readKeyedEntry(string $path, string $prefix): void {
		$spec = self::KEYED_CHANNELS[$prefix];
		$key = $this->jsonKey(path: $path, prefix: $prefix);
		if (preg_match($spec['pattern'], $key) !== 1) {
			return;
		}

		$blob = $this->decodeEntry(contents: $this->files[$path]);
		if ($blob !== null) {
			$this->channels[$spec['channel']][$key] = $blob;
		}

	}//end readKeyedEntry()

	/**
	 * File a `connectors/<kind>/<slug>.json` entry.
	 *
	 * @param string $path The repo-relative path.
	 *
	 * @return void
	 */
	private function readConnector(string $path): void {
		$parts = explode('/', $this->jsonKey(path: $path, prefix: AppRepoParser::CONNECTORS_PREFIX));
		if ($this->isConnectorKey(parts: $parts) === false) {
			return;
		}

		$blob = $this->decodeEntry(contents: $this->files[$path]);
		if ($blob !== null) {
			$this->channels['connectors'][$parts[0]][$parts[1]] = $blob;
		}

	}//end readConnector()

	/**
	 * Whether connector path parts are exactly a known kind and a slug.
	 *
	 * @param array<int,string> $parts The path parts below the connectors prefix.
	 *
	 * @return boolean
	 */
	private function isConnectorKey(array $parts): bool {
		return count($parts) === 2
			&& in_array($parts[0], self::CONNECTOR_KINDS, true) === true
			&& preg_match(AppRepoParser::SLUG_PATTERN, $parts[1]) === 1;

	}//end isConnectorKey()

	/**
	 * File one `skills/<slug>/<file>` entry verbatim.
	 *
	 * Skills are carried verbatim (hermiq's SkillBundleSerializer layout) —
	 * SKILL.md is markdown, not JSON, so no decode here.
	 *
	 * @param string $path The repo-relative path.
	 *
	 * @return void
	 */
	private function readSkillFile(string $path): void {
		$parts = explode('/', substr($path, strlen(AppRepoParser::SKILLS_PREFIX)), 2);
		if (count($parts) === 2 && preg_match(AppRepoParser::SLUG_PATTERN, $parts[0]) === 1 && $parts[1] !== '') {
			$this->channels['skills'][$parts[0]][$parts[1]] = (string)$this->files[$path];
		}

	}//end readSkillFile()

	/**
	 * Whether a path is a `.json` file under a prefix.
	 *
	 * @param string $path   The repo-relative path.
	 * @param string $prefix The channel prefix.
	 *
	 * @return boolean
	 */
	private function isJsonUnder(string $path, string $prefix): bool {
		return str_starts_with($path, $prefix) === true && str_ends_with($path, '.json') === true;

	}//end isJsonUnder()

	/**
	 * The part of a `.json` path between its prefix and the suffix.
	 *
	 * @param string $path   The repo-relative path.
	 * @param string $prefix The channel prefix.
	 *
	 * @return string
	 */
	private function jsonKey(string $path, string $prefix): string {
		return substr($path, strlen($prefix), -strlen('.json'));

	}//end jsonKey()

	/**
	 * Decode one channel entry, returning null rather than throwing.
	 *
	 * @param string $contents The raw contents.
	 *
	 * @return array<string,mixed>|null The decoded object, or null when unreadable.
	 */
	private function decodeEntry(string $contents): ?array {
		if (strlen($contents) > AppRepoParser::MAX_FILE_BYTES) {
			return null;
		}

		$decoded = json_decode($contents, true, AppRepoParser::MAX_JSON_DEPTH);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;

	}//end decodeEntry()
}//end class
