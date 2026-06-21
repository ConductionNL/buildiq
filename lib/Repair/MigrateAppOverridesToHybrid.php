<?php

/**
 * OpenBuild MigrateAppOverridesToHybrid Repair Step
 *
 * One-time, idempotent migration from the retired standalone `AppOverride`
 * schema to the unified app model (unify-apps-with-app-type). Each pre-existing
 * `AppOverride` row is converted into a HYBRID `Application` (`appType:hybrid`,
 * `slug = appId`, `baseRef.id = appId`) plus a delta-only `ApplicationVersion`
 * (`manifest:{}`, `manifestDelta` = the override delta), with the Application's
 * `productionVersion` pointed at that version. After each row is copied and
 * verified, the source `AppOverride` row is DELETED (clean break, D-RETIRE).
 * Once every row is migrated the now-empty `app-override` schema is dropped from
 * the `openbuild` register so the unified model is the single source of truth.
 *
 * Idempotent: on a fresh install (no `app-override` schema) the step is a
 * no-op; on a re-run after migration it finds no rows and exits cleanly; a row
 * whose hybrid Application already exists updates that app's version delta in
 * place rather than creating a duplicate (delegated to AppOverrideService).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenBuild\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Repair;

use OCA\OpenBuild\Service\AppOverrideService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Idempotent migration of AppOverride rows into hybrid Applications.
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */
class MigrateAppOverridesToHybrid implements IRepairStep
{
    /**
     * The retired schema slug being migrated away from.
     */
    private const LEGACY_SCHEMA_SLUG = 'app-override';

    /**
     * Constructor.
     *
     * @param LoggerInterface    $logger             PSR logger for diagnostics.
     * @param ObjectService      $objectService      OpenRegister object service.
     * @param RegisterMapper     $registerMapper     Resolves the openbuild register id.
     * @param SchemaMapper       $schemaMapper       Resolves + drops the app-override schema.
     * @param AppOverrideService $appOverrideService Unified hybrid-app store (create/update path).
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AppOverrideService $appOverrideService,
    ) {
    }//end __construct()

    /**
     * Get the human-readable name of this repair step.
     *
     * @return string
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
     */
    public function getName(): string
    {
        return 'Migrate OpenBuild AppOverride records to hybrid Applications';

    }//end getName()

    /**
     * Execute the migration.
     *
     * @param IOutput $output The output channel for progress reporting.
     *
     * @return void
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
     */
    public function run(IOutput $output): void
    {
        $schemaId = $this->resolveLegacySchemaId();
        if ($schemaId === null) {
            $output->info('Migrate-app-overrides-to-hybrid: no app-override schema present, nothing to migrate.');
            return;
        }

        $rows     = $this->enumerateLegacyOverrides(schemaId: $schemaId);
        $migrated = 0;
        $failed   = 0;
        foreach ($rows as $row) {
            if ($this->migrateOne(row: $row, output: $output) === true) {
                $migrated++;
            } else {
                $failed++;
            }
        }

        $output->info('Migrate-app-overrides-to-hybrid: migrated '.$migrated.' override(s) to hybrid apps.');

        // Clean break: drop the legacy schema so the unified model is the single
        // source of truth (D-RETIRE) — but ONLY when EVERY row migrated. Dropping
        // the schema cascade-deletes any rows still under it, so a partial failure
        // must retain the schema (and its un-migrated rows) for the next run to
        // retry; otherwise un-migrated overrides would be silently destroyed.
        if ($failed === 0) {
            $this->dropLegacySchema(schemaId: $schemaId, output: $output);
        } else {
            $output->warning(
                'Migrate-app-overrides-to-hybrid: '.$failed.' override(s) failed to migrate; '
                .'retaining the app-override schema and its rows for retry (schema NOT dropped).'
            );
            $this->logger->warning(
                'OpenBuild: MigrateAppOverridesToHybrid: schema retained — '.$failed.' override(s) un-migrated'
            );
        }

    }//end run()

