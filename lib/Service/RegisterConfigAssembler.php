<?php

/**
 * Buildiq RegisterConfigAssembler
 *
 * Assembles the register configuration Buildiq hands to OpenRegister: the
 * base openbuild_register.json merged with every Settings/register.d fragment
 * (ADR-037), and the version signed with those fragments.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use Psr\Log\LoggerInterface;

/**
 * One assembly of the register configuration.
 *
 * @spec openspec/specs/settings-and-observability/spec.md
 */
final class RegisterConfigAssembler {

	/**
	 * The merged register configuration.
	 *
	 * @var array<string,mixed>
	 */
	private array $data = [];

	/**
	 * The fragment-signed version.
	 *
	 * @var string
	 */
	private string $version = '';

	/**
	 * Build an assembler.
	 *
	 * @param LoggerInterface $logger PSR logger for unreadable configuration.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The merged register configuration (after assemble()).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/settings-and-observability/spec.md
	 */
	public function data(): array {
		return $this->data;
	}//end data()

	/**
	 * The fragment-signed version (after assemble()).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/settings-and-observability/spec.md
	 */
	public function version(): string {
		return $this->version;
	}//end version()

	/**
	 * Merge the register.d fragments onto the base register configuration and
	 * sign the version with them.
	 *
	 * ADR-037: each OpenSpec change drops its own fragment file in
	 * Settings/register.d/*.json instead of editing the monolith, so concurrent
	 * builds touch disjoint files (no merge conflicts). OpenAPI
	 * `components.schemas` / `paths` are keyed objects, so disjoint fragments
	 * union cleanly by key.
	 *
	 * @return string|null Null once assembled, or the failure message when the
	 *                     base configuration cannot be loaded.
	 *
	 * @spec openspec/specs/settings-and-observability/spec.md
	 */
	public function assemble(): ?string {
		$configData = $this->readBaseRegisterConfig();
		if (is_string($configData) === true) {
			return $configData;
		}

		$fragmentSig = '';
		foreach ($this->readRegisterFragments() as $fragment) {
			$configData = self::deepMergeConfig(base: $configData, overlay: $fragment['data']);
			$fragmentSig .= $fragment['name'] . ':' . md5($fragment['content']) . ';';
		}

		$this->data = $configData;
		$this->version = self::signedVersion(configData: $configData, fragmentSig: $fragmentSig);
		return null;
	}//end assemble()

	/**
	 * Fold the fragment signature into the version so OpenRegister's
	 * version-gated importFromApp re-imports whenever fragments change.
	 *
	 * @param array<string,mixed> $configData  The merged register configuration.
	 * @param string              $fragmentSig The fragment names and content hashes.
	 *
	 * @return string
	 */
	private static function signedVersion(array $configData, string $fragmentSig): string {
		$configVersion = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$configVersion .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		return $configVersion;
	}//end signedVersion()

	/**
	 * Read and decode openbuild_register.json, logging why it cannot be used.
	 *
	 * @return array<string,mixed>|string The decoded configuration, or the failure message.
	 */
	private function readBaseRegisterConfig(): array|string {
		$configPath = __DIR__ . '/../Settings/openbuild_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Buildiq: openbuild_register.json not found at ' . $configPath);
			return 'Configuration file openbuild_register.json not found.';
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Buildiq: failed to read openbuild_register.json');
			return 'Failed to read configuration file.';
		}

		return $this->decodeBaseRegisterConfig(configContent: $configContent);
	}//end readBaseRegisterConfig()

	/**
	 * Decode openbuild_register.json, logging a parse failure.
	 *
	 * @param string $configContent The raw file contents.
	 *
	 * @return array<string,mixed>|string The decoded configuration, or the failure message.
	 */
	private function decodeBaseRegisterConfig(string $configContent): array|string {
		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Buildiq: failed to parse openbuild_register.json: ' . json_last_error_msg());
			return 'Failed to parse configuration file: ' . json_last_error_msg();
		}

		return $configData;
	}//end decodeBaseRegisterConfig()

	/**
	 * Read the readable, well-formed register.d fragments in file-name order.
	 *
	 * @return array<int,array{name:string,content:string,data:array<mixed>}>
	 */
	private function readRegisterFragments(): array {
		$fragments = [];
		foreach ($this->registerFragmentFiles() as $fragmentFile) {
			$fragment = $this->readRegisterFragment(fragmentFile: $fragmentFile);
			if ($fragment !== null) {
				$fragments[] = $fragment;
			}
		}

		return $fragments;
	}//end readRegisterFragments()

	/**
	 * The register.d fragment paths in file-name order.
	 *
	 * @return array<int,string>
	 */
	private function registerFragmentFiles(): array {
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		if (is_dir($fragmentDir) === false) {
			return [];
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		sort($fragmentFiles);
		return $fragmentFiles;
	}//end registerFragmentFiles()

	/**
	 * Read one register.d fragment; an unreadable one is skipped silently, a
	 * malformed one with a warning.
	 *
	 * @param string $fragmentFile The fragment path.
	 *
	 * @return array{name:string,content:string,data:array<mixed>}|null
	 */
	private function readRegisterFragment(string $fragmentFile): ?array {
		$fragmentContent = file_get_contents($fragmentFile);
		if ($fragmentContent === false) {
			return null;
		}

		$fragmentData = json_decode($fragmentContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->warning(
				'Buildiq: skipping malformed register fragment ' . basename($fragmentFile)
				. ': ' . json_last_error_msg()
			);
			return null;
		}

		return ['name' => basename($fragmentFile), 'content' => $fragmentContent, 'data' => $fragmentData];
	}//end readRegisterFragment()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$base[$key] = self::mergeConfigValue(base: ($base[$key] ?? null), overlay: $value);
		}

		return $base;
	}//end deepMergeConfig()

	/**
	 * Merge one fragment value onto the base value under the same key: two
	 * lists concatenate, two arrays otherwise merge by key, anything else is
	 * overwritten by the fragment.
	 *
	 * @param mixed $base    The base value (null when the key is absent).
	 * @param mixed $overlay The fragment value.
	 *
	 * @return mixed The merged value.
	 */
	private static function mergeConfigValue(mixed $base, mixed $overlay): mixed {
		if (is_array($overlay) === false || is_array($base) === false) {
			return $overlay;
		}

		if (self::isConfigList(value: $base) === true && self::isConfigList(value: $overlay) === true) {
			return array_merge($base, $overlay);
		}

		return self::deepMergeConfig(base: $base, overlay: $overlay);
	}//end mergeConfigValue()

	/**
	 * Whether an array is empty or a 0-based sequential list.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return bool
	 */
	private static function isConfigList(array $value): bool {
		return $value === [] || array_keys($value) === range(0, (count($value) - 1));
	}//end isConfigList()
}//end class
