<?php

/**
 * Handler for the openbuilt.upsertSchema MCP tool.
 *
 * Creates or updates a JSON Schema in the given app version's per-version OR
 * register. The slug is automatically namespaced with appSlug+versionSlug.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Mcp\Handler
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Mcp\Handler;

/**
 * Handles the openbuilt.upsertSchema tool invocation.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuilt/tasks.md#task-8
 */
class UpsertSchemaHandler extends AbstractToolHandler
{
    /**
     * Execute the upsertSchema tool.
     *
     * @param array<string, mixed> $args Tool arguments (appSlug, versionSlug, slug, title, description, properties, required).
     *
     * @return array<string, mixed>
     */
    public function handle(array $args): array
    {
        $validation = $this->validateArgs(args: $args);
        if (isset($validation['error']) === true) {
            return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
        }

        if ($this->requireAuthenticatedUser() === null) {
            return $this->errorResult(error: 'forbidden', message: 'You must be signed in to author schemas.');
        }

        $appSlug        = $validation['appSlug'];
        $versionSlug    = $validation['versionSlug'];
        $rawSlug        = $validation['rawSlug'];
        $title          = $validation['title'];
        $description    = $validation['description'];
        $properties     = $validation['properties'];
        $required       = $validation['required'];
        $namespacedSlug = $appSlug.'-'.$versionSlug.'-'.$rawSlug;
        $registerSlug   = 'openbuilt-'.$appSlug.'-'.$versionSlug;

        try {
            $schemaMapper   = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
            $registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

            $blob = [
                'slug'        => $namespacedSlug,
                'title'       => $title,
                'description' => $description,
                'type'        => 'object',
                'required'    => array_values(array_filter((array) $required, 'is_string')),
                'properties'  => (array) $properties,
            ];

            $existing = $this->findExistingSchema(schemaMapper: $schemaMapper, namespacedSlug: $namespacedSlug);

            if ($existing !== null) {
                $schema = $schemaMapper->updateFromArray($existing->getId(), $blob);
                return [
                    'success' => true,
                    'action'  => 'updated',
                    'schema'  => [
                        'id'        => $schema->getId(),
                        'slug'      => $namespacedSlug,
                        'shortSlug' => $rawSlug,
                        'title'     => $title,
                        'register'  => $registerSlug,
                    ],
                ];
            }

            $schema = $schemaMapper->createFromArray($blob);
            $this->attachSchemaToRegister(
                registerMapper: $registerMapper,
                registerSlug: $registerSlug,
                schemaId: $schema->getId()
            );

            return [
                'success' => true,
                'action'  => 'created',
                'schema'  => [
                    'id'        => $schema->getId(),
                    'slug'      => $namespacedSlug,
                    'shortSlug' => $rawSlug,
                    'title'     => $title,
                    'register'  => $registerSlug,
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'OpenBuilt MCP: upsertSchema failed',
                ['appSlug' => $appSlug, 'slug' => $rawSlug, 'exception' => $e->getMessage()]
            );
            return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert schema: '.$e->getMessage());
        }//end try

    }//end handle()

    /**
     * Validate and extract typed arguments for upsertSchema.
     *
     * Delegates slug/title checks to validateSlugsAndTitle() and
     * properties/required normalisation to normaliseSchemaBody() so each
     * helper stays well within the cyclomatic-complexity threshold.
     *
     * @param array<string, mixed> $args Raw tool arguments.
     *
     * @return array<string, mixed>
     */
    private function validateArgs(array $args): array
    {
        $appSlug     = (string) ($args['appSlug'] ?? '');
        $versionSlug = (string) ($args['versionSlug'] ?? 'development');
        $rawSlug     = (string) ($args['slug'] ?? '');
        $title       = (string) ($args['title'] ?? '');
        $description = (string) ($args['description'] ?? '');

        $slugError = $this->validateSlugsAndTitle(appSlug: $appSlug, versionSlug: $versionSlug, rawSlug: $rawSlug, title: $title);
        if ($slugError !== null) {
            return ['error' => $slugError];
        }

        $bodyResult = $this->normaliseSchemaBody(properties: $args['properties'] ?? [], required: $args['required'] ?? []);
        if (isset($bodyResult['error']) === true) {
            return $bodyResult;
        }

        return [
            'appSlug'     => $appSlug,
            'versionSlug' => $versionSlug,
            'rawSlug'     => $rawSlug,
            'title'       => $title,
            'description' => $description,
            'properties'  => $bodyResult['properties'],
            'required'    => $bodyResult['required'],
        ];

    }//end validateArgs()

    /**
     * Validate the slug and title fields, returning an error string or null on success.
     *
     * @param string $appSlug     Application slug.
     * @param string $versionSlug Version slug.
     * @param string $rawSlug     Schema slug (before namespacing).
     * @param string $title       Schema title.
     *
     * @return string|null
     */
    private function validateSlugsAndTitle(string $appSlug, string $versionSlug, string $rawSlug, string $title): ?string
    {
        if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
            return "Invalid appSlug '{$appSlug}'.";
        }

        if ($this->isValidSlug(candidate: $versionSlug) === false) {
            return "Invalid versionSlug '{$versionSlug}'.";
        }

        if ($rawSlug === '' || $this->isValidSlug(candidate: $rawSlug) === false) {
            return "Invalid schema slug '{$rawSlug}'.";
        }

        if ($title === '') {
            return 'title is required.';
        }

        return null;

    }//end validateSlugsAndTitle()

