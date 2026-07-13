<?php

/**
 * OpenBuild MigrateToVersionedModel Repair Step
 *
 * @destructive
 *
 * SAFETY: This step deletes every pre-migration `Application` row and its
 * per-app register (`openbuild-{slug}`). ADR-002 records the explicit
 * decision to accept this data loss: existing OpenBuild installs hold
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-26
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-27
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-28
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-29
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Repair;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IAppConfig;
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
     * IAppConfig key persisting the migration outcome so a finished (or
     * permanently blocked) migration is never re-attempted on every
     * repair run. Re-arm manually via
     * `occ config:app:delete openbuild repair_migrate_versioned_model`.
     */
    private const STATE_KEY = 'repair_migrate_versioned_model';

    /**
     * Terminal state: every pre-migration row was migrated (or none existed).
     * `run()` short-circuits immediately once this is persisted.
     */
    private const STATE_DONE = 'done';

    /**
     * Waiting state: a row could not be deleted while running WITHOUT
     * system-context elevation (the installed OpenRegister predates
     * `ObjectService::runAsSystem()`). Not terminal — `run()` retries on
     * every subsequent invocation until the OpenRegister upgrade ships,
     * logging at debug level rather than error since the precondition
     * (elevation support) is expected to still be missing.
     */
    private const STATE_BLOCKED = 'blocked';

    /**
     * Terminal state: a row still failed to migrate while running WITH
     * system-context elevation active — an operator-facing problem, not
     * an RBAC gap. `run()` stops retrying; re-arm via the occ command above.
     */
    private const STATE_FAILED = 'failed';

    /**
     * Per-row outcome: migrated (or nothing to migrate for this row).
     */
    private const ROW_OK = 'row_ok';

    /**
     * Per-row outcome: failed without elevation available (see STATE_BLOCKED).
     */
    private const ROW_BLOCKED = 'row_blocked';

    /**
     * Per-row outcome: failed with elevation active, or unresolvable data
     * (missing slug/uuid) — always operator-facing (see STATE_FAILED).
     */
    private const ROW_FAILED = 'row_failed';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger          PSR logger for diagnostics
     * @param ObjectService   $objectService   OpenRegister object service
     * @param RegisterService $registerService OpenRegister register service
     * @param RegisterMapper  $registerMapper  Resolves register slugs
     * @param SchemaMapper    $schemaMapper    Resolves schema slugs
     * @param IAppConfig      $appConfig       Persists the migration state marker
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterService $registerService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get the human-readable name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Migrate OpenBuild to versioned app model (DESTRUCTIVE)';
    }//end getName()

    /**
     * Execute the migration.
     *
     * Logic:
     *   0. Short-circuit when a previous run already persisted a terminal
     *      outcome (`done` or `failed`) — see the STATE_* constants. This is
     *      what makes the step converge: without it, a repair step that runs
     *      on every app-upgrade cycle re-attempts the identical doomed
     *      operation forever, flooding the log with the same error.
     *   1. Short-circuit when the schema is already in versioned shape.
     *   2. Enumerate every Application row.
     *   3. For each row: drop the per-app register (elevated via
     *      `ObjectService::runAsSystem()` when available — repair steps run
     *      without a user session, so RBAC otherwise denies every write as
     *      "Anonymous"); on success delete the Application row; emit one
     *      info-line; on failure log and skip the Application row.
     *   4. Persist the aggregate outcome so the next invocation short-circuits
     *      instead of re-attempting rows that are known done/blocked/failed.
     *
     * @param IOutput $output The output channel for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-26
     */
    public function run(IOutput $output): void
    {
        $state = $this->appConfig->getValueString(Application::APP_ID, self::STATE_KEY, '');
        if ($state === self::STATE_DONE) {
            $this->logger->debug('OpenBuild: MigrateToVersionedModel already completed; skipping.');
            $output->info('Migrated-to-versioned-model: previously completed, skipping.');
            return;
        }

        if ($state === self::STATE_FAILED) {
            $this->logger->debug('OpenBuild: MigrateToVersionedModel previously failed under system context; skipping until re-armed.');
            $output->info(
                'Migrated-to-versioned-model: previously failed and needs operator attention;'
                .' skipping (re-arm via `occ config:app:delete openbuild '.self::STATE_KEY.'`).'
            );
            return;
        }

        try {
            if ($this->isAlreadyVersioned() === true) {
                $output->info('Migrated-to-versioned-model: schema already in versioned shape, skipping');
                $this->appConfig->setValueString(Application::APP_ID, self::STATE_KEY, self::STATE_DONE);
                return;
            }
        } catch (Throwable $e) {
            // If we cannot even read the schema state we cannot safely
            // continue — assume the worst and skip rather than blow away
            // data that we may not own. Not persisted: a transient read
            // failure (e.g. DB hiccup) should not become a permanent skip.
            $output->warning(
                'Migrated-to-versioned-model: could not determine schema state ('.$e->getMessage().'); skipping for safety.'
            );
            $this->logger->error(
                'OpenBuild: MigrateToVersionedModel short-circuit detection failed',
                ['exception' => $e]
            );
            return;
        }

        $applications = $this->enumerateApplications();
        if ($applications === []) {
            $output->info('Migrated-to-versioned-model: no pre-migration Application rows found.');
            $this->appConfig->setValueString(Application::APP_ID, self::STATE_KEY, self::STATE_DONE);
            return;
        }

        $hasSystemContext = method_exists($this->objectService, 'runAsSystem') === true;
        $sawBlocked       = false;
        $sawFailed        = false;

        foreach ($applications as $application) {
            $rowOutcome = $this->migrateOne(
                application: $application,
                output: $output,
                hasSystemContext: $hasSystemContext
            );

            if ($rowOutcome === self::ROW_FAILED) {
                $sawFailed = true;
            } else if ($rowOutcome === self::ROW_BLOCKED) {
                $sawBlocked = true;
            }
        }

        if ($sawFailed === true) {
            $this->appConfig->setValueString(Application::APP_ID, self::STATE_KEY, self::STATE_FAILED);
        } else if ($sawBlocked === true) {
            $this->appConfig->setValueString(Application::APP_ID, self::STATE_KEY, self::STATE_BLOCKED);
        } else {
            $this->appConfig->setValueString(Application::APP_ID, self::STATE_KEY, self::STATE_DONE);
        }
    }//end run()

    /**
     * Detect whether the schema is already in versioned shape.
     *
     * Short-circuit fires when EITHER:
     *   - The `applicationVersion` schema exists in the `openbuild`
     *     register; OR
     *   - No pre-migration Application row carries a `currentVersion`
     *     field (all surviving rows already match the new shape).
     *
     * @return bool True when the schema is already versioned
     *
     * @throws Throwable Propagated by callers — the caller decides whether
     *                   to abort or continue
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-27
     */
    private function isAlreadyVersioned(): bool
    {
        // The previous check was "does the versioned schema exist?" — but
        // InitializeSettings imports the schema register BEFORE this step
        // runs, so the `applicationVersion` schema is always present by
        // the time we reach here. That made the short-circuit always fire
        // and the migration always skip (issue #69).
        //
        // Correct check: examine the Application rows themselves. If any
        // row carries a legacy top-level `manifest` / `version` / `status`
        // / `currentVersion` field (the pre-spec-C shape), the install
        // still has pre-migration data. If no Application rows exist OR
        // all surviving rows already match the post-C shape, we're
        // versioned.
        try {
            $applications = $this->enumerateApplications();
        } catch (Throwable) {
            // No openbuild register or no Application schema — fresh
            // install. Nothing to migrate.
            return true;
        }

        if ($applications === []) {
            return true;
        }

        foreach ($applications as $row) {
            // Pre-C shape keys we need to migrate away from.
            if (array_key_exists('currentVersion', $row) === true
                || array_key_exists('manifest', $row) === true
                || array_key_exists('version', $row) === true
                || array_key_exists('status', $row) === true
            ) {
                return false;
            }
        }

        return true;
    }//end isAlreadyVersioned()

    /**
     * Fetch every Application row in the `openbuild` register.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-26
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
                'OpenBuild: MigrateToVersionedModel enumeration found no register/schema: '.$e->getMessage()
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
     * The repair step runs without a Nextcloud user session (Anonymous),
     * which OpenRegister RBAC denies write access to by default. Both
     * OpenRegister mutations are wrapped in `ObjectService::runAsSystem()`
     * when the installed OpenRegister ships it, elevating the caller to a
     * trusted system principal for the duration of the callable only.
     * Guarded with `method_exists()` for back-compat with an OpenRegister
     * release that predates the elevation API.
     *
     * @param array<string,mixed> $application      Application row data
     * @param IOutput             $output           Output channel for progress
     * @param bool                $hasSystemContext Whether `runAsSystem()` is available on the installed OpenRegister
     *
     * @return string One of self::ROW_OK, self::ROW_BLOCKED, self::ROW_FAILED
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-28
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-29
     */
    private function migrateOne(array $application, IOutput $output, bool $hasSystemContext): string
    {
        $slug = (string) ($application['slug'] ?? '');
        if ($slug === '') {
            $this->logger->warning(
                'OpenBuild: MigrateToVersionedModel skipped Application without slug',
                ['application' => $application]
            );
            return self::ROW_FAILED;
        }

        $perAppRegisterSlug = ApplicationVersionService::REGISTER_SLUG.'-'.$slug;

        try {
            $register = $this->registerMapper->find($perAppRegisterSlug, _multitenancy: false);
        } catch (Throwable $e) {
            // No per-app register to drop — proceed to delete the row.
            $register = null;
            $this->logger->debug(
                'OpenBuild: MigrateToVersionedModel: register '.$perAppRegisterSlug.' not found ('.$e->getMessage().'); proceeding to row delete.'
            );
        }

        if ($register !== null) {
            try {
                if ($hasSystemContext === true) {
                    $this->objectService->runAsSystem(
                        function () use ($register): void {
                            $this->registerService->delete(register: $register);
                        }
                    );
                } else {
                    $this->registerService->delete(register: $register);
                }
            } catch (Throwable $e) {
                if ($hasSystemContext === false) {
                    // Expected/precondition-absent: no elevation available on
                    // this OpenRegister release. Log at debug — this is not
                    // an operator-facing error, it self-resolves on upgrade.
                    $this->logger->debug(
                        'OpenBuild: MigrateToVersionedModel: register-delete failed without system-context elevation available',
                        [
                            'slug'      => $slug,
                            'register'  => $perAppRegisterSlug,
                            'exception' => $e->getMessage(),
                        ]
                    );
                    $output->info(
                        sprintf(
                            'Migrated-to-versioned-model: register \'%s\' delete needs OpenRegister system-context'
                            .' elevation, not yet available; will retry on a later run.',
                            $perAppRegisterSlug
                        )
                    );
                    return self::ROW_BLOCKED;
                }//end if

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
                    'OpenBuild: MigrateToVersionedModel: register-delete failed; preserving Application row',
                    [
                        'slug'      => $slug,
                        'register'  => $perAppRegisterSlug,
                        'exception' => $e->getMessage(),
                    ]
                );
                return self::ROW_FAILED;
            }//end try
        }//end if

        $applicationUuid = (string) ($application['id'] ?? $application['uuid'] ?? '');
        if ($applicationUuid === '') {
            $this->logger->warning(
                'OpenBuild: MigrateToVersionedModel: Application \''.$slug.'\' has no UUID; cannot delete row.'
            );
            return self::ROW_FAILED;
        }

        try {
            if ($hasSystemContext === true) {
                $this->objectService->runAsSystem(
                    function () use ($applicationUuid): void {
                        $this->objectService->deleteObject(uuid: $applicationUuid);
                    }
                );
            } else {
                $this->objectService->deleteObject(uuid: $applicationUuid);
            }
        } catch (Throwable $e) {
            if ($hasSystemContext === false) {
                $this->logger->debug(
                    'OpenBuild: MigrateToVersionedModel: row-delete failed without system-context elevation available',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
                $output->info(
                    sprintf(
                        'Migrated-to-versioned-model: dropped register \'%s\' but Application row delete'
                        .' needs OpenRegister system-context elevation, not yet available; will retry on a later run.',
                        $perAppRegisterSlug
                    )
                );
                return self::ROW_BLOCKED;
            }

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
                'OpenBuild: MigrateToVersionedModel: row-delete failed after register dropped',
                ['slug' => $slug, 'exception' => $e->getMessage()]
            );
            return self::ROW_FAILED;
        }//end try

        $output->info(
            "Migrated-to-versioned-model: dropped Application '".$slug."' and register 'openbuild-".$slug."'"
        );
        return self::ROW_OK;
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
