<?php

/**
 * OpenBuild Data Register Export Bundler
 *
 * Extracted from ExportService so that class stays under PHPMD's
 * cyclomatic-complexity / coupling-between-objects thresholds — a
 * single-purpose collaborator ExportService depends on, exactly like
 * PlaceholderResolver is already its dependency for a different
 * self-contained concern (data-registers-runtime).
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
 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bundles a source Application's bound `dataRegisters` (schema definitions
 * always, row data opt-in per binding) into an exported app tree, at
 * `lib/Settings/data-registers/<register-slug>.{schema,seed-data}.json`
 * (spec openbuild-exporter, ADDED Requirements "Bound data registers'
 * schema definitions are bundled into every export" + "Per-binding
 * includeData toggle controls data-register row-data inclusion").
 *
 * Neither file is merged into the app's own `<app>_register.json`, nor
 * referenced by any `<repair-step>` — they are reference-only
 * documentation of a register this app does not own (design.md Decision
 * 5). A dangling `register` slug that RegisterMapper cannot resolve is
 * skipped silently (no schemas bundled), matching the existing failure
 * mode for a deleted `ApplicationVersion.register`.
 *
 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
 */
class DataRegisterExportBundler {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Resolves a bound data register's slug.
	 * @param SchemaMapper $schemaMapper Resolves a bound data register's schema definitions.
	 * @param ObjectServiceInterface $objectService Reads a bound data register's row data (includeData opt-in).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Bundle every `dataRegisters` binding into `$rootDir`.
	 *
	 * Each entry's shape is `{register: string, includeData?: bool}`, but
	 * this is untrusted data round-tripped through OR (ultimately read back
	 * from an `ExportJob` record by RunExportJob) — typed loosely here
	 * (rather than a PHPStan array-shape) so the defensive `is_array()` /
	 * `??` guards below stay meaningful instead of being flagged as dead
	 * code against an assumed-certain shape.
	 *
	 * @param string $rootDir Scratch directory (exported tree root).
	 * @param array<int,mixed> $dataRegisters Bindings + per-export includeData choice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
	 */
	public function bundle(string $rootDir, array $dataRegisters): void {
		if ($dataRegisters === []) {
			return;
		}

		$targetDir = $rootDir . '/lib/Settings/data-registers';

		foreach ($dataRegisters as $binding) {
			if (is_array($binding) === false) {
				continue;
			}

			$registerSlug = (string)($binding['register'] ?? '');
			if ($registerSlug === '') {
				continue;
			}

			try {
				$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
			} catch (Throwable $e) {
				// Dangling reference — no schemas bundled (Non-Goals precedent).
				$this->logger->info(
					'OpenBuild export: dataRegisters binding "' . $registerSlug . '" did not resolve to a register — '
					. 'no schema definitions bundled: ' . $e->getMessage()
				);
				continue;
			}

			$schemaDefinitions = $this->resolveRegisterSchemaDefinitions(register: $register, registerSlug: $registerSlug);

			if (is_dir($targetDir) === false) {
				mkdir($targetDir, 0o755, true);
			}

			$this->writeSchemaFile(targetDir: $targetDir, registerSlug: $registerSlug, schemaDefinitions: $schemaDefinitions);

			if (((bool)($binding['includeData'] ?? false)) === true) {
				$this->writeSeedDataFile(targetDir: $targetDir, registerSlug: $registerSlug, register: $register);
			}
		}//end foreach
	}//end bundle()

