<?php

/**
 * Build a template array from an app, the server-side twin of
 * src/services/templateCapture.js.
 *
 * A clone namespaces every companion schema with the new app's slug
 * (`{newSlug}-{slug}`) and rewrites the manifest's `schema` and
 * `relatedSchema` references to match. Capture is the exact inverse: strip
 * the source app's prefix from each companion schema and from the manifest,
 * so capture followed by clone is a clean rename with no stacked prefix.
 * Copying an app (apps-copy-app-and-page) runs capture and clone in one
 * request, which is why this step exists in PHP as well; the cases in
 * AppTemplateCaptureTest are the ones the JS module's tests run.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://buildiq.nl
 *
 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use InvalidArgumentException;

/**
 * De-namespaces an app's schemas and manifest into a template array.
 */
class AppTemplateCapture {

	/**
	 * Capture an app into a template array: companion schemas and manifest,
	 * de-namespaced. Definitions only; no record is ever read or copied.
	 *
	 * @param array<string,mixed> $application The source Application (needs `slug`)
	 * @param array<int,array<string,mixed>> $schemas The app's companion schema blobs
	 * @param array<string,mixed> $manifest The app's current manifest
	 *
	 * @return array{description:string,manifest:mixed,companionSchemas:list<array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When two schemas de-namespace to one slug.
	 *
	 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
	 */
	public function capture(array $application, array $schemas, array $manifest): array {
		$appSlug = (string)($application['slug'] ?? '');
		$map = [];
		$sources = [];
		$companions = [];

		foreach ($schemas as $schema) {
			$sourceSlug = (string)($schema['slug'] ?? '');
			if ($sourceSlug === '') {
				continue;
			}

			$canonical = $this->deNamespaceSlug(schemaSlug: $sourceSlug, appSlug: $appSlug)['slug'];
			if (isset($sources[$canonical]) === true && $sources[$canonical] !== $sourceSlug) {
				throw new InvalidArgumentException('slug-collision: ' . $sources[$canonical] . ', ' . $sourceSlug);
			}

			$sources[$canonical] = $sourceSlug;
			$map[$sourceSlug] = $canonical;
			$schema['slug'] = $canonical;
			$companions[] = $schema;
		}

		return [
			'description' => (string)($application['description'] ?? ''),
			'manifest' => $this->rewriteSchemaRefs(node: $manifest, map: $map),
			'companionSchemas' => $companions,
		];
	}//end capture()

	/**
	 * Strip the app's prefix from a schema slug.
	 *
	 * @param string $schemaSlug The companion schema slug
	 * @param string $appSlug The source app's slug
	 *
	 * @return array{slug:string,shared:bool} `shared` when the slug carried no prefix
	 *
	 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
	 */
	public function deNamespaceSlug(string $schemaSlug, string $appSlug): array {
		$prefix = $appSlug . '-';
		if ($appSlug !== '' && str_starts_with($schemaSlug, $prefix) === true) {
			return ['slug' => substr($schemaSlug, strlen($prefix)), 'shared' => false];
		}

		return ['slug' => $schemaSlug, 'shared' => true];
	}//end deNamespaceSlug()

	/**
	 * Rewrite every `schema` and `relatedSchema` string a map names, at any depth.
	 *
	 * @param mixed $node The manifest node
	 * @param array<string,string> $map Source slug to target slug
	 *
	 * @return mixed The rewritten node (a copy; PHP arrays are values)
	 *
	 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
	 */
	public function rewriteSchemaRefs(mixed $node, array $map): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		foreach ($node as $key => $value) {
			if (($key === 'schema' || $key === 'relatedSchema')
				&& is_string($value) === true
				&& array_key_exists($value, $map) === true
			) {
				$node[$key] = $map[$value];
				continue;
			}

			$node[$key] = $this->rewriteSchemaRefs(node: $value, map: $map);
		}

		return $node;
	}//end rewriteSchemaRefs()
}//end class
