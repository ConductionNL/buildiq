<?php

/**
 * OpenBuild TemplateRepoSerializer
 *
 * Serialises a seeded `application-template` object into the SAME canonical
 * GitHub app-repo file map AppRepoSerializer produces for an Application +
 * ApplicationVersion, so a published template repo round-trips identically
 * back through AppRepoParser (github-app-sync template publish). A template
 * carries its own inline `manifest` and `companionSchemas` (inline schema
 * blobs OR id-references), so companions are read from the template itself —
 * inline blobs are normalised directly, id/slug references are resolved through
 * SchemaMapper the way AppRepoSerializer resolves an app's schemas. Pure
 * transformation: no network I/O, no OpenRegister writes.
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

use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serialises a seeded application-template into the canonical repo layout.
 *
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */
class TemplateRepoSerializer {
	/**
	 * The layout version stamped on every emitted descriptor.
	 */
	public const FORMAT_VERSION = '1.0';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper $schemaMapper Resolves id/slug-referenced companion schemas.
	 * @param LoggerInterface $logger PSR logger (server-side diagnostics only).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Serialise a seeded template into a `path => contents` repo file map.
	 *
	 * @param array<string,mixed> $template The application-template object.
	 *
	 * @return array<string,string> Ordered map (canonical JSON + README).
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function serialize(array $template): array {
		$manifest = [];
		if (is_array($template['manifest'] ?? null) === true) {
			$manifest = $template['manifest'];
		}

		$files = [];
		$files['openbuild-app.json'] = $this->encode(data: $this->buildDescriptor(template: $template, manifest: $manifest));
		$files['manifest.json'] = $this->encode(data: $manifest);

		$companions = $this->collectCompanionSchemas(template: $template);
		ksort($companions);
		foreach ($companions as $schemaSlug => $blob) {
			$files['schemas/' . $schemaSlug . '.json'] = $this->encode(data: $blob);
		}

		$readme = $this->buildReadme(template: $template);
		if ($readme !== null) {
			$files['README.md'] = $readme;
		}

		return $files;
	}//end serialize()

	/**
	 * Build the `openbuild-app.json` descriptor for a seeded template.
	 *
	 * @param array<string,mixed> $template The application-template object.
	 * @param array<string,mixed> $manifest The template's manifest blob.
	 *
	 * @return array<string,mixed>
	 */
	private function buildDescriptor(array $template, array $manifest): array {
		$slug = (string)($template['slug'] ?? '');

		$descriptor = [
			'formatVersion' => self::FORMAT_VERSION,
			'slug' => $slug,
			'name' => (string)($template['title'] ?? ($template['name'] ?? $slug)),
			'description' => (string)($template['description'] ?? ''),
			'category' => $this->resolveCategory(template: $template, manifest: $manifest),
			'appType' => 'virtual',
			'version' => (string)($template['version'] ?? '0.1.0'),
		];

		// Preserve the template's useCase so the parsed template keeps it (the
		// parser reads descriptor.useCase, falling back to description otherwise).
		$useCase = trim((string)($template['useCase'] ?? ''));
		if ($useCase !== '') {
			$descriptor['useCase'] = $useCase;
		}

		$credentials = $this->deriveCredentials(manifest: $manifest);
		if ($credentials !== []) {
			$descriptor['credentials'] = $credentials;
		}

		return $descriptor;
	}//end buildDescriptor()

	/**
	 * Resolve the descriptor category from the template, then the manifest, else
	 * a neutral default.
	 *
	 * @param array<string,mixed> $template The application-template object.
	 * @param array<string,mixed> $manifest The template's manifest blob.
	 *
	 * @return string
	 */
	private function resolveCategory(array $template, array $manifest): string {
		$category = trim((string)($template['category'] ?? ''));
		if ($category !== '') {
			return $category;
		}

		$category = trim((string)($manifest['category'] ?? ''));
		if ($category !== '') {
			return $category;
		}

		return 'general';
	}//end resolveCategory()

