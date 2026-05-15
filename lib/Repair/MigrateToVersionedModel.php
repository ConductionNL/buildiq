<?php

/**
 * OpenBuilt MigrateToVersionedModel Repair Step
 *
 * @destructive
 *
 * SAFETY: This step deletes every pre-migration `Application` row and its
 * per-app register (`openbuilt-{slug}`). ADR-002 records the explicit
 * decision to accept this data loss: existing OpenBuilt installs hold
 * only test data, and the new versioned model re-seeds Hello World at
 * install time via the creation-wizard capability. If a deployment is
 * known to hold real user data, that data MUST be exported before this
 * step ships.
 *
 * The step is idempotent — re-running on an already-migrated install is
 * a no-op via the short-circuit guard (spec REQ-OBGFM-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenBuilt\Repair
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

namespace OCA\OpenBuilt\Repair;

use OCA\OpenBuilt\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Destructive, idempotent green-field migration to the versioned-app model.
 */
class MigrateToVersionedModel implements IRepairStep
{
    /**
     * Schema slug introduced by the versioned-app model (post-migration).
     */
    private const VERSIONED_SCHEMA = 'applicationVersion';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger          PSR logger for diagnostics
     * @param ObjectService   $objectService   OpenRegister object service
     * @param RegisterService $registerService OpenRegister register service
     * @param RegisterMapper  $registerMapper  Resolves register slugs
     * @param SchemaMapper    $schemaMapper    Resolves schema slugs
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterService $registerService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
    ) {
    }//end __construct()

    /**
     * Get the human-readable name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Migrate OpenBuilt to versioned app model (DESTRUCTIVE)';
    }//end getName()

    /**
     * Execute the migration.
     *
     * Logic:
     *   1. Short-circuit when the schema is already in versioned shape.
     *   2. Enumerate every Application row.
     *   3. For each row: drop the per-app register; on success delete the
     *      Application row; emit one info-line; on register-delete failure
     *      log the error and skip the Application row.
     *
     * @param IOutput $output The output channel for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        try {
            if ($this->isAlreadyVersioned() === true) {
                $output->info('Migrated-to-versioned-model: schema already in versioned shape, skipping');
                return;
            }
        } catch (Throwable $e) {
            // If we cannot even read the schema state we cannot safely
            // continue — assume the worst and skip rather than blow away
            // data that we may not own.
            $output->warning(
                'Migrated-to-versioned-model: could not determine schema state ('.$e->getMessage().'); skipping for safety.'
            );
            $this->logger->error(
                'OpenBuilt: MigrateToVersionedModel short-circuit detection failed',
                ['exception' => $e]
            );
            return;
        }

        $applications = $this->enumerateApplications();
        if ($applications === []) {
            $output->info('Migrated-to-versioned-model: no pre-migration Application rows found.');
            return;
        }

        foreach ($applications as $application) {
            $this->migrateOne(application: $application, output: $output);
        }
    }//end run()

    /**
     * Detect whether the schema is already in versioned shape.
     *
     * Short-circuit fires when EITHER:
     *   - The `applicationVersion` schema exists in the `openbuilt`
     *     register; OR
     *   - No pre-migration Application row carries a `currentVersion`
     *     field (all surviving rows already match the new shape).
     *
     * @return bool True when the schema is already versioned
     *
     * @throws Throwable Propagated by callers — the caller decides whether
     *                   to abort or continue
     */
    private function isAlreadyVersioned(): bool
    {
        // Test 1 — does the versioned schema exist?
        try {
            $this->schemaMapper->find(self::VERSIONED_SCHEMA, _multitenancy: false);
            return true;
        } catch (Throwable) {
            // Not found — fall through to Test 2.
        }

        // Test 2 — do any Application rows carry the legacy `currentVersion` field?
        try {
            $applications = $this->enumerateApplications();
        } catch (Throwable) {
            // If the register or schema do not exist yet, we are on a
            // fresh install — no pre-migration data to migrate.
            return true;
        }

        foreach ($applications as $row) {
            if (array_key_exists('currentVersion', $row) === true) {
                return false;
            }
        }

        return true;
    }//end isAlreadyVersioned()

    /**
     * Fetch every Application row in the `openbuilt` register.
     *
     * @return array<int,array<string,mixed>>
     */
    private function enumerateApplications(): array
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
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuilt: MigrateToVersionedModel enumeration found no register/schema: '.$e->getMessage()
            );
            return [];
        }

        $rows = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => $registerId,
                    'schema'   => $schemaId,
                ],
            ]
        );

        if (is_array($rows) === false) {
            return [];
        }

        $normalised = [];
        foreach ($rows as $row) {
            $normalised[] = $this->normaliseObjectArray(object: $row);
        }

        return $normalised;
    }//end enumerateApplications()

    /**
     * Migrate a single pre-migration Application row.
     *
     * Drops the per-app register first; only deletes the row when the
     * register drop succeeded. On failure, leaves the row in place so
     * the operator can retry on the next upgrade after fixing the
     * underlying issue (spec REQ-OBGFM-004).
     *
     * @param array<string,mixed> $application Application row data
     * @param IOutput             $output      Output channel for progress
     *
     * @return void
     */
    private function migrateOne(array $application, IOutput $output): void
    {
        $slug = (string) ($application['slug'] ?? '');
        if ($slug === '') {
            $this->logger->warning(
                'OpenBuilt: MigrateToVersionedModel skipped Application without slug',
                ['application' => $application]
            );
            return;
        }

        $perAppRegisterSlug = ApplicationVersionService::REGISTER_SLUG.'-'.$slug;

        try {
            $register = $this->registerMapper->find($perAppRegisterSlug, _multitenancy: false);
        } catch (Throwable $e) {
            // No per-app register to drop — proceed to delete the row.
            $register = null;
            $this->logger->debug(
                'OpenBuilt: MigrateToVersionedModel: register '.$perAppRegisterSlug.' not found ('.$e->getMessage().'); proceeding to row delete.'
            );
        }

        if ($register !== null) {
            try {
                $this->registerService->delete(register: $register);
            } catch (Throwable $e) {
                $output->warning(
                    sprintf(
                        'Migrated-to-versioned-model: FAILED to drop register \'%s\''
                        .' for Application \'%s\' (%s); Application row NOT deleted.',
                        $perAppRegisterSlug,
                        $slug,
                        $e->getMessage()
                    )
                );
                $this->logger->error(
                    'OpenBuilt: MigrateToVersionedModel: register-delete failed; preserving Application row',
                    [
                        'slug'      => $slug,
                        'register'  => $perAppRegisterSlug,
                        'exception' => $e->getMessage(),
                    ]
                );
                return;
            }//end try
        }//end if

        $applicationUuid = (string) ($application['id'] ?? $application['uuid'] ?? '');
        if ($applicationUuid === '') {
            $this->logger->warning(
                'OpenBuilt: MigrateToVersionedModel: Application \''.$slug.'\' has no UUID; cannot delete row.'
            );
            return;
        }

        try {
            $this->objectService->deleteObject(uuid: $applicationUuid);
        } catch (Throwable $e) {
            $output->warning(
                sprintf(
                    'Migrated-to-versioned-model: dropped register \'%s\''
                    .' but FAILED to delete Application row \'%s\' (%s).',
                    $perAppRegisterSlug,
                    $slug,
                    $e->getMessage()
                )
            );
            $this->logger->error(
                'OpenBuilt: MigrateToVersionedModel: row-delete failed after register dropped',
                ['slug' => $slug, 'exception' => $e->getMessage()]
            );
            return;
        }

        $output->info(
            "Migrated-to-versioned-model: dropped Application '".$slug."' and register 'openbuilt-".$slug."'"
        );
    }//end migrateOne()

    /**
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object/result entry
     *
     * @return array<string,mixed>
     */
    private function normaliseObjectArray(mixed $object): array
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
    }//end normaliseObjectArray()
}//end class
