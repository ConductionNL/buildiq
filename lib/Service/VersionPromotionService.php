<?php

/**
 * OpenBuilt VersionPromotionService
 *
 * Owns the imperative promotion flow defined in spec
 * `openbuilt-version-promotion` and ADR-002. Responsibilities:
 *
 *   - Resolve the target as `sourceVersion.promotesTo` (spec REQ-OBVP-001).
 *   - Validate the strategy against the closed enum
 *     `start-with-source-data | migrate-existing-data | empty-start`
 *     (spec REQ-OBVP-001).
 *   - Acquire OR's object lock on the target row (spec REQ-OBVP-006); release
 *     in a `finally` regardless of outcome.
 *   - Run the strategy branch (spec REQ-OBVP-002 / -003 / -004): manage rows
 *     and forward the source's schema set to OR's register-merge surface
 *     (spec REQ-OBVP-005 — deferred to OR).
 *   - Replace the target's `manifest` + `semver` with the source's (spec
 *     REQ-OBVP-008).
 *   - On any failure, flip the target's status to `archived`, stamp
 *     `_self.promotionFailedAt`, save, and re-throw a 500-mapped
 *     {@see PromotionFailedException} (spec REQ-OBVP-009).
 *
 * Per ADR-031 §Exceptions, every branch in this file is classified
 * imperative — the design decisions are documented in the change's
 * design.md "Declarative-vs-imperative decision section".
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

use OCA\OpenBuilt\Exception\InvalidStrategyException;
use OCA\OpenBuilt\Exception\NoPromoteTargetException;
use OCA\OpenBuilt\Exception\PromotionFailedException;
use OCA\OpenBuilt\Exception\VersionLockedException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imperative business-logic surface for ApplicationVersion promotion.
 */
class VersionPromotionService
{
    /**
     * Strategy: replace target rows with source rows, import source schemas.
     */
    public const STRATEGY_START_WITH_SOURCE_DATA = 'start-with-source-data';

    /**
     * Strategy: keep target rows; OR handles schema-migration column-level changes.
     */
    public const STRATEGY_MIGRATE_EXISTING_DATA = 'migrate-existing-data';

    /**
     * Strategy: wipe target rows; install source's schema set.
     */
    public const STRATEGY_EMPTY_START = 'empty-start';

    /**
     * Closed enum of accepted strategy values.
     *
     * @var array<int,string>
     */
    public const VALID_STRATEGIES = [
        self::STRATEGY_START_WITH_SOURCE_DATA,
        self::STRATEGY_MIGRATE_EXISTING_DATA,
        self::STRATEGY_EMPTY_START,
    ];

    /**
     * Shared register hosting Application + ApplicationVersion rows.
     */
    public const REGISTER_SLUG = ApplicationVersionService::REGISTER_SLUG;

    /**
     * Schema slug of the parent Application object.
     */
    public const APPLICATION_SCHEMA = ApplicationVersionService::APPLICATION_SCHEMA;

    /**
     * Schema slug of the ApplicationVersion object.
     */
    public const APPLICATION_VERSION_SCHEMA = ApplicationVersionService::APPLICATION_VERSION_SCHEMA;

    /**
     * Status string applied to a target after a failed promotion (spec REQ-OBVP-009).
     */
    private const STATUS_ARCHIVED = 'archived';