    /**
     * Resolve the numeric id of the legacy `app-override` schema, if present.
     *
     * @return int|null The schema id, or null when the schema does not exist.
     */
    private function resolveLegacySchemaId(): ?int
    {
        try {
            return $this->schemaMapper->find(self::LEGACY_SCHEMA_SLUG, _multitenancy: false)->getId();
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuild: MigrateAppOverridesToHybrid: no app-override schema ('.$e->getMessage().').'
            );
            return null;
        }

    }//end resolveLegacySchemaId()

    /**
     * Fetch every legacy AppOverride row in the openbuild register.
     *
     * @param int $schemaId The resolved app-override schema id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function enumerateLegacyOverrides(int $schemaId): array
    {
        try {
            $registerId = $this->registerMapper->find(
                AppOverrideService::REGISTER_SLUG,
                _multitenancy: false
            )->getId();
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuild: MigrateAppOverridesToHybrid: openbuild register not found ('.$e->getMessage().').'
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

    }//end enumerateLegacyOverrides()

    /**
     * Migrate one legacy override row into the unified hybrid-app model.
     *
     * Delegates the create-or-update to AppOverrideService::upsert (idempotent
     * find-by-appId), then deletes the source row once the copy succeeded.
     *
     * @param array<string, mixed> $row    The legacy AppOverride row.
     * @param IOutput              $output Output channel for progress.
     *
     * @return bool True when the row was migrated (or already migrated) and removed.
     */
    private function migrateOne(array $row, IOutput $output): bool
    {
        $appId = (string) ($row['appId'] ?? '');
        if ($appId === '') {
            $this->logger->warning(
                'OpenBuild: MigrateAppOverridesToHybrid skipped a row without appId',
                ['row' => $row]
            );
            return false;
        }

        $delta = ($row['manifestDelta'] ?? []);
        if (is_array($delta) === false) {
            $delta = [];
        }

        $baseRef = null;
        if (isset($row['baseRef']) === true && is_array($row['baseRef']) === true) {
            $encoded = json_encode($row['baseRef']);
            if (is_string($encoded) === true) {
                $baseRef = $encoded;
            }
        }

        $updatedBy = (string) ($row['updatedBy'] ?? '');

        try {
            // Repair steps run as the Anonymous system user, which cannot
            // satisfy the Application schema's create:[admin] guard — write in
            // system context so OR RBAC + multitenancy are bypassed.
            $this->appOverrideService->upsert(
                appId: $appId,
                delta: $delta,
                baseRef: $baseRef,
                updatedBy: $updatedBy,
                systemContext: true
            );
        } catch (Throwable $e) {
            $output->warning(
                'Migrate-app-overrides-to-hybrid: FAILED to migrate override \''.$appId.'\' ('.$e->getMessage().'); source row preserved.'
            );
            $this->logger->error(
                'OpenBuild: MigrateAppOverridesToHybrid: upsert failed; preserving source row',
                ['appId' => $appId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try

        // Copy verified — delete the source AppOverride row (clean break).
        $uuid = (string) ($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? '')));
        if ($uuid !== '') {
            try {
                // System-context delete — Anonymous cannot satisfy the
                // app-override delete:[admin] guard during a repair run.
                $this->objectService->deleteObject(uuid: $uuid, _rbac: false, _multitenancy: false);
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuild: MigrateAppOverridesToHybrid: migrated but failed to delete source row',
                    ['appId' => $appId, 'exception' => $e->getMessage()]
                );
            }
        }

        $output->info('Migrate-app-overrides-to-hybrid: migrated override \''.$appId.'\' to a hybrid app.');
        return true;

    }//end migrateOne()

    /**
     * Drop the now-empty legacy `app-override` schema (best-effort).
     *
     * @param int     $schemaId The schema id resolved at the start of the run.
     * @param IOutput $output   Output channel for progress.
     *
     * @return void
     */
    private function dropLegacySchema(int $schemaId, IOutput $output): void
    {
        try {
            $schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
            $this->schemaMapper->delete($schema);
            $output->info('Migrate-app-overrides-to-hybrid: dropped the retired app-override schema.');
        } catch (Throwable $e) {
            $output->warning(
                'Migrate-app-overrides-to-hybrid: could not drop the app-override schema ('.$e->getMessage().'); it is empty and harmless.'
            );
            $this->logger->warning(
                'OpenBuild: MigrateAppOverridesToHybrid: schema drop failed',
                ['exception' => $e->getMessage()]
            );
        }

    }//end dropLegacySchema()

    /**
     * Coerce an OR result entry to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string, mixed>
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
