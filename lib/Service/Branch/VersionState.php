<?php

/**
 * Buildiq VersionState
 *
 * A version's manifest and own schemas in a form two versions can be compared
 * in (lifecycle-git-branches). Every version keeps its schemas in its own
 * register under its own slugs (`{app}-{version}-{name}`), and its manifest
 * names that register and those slugs. Compared as stored, every page of two
 * versions would differ. So the version's own register is written `@version`
 * and each of its own schemas `@schema:{name}`, keyed by `{name}`; `restore()`
 * turns that back into a given version's own register and slugs.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Branch;

use BadFunctionCallException;
use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Service\VersionSchemaCarrier;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Reads and restores a version's register-neutral state.
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
 */
class VersionState {

	/**
	 * The neutral name of a version's own register.
	 */
	public const OWN_REGISTER = '@version';

	/**
	 * The neutral prefix of a version's own schema.
	 */
	public const OWN_SCHEMA = '@schema:';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Reads the version registers.
	 * @param SchemaMapper   $schemaMapper   Reads the version schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * The schema slug prefix of a per-version register, or '' when it has none.
	 *
	 * @param string $registerSlug The register, `openbuild-{app}-{version}`.
	 *
	 * @return string `{app}-{version}-`, or ''.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-starts-a-branch-from-a-version-req-bqgb-001
	 */
	public static function stemOf(string $registerSlug): string {
		$prefix = ApplicationVersionServiceInterface::VERSION_REGISTER_PREFIX;
		if (str_starts_with($registerSlug, $prefix) === false || strlen($registerSlug) === strlen($prefix)) {
			return '';
		}

		return substr($registerSlug, strlen($prefix)) . '-';
	}//end stemOf()

	/**
	 * A version's neutral state: its manifest and its own schemas by name.
	 *
	 * @param array<string,mixed> $version The version's object data (`manifest`, `register`).
	 *
	 * @return array{manifest: array<string,mixed>, schemas: array<string,array<string,mixed>>}
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function read(array $version): array {
		$register = (string)($version['register'] ?? '');
		$map      = ['register' => [], 'schema' => []];
		$schemas  = [];
		if ($register !== '') {
			$map['register'][$register] = self::OWN_REGISTER;
		}

		foreach ($this->ownSchemas(registerSlug: $register) as $name => $schema) {
			$map['schema'][(string)$schema->getSlug()] = self::OWN_SCHEMA . $name;
			$map['schema'][(string)$schema->getId()]   = self::OWN_SCHEMA . $name;
			$schemas[$name] = self::definition(schema: $schema);
		}

		$manifest = [];
		if (is_array($version['manifest'] ?? null) === true) {
			$manifest = $version['manifest'];
		}

		return ['manifest' => self::rewire(node: $manifest, map: $map), 'schemas' => $schemas];
	}//end read()

	/**
	 * A neutral manifest in a version's own register and schema slugs.
	 *
	 * @param array<string,mixed> $manifest The neutral manifest.
	 * @param array<string,mixed> $version  The version it is written to.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function restore(array $manifest, array $version): array {
		$register = (string)($version['register'] ?? '');

		return self::rewire(
			node: $manifest,
			map: ['register' => [self::OWN_REGISTER => $register], 'schema' => ['stem' => self::stemOf(registerSlug: $register)]]
		);
	}//end restore()

	/**
	 * A version's own schemas, namespaced to its register, by name.
	 *
	 * @param string $registerSlug The version's register.
	 *
	 * @return array<string,Schema>
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function ownSchemas(string $registerSlug): array {
		$stem = self::stemOf(registerSlug: $registerSlug);
		if ($stem === '') {
			return [];
		}

		$own = [];
		foreach ($this->schemaIdsOf(registerSlug: $registerSlug) as $schemaId) {
			$schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
			$slug   = (string)$schema->getSlug();
			if (str_starts_with($slug, $stem) === true) {
				$own[substr($slug, strlen($stem))] = $schema;
			}
		}

		ksort($own);

		return $own;
	}//end ownSchemas()

	/**
	 * The schema ids a register lists; none when the register is missing.
	 *
	 * @param string $registerSlug The register.
	 *
	 * @return array<int,mixed>
	 */
	private function schemaIdsOf(string $registerSlug): array {
		try {
			$ids = $this->registerMapper->find($registerSlug, _multitenancy: false)->getSchemas();
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($ids) === false) {
			return [];
		}

		return $ids;
	}//end schemaIdsOf()

	/**
	 * The definition of a schema that a branch copies and a merge compares.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public static function definition(Schema $schema): array {
		$definition = [];
		foreach (VersionSchemaCarrier::CARRIED_SCHEMA_FIELDS as $field) {
			try {
				$definition[$field] = $schema->{'get' . ucfirst($field)}();
			} catch (BadFunctionCallException $e) {
				// A field this OpenRegister's Schema does not have is not compared.
				continue;
			}
		}

		return $definition;
	}//end definition()

	/**
	 * Swap every `register` and `schema` value the map names.
	 *
	 * A map with a `stem` under `schema` turns `@schema:{name}` into `{stem}{name}`.
	 *
	 * @param mixed                                                       $node The manifest, or a part of it.
	 * @param array{register: array<string,string>, schema: array<string,string>} $map  From value to value.
	 *
	 * @return mixed
	 */
	private static function rewire(mixed $node, array $map): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		foreach ($node as $key => $value) {
			if (($key === 'register' || $key === 'schema') && (is_string($value) === true || is_int($value) === true)) {
				$node[$key] = self::swap(value: $value, map: $map[$key]);
				continue;
			}

			$node[$key] = self::rewire(node: $value, map: $map);
		}

		return $node;
	}//end rewire()

	/**
	 * One value through the map.
	 *
	 * @param string|int            $value The value.
	 * @param array<string,string>  $map   From value to value, or a `stem` for neutral schemas.
	 *
	 * @return string|int
	 */
	private static function swap(string|int $value, array $map): string|int {
		$asString = (string)$value;
		if (isset($map['stem']) === true && str_starts_with($asString, self::OWN_SCHEMA) === true) {
			return $map['stem'] . substr($asString, strlen(self::OWN_SCHEMA));
		}

		return ($map[$asString] ?? $value);
	}//end swap()
}//end class
