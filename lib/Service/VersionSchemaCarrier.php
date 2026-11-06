<?php

/**
 * Buildiq VersionSchemaCarrier
 *
 * Carries an application version's schemas and manifest wiring over to the
 * version it is promoted to (spec `version-promotion`, REQ-OBVP-005).
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
 * @spec openspec/changes/fix-promote-rewires-target-version/specs/version-promotion/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Syncs a promotion's schemas and rewires the copied manifest.
 *
 * @spec openspec/changes/fix-promote-rewires-target-version/specs/version-promotion/spec.md
 */
class VersionSchemaCarrier {
	/**
	 * Schema fields a promotion carries from the source schema to the target.
	 *
	 * Identity (id, uuid, slug, owner, organisation) and timestamps stay with
	 * the target schema.
	 *
	 * @var array<int,string>
	 */
	private const CARRIED_SCHEMA_FIELDS = [
		'title',
		'description',
		'summary',
		'icon',
		'required',
		'properties',
		'hardValidation',
		'immutable',
		'appendOnly',
		'searchable',
		'maxDepth',
		'authorization',
		'configuration',
		'hooks',
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger
	 * @param RegisterMapper $registerMapper Reads and updates the version registers
	 * @param SchemaMapper $schemaMapper Reads, updates and creates the per-version schemas
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Carry the source's schema set over to the target version (spec REQ-OBVP-005).
	 *
	 * Every version owns its own copies of the app's schemas, namespaced by the
	 * register stem (`{app}-{version}-{name}`, see ApplicationCreationService).
	 * This used to hand the target register the SOURCE's schema ids verbatim.
	 * That made production's register point at development's schema objects,
	 * left production's own schemas detached from any register (so deleting the
	 * app later could not find them), and never touched the production schema
	 * definitions, so a field added in development did not appear on them.
	 *
	 * Now each namespaced source schema is matched to its target counterpart by
	 * slug. An existing counterpart takes the source's definition; a missing one
	 * is created from it. A schema that is not namespaced to the source version
	 * is shared and is kept as is. The target register then lists the target's
	 * own schemas.
	 *
	 * @param array<string,mixed> $source Source ApplicationVersion
	 * @param array<string,mixed> $target Target ApplicationVersion
	 *
	 * @return array{schemaSlugs: array<string,string>, schemaIds: array<string,string>} Source to target maps
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-63
	 */
	public function carrySchemas(array $source, array $target): array {
		$maps = ['schemaSlugs' => [], 'schemaIds' => []];
		$sourceRegisterSlug = (string)($source['register'] ?? '');
		$targetRegisterSlug = (string)($target['register'] ?? '');

		if ($sourceRegisterSlug === '' || $targetRegisterSlug === '' || $sourceRegisterSlug === $targetRegisterSlug) {
			$this->logger->info(
				'Buildiq: carrySchemas skipped, source and target do not have two distinct registers'
				. ' (source=' . $sourceRegisterSlug . ', target=' . $targetRegisterSlug . ').'
			);
			return $maps;
		}

		$sourceRegister = $this->registerMapper->find($sourceRegisterSlug, _multitenancy: false);
		$targetRegister = $this->registerMapper->find($targetRegisterSlug, _multitenancy: false);

		$sourceStem = $this->schemaStem(registerSlug: $sourceRegisterSlug);
		$targetStem = $this->schemaStem(registerSlug: $targetRegisterSlug);
		$targetSchemaIds = [];

		foreach ((array)($sourceRegister->getSchemas() ?? []) as $sourceSchemaId) {
			$sourceSchema = $this->schemaMapper->find($sourceSchemaId, _rbac: false, _multitenancy: false);
			$sourceSlug = (string)$sourceSchema->getSlug();

			if ($sourceStem === '' || str_starts_with($sourceSlug, $sourceStem) === false) {
				// Not namespaced to the source version: a shared schema.
				$targetSchemaIds[] = $sourceSchemaId;
				continue;
			}

			$targetSlug = $targetStem . substr($sourceSlug, strlen($sourceStem));
			$targetSchema = $this->syncTargetSchema(sourceSchema: $sourceSchema, targetSlug: $targetSlug);

			$targetSchemaIds[] = $targetSchema->getId();
			$maps['schemaSlugs'][$sourceSlug] = $targetSlug;
			$maps['schemaIds'][(string)$sourceSchema->getId()] = (string)$targetSchema->getId();
		}//end foreach

		$targetRegister->setSchemas(array_values(array_unique($targetSchemaIds)));
		$this->registerMapper->update($targetRegister);

		$this->logger->info(
			'Buildiq: carrySchemas: target register ' . $targetRegisterSlug
			. ' now holds ' . count($targetSchemaIds) . ' schemas carried over from ' . $sourceRegisterSlug . '.'
		);

		return $maps;
	}//end carrySchemas()