    /**
     * Default lock duration in seconds passed to OR's `lockObject()`.
     *
     * OR auto-extends the lock on save; this value is the safety net if
     * the caller's flow stalls.
     */
    private const LOCK_DURATION_SECONDS = 60;

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger         PSR logger
     * @param ObjectService   $objectService  OR object surface (lock, save, search, delete)
     * @param RegisterMapper  $registerMapper Resolves register slugs to entities
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
    ) {
    }//end __construct()

    /**
     * Pure-function default-strategy rule (spec REQ-OBVP-011 / Decision 3).
     *
     * Returns `migrate-existing-data` when the target version IS the
     * Application's `productionVersion`; otherwise returns
     * `start-with-source-data`. Never returns `empty-start`.
     *
     * @param array<string,mixed> $application Application object (must carry `productionVersion`)
     * @param array<string,mixed> $target      Target ApplicationVersion object (must carry `id` / `uuid`)
     *
     * @return string One of {@see self::STRATEGY_MIGRATE_EXISTING_DATA},
     *                {@see self::STRATEGY_START_WITH_SOURCE_DATA}.
     */
    public static function defaultStrategyFor(array $application, array $target): string
    {
        $productionUuid = (string) ($application['productionVersion'] ?? '');
        $targetUuid     = (string) ($target['id'] ?? ($target['uuid'] ?? ''));

        if ($productionUuid !== '' && $targetUuid !== '' && $productionUuid === $targetUuid) {
            return self::STRATEGY_MIGRATE_EXISTING_DATA;
        }

        return self::STRATEGY_START_WITH_SOURCE_DATA;
    }//end defaultStrategyFor()

    /**
     * Orchestrate promotion from source to its `promotesTo` target (spec REQ-OBVP-001..-009).
     *
     * Acquires the OR object lock on the target, runs the strategy branch
     * inside a `try { … }` block, and releases the lock in a `finally`
     * regardless of outcome. On failure the target is archived and
     * a {@see PromotionFailedException} is re-thrown so the controller can
     * map it to a 500 response.
     *
     * @param array<string,mixed> $source   Source ApplicationVersion object data
     * @param string              $strategy One of {@see self::VALID_STRATEGIES}
     *
     * @return array<string,mixed> The updated target ApplicationVersion data
     *
     * @throws NoPromoteTargetException When the source has no `promotesTo`
     * @throws InvalidStrategyException When the strategy is missing or unknown
     * @throws VersionLockedException   When OR's lock is held by another caller
     * @throws PromotionFailedException When the strategy branch fails midway
     */
    public function promote(array $source, string $strategy): array
    {
        $this->assertValidStrategy(strategy: $strategy);

        $targetUuid = $this->resolveTargetUuid(source: $source);
        $target     = $this->loadVersion(uuid: $targetUuid);

        $this->acquireLock(targetUuid: $targetUuid);

        try {
            $updated = $this->runStrategy(strategy: $strategy, source: $source, target: $target);
            return $updated;
        } catch (PromotionFailedException $e) {
            // Already handled — re-throw after lock release.
            throw $e;
        } catch (Throwable $e) {
            // Convert any other failure into the on-failure flow.
            $this->handlePromotionFailure(targetUuid: $targetUuid, strategy: $strategy, error: $e);
        } finally {
            $this->releaseLock(targetUuid: $targetUuid);
        }//end try
    }//end promote()

    /**
     * Branch on strategy and run the matching imperative step.
     *
     * Separate `private` methods make each branch independently
     * unit-testable.
     *
     * @param string              $strategy Validated strategy string
     * @param array<string,mixed> $source   Source ApplicationVersion
     * @param array<string,mixed> $target   Target ApplicationVersion
     *
     * @return array<string,mixed> The updated target row
     *
     * @throws PromotionFailedException On any failure inside the branch
     */
    private function runStrategy(string $strategy, array $source, array $target): array
    {
        try {
            switch ($strategy) {
                case self::STRATEGY_START_WITH_SOURCE_DATA:
                    return $this->runStartWithSourceData(source: $source, target: $target);
                case self::STRATEGY_MIGRATE_EXISTING_DATA:
                    return $this->runMigrateExistingData(source: $source, target: $target);
                case self::STRATEGY_EMPTY_START:
                    return $this->runEmptyStart(source: $source, target: $target);
                default:
                    // Defensive — assertValidStrategy() already rejected this.
                    throw new InvalidStrategyException(
                        message: sprintf('Unhandled strategy "%s" in runStrategy().', $strategy)
                    );
            }//end switch
        } catch (Throwable $e) {
            $targetUuid = (string) ($target['id'] ?? ($target['uuid'] ?? ''));
            $this->handlePromotionFailure(targetUuid: $targetUuid, strategy: $strategy, error: $e);
        }//end try
    }//end runStrategy()

    /**
     * Strategy: start-with-source-data (spec REQ-OBVP-002).
     *
     * Schema-import → delete-all-target-rows → copy-source-rows → write
     * manifest + semver → save.
     *
     * @param array<string,mixed> $source Source ApplicationVersion
     * @param array<string,mixed> $target Target ApplicationVersion
     *
     * @return array<string,mixed> The updated target row
     */
    private function runStartWithSourceData(array $source, array $target): array
    {
        $this->forwardSchemaSetToOR(source: $source, target: $target);
        $this->wipeTargetRegister(target: $target);
        $this->copyRowsFromSource(source: $source, target: $target);
        return $this->applyManifestAndSemver(source: $source, target: $target);
    }//end runStartWithSourceData()

    /**
     * Strategy: migrate-existing-data (spec REQ-OBVP-003).
     *
     * Schema-import (OR handles column-level migration) → leave target rows
     * → write manifest + semver → save.
     *
     * @param array<string,mixed> $source Source ApplicationVersion
     * @param array<string,mixed> $target Target ApplicationVersion
     *
     * @return array<string,mixed> The updated target row
     */
    private function runMigrateExistingData(array $source, array $target): array
    {
        $this->forwardSchemaSetToOR(source: $source, target: $target);
        return $this->applyManifestAndSemver(source: $source, target: $target);
    }//end runMigrateExistingData()

    /**
     * Strategy: empty-start (spec REQ-OBVP-004).
     *
     * Delete-all-target-rows → schema-import → write manifest + semver → save.
     *
     * @param array<string,mixed> $source Source ApplicationVersion
     * @param array<string,mixed> $target Target ApplicationVersion
     *
     * @return array<string,mixed> The updated target row
     */
    private function runEmptyStart(array $source, array $target): array
    {
        $this->wipeTargetRegister(target: $target);
        $this->forwardSchemaSetToOR(source: $source, target: $target);
        return $this->applyManifestAndSemver(source: $source, target: $target);
    }//end runEmptyStart()

    /**
     * Forward the source's schema set to OR's register-merge surface (spec REQ-OBVP-005).
     *
     * Read the source register's schema set and ensure the target register's
     * schema set contains the union. OR's own breaking-change handling
     * applies at write time inside RegisterMapper::update().
     *
     * Per Decision 4: no openbuilt-side diff or dry-run; OR drives the
     * outcome.
     *
     * @param array<string,mixed> $source Source ApplicationVersion
     * @param array<string,mixed> $target Target ApplicationVersion
     *
     * @return void
     */
    private function forwardSchemaSetToOR(array $source, array $target): void
    {
        $sourceRegisterSlug = (string) ($source['register'] ?? '');
        $targetRegisterSlug = (string) ($target['register'] ?? '');

        if ($sourceRegisterSlug === '' || $targetRegisterSlug === '') {
            $this->logger->info(
                'OpenBuilt: forwardSchemaSetToOR skipped — source or target register slug missing'
                .' (source='.$sourceRegisterSlug.', target='.$targetRegisterSlug.').'
            );
            return;
        }

        $sourceRegister = $this->registerMapper->find($sourceRegisterSlug, _multitenancy: false);
        $targetRegister = $this->registerMapper->find($targetRegisterSlug, _multitenancy: false);

        $sourceSchemas = $sourceRegister->getSchemas();
        if (is_array($sourceSchemas) === false) {
            $sourceSchemas = [];
        }

        // Spec Decision 4: trust OR's setSchemas + update to handle the
        // migration outcome. The schema set is the source's verbatim;
        // openbuilt does not pre-flight column-level diffs.
        $targetRegister->setSchemas($sourceSchemas);
        $this->registerMapper->update($targetRegister);

        $this->logger->info(
            'OpenBuilt: forwardSchemaSetToOR: target register '.$targetRegisterSlug
            .' aligned with source register '.$sourceRegisterSlug.' ('.count($sourceSchemas).' schemas).'
        );
    }//end forwardSchemaSetToOR()

    /**
     * Delete every row in the target version's register (used by REQ-OBVP-002 / -004).
     *
     * The target ApplicationVersion row itself is NOT deleted — only the
     * companion data rows hosted in its per-version register.
     *
     * @param array<string,mixed> $target Target ApplicationVersion
     *
     * @return void
     */
    private function wipeTargetRegister(array $target): void
    {
        $targetRegisterSlug = (string) ($target['register'] ?? '');
        if ($targetRegisterSlug === '') {
            return;
        }

        $register   = $this->registerMapper->find($targetRegisterSlug, _multitenancy: false);
        $registerId = $register->getId();

        $rows = $this->objectService->searchObjects(
            query: ['@self' => ['register' => $registerId]],
            _rbac: false,
            _multitenancy: false
        );

        if (is_array($rows) === false || $rows === []) {
            return;
        }

        foreach ($rows as $row) {
            $uuid = $this->extractUuid(row: $row);
            if ($uuid === '') {
                continue;
            }

            $this->objectService->deleteObject(uuid: $uuid, _rbac: false, _multitenancy: false);
        }
    }//end wipeTargetRegister()

    /**
     * Copy every row from source register into target register (used by REQ-OBVP-002).
     *
     * Rows are written via `ObjectService::saveObject` against the target
     * register's slug; OR assigns fresh UUIDs (we explicitly strip any
     * source-side identifiers before save).
     *
     * @param array<string,mixed> $source Source ApplicationVersion
     * @param array<string,mixed> $target Target ApplicationVersion
     *
     * @return void
     */
    private function copyRowsFromSource(array $source, array $target): void
    {
        $sourceRegisterSlug = (string) ($source['register'] ?? '');
        $targetRegisterSlug = (string) ($target['register'] ?? '');

        if ($sourceRegisterSlug === '' || $targetRegisterSlug === '') {
            return;
        }

        $sourceRegister = $this->registerMapper->find($sourceRegisterSlug, _multitenancy: false);

        $rows = $this->objectService->searchObjects(
            query: ['@self' => ['register' => $sourceRegister->getId()]],
            _rbac: false,
            _multitenancy: false
        );

        if (is_array($rows) === false || $rows === []) {
            return;
        }

        foreach ($rows as $row) {
            $payload      = $this->normaliseObjectArray(object: $row);
            $schemaIdHint = $this->extractSchemaSlug(row: $row, payload: $payload);

            unset($payload['id'], $payload['uuid'], $payload['@self']);

            $this->objectService->saveObject(
                object: $payload,
                register: $targetRegisterSlug,
                schema: $schemaIdHint,
                _rbac: false,
                _multitenancy: false
            );
        }
    }//end copyRowsFromSource()

    /**
     * Write the source's manifest + semver onto the target row, then save (spec REQ-OBVP-008).
     *
     * Marks the target's `status` as `published` so a previously-archived
     * target recovers (idempotent re-promotion, REQ-OBVP-009 scenario 2).
     *
     * @param array<string,mixed> $source Source ApplicationVersion
     * @param array<string,mixed> $target Target ApplicationVersion
     *
     * @return array<string,mixed> The persisted target row
     */
    private function applyManifestAndSemver(array $source, array $target): array
    {
        $targetUuid         = (string) ($target['id'] ?? ($target['uuid'] ?? ''));
        $target['manifest'] = $source['manifest'] ?? ($target['manifest'] ?? []);
        $target['semver']   = (string) ($source['semver'] ?? ($target['semver'] ?? '0.1.0'));
        $target['status']   = 'published';

        $saved = $this->objectService->saveObject(
            object: $target,
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_VERSION_SCHEMA,
            uuid: $targetUuid,
            _rbac: false,
            _multitenancy: false
        );

        return $this->normaliseObjectArray(object: $saved);
    }//end applyManifestAndSemver()

    /**
     * On-failure path: flip target to archived, stamp metadata, save, re-throw 500 (spec REQ-OBVP-009).
     *
     * Called from inside the `try` block in {@see runStrategy()}. The
     * surrounding `finally` releases the lock independently — this method
     * MUST NOT swallow or wrap the lock-release error.
     *
     * @param string    $targetUuid UUID of the target row
     * @param string    $strategy   Strategy the admin chose
     * @param Throwable $error      The underlying failure
     *
     * @return never
     *
     * @throws PromotionFailedException Always
     */
    private function handlePromotionFailure(string $targetUuid, string $strategy, Throwable $error): never
    {
        $message = $error->getMessage();
        $this->logger->error(
            'OpenBuilt: promotion failed (strategy '.$strategy.', target '.$targetUuid.'): '.$message,
            ['exception' => $error]
        );

        try {
            $current           = $this->loadVersion(uuid: $targetUuid);
            $current['status'] = self::STATUS_ARCHIVED;

            $self = ($current['_self'] ?? []);
            if (is_array($self) === false) {
                $self = [];
            }

            $self['promotionFailedAt'] = gmdate(format: 'Y-m-d\TH:i:s\Z');
            $current['_self']          = $self;

            $this->objectService->saveObject(
                object: $current,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_VERSION_SCHEMA,
                uuid: $targetUuid,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $persistError) {
            // Persisting the archived flip itself failed — log and continue
            // so we still surface the original failure to the caller.
            $this->logger->error(
                'OpenBuilt: failed to persist archived flip for target '.$targetUuid.': '
                .$persistError->getMessage(),
                ['exception' => $persistError]
            );
        }//end try

        throw new PromotionFailedException(
            strategy: $strategy,
            message: $message,
            previous: $error
        );
    }//end handlePromotionFailure()

    /**
     * Acquire OR's object lock on the target ApplicationVersion row (spec REQ-OBVP-006).
     *
     * On contention we surface a 409-mapped {@see VersionLockedException}
     * with `lockedBy` + `expiresAt` read from OR's lock metadata via
     * `getLockInfo()` when available.
     *
     * @param string $targetUuid UUID of the target row
     *
     * @return void
     *
     * @throws VersionLockedException When OR's lockObject reports contention
     */
    private function acquireLock(string $targetUuid): void
    {
        try {
            $this->objectService->lockObject(
                identifier: $targetUuid,
                process: 'openbuilt.version-promotion',
                duration: self::LOCK_DURATION_SECONDS
            );
        } catch (Throwable $e) {
            // ObjectService surfaces OR's LockedException + any other lock
            // failure. We translate both into VersionLockedException with
            // best-effort metadata; if OR didn't supply the lock context,
            // the controller still returns 409 + `code: "version_locked"`.
            $lockedBy  = null;
            $expiresAt = null;

            if (method_exists($this->objectService, 'getLockInfo') === true) {
                $info = $this->callGetLockInfo(targetUuid: $targetUuid);
                if (is_array($info) === true) {
                    $lockedBy  = $this->stringOrNull(value: ($info['locked_by'] ?? $info['lockedBy'] ?? null));
                    $expiresAt = $this->stringOrNull(value: ($info['expires_at'] ?? $info['expiresAt'] ?? null));
                }
            }

            throw new VersionLockedException(
                lockedBy: $lockedBy,
                expiresAt: $expiresAt,
                message: 'Target ApplicationVersion '.$targetUuid.' is locked: '.$e->getMessage(),
                previous: $e
            );
        }//end try
    }//end acquireLock()

    /**
     * Release the OR object lock on the target ApplicationVersion row.
     *
     * Failures are logged and swallowed — the lock release is a cleanup
     * step that must not mask the surrounding success or failure.
     *
     * @param string $targetUuid UUID of the target row
     *
     * @return void
     */
    private function releaseLock(string $targetUuid): void
    {
        try {
            $this->objectService->unlockObject(identifier: $targetUuid);
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuilt: failed to release lock on target '.$targetUuid.': '.$e->getMessage()
            );
        }
    }//end releaseLock()

    /**
     * Helper — call `getLockInfo` defensively on the OR object service.
     *
     * Wrapped because the method is optional in the stub used by unit tests.
     *
     * @param string $targetUuid UUID to look up
     *
     * @return array<string,mixed>|null
     */
    private function callGetLockInfo(string $targetUuid): ?array
    {
        try {
            // @phpstan-ignore-next-line method.notFound
            $info = $this->objectService->getLockInfo($targetUuid);
            if (is_array($info) === true) {
                return $info;
            }
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuilt: getLockInfo lookup for '.$targetUuid.' failed: '.$e->getMessage()
            );
        }

        return null;
    }//end callGetLockInfo()

    /**
     * Load an ApplicationVersion row by UUID via OR's object service.
     *
     * @param string $uuid UUID to fetch
     *
     * @return array<string,mixed>
     */
    private function loadVersion(string $uuid): array
    {
        $entity = $this->objectService->find(
            id: $uuid,
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_VERSION_SCHEMA
        );

        if ($entity === null) {
            return [];
        }

        return $this->normaliseObjectArray(object: $entity);
    }//end loadVersion()

    /**
     * Resolve the target UUID from the source's `promotesTo` field (spec REQ-OBVP-001).
     *
     * @param array<string,mixed> $source Source ApplicationVersion
     *
     * @return string UUID of the target version
     *
     * @throws NoPromoteTargetException When `promotesTo` is null / empty
     */
    private function resolveTargetUuid(array $source): string
    {
        $target = $source['promotesTo'] ?? null;
        if (is_string($target) === false || $target === '') {
            throw new NoPromoteTargetException(
                message: 'Source ApplicationVersion has no promotesTo target.'
            );
        }

        return $target;
    }//end resolveTargetUuid()

    /**
     * Reject any strategy outside {@see self::VALID_STRATEGIES} (spec REQ-OBVP-001).
     *
     * @param string $strategy Strategy value supplied by the client
     *
     * @return void
     *
     * @throws InvalidStrategyException When the strategy is missing or unknown
     */
    private function assertValidStrategy(string $strategy): void
    {
        if (in_array($strategy, self::VALID_STRATEGIES, true) === false) {
            throw new InvalidStrategyException(
                message: sprintf(
                    'Unknown promotion strategy "%s" — must be one of: %s',
                    $strategy,
                    implode(', ', self::VALID_STRATEGIES)
                )
            );
        }
    }//end assertValidStrategy()

    /**
     * Pull the UUID off an OR row entry (entity or array shape).
     *
     * @param mixed $row OR row entry
     *
     * @return string UUID, or empty string when unavailable
     */
    private function extractUuid(mixed $row): string
    {
        if (is_array($row) === true) {
            $value = ($row['id'] ?? ($row['uuid'] ?? ''));
            return (string) $value;
        }

        if (is_object($row) === true && method_exists($row, 'getUuid') === true) {
            $uuid = $row->getUuid();
            if (is_string($uuid) === true) {
                return $uuid;
            }
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $payload = $row->jsonSerialize();
            if (is_array($payload) === true) {
                return (string) ($payload['id'] ?? ($payload['uuid'] ?? ''));
            }
        }

        return '';
    }//end extractUuid()

    /**
     * Read a schema-slug hint off an OR row (for copyRowsFromSource's save call).
     *
     * @param mixed               $row     The OR row entry
     * @param array<string,mixed> $payload Pre-normalised payload of the row
     *
     * @return string|null Schema slug if available, else null
     */
    private function extractSchemaSlug(mixed $row, array $payload): ?string
    {
        $self = ($payload['@self'] ?? []);
        if (is_array($self) === true) {
            $schema = ($self['schema'] ?? null);
            if (is_string($schema) === true && $schema !== '') {
                return $schema;
            }
        }

        if (is_object($row) === true && method_exists($row, 'getSchema') === true) {
            try {
                // @phpstan-ignore-next-line method.notFound
                $hint = $row->getSchema();
                if (is_string($hint) === true && $hint !== '') {
                    return $hint;
                }
            } catch (Throwable $e) {
                $this->logger->debug('OpenBuilt: extractSchemaSlug fallthrough: '.$e->getMessage());
            }
        }

        return null;
    }//end extractSchemaSlug()

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

    /**
     * Reduce a mixed scalar/null to a non-empty string or null.
     *
     * @param mixed $value Arbitrary scalar
     *
     * @return string|null
     */
    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end stringOrNull()
}//end class
