<?php

/**
 * OpenBuilt ApplicationCreationService
 *
 * Owns the atomic creation flow for the `POST /api/applications/wizard`
 * endpoint (spec `openbuilt-app-creation-wizard`, REQ-OBWIZ-007 through
 * REQ-OBWIZ-010).
 *
 * Flow per Decision 7 of the design:
 *   1. Validate the whole payload (slugs, chain, app-slug uniqueness).
 *   2. Create the Application record (caller becomes sole owner).
 *   3. For each version in chain order:
 *      a. Create the ApplicationVersion record.
 *      b. Provision the per-version OR register.
 *   4. Wire the `promotesTo` chain on non-terminal versions.
 *   5. Set Application.productionVersion to the terminal version.
 *
 * On any failure at any step: roll back in reverse creation order —
 * registers first, then ApplicationVersion rows, then Application row.
 * Rollback is best-effort; failures during rollback are logged and
 * accumulated in the WizardCreationException's orphanedResources list.
 *
 * All persistence flows through OpenRegister abstractions (ADR-022).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Service
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

namespace OCA\OpenBuilt\Service;

use OCA\OpenBuilt\Exception\WizardCreationException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Atomic creation orchestrator for the app-creation wizard.
 *
 * This is an ADR-031 §Exceptions(3) imperative surface — the OR layer has
 * no transaction that spans Application, N ApplicationVersion rows, and
 * N register provisions simultaneously, so we implement a careful-sequencing
 * + reverse-delete rollback strategy.
 */
class ApplicationCreationService
{
    /**
     * Four canonical presets and their hardcoded version chains.
     * Each entry is [name => string, slug => string] in chain order
     * (upstream → downstream).
     *
     * @var array<string,array<int,array<string,string>>>
     */
    private const PRESET_CHAINS = [
        'single'           => [
            ['name' => 'Production', 'slug' => 'production'],
        ],
        'dev-prod'         => [
            ['name' => 'Development', 'slug' => 'development'],
            ['name' => 'Production',  'slug' => 'production'],
        ],
        'dev-staging-prod' => [
            ['name' => 'Development', 'slug' => 'development'],
            ['name' => 'Staging',     'slug' => 'staging'],
            ['name' => 'Production',  'slug' => 'production'],
        ],
    ];