	/**
	 * Give the target version's schema the source schema's definition.
	 *
	 * Updates the existing target schema when one with `$targetSlug` exists,
	 * otherwise creates it.
	 *
	 * @param Schema $sourceSchema The source version's schema
	 * @param string $targetSlug Slug of the target version's counterpart
	 *
	 * @return Schema The target schema
	 */
	private function syncTargetSchema(Schema $sourceSchema, string $targetSlug): Schema {
		$definition = [];
		foreach (self::CARRIED_SCHEMA_FIELDS as $field) {
			$definition[$field] = $sourceSchema->{'get' . ucfirst($field)}();
		}

		try {
			$existing = $this->schemaMapper->find($targetSlug, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$existing = null;
		}

		if ($existing instanceof Schema && (string)$existing->getSlug() === $targetSlug) {
			$existing->hydrate($definition);
			$updated = $this->schemaMapper->update($existing);
			if ($updated instanceof Schema) {
				return $updated;
			}

			return $existing;
		}

		$definition['slug'] = $targetSlug;
		return $this->schemaMapper->createFromArray($definition);
	}//end syncTargetSchema()

	/**
	 * The schema slug prefix a per-version register namespaces its schemas with.
	 *
	 * `openbuild-{app}-{version}` owns schemas named `{app}-{version}-{name}`.
	 *
	 * @param string $registerSlug The per-version register slug
	 *
	 * @return string The prefix, or '' when the register does not follow the convention
	 */
	private function schemaStem(string $registerSlug): string {
		if (str_starts_with($registerSlug, ApplicationVersionService::VERSION_REGISTER_PREFIX) === false) {
			return '';
		}

		return substr($registerSlug, strlen(ApplicationVersionService::VERSION_REGISTER_PREFIX)) . '-';
	}//end schemaStem()

	/**
	 * Point the target's manifest at the target version's own data.
	 *
	 * The source manifest names the source register and the source schemas in
	 * every `register` / `schema` key (page configs, widget sources, and so on).
	 * Copied verbatim, production's pages listed development's records. This
	 * walks the whole manifest and swaps each such value for its target
	 * counterpart. Values that name anything else (a shared register, a bound
	 * data register) are left alone.
	 *
	 * @param mixed $node The manifest (or a part of it)
	 * @param string $sourceRegister Source register slug
	 * @param string $targetRegister Target register slug
	 * @param array{schemaSlugs: array<string,string>, schemaIds: array<string,string>} $maps Source to target schema maps
	 *
	 * @return mixed The rewritten node
	 *
	 * @spec openspec/changes/fix-promote-rewires-target-version/specs/version-promotion/spec.md
	 */
	public function rewriteManifestWiring(mixed $node, string $sourceRegister, string $targetRegister, array $maps): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		foreach ($node as $key => $value) {
			if ($key === 'register' || $key === 'schema') {
				$node[$key] = $this->rewireValue(
					key: (string)$key,
					value: $value,
					sourceRegister: $sourceRegister,
					targetRegister: $targetRegister,
					maps: $maps
				);
				continue;
			}

			$node[$key] = $this->rewriteManifestWiring(
				node: $value,
				sourceRegister: $sourceRegister,
				targetRegister: $targetRegister,
				maps: $maps
			);
		}

		return $node;
	}//end rewriteManifestWiring()

	/**
	 * The target counterpart of one `register` or `schema` value.
	 *
	 * @param string $key Either `register` or `schema`
	 * @param mixed $value The value in the source manifest
	 * @param string $sourceRegister Source register slug
	 * @param string $targetRegister Target register slug
	 * @param array{schemaSlugs: array<string,string>, schemaIds: array<string,string>} $maps Source to target schema maps
	 *
	 * @return mixed The value to write
	 */
	private function rewireValue(mixed $key, mixed $value, string $sourceRegister, string $targetRegister, array $maps): mixed {
		if (is_string($value) === false && is_int($value) === false) {
			return $value;
		}

		$asString = (string)$value;
		if ($key === 'register') {
			if ($sourceRegister !== '' && $asString === $sourceRegister) {
				return $targetRegister;
			}

			return $value;
		}

		if (isset($maps['schemaSlugs'][$asString]) === true) {
			return $maps['schemaSlugs'][$asString];
		}

		if (isset($maps['schemaIds'][$asString]) === false) {
			return $value;
		}

		if (is_int($value) === true) {
			return (int)$maps['schemaIds'][$asString];
		}

		return $maps['schemaIds'][$asString];
	}//end rewireValue()
}//end class
