<?php

/**
 * OpenBuild AppRepoSerializer
 *
 * Turns a local Application object + a chosen ApplicationVersion object into the
 * ordered set of GitHub app-repo files (github-app-repo-format REQ-GARF-006): the
 * `openbuild-app.json` descriptor, `manifest.json` (the version's manifest blob
 * verbatim), one `schemas/<slug>.json` per companion schema of the app's per-app
 * register, and an optional `README.md`. Pure transformation — it reads the
 * companion schemas from the app's per-app OpenRegister register but performs NO
 * network I/O and NO OpenRegister writes.
 *
 * Every emitted JSON file is canonicalised (recursively sorted keys, stable
 * indentation, trailing newline) and files are emitted in a deterministic order
 * (descriptor, manifest, `schemas/*` sorted by slug, then README) so that
 * re-serialising an unchanged app yields a byte-identical file set — stable diffs
 * for the blob-by-blob tree push in github-app-sync.
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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serialises an Application + ApplicationVersion into the canonical repo layout.
 *
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */
class AppRepoSerializer
{
    /**
     * The layout version stamped on every emitted descriptor.
     */
    public const FORMAT_VERSION = '1.0';

    /**
     * Constructor.
     *
     * @param RegisterMapper         $registerMapper     Resolves the app's per-app register by slug.
     * @param SchemaMapper           $schemaMapper       Resolves companion schema definitions by id.
     * @param LoggerInterface        $logger             PSR logger (server-side diagnostics only).
     * @param TemplateRepoSerializer $templateSerializer Serialises a seeded template into the same repo layout.
     *
     * @return void
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
        private readonly TemplateRepoSerializer $templateSerializer,
    ) {
    }//end __construct()

    /**
     * Serialise an Application + chosen ApplicationVersion into a repo file map.
     *
     * @param array<string,mixed> $application The Application object (jsonSerialize shape).
     * @param array<string,mixed> $version     The chosen ApplicationVersion object.
     *
     * @return array<string,string> Ordered `path => contents` map (canonical JSON + README).
     *
     * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
     */
    public function serialize(array $application, array $version): array
    {
        $slug     = (string) ($application['slug'] ?? '');
        $manifest = [];
        if (is_array($version['manifest'] ?? null) === true) {
            $manifest = $version['manifest'];
        }

        $files = [];
        $files['openbuild-app.json'] = $this->encode(
            data: $this->buildDescriptor(application: $application, version: $version, manifest: $manifest)
        );
        $files['manifest.json']      = $this->encode(data: $manifest);

        $companions = $this->collectCompanionSchemas(slug: $slug);
        ksort($companions);
        foreach ($companions as $schemaSlug => $blob) {
            $files['schemas/'.$schemaSlug.'.json'] = $this->encode(data: $blob);
        }

        $readme = $this->buildReadme(application: $application);
        if ($readme !== null) {
            $files['README.md'] = $readme;
        }

        return $files;
    }//end serialize()

    /**
     * Serialise a seeded `application-template` object into the same repo file
     * map `serialize()` produces, so a published template repo round-trips back
     * through AppRepoParser identically to a published Application version.
     *
     * Delegated to TemplateRepoSerializer (a template carries its own inline
     * `manifest` and `companionSchemas`, so its serialisation is a distinct
     * concern from the Application + per-app-register path), keeping the public
     * seam on this service where callers (GitHubAppSyncService::publishTemplate)
     * already resolve the serializer.
     *
     * @param array<string,mixed> $template The seeded application-template object
     *                                      (jsonSerialize shape): slug/title/
     *                                      description/useCase/category/version/
     *                                      manifest/companionSchemas.
     *
     * @return array<string,string> Ordered `path => contents` map (canonical JSON + README).
     *
     * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
     */
    public function serializeTemplate(array $template): array
    {
        return $this->templateSerializer->serialize(template: $template);
    }//end serializeTemplate()

    /**
     * Build the `openbuild-app.json` descriptor (REQ-GARF-002, REQ-GARF-009).
     *
     * @param array<string,mixed> $application The Application object.
     * @param array<string,mixed> $version     The chosen ApplicationVersion object.
     * @param array<string,mixed> $manifest    The version's manifest blob.
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
     */
    private function buildDescriptor(array $application, array $version, array $manifest): array
    {
        $appType = (string) ($application['appType'] ?? 'virtual');

        $descriptor = [
            'formatVersion' => self::FORMAT_VERSION,
            'slug'          => (string) ($application['slug'] ?? ''),
            'name'          => (string) ($application['name'] ?? ($application['slug'] ?? '')),
            'description'   => (string) ($application['description'] ?? ''),
            'category'      => $this->resolveCategory(application: $application, manifest: $manifest),
            'appType'       => $appType,
            'version'       => (string) ($version['semver'] ?? '0.1.0'),
        ];

        $iconRef = $this->iconRef(icon: ($application['icon'] ?? null), fallback: 'img/icon.svg');
        if ($iconRef !== null) {
            $descriptor['icon'] = ['ref' => $iconRef];
        }

        $iconDarkRef = $this->iconRef(icon: ($application['iconDark'] ?? null), fallback: 'img/icon-dark.svg');
        if ($iconDarkRef !== null) {
            $descriptor['iconDark'] = ['ref' => $iconDarkRef];
        }

        if ($appType === 'hybrid' && is_array($application['baseRef'] ?? null) === true) {
            $descriptor['baseRef'] = $application['baseRef'];
        }

        $credentials = $this->deriveCredentials(manifest: $manifest);
        if ($credentials !== []) {
            $descriptor['credentials'] = $credentials;
        }

        return $descriptor;
    }//end buildDescriptor()