	/**
	 * Derive the descriptor `credentials[]` from the manifest's top-level
	 * `credentials[]` (REQ-GARF-009), mirroring AppRepoSerializer.
	 *
	 * @param array<string,mixed> $manifest The template's manifest blob.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function deriveCredentials(array $manifest): array {
		$raw = ($manifest['credentials'] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$credentials = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false || isset($entry['provider']) === false) {
				continue;
			}

			$scopes = [];
			if (is_array($entry['scopes'] ?? null) === true) {
				$scopes = array_values(array_map(static fn ($scope): string => (string)$scope, $entry['scopes']));
			}

			$credentials[] = [
				'provider' => (string)$entry['provider'],
				'reason' => (string)($entry['reason'] ?? ''),
				'scopes' => $scopes,
			];
		}

		return $credentials;
	}//end deriveCredentials()

	/**
	 * Resolve a template's `companionSchemas` into `slug => blob` pairs.
	 *
	 * @param array<string,mixed> $template The application-template object.
	 *
	 * @return array<string,array<string,mixed>> Schema blobs keyed by slug.
	 */
	private function collectCompanionSchemas(array $template): array {
		$raw = ($template['companionSchemas'] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$schemas = [];
		foreach ($raw as $entry) {
			$blob = $this->companionSchemaBlob(entry: $entry);
			if ($blob === null) {
				continue;
			}

			$schemas[$blob['slug']] = $blob;
		}

		return $schemas;
	}//end collectCompanionSchemas()

	/**
	 * Normalise one companionSchemas entry into a canonical schema blob.
	 *
	 * An inline schema (an object carrying `properties`) is normalised directly;
	 * a bare id/slug reference (a scalar, or an object with `id`/`slug` but no
	 * `properties`) is resolved through SchemaMapper — a reference that cannot be
	 * resolved is skipped (serialise stays total), never fabricated.
	 *
	 * @param mixed $entry One companionSchemas entry (inline object or reference).
	 *
	 * @return array<string,mixed>|null The normalised blob, or null when unusable.
	 */
	private function companionSchemaBlob(mixed $entry): ?array {
		if (is_array($entry) === true && is_array($entry['properties'] ?? null) === true) {
			return $this->normaliseInlineSchema(entry: $entry);
		}

		$reference = $entry;
		if (is_array($entry) === true) {
			$reference = ($entry['id'] ?? ($entry['slug'] ?? null));
		}

		if (is_int($reference) === false && is_string($reference) === false) {
			return null;
		}

		if (is_string($reference) === true && trim($reference) === '') {
			return null;
		}

		return $this->resolveSchemaReference(reference: $reference);
	}//end companionSchemaBlob()

	/**
	 * Normalise an inline template schema blob to the canonical emitted shape.
	 *
	 * @param array<string,mixed> $entry The inline schema object.
	 *
	 * @return array<string,mixed>|null The normalised blob, or null when it has no slug.
	 */
	private function normaliseInlineSchema(array $entry): ?array {
		$slug = trim((string)($entry['slug'] ?? ''));
		if ($slug === '') {
			return null;
		}

		$version = trim((string)($entry['version'] ?? ''));
		if ($version === '') {
			$version = '0.1.0';
		}

		return [
			'slug' => $slug,
			'title' => (string)($entry['title'] ?? $slug),
			'description' => (string)($entry['description'] ?? ''),
			'version' => $version,
			'type' => 'object',
			'required' => array_values((array)($entry['required'] ?? [])),
			'properties' => (array)($entry['properties'] ?? []),
		];
	}//end normaliseInlineSchema()

	/**
	 * Resolve a schema id/slug reference through SchemaMapper into a schema blob.
	 *
	 * @param int|string $reference The schema id or slug.
	 *
	 * @return array<string,mixed>|null The resolved blob, or null when unresolvable.
	 */
	private function resolveSchemaReference(int|string $reference): ?array {
		try {
			$schema = $this->schemaMapper->find($reference, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->debug(
				'OpenBuild TemplateRepoSerializer: could not resolve companion schema "'
				. ((string)$reference) . '": ' . $e->getMessage()
			);
			return null;
		}

		$schemaSlug = $schema->getSlug();
		if ($schemaSlug === '') {
			return null;
		}

		$version = (string)$schema->getVersion();
		if ($version === '') {
			$version = '0.1.0';
		}

		return [
			'slug' => $schemaSlug,
			'title' => (string)$schema->getTitle(),
			'description' => (string)$schema->getDescription(),
			'version' => $version,
			'type' => 'object',
			'required' => array_values((array)$schema->getRequired()),
			'properties' => (array)$schema->getProperties(),
		];
	}//end resolveSchemaReference()

	/**
	 * Build the README.md for a seeded template (title + description + provenance).
	 *
	 * @param array<string,mixed> $template The application-template object.
	 *
	 * @return string|null The README contents, or null when the template has no description.
	 */
	private function buildReadme(array $template): ?string {
		$description = trim((string)($template['description'] ?? ''));
		if ($description === '') {
			return null;
		}

		$name = (string)($template['title'] ?? ($template['slug'] ?? 'OpenBuild app'));

		return '# ' . $name . "\n\n" . $description . "\n\n"
			. '_Built with [OpenBuild](https://conduction.nl) — a citizen-developer app builder for Nextcloud._' . "\n";
	}//end buildReadme()

	/**
	 * Canonicalise + encode a JSON payload (sorted keys, stable indentation,
	 * trailing newline) so re-serialising an unchanged template is byte-stable.
	 *
	 * @param array<string,mixed> $data The payload to encode.
	 *
	 * @return string Canonical JSON with a trailing newline.
	 */
	private function encode(array $data): string {
		$sorted = $this->sortKeysRecursive(value: $data);
		$encoded = json_encode($sorted, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			$this->logger->warning('OpenBuild TemplateRepoSerializer: JSON encode failed for a repo file.');
			return "{}\n";
		}

		return $encoded . "\n";
	}//end encode()

	/**
	 * Recursively sort associative-array keys while preserving list order.
	 *
	 * @param mixed $value The value to canonicalise.
	 *
	 * @return mixed The canonicalised value.
	 */
	private function sortKeysRecursive(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === true) {
			return array_map(fn ($item): mixed => $this->sortKeysRecursive(value: $item), $value);
		}

		ksort($value);
		$result = [];
		foreach ($value as $key => $item) {
			$result[$key] = $this->sortKeysRecursive(value: $item);
		}

		return $result;
	}//end sortKeysRecursive()
}//end class
