<?php

/**
 * Buildiq VersionSchemaWriter
 *
 * Writes merged schemas into a version's own register (lifecycle-git-branches)
 * and names the changes among them that can break records already stored:
 * a removed schema or property, a property whose type changed, and a property
 * that became required. Those are the changes OpenRegister's schema API asks
 * the designer to confirm with `acknowledgeBreaking`.
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Saves merged schemas and lists their breaking changes.
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
 */
class VersionSchemaWriter {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Updates the version register's schema list.
	 * @param SchemaMapper   $schemaMapper   Updates and creates the version's schemas.
	 * @param VersionState   $state          Reads the version's own schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly VersionState $state,
	) {
	}//end __construct()

	/**
	 * The changes from the current schemas to the merged ones that can break stored records.
	 *
	 * @param array<string,array<string,mixed>> $current The version's schemas by name.
	 * @param array<string,array<string,mixed>> $merged  The merged schemas by name.
	 *
	 * @return list<string> One plain sentence per breaking change.
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
	 */
	public static function breakingChanges(array $current, array $merged): array {
		$changes = [];
		foreach ($current as $name => $schema) {
			if (isset($merged[$name]) === false) {
				$changes[] = sprintf('The schema "%s" is removed.', $name);
				continue;
			}

			$changes = array_merge($changes, self::propertyChanges(name: (string)$name, before: $schema, after: $merged[$name]));
		}

		return $changes;
	}//end breakingChanges()

	/**
	 * The breaking changes to one schema's properties.
	 *
	 * @param string              $name   The schema.
	 * @param array<string,mixed> $before Its current definition.
	 * @param array<string,mixed> $after  Its merged definition.
	 *
	 * @return list<string>
	 */
	private static function propertyChanges(string $name, array $before, array $after): array {
		$was      = (array)($before['properties'] ?? []);
		$is       = (array)($after['properties'] ?? []);
		$required = array_diff((array)($after['required'] ?? []), (array)($before['required'] ?? []));
		$changes  = [];
		foreach ($was as $property => $definition) {
			if (array_key_exists($property, $is) === false) {
				$changes[] = sprintf('The field "%s.%s" is removed.', $name, $property);
			} else if ((($definition['type'] ?? null) !== ($is[$property]['type'] ?? null))) {
				$changes[] = sprintf('The field "%s.%s" changes type.', $name, $property);
			}
		}

		foreach ($required as $property) {
			$changes[] = sprintf('The field "%s.%s" becomes required.', $name, (string)$property);
		}

		return $changes;
	}//end propertyChanges()

	/**
	 * Save the merged schemas as the version's own: each existing one takes its
	 * merged definition, a new one is created and listed on the register, and
	 * one the merge removed is taken off the register (its definition is kept).
	 *
	 * @param array<string,mixed>               $version The version written to (`register`).
	 * @param array<string,array<string,mixed>> $schemas The merged schemas by name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
	 */
	public function write(array $version, array $schemas): void {
		$registerSlug = (string)($version['register'] ?? '');
		$stem         = VersionState::stemOf(registerSlug: $registerSlug);
		if ($stem === '') {
			return;
		}

		$own = $this->state->ownSchemas(registerSlug: $registerSlug);
		$ids = [];
		foreach ($schemas as $name => $definition) {
			$ids[] = $this->save(existing: ($own[$name] ?? null), definition: $definition + ['slug' => $stem . $name])->getId();
		}

		$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
		$removed  = array_map(static fn (Schema $schema): mixed => $schema->getId(), array_diff_key($own, $schemas));
		$listed   = array_diff((array)($register->getSchemas() ?? []), $removed);
		$register->setSchemas(array_values(array_unique(array_merge($listed, $ids))));
		$this->registerMapper->update($register);
	}//end write()

	/**
	 * Update one schema, or create it when the version does not have it yet.
	 *
	 * @param Schema|null         $existing   The version's schema, or null.
	 * @param array<string,mixed> $definition The merged definition with its slug.
	 *
	 * @return Schema
	 */
	private function save(?Schema $existing, array $definition): Schema {
		if ($existing === null) {
			return $this->schemaMapper->createFromArray($definition);
		}

		unset($definition['slug']);
		foreach ($definition as $field => $value) {
			try {
				$existing->{'set' . ucfirst((string)$field)}($value);
			} catch (BadFunctionCallException $e) {
				continue;
			}
		}

		$updated = $this->schemaMapper->update($existing);
		if ($updated instanceof Schema) {
			return $updated;
		}

		return $existing;
	}//end save()
}//end class