    /**
     * Validate and normalise the properties + required fields of the schema body.
     *
     * @param mixed $properties Raw properties value from tool arguments.
     * @param mixed $required   Raw required value from tool arguments.
     *
     * @return array{properties?: array, required?: array, error?: string}
     */
    private function normaliseSchemaBody(mixed $properties, mixed $required): array
    {
        if (is_array($properties) === false || $properties === []) {
            return ['error' => 'properties must be a non-empty object of JSON-Schema property definitions.'];
        }

        if (is_array($required) === false) {
            $required = [];
        }

        return ['properties' => $properties, 'required' => $required];

    }//end normaliseSchemaBody()

    /**
     * Find an existing schema by its namespaced slug, or return null.
     *
     * @param object $schemaMapper   OR SchemaMapper instance.
     * @param string $namespacedSlug Full namespaced slug to look up.
     *
     * @return object|null
     */
    private function findExistingSchema(object $schemaMapper, string $namespacedSlug): ?object
    {
        try {
            $matches = $schemaMapper->findBySlug($namespacedSlug);
            if (is_array($matches) === true && $matches !== []) {
                return $matches[0];
            }
        } catch (\Throwable $_e) {
            // Not found — treat as absent.
        }

        return null;

    }//end findExistingSchema()

    /**
     * Attach a newly created schema to its per-version register.
     *
     * Non-fatal: logs a warning but does not re-throw if the register is missing.
     *
     * @param object $registerMapper OR RegisterMapper instance.
     * @param string $registerSlug   Slug of the per-version register to attach to.
     * @param int    $schemaId       ID of the freshly created schema.
     *
     * @return void
     */
    private function attachSchemaToRegister(object $registerMapper, string $registerSlug, int $schemaId): void
    {
        try {
            $register = $registerMapper->find($registerSlug, _multitenancy: false);
            $current  = $register->getSchemas();
            if (is_array($current) === false) {
                $current = [];
            }

            $register->setSchemas(array_values(array_unique(array_merge($current, [$schemaId]))));
            $registerMapper->update($register);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'OpenBuilt MCP: upsertSchema attach-to-register failed',
                ['register' => $registerSlug, 'exception' => $e->getMessage()]
            );
        }

    }//end attachSchemaToRegister()
}//end class