    /**
     * Default semver for every wizard-provisioned ApplicationVersion.
     */
    private const INITIAL_SEMVER = '0.1.0';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger          PSR logger for diagnostics
     * @param ObjectService   $objectService   OpenRegister object service
     * @param RegisterService $registerService OpenRegister register-level service
     * @param RegisterMapper  $registerMapper  Resolves register slugs
     * @param SchemaMapper    $schemaMapper    Resolves schema slugs
     * @param IUserSession    $userSession     Current Nextcloud user session
     * @param SlugValidator   $slugValidator   Slug validation helper
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterService $registerService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IUserSession $userSession,
        private readonly SlugValidator $slugValidator,
    ) {
    }//end __construct()

    /**
     * Execute the full atomic creation flow for the wizard payload.
     *
     * @param array<string,mixed> $payload The wizard POST payload (validated internally)
     *
     * @return string The newly-created Application's UUID
     *
     * @throws WizardCreationException On validation failure (failedAtStep=validate)
     *                                 or on any mid-flight creation failure (with rollback)
     */
    public function createApplication(array $payload): string
    {
        // ---- Step 1: Validate -----------------------------------------------
        $this->validatePayload(payload: $payload);

        $appSlug     = (string) ($payload['slug'] ?? '');
        $appName     = (string) ($payload['name'] ?? '');
        $description = (string) ($payload['description'] ?? '');
        $preset      = (string) ($payload['preset'] ?? '');
        $versions    = $this->resolveVersionChain(payload: $payload);

        // ---- State tracker for rollback -------------------------------------
        // Indexed by version slug.
        $state = [
            'applicationUuid' => null,
            'versionUuids'    => [],
            'registerSlugs'   => [],
        ];

        // ---- Step 2: Create Application -------------------------------------
        $caller      = $this->resolveCallerUid();
        $permissions = [
            'owners'  => ['user:'.$caller],
            'editors' => [],
            'viewers' => [],
        ];

        $applicationPayload = [
            'slug'        => $appSlug,
            'name'        => $appName,
            'description' => $description,
            'permissions' => $permissions,
        ];

        try {
            $created = $this->objectService->saveObject(
                object: $applicationPayload,
                register: ApplicationVersionService::REGISTER_SLUG,
                schema: ApplicationVersionService::APPLICATION_SCHEMA
            );
            $appData = $this->normaliseObject(object: $created);
            $state['applicationUuid'] = (string) ($appData['id'] ?? $appData['uuid'] ?? '');
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: wizard create-application failed for slug '.$appSlug.': '.$e->getMessage(),
                ['exception' => $e]
            );
            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'create-application',
                message: $e->getMessage(),
                rollbackStatus: 'complete',
                previous: $e
            );
        }//end try

        if ($state['applicationUuid'] === '') {
            $orphaned = [];
            $this->rollback(state: $state, orphaned: $orphaned);
            if ($orphaned !== []) {
                $status = 'partial';
            } else {
                $status = 'complete';
            }

            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'create-application',
                message: 'Application record was not assigned a UUID by OR.',
                rollbackStatus: $status,
                orphanedResources: $orphaned
            );
        }

        // ---- Step 3: Create ApplicationVersions + provision registers -------
        $defaultManifest = $this->loadDefaultManifest();
        $defaultSchemas  = $this->loadDefaultSchemas();

        foreach ($versions as $versionDef) {
            $versionSlug  = (string) ($versionDef['slug'] ?? '');
            $versionName  = (string) ($versionDef['name'] ?? '');
            $registerSlug = 'openbuilt-'.$appSlug.'-'.$versionSlug;

            // 3a: Create ApplicationVersion
            $versionManifest = $this->substituteRegisterSlug(
                manifest: $defaultManifest,
                registerSlug: $registerSlug
            );

            $versionPayload = [
                'name'        => $versionName,
                'slug'        => $versionSlug,
                'manifest'    => $versionManifest,
                'register'    => $registerSlug,
                'semver'      => self::INITIAL_SEMVER,
                'status'      => 'draft',
                'application' => $state['applicationUuid'],
            ];

            try {
                $createdVersion = $this->objectService->saveObject(
                    object: $versionPayload,
                    register: ApplicationVersionService::REGISTER_SLUG,
                    schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
                );
                $versionData    = $this->normaliseObject(object: $createdVersion);
                $versionUuid    = (string) ($versionData['id'] ?? $versionData['uuid'] ?? '');
                $state['versionUuids'][$versionSlug]  = $versionUuid;
                $state['registerSlugs'][$versionSlug] = $registerSlug;
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: wizard create-version failed for '.$versionSlug.': '.$e->getMessage(),
                    ['exception' => $e]
                );
                $orphaned = [];
                $this->rollback(state: $state, orphaned: $orphaned);
                if ($orphaned === []) {
                    $status = 'complete';
                } else {
                    $status = 'partial';
                }

                throw new WizardCreationException(
                    errorCode: 'wizard_rollback',
                    failedAtStep: 'create-version-'.$versionSlug,
                    message: $e->getMessage(),
                    rollbackStatus: $status,
                    orphanedResources: $orphaned,
                    previous: $e
                );
            }//end try

            // 3b: Provision per-version register
            try {
                $this->provisionRegister(
                    registerSlug: $registerSlug,
                    appSlug: $appSlug,
                    versionSlug: $versionSlug,
                    defaultSchemas: $defaultSchemas
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: wizard register-provision failed for '.$registerSlug.': '.$e->getMessage(),
                    ['exception' => $e]
                );
                $orphaned = [];
                $this->rollback(state: $state, orphaned: $orphaned);
                if ($orphaned === []) {
                    $status = 'complete';
                } else {
                    $status = 'partial';
                }

                throw new WizardCreationException(
                    errorCode: 'wizard_rollback',
                    failedAtStep: 'register-provision-'.$versionSlug,
                    message: $e->getMessage(),
                    rollbackStatus: $status,
                    orphanedResources: $orphaned,
                    previous: $e
                );
            }//end try
        }//end foreach

        // ---- Step 4: Wire promotesTo chain ----------------------------------
        $versionSlugs = array_column($versions, 'slug');
        $lastIdx      = count($versionSlugs) - 1;

        for ($i = 0; $i < $lastIdx; $i++) {
            $currentSlug = $versionSlugs[$i];
            $nextSlug    = $versionSlugs[$i + 1];

            $currentUuid = (string) ($state['versionUuids'][$currentSlug] ?? '');
            $nextUuid    = (string) ($state['versionUuids'][$nextSlug] ?? '');

            if ($currentUuid === '' || $nextUuid === '') {
                continue;
            }

            try {
                $this->objectService->saveObject(
                    object: ['promotesTo' => $nextUuid],
                    register: ApplicationVersionService::REGISTER_SLUG,
                    schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
                    uuid: $currentUuid
                );
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: wizard chain-wiring failed for '.$currentSlug.' → '.$nextSlug.': '.$e->getMessage(),
                    ['exception' => $e]
                );
                $orphaned = [];
                $this->rollback(state: $state, orphaned: $orphaned);
                if ($orphaned === []) {
                    $status = 'complete';
                } else {
                    $status = 'partial';
                }

                throw new WizardCreationException(
                    errorCode: 'wizard_rollback',
                    failedAtStep: 'wire-chain-'.$currentSlug.'-to-'.$nextSlug,
                    message: $e->getMessage(),
                    rollbackStatus: $status,
                    orphanedResources: $orphaned,
                    previous: $e
                );
            }//end try
        }//end for

        // ---- Step 5: Set productionVersion on Application -------------------
        $terminalSlug = $versionSlugs[$lastIdx];
        $terminalUuid = (string) ($state['versionUuids'][$terminalSlug] ?? '');

        try {
            $this->objectService->saveObject(
                object: ['productionVersion' => $terminalUuid],
                register: ApplicationVersionService::REGISTER_SLUG,
                schema: ApplicationVersionService::APPLICATION_SCHEMA,
                uuid: $state['applicationUuid']
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: wizard set-productionVersion failed: '.$e->getMessage(),
                ['exception' => $e]
            );
            $orphaned = [];
            $this->rollback(state: $state, orphaned: $orphaned);
            if ($orphaned === []) {
                $status = 'complete';
            } else {
                $status = 'partial';
            }

            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'set-production-version',
                message: $e->getMessage(),
                rollbackStatus: $status,
                orphanedResources: $orphaned,
                previous: $e
            );
        }//end try

        $versionCount = count($versions);
        $this->logger->info(
            'OpenBuilt: wizard successfully created Application '.$appSlug
            .' (uuid: '.$state['applicationUuid'].') with '.$versionCount.' version(s).'
        );

        return $state['applicationUuid'];
    }//end createApplication()

    /**
     * Validate the wizard payload before any persistence.
     *
     * @param array<string,mixed> $payload The wizard POST payload
     *
     * @return void
     *
     * @throws WizardCreationException With failedAtStep=validate on any failure
     */
    private function validatePayload(array $payload): void
    {
        $appSlug = (string) ($payload['slug'] ?? '');
        $appName = (string) ($payload['name'] ?? '');

        if ($appName === '') {
            throw new WizardCreationException(
                errorCode: 'validation_error',
                failedAtStep: 'validate',
                message: 'Application name must not be empty.',
                rollbackStatus: 'none'
            );
        }

        $slugError = $this->slugValidator->validateAppSlug(slug: $appSlug);
        if ($slugError !== []) {
            throw new WizardCreationException(
                errorCode: 'validation_error',
                failedAtStep: 'validate',
                message: (string) ($slugError['message'] ?? 'Invalid application slug.'),
                rollbackStatus: 'none'
            );
        }

        // Validate preset or custom versions.
        $preset   = (string) ($payload['preset'] ?? '');
        $versions = $this->resolveVersionChain(payload: $payload);

        if ($versions === []) {
            throw new WizardCreationException(
                errorCode: 'validation_error',
                failedAtStep: 'validate',
                message: 'At least one version is required.',
                rollbackStatus: 'none'
            );
        }

        // Validate each version slug.
        foreach ($versions as $versionDef) {
            $versionSlug = (string) ($versionDef['slug'] ?? '');
            $versionName = (string) ($versionDef['name'] ?? '');

            if ($versionName === '') {
                throw new WizardCreationException(
                    errorCode: 'validation_error',
                    failedAtStep: 'validate',
                    message: 'Version name must not be empty.',
                    rollbackStatus: 'none'
                );
            }

            $slugError = $this->slugValidator->validateVersionSlug(slug: $versionSlug);
            if ($slugError !== []) {
                throw new WizardCreationException(
                    errorCode: (string) ($slugError['code'] ?? 'validation_error'),
                    failedAtStep: 'validate',
                    message: (string) ($slugError['message'] ?? 'Invalid version slug.'),
                    rollbackStatus: 'none'
                );
            }
        }//end foreach

        // Validate no duplicate version slugs in chain.
        $slugList   = array_column($versions, 'slug');
        $chainError = $this->slugValidator->validateChainSlugs(slugs: $slugList);
        if ($chainError !== []) {
            throw new WizardCreationException(
                errorCode: 'duplicate_version_slug',
                failedAtStep: 'validate',
                message: sprintf(
                    'Duplicate version slug "%s" at rows [%s].',
                    $chainError['slug'] ?? '',
                    implode(', ', (array) ($chainError['rows'] ?? []))
                ),
                rollbackStatus: 'none'
            );
        }

        // Check app slug uniqueness across existing Applications.
        if ($this->appSlugExists(slug: $appSlug) === true) {
            throw new WizardCreationException(
                errorCode: 'app_slug_conflict',
                failedAtStep: 'validate',
                message: sprintf('An Application with slug "%s" already exists.', $appSlug),
                rollbackStatus: 'none'
            );
        }
    }//end validatePayload()

    /**
     * Resolve the version chain from the payload.
     *
     * For canned presets, returns the hardcoded chain; for `custom` returns
     * the versions array from the payload.
     *
     * @param array<string,mixed> $payload The wizard POST payload
     *
     * @return array<int,array<string,string>> Version definitions [{name, slug}, ...]
     */
    public function resolveVersionChain(array $payload): array
    {
        $preset = (string) ($payload['preset'] ?? '');

        if (isset(self::PRESET_CHAINS[$preset]) === true) {
            return self::PRESET_CHAINS[$preset];
        }

        // Custom preset — use the versions array.
        $versions = $payload['versions'] ?? [];
        if (is_array($versions) === false) {
            return [];
        }

        $result = [];
        foreach ($versions as $v) {
            if (is_array($v) === false) {
                continue;
            }

            $result[] = [
                'name' => (string) ($v['name'] ?? ''),
                'slug' => (string) ($v['slug'] ?? ''),
            ];
        }

        return $result;
    }//end resolveVersionChain()

    /**
     * Check whether an Application with the given slug already exists.
     *
     * @param string $slug The slug to check
     *
     * @return bool True when a conflicting row exists
     */
    private function appSlugExists(string $slug): bool
    {
        try {
            $registerId = $this->registerMapper->find(
                ApplicationVersionService::REGISTER_SLUG,
                _multitenancy: false
            )->getId();
            $schemaId   = $this->schemaMapper->find(
                ApplicationVersionService::APPLICATION_SCHEMA,
                _multitenancy: false
            )->getId();

            $rows = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $schemaId,
                    ],
                    'slug'  => $slug,
                ]
            );

            return is_array($rows) === true && $rows !== [];
        } catch (Throwable $e) {
            // If we cannot query, assume no conflict — OR will reject on create if there is one.
            $this->logger->debug(
                'OpenBuilt: appSlugExists check failed for slug '.$slug.': '.$e->getMessage()
            );
            return false;
        }//end try
    }//end appSlugExists()

    /**
     * Provision a per-version OR register and seed its schema set.
     *
     * @param string                         $registerSlug   The register slug to create
     * @param string                         $appSlug        Parent application slug (for labels)
     * @param string                         $versionSlug    Version slug (for labels)
     * @param array<int,array<string,mixed>> $defaultSchemas Seed schema blobs from default-schemas.json
     *
     * @return void
     *
     * @throws Throwable When register creation or schema seeding fails
     */
    private function provisionRegister(
        string $registerSlug,
        string $appSlug,
        string $versionSlug,
        array $defaultSchemas,
    ): void {
        $register = $this->registerMapper->createFromArray(
            [
                'slug'        => $registerSlug,
                'title'       => 'OpenBuilt — '.$appSlug.' ('.$versionSlug.')',
                'description' => 'Per-version schema namespace for OpenBuilt app `'.$appSlug.'` version `'.$versionSlug.'`.',
                'version'     => '0.1.0',
                'schemas'     => [],
            ]
        );

        // Seed the default schema set into the freshly-provisioned register.
        // Schema slugs are unique per organisation, so namespace each seed slug
        // with the app+version prefix to avoid colliding with the same seed
        // already installed in another register (e.g. the global `openbuilt`
        // register or another wizard-provisioned app's register).
        $slugPrefix = $appSlug.'-'.$versionSlug.'-';
        $createdIds = [];
        foreach ($defaultSchemas as $schemaBlob) {
            $blob         = $schemaBlob;
            $originalSlug = (string) ($blob['slug'] ?? '');
            if ($originalSlug !== '') {
                $blob['slug'] = $slugPrefix.$originalSlug;
            }
            $schema       = $this->schemaMapper->createFromArray(object: $blob);
            $createdIds[] = $schema->getId();
        }

        if ($createdIds !== []) {
            $existing = $register->getSchemas();
            if (is_array($existing) === false) {
                $existing = [];
            }

            $register->setSchemas(array_values(array_unique(array_merge($existing, $createdIds))));
            $this->registerMapper->update($register);
        }
    }//end provisionRegister()

    /**
     * Delete a per-version register as part of rollback.
     *
     * Returns false on failure so the caller can accumulate orphaned resources.
     *
     * @param string $registerSlug The OR register slug to drop
     *
     * @return bool True on success, false on failure
     */
    private function deleteRegister(string $registerSlug): bool
    {
        try {
            $register = $this->registerMapper->find($registerSlug, _multitenancy: false);
            $this->registerService->delete(register: $register);
            return true;
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: wizard rollback failed to delete register '.$registerSlug.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return false;
        }
    }//end deleteRegister()

    /**
     * Roll back everything created so far, in reverse creation order.
     *
     * Reverse order: registers (last created first), then ApplicationVersion
     * rows, then the Application row.
     *
     * Each rollback step is wrapped in try/catch; failures are logged and
     * appended to `$orphaned` (passed by reference) rather than aborting
     * the remaining rollback.
     *
     * @param array<string,mixed> $state    Creation state tracker
     * @param array<int,string>   $orphaned Accumulates resources that could not be cleaned (by ref)
     *
     * @return void
     */
    private function rollback(array $state, array &$orphaned): void
    {
        // 1. Delete registers (reverse order of creation).
        $registerSlugs = array_reverse(array_values((array) ($state['registerSlugs'] ?? [])));
        foreach ($registerSlugs as $registerSlug) {
            if ($this->deleteRegister(registerSlug: (string) $registerSlug) === false) {
                $orphaned[] = (string) $registerSlug;
            }
        }

        // 2. Delete ApplicationVersion rows (reverse order of creation).
        $versionUuids = array_reverse(array_values((array) ($state['versionUuids'] ?? [])));
        foreach ($versionUuids as $versionUuid) {
            $uuid = (string) $versionUuid;
            if ($uuid === '') {
                continue;
            }

            try {
                $this->objectService->deleteObject(uuid: $uuid);
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: wizard rollback failed to delete ApplicationVersion '.$uuid.': '.$e->getMessage(),
                    ['exception' => $e]
                );
                $orphaned[] = 'version:'.$uuid;
            }
        }

        // 3. Delete Application row.
        $applicationUuid = (string) ($state['applicationUuid'] ?? '');
        if ($applicationUuid !== '') {
            try {
                $this->objectService->deleteObject(uuid: $applicationUuid);
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: wizard rollback failed to delete Application '.$applicationUuid.': '.$e->getMessage(),
                    ['exception' => $e]
                );
                $orphaned[] = 'application:'.$applicationUuid;
            }
        }
    }//end rollback()

    /**
     * Load the default manifest from the static fixture file.
     *
     * @return array<string,mixed> The parsed manifest blob
     *
     * @throws WizardCreationException When the fixture cannot be read or decoded
     */
    private function loadDefaultManifest(): array
    {
        $path = __DIR__.'/../Resources/wizard/default-manifest.json';
        if (file_exists($path) === false) {
            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'load-default-manifest',
                message: 'Default manifest fixture not found at '.$path,
                rollbackStatus: 'none'
            );
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'load-default-manifest',
                message: 'Could not read default manifest fixture.',
                rollbackStatus: 'none'
            );
        }

        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        if (is_array($decoded) === false) {
            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'load-default-manifest',
                message: 'Default manifest fixture is not a JSON object.',
                rollbackStatus: 'none'
            );
        }

        return $decoded;
    }//end loadDefaultManifest()

    /**
     * Load the default schema set from the static fixture file.
     *
     * @return array<int,array<string,mixed>> The parsed schema blobs
     *
     * @throws WizardCreationException When the fixture cannot be read or decoded
     */
    private function loadDefaultSchemas(): array
    {
        $path = __DIR__.'/../Resources/wizard/default-schemas.json';
        if (file_exists($path) === false) {
            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'load-default-schemas',
                message: 'Default schemas fixture not found at '.$path,
                rollbackStatus: 'none'
            );
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'load-default-schemas',
                message: 'Could not read default schemas fixture.',
                rollbackStatus: 'none'
            );
        }

        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        if (is_array($decoded) === false) {
            throw new WizardCreationException(
                errorCode: 'wizard_rollback',
                failedAtStep: 'load-default-schemas',
                message: 'Default schemas fixture is not a JSON array.',
                rollbackStatus: 'none'
            );
        }

        return $decoded;
    }//end loadDefaultSchemas()

    /**
     * Substitute the `{registerSlug}` placeholder in a manifest template.
     *
     * Walks through all `pages[*].config.register` fields and replaces the
     * template token with the actual per-version register slug.
     *
     * @param array<string,mixed> $manifest     The manifest template blob
     * @param string              $registerSlug The per-version register slug
     *
     * @return array<string,mixed> The manifest with the token substituted
     */
    public function substituteRegisterSlug(array $manifest, string $registerSlug): array
    {
        if (isset($manifest['pages']) === false || is_array($manifest['pages']) === false) {
            return $manifest;
        }

        foreach ($manifest['pages'] as &$page) {
            if (is_array($page) === false) {
                continue;
            }

            if (isset($page['config']) === false || is_array($page['config']) === false) {
                continue;
            }

            if (isset($page['config']['register']) === true && $page['config']['register'] === '{registerSlug}') {
                $page['config']['register'] = $registerSlug;
            }
        }

        unset($page);
        return $manifest;
    }//end substituteRegisterSlug()

    /**
     * Get the UID of the currently authenticated user.
     *
     * @return string The user UID, or 'unknown' when no session is active
     */
    private function resolveCallerUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'unknown';
        }

        return $user->getUID();
    }//end resolveCallerUid()

    /**
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object / result entry
     *
     * @return array<string,mixed>
     */
    private function normaliseObject(mixed $object): array
    {
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
    }//end normaliseObject()
}//end class