    /**
     * Derive the descriptor `credentials[]` from the manifest's top-level
     * `credentials[]` (REQ-GARF-009) — a surfaced convenience mirror; the manifest
     * stays authoritative on parse.
     *
     * @param array<string,mixed> $manifest The version's manifest blob.
     *
     * @return array<int,array<string,mixed>>
     */
    private function deriveCredentials(array $manifest): array
    {
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
                $scopes = array_values(array_map(static fn ($scope): string => (string) $scope, $entry['scopes']));
            }

            $credentials[] = [
                'provider' => (string) $entry['provider'],
                'reason'   => (string) ($entry['reason'] ?? ''),
                'scopes'   => $scopes,
            ];
        }

        return $credentials;
    }//end deriveCredentials()

    /**
     * Read the app's per-app register companion schemas, keyed by schema slug.
     *
     * Mirrors DataRegisterExportBundler's schema resolution; a register that is
     * absent (an app never provisioned a per-app register) yields no companions
     * rather than an error — serialise is total.
     *
     * @param string $slug The Application slug (per-app register is `openbuild-{slug}`).
     *
     * @return array<string,array<string,mixed>> Schema blobs keyed by slug.
     *
     * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
     */
    private function collectCompanionSchemas(string $slug): array
    {
        if ($slug === '') {
            return [];
        }

        try {
            $register = $this->registerMapper->find('openbuild-'.$slug, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuild AppRepoSerializer: no per-app register for "'.$slug.'": '.$e->getMessage()
            );
            return [];
        }

        $schemas = [];
        foreach ((array) $register->getSchemas() as $schemaId) {
            try {
                $schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
            } catch (Throwable $e) {
                $this->logger->debug(
                    'OpenBuild AppRepoSerializer: could not resolve schema '.((string) $schemaId).': '.$e->getMessage()
                );
                continue;
            }

            $schemaSlug = $schema->getSlug();
            if ($schemaSlug === '') {
                continue;
            }

            $version = (string) $schema->getVersion();
            if ($version === '') {
                $version = '0.1.0';
            }

            $schemas[$schemaSlug] = [
                'slug'        => $schemaSlug,
                'title'       => (string) $schema->getTitle(),
                'description' => (string) $schema->getDescription(),
                'version'     => $version,
                'type'        => 'object',
                'required'    => array_values((array) $schema->getRequired()),
                'properties'  => (array) $schema->getProperties(),
            ];
        }//end foreach

        return $schemas;
    }//end collectCompanionSchemas()

    /**
     * Build the optional README.md (name + description + provenance line).
     *
     * @param array<string,mixed> $application The Application object.
     *
     * @return string|null The README contents, or null when the app has no description.
     */
    private function buildReadme(array $application): ?string
    {
        $description = trim((string) ($application['description'] ?? ''));
        if ($description === '') {
            return null;
        }

        $name = (string) ($application['name'] ?? ($application['slug'] ?? 'OpenBuild app'));

        return '# '.$name."\n\n".$description."\n\n"
            .'_Built with [OpenBuild](https://conduction.nl) — a citizen-developer app builder for Nextcloud._'."\n";
    }//end buildReadme()

    /**
     * Resolve the descriptor category from the app, then the manifest, else a
     * neutral default.
     *
     * @param array<string,mixed> $application The Application object.
     * @param array<string,mixed> $manifest    The version's manifest blob.
     *
     * @return string
     */
    private function resolveCategory(array $application, array $manifest): string
    {
        $category = trim((string) ($application['category'] ?? ''));
        if ($category !== '') {
            return $category;
        }

        $category = trim((string) ($manifest['category'] ?? ''));
        if ($category !== '') {
            return $category;
        }

        return 'general';
    }//end resolveCategory()

    /**
     * Resolve an icon reference to an `img/`-scoped path for the descriptor.
     *
     * @param mixed  $icon     The Application `icon`/`iconDark` block (`{ ref }`) or null.
     * @param string $fallback The canonical `img/` path to use when only a bare filename is stored.
     *
     * @return string|null The `img/`-scoped ref, or null when no icon is set.
     */
    private function iconRef(mixed $icon, string $fallback): ?string
    {
        if (is_array($icon) === false) {
            return null;
        }

        $ref = trim((string) ($icon['ref'] ?? ''));
        if ($ref === '') {
            return null;
        }

        if (str_starts_with($ref, 'img/') === true) {
            return $ref;
        }

        if (str_contains($ref, '/') === false) {
            return 'img/'.$ref;
        }

        return $fallback;
    }//end iconRef()

    /**
     * Canonicalise + encode a JSON payload (sorted keys, stable indentation,
     * trailing newline) so re-serialising an unchanged app is byte-stable.
     *
     * @param array<string,mixed> $data The payload to encode.
     *
     * @return string Canonical JSON with a trailing newline.
     */
    private function encode(array $data): string
    {
        $sorted  = $this->sortKeysRecursive(value: $data);
        $encoded = json_encode($sorted, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($encoded === false) {
            // A payload that cannot encode is a programming error, not user input;
            // emit an empty object so the file map stays well-formed and diffable.
            $this->logger->warning('OpenBuild AppRepoSerializer: JSON encode failed for a repo file.');
            return "{}\n";
        }

        return $encoded."\n";
    }//end encode()

    /**
     * Recursively sort associative-array keys while preserving list order
     * (lists are ordered runtime data and MUST NOT be reordered).
     *
     * @param mixed $value The value to canonicalise.
     *
     * @return mixed The canonicalised value.
     */
    private function sortKeysRecursive(mixed $value): mixed
    {
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