	/**
	 * Resolve an already-loaded data register's schema definitions (JSON
	 * Schema shape only — title/description/type/required/properties),
	 * keyed by schema slug. A schema id that SchemaMapper cannot resolve is
	 * skipped (defensive; every id on `Register::getSchemas()` is expected
	 * to resolve in practice).
	 *
	 * @param Register $register The resolved data register.
	 * @param string $registerSlug Slug of the register (for logging only).
	 *
	 * @return array<string,array<string,mixed>>
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
	 */
	private function resolveRegisterSchemaDefinitions(Register $register, string $registerSlug): array {
		$definitions = [];
		foreach ((array)$register->getSchemas() as $schemaId) {
			try {
				$schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->debug(
					'OpenBuild export: could not resolve schema ' . ((string)$schemaId) . ' in data register "'
					. $registerSlug . '": ' . $e->getMessage()
				);
				continue;
			}

			$schemaSlug = $schema->getSlug();
			if ($schemaSlug === '') {
				continue;
			}

			$definitions[$schemaSlug] = [
				'title' => $schema->getTitle(),
				'description' => $schema->getDescription(),
				'type' => 'object',
				'required' => $schema->getRequired(),
				'properties' => $schema->getProperties(),
			];
		}//end foreach

		return $definitions;
	}//end resolveRegisterSchemaDefinitions()

	/**
	 * Write `<register-slug>.schema.json` — the register's schema
	 * definitions, namespaced away from the app's own `<app>_register.json`.
	 *
	 * @param string $targetDir `lib/Settings/data-registers` in the scratch tree.
	 * @param string $registerSlug Slug of the bound data register.
	 * @param array<string,array<string,mixed>> $schemaDefinitions Schema definitions keyed by schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
	 */
	private function writeSchemaFile(string $targetDir, string $registerSlug, array $schemaDefinitions): void {
		$payload = [
			'_comment' => 'Reference-only schema definitions for the shared data register "' . $registerSlug . '", '
				. 'bound via Application.dataRegisters (data-registers-schema-declaration). This app does not own '
				. 'this register — these definitions are documentation for whoever maintains the exported app next. '
				. 'NOT merged into this app\'s own register, NOT referenced by any <repair-step>, NOT auto-imported.',
			'components' => [
				'schemas' => $schemaDefinitions,
			],
		];

		$this->writeJsonFile(path: $targetDir . '/' . $registerSlug . '.schema.json', payload: $payload);
	}//end writeSchemaFile()

	/**
	 * Write `<register-slug>.seed-data.json` — the register's current row
	 * data, in the same `{ "_comment", "objects": [...] }` shape the head's
	 * own `seed-data.json` fixture uses. Only called when a binding's
	 * `includeData` is true.
	 *
	 * @param string $targetDir `lib/Settings/data-registers` in the scratch tree.
	 * @param string $registerSlug Slug of the bound data register (for logging only).
	 * @param Register $register The already-resolved data register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
	 */
	private function writeSeedDataFile(string $targetDir, string $registerSlug, Register $register): void {
		try {
			$rows = $this->objectService->searchObjects(
				query: ['@self' => ['register' => $register->getId()]],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'OpenBuild export: could not read row data for data register "' . $registerSlug . '": ' . $e->getMessage()
			);
			return;
		}

		if (is_array($rows) === false) {
			$rows = [];
		}

		$objects = [];
		foreach ($rows as $row) {
			$objects[] = $this->normaliseObjectArray(object: $row);
		}

		$payload = [
			'_comment' => 'Reference-only row-data fixture for the shared data register "' . $registerSlug . '" — '
				. 'bundled because this binding\'s includeData was explicitly toggled on at export time. NOT '
				. 'auto-imported by this app\'s install process (no <repair-step> references this file).',
			'objects' => $objects,
		];

		$this->writeJsonFile(path: $targetDir . '/' . $registerSlug . '.seed-data.json', payload: $payload);
	}//end writeSeedDataFile()

	/**
	 * Encode + write a JSON payload, logging (not throwing) on failure.
	 *
	 * @param string $path Absolute file path to write.
	 * @param array<string,mixed> $payload Payload to encode.
	 *
	 * @return void
	 */
	private function writeJsonFile(string $path, array $payload): void {
		$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			$this->logger->warning('OpenBuild export: failed to encode JSON for ' . $path);
			return;
		}

		file_put_contents($path, $encoded . "\n");
	}//end writeJsonFile()

	/**
	 * Coerce an OR result entry to a plain associative array (mirrors
	 * VersionPromotionService::normaliseObjectArray()'s contract).
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseObjectArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseObjectArray()
}//end class
