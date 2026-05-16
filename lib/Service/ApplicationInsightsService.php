<?php

/**
 * OpenBuilt ApplicationInsightsService
 *
 * Computes the four KPI scalars + activity timeline rendered by the
 * Application detail-page maintainer dashboard (spec
 * `openbuilt-app-detail-overview`, capability `application-insights`).
 *
 * Responsibilities:
 *   - Resolve the Application + ApplicationVersion records (IDOR-safe).
 *   - Enforce the RBAC gate (REQ-OBAI-002 — same shape as
 *     ManifestResolverService): viewer-or-better for production,
 *     editor-or-better for non-production. Nextcloud admins are NOT
 *     auto-granted.
 *   - Walk `manifest.pages[].config.{register,schema}` to derive the
 *     schema-set scoped to the version's per-version register
 *     (`openbuilt-{appSlug}-{versionSlug}`).
 *   - Fan out four KPI calls + one chart call to OpenRegister mappers /
 *     services and assemble the response payload.
 *
 * Per ADR-031 §Exceptions: cross-table aggregations that fan across
 * schemas are imperative work, not schema-declarative. The RBAC gate is
 * a cross-cutting service concern (mirrors ManifestResolverService).
 *
 * Defensive `method_exists` guards on the OR `AuditTrailMapper` are used
 * for `getDistinctActorCount` (delivered by
 * `openregister-distinct-actor-aggregation`). When that floor change has
 * not yet landed, the Active-users KPI degrades to `0` rather than
 * 500-ing the whole endpoint.
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

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Insights aggregation for an ApplicationVersion's per-version register.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApplicationInsightsService
{
    /**
     * Allowed `window` query parameter values (REQ-OBAI-001).
     *
     * @var array<int, string>
     */
    public const ALLOWED_WINDOWS = ['7d', '30d', '90d'];

    /**
     * Window-to-hours mapping (REQ-OBAI-004).
     *
     * @var array<string, int>
     */
    private const WINDOW_HOURS = [
        '7d'  => 168,
        '30d' => 720,
        '90d' => 2160,
    ];

    /**
     * Schema slug for Application records.
     *
     * @var string
     */
    private const APPLICATION_SCHEMA = 'application';

    /**
     * Schema slug for ApplicationVersion records.
     *
     * @var string
     */
    private const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

    /**
     * Shared register slug carrying Application + ApplicationVersion rows.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'openbuilt';

    /**
     * Constructor.
     *
     * Per ADR-022 — no app-local DB access; everything flows through
     * OpenRegister abstractions.
     *
     * @param ObjectService    $objectService    OR object surface
     * @param AuditTrailMapper $auditTrailMapper Audit-trail aggregations (chart + actors + counts)
     * @param LoggerInterface  $logger           PSR logger
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve + authorise the (Application, Version, caller) tuple.
     *
     * Returns `[$application, $version]` on success, or `null` on any 404
     * mode (unknown app, unknown version, IDOR mismatch, RBAC denial).
     *
     * Public so the controller can call it explicitly as a guard step —
     * hydra gate-7 (no-admin-idor) expects a `require*` / `authorize*` /
     * `ensure*` / `check*` call in the controller method body alongside
     * the `#[NoAdminRequired]` annotation, even when the same logic also
     * lives inside the service layer.
     *
     * @param string     $appUuid     Application UUID.
     * @param string     $versionUuid ApplicationVersion UUID.
     * @param IUser|null $caller      The authenticated user, or null for unauthenticated.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public function requireAuthorisedCaller(
        string $appUuid,
        string $versionUuid,
        ?IUser $caller
    ): ?array {
        $application = $this->loadApplication(uuid: $appUuid);
        if ($application === null) {
            return null;
        }

        $version = $this->loadVersion(uuid: $versionUuid);
        if ($version === null) {
            return null;
        }

        if ($this->versionBelongsToApplication(version: $version, applicationUuid: $appUuid) === false) {
            return null;
        }

        if ($this->isAuthorised(application: $application, version: $version, caller: $caller) === false) {
            return null;
        }

        return [$application, $version];
    }//end requireAuthorisedCaller()

    /**
     * Compute the insights payload for a given Application + Version + window.
     *
     * Returns `null` for any failure mode the caller maps to 404 (IDOR-safe
     * — no existence leak):
     *   - Unknown `appUuid`
     *   - Unknown `versionUuid`
     *   - Version whose `application` relation does not point at `appUuid`
     *   - RBAC failure (viewer-on-non-production, no role at all on production)
     *
     * Returns `null` for an invalid `window` value (caller is expected to
     * pre-validate at the controller layer; the defensive check here keeps
     * the service safe in isolation).
     *
     * @param string     $appUuid     Application UUID (path parameter).
     * @param string     $versionUuid ApplicationVersion UUID (path parameter).
     * @param string     $window      Window string — one of `7d`, `30d`, `90d`.
     * @param IUser|null $caller      The authenticated user, or null for unauthenticated.
     *
     * @return array<string, mixed>|null Insights payload `{kpis, activity}` or null on 404.
     */
    public function computeInsights(
        string $appUuid,
        string $versionUuid,
        string $window,
        ?IUser $caller
    ): ?array {
        if (in_array($window, self::ALLOWED_WINDOWS, true) === false) {
            return null;
        }

        try {
            $resolved = $this->requireAuthorisedCaller(
                appUuid: $appUuid,
                versionUuid: $versionUuid,
                caller: $caller
            );
            if ($resolved === null) {
                return null;
            }

            [$application, $version] = $resolved;

            $appSlug      = (string) ($application['slug'] ?? '');
            $versionSlug  = (string) ($version['slug'] ?? '');
            $registerSlug = sprintf('openbuilt-%s-%s', $appSlug, $versionSlug);

            $manifest  = $this->extractManifest(version: $version);
            $schemaIds = $this->deriveSchemaIds(manifest: $manifest, registerSlug: $registerSlug);

            $hours = self::WINDOW_HOURS[$window];

            $kpis = [
                'activeUsers'     => $this->safeDistinctActorCount(schemaIds: $schemaIds, hours: $hours),
                'objectCount'     => $this->countObjects(schemaIds: $schemaIds, registerSlug: $registerSlug),
                'filesCount'      => $this->countAttachedFiles(registerSlug: $registerSlug, schemaIds: $schemaIds),
                'auditEventCount' => $this->countAuditEvents(schemaIds: $schemaIds, hours: $hours),
            ];

            $activity = $this->buildActivityTimeline(schemaIds: $schemaIds, hours: $hours, registerSlug: $registerSlug);

            return [
                'kpis'     => $kpis,
                'activity' => $activity,
            ];
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuilt: ApplicationInsightsService::computeInsights failed: {message}',
                ['message' => $e->getMessage(), 'exception' => $e]
            );
            return null;
        }//end try
    }//end computeInsights()

    /**
     * Derive the schema-set for the version per REQ-OBAI-003.
     *
     * Walks `manifest.pages[].config.{register,schema}`, filters to the
     * version's own per-version register, and uniques by schema id.
     *
     * Public so it can be unit-tested in isolation.
     *
     * @param array<string, mixed>|null $manifest     The version's manifest payload (null tolerated).
     * @param string                    $registerSlug The version's per-version register slug.
     *
     * @return array<int, string> Unique schema IDs (string form — OR stores audit schema column as VARCHAR).
     */
    public function deriveSchemaIds(?array $manifest, string $registerSlug): array
    {
        if ($manifest === null) {
            return [];
        }

        $pages = ($manifest['pages'] ?? null);
        if (is_array($pages) === false) {
            return [];
        }

        $schemaIds = [];
        foreach ($pages as $page) {
            $schemaId = $this->extractSchemaIdForRegister(page: $page, registerSlug: $registerSlug);
            if ($schemaId === null) {
                continue;
            }

            $schemaIds[$schemaId] = true;
        }//end foreach

        return array_keys($schemaIds);
    }//end deriveSchemaIds()

    /**
     * Extract a schema ID from a manifest page entry IF the entry's
     * `config.register` matches the supplied register slug AND
     * `config.schema` is a non-empty string. Returns null otherwise.
     *
     * Split out from {@see deriveSchemaIds()} to keep that method below
     * PHPMD's cyclomatic-complexity threshold.
     *
     * @param mixed  $page         The manifest page entry (or non-array junk).
     * @param string $registerSlug The version's per-version register slug.
     *
     * @return string|null The schema ID, or null when the page does not match.
     */
    private function extractSchemaIdForRegister(mixed $page, string $registerSlug): ?string
    {
        if (is_array($page) === false) {
            return null;
        }

        $config = ($page['config'] ?? null);
        if (is_array($config) === false) {
            return null;
        }

        $pageRegister = ($config['register'] ?? null);
        if (is_string($pageRegister) === false || $pageRegister !== $registerSlug) {
            return null;
        }

        $pageSchema = ($config['schema'] ?? null);
        if (is_string($pageSchema) === false || $pageSchema === '') {
            return null;
        }

        return $pageSchema;
    }//end extractSchemaIdForRegister()

    /**
     * Apply the RBAC gate per REQ-OBAI-002.
     *
     * Production version: viewer-or-better required.
     * Non-production: editor-or-better required.
     * Nextcloud admins are NOT auto-granted.
     *
     * @param array<string, mixed> $application The Application record.
     * @param array<string, mixed> $version     The ApplicationVersion record.
     * @param IUser|null           $caller      The authenticated user.
     *
     * @return bool True when authorised, false otherwise.
     */
    private function isAuthorised(array $application, array $version, ?IUser $caller): bool
    {
        if ($caller === null) {
            return false;
        }

        $prodUuid     = $this->extractProductionVersionUuid(application: $application);
        $resolvedUuid = (string) ($version['uuid'] ?? $version['id'] ?? '');
        $isProduction = $prodUuid !== '' && $resolvedUuid === $prodUuid;

        $permissions = ($application['permissions'] ?? []);
        if (is_array($permissions) === false) {
            return false;
        }

        $callerUid = $caller->getUID();

        if ($isProduction === true) {
            return $this->callerInAnyRole(
                permissions: $permissions,
                callerUid: $callerUid,
                roles: ['owners', 'editors', 'viewers']
            );
        }

        return $this->callerInAnyRole(
            permissions: $permissions,
            callerUid: $callerUid,
            roles: ['owners', 'editors']
        );
    }//end isAuthorised()

    /**
     * Check whether the caller appears in any of the named permission roles.
     *
     * Matches both `user:<uid>` and bare `<uid>` entries for backwards-compat
     * with pre-RBAC-canonicalisation manifests (mirrors VersionPromotionService).
     *
     * @param array<string, mixed> $permissions The Application's permissions block.
     * @param string               $callerUid   The caller's NC UID.
     * @param array<int, string>   $roles       Roles to check (e.g. ['owners', 'editors']).
     *
     * @return bool True when the caller is found in any of the listed buckets.
     */
    private function callerInAnyRole(array $permissions, string $callerUid, array $roles): bool
    {
        foreach ($roles as $role) {
            $bucket = ($permissions[$role] ?? []);
            if (is_array($bucket) === false) {
                continue;
            }

            foreach ($bucket as $principal) {
                if (is_string($principal) === false || $principal === '') {
                    continue;
                }

                if ($principal === 'user:'.$callerUid || $principal === $callerUid) {
                    return true;
                }
            }
        }

        return false;
    }//end callerInAnyRole()

    /**
     * Load the Application record by UUID via OR's ObjectService.
     *
     * @param string $uuid Application UUID.
     *
     * @return array<string, mixed>|null
     */
    private function loadApplication(string $uuid): ?array
    {
        try {
            $entity = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_SCHEMA
            );

            if ($entity === null) {
                return null;
            }

            return $this->normaliseObject(object: $entity);
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuilt: ApplicationInsightsService::loadApplication failed for uuid={uuid}: {message}',
                ['uuid' => $uuid, 'message' => $e->getMessage()]
            );
            return null;
        }
    }//end loadApplication()

    /**
     * Load the ApplicationVersion record by UUID via OR's ObjectService.
     *
     * @param string $uuid ApplicationVersion UUID.
     *
     * @return array<string, mixed>|null
     */
    private function loadVersion(string $uuid): ?array
    {
        try {
            $entity = $this->objectService->find(
                id: $uuid,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_VERSION_SCHEMA
            );

            if ($entity === null) {
                return null;
            }

            return $this->normaliseObject(object: $entity);
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuilt: ApplicationInsightsService::loadVersion failed for uuid={uuid}: {message}',
                ['uuid' => $uuid, 'message' => $e->getMessage()]
            );
            return null;
        }
    }//end loadVersion()

    /**
     * Verify an ApplicationVersion's `application` relation points at the
     * expected Application UUID (IDOR guard).
     *
     * @param array<string, mixed> $version         The version record.
     * @param string               $applicationUuid The expected parent UUID.
     *
     * @return bool
     */
    private function versionBelongsToApplication(array $version, string $applicationUuid): bool
    {
        $relation = ($version['application'] ?? null);

        if (is_string($relation) === true) {
            return $relation === $applicationUuid;
        }

        if (is_array($relation) === true) {
            $relUuid = (string) ($relation['uuid'] ?? $relation['id'] ?? '');
            return $relUuid === $applicationUuid;
        }

        return false;
    }//end versionBelongsToApplication()

    /**
     * Extract the productionVersion UUID from an Application record.
     *
     * @param array<string, mixed> $application Application data.
     *
     * @return string The UUID, empty string when not determinable.
     */
    private function extractProductionVersionUuid(array $application): string
    {
        $productionVersion = ($application['productionVersion'] ?? null);

        if (is_string($productionVersion) === true) {
            return $productionVersion;
        }

        if (is_array($productionVersion) === true) {
            return (string) ($productionVersion['uuid'] ?? $productionVersion['id'] ?? '');
        }

        return '';
    }//end extractProductionVersionUuid()

    /**
     * Pull the manifest payload off an ApplicationVersion record.
     *
     * @param array<string, mixed> $version Version record.
     *
     * @return array<string, mixed>|null
     */
    private function extractManifest(array $version): ?array
    {
        $manifest = ($version['manifest'] ?? null);
        if (is_array($manifest) === true) {
            return $manifest;
        }

        return null;
    }//end extractManifest()

    /**
     * Active-users KPI: distinct actor UIDs in audit-trail rows scoped to
     * the schema-set within the window (REQ-OBAI-004).
     *
     * Defensively `method_exists` guarded so the controller keeps a 200
     * response (with `activeUsers: 0`) when running against an OR floor
     * that has not yet landed the `openregister-distinct-actor-aggregation`
     * change.
     *
     * @param array<int, string> $schemaIds Unique schema IDs.
     * @param int                $hours     Window hours.
     *
     * @return int Distinct actor count, or 0 when the aggregation API is unavailable.
     */
    private function safeDistinctActorCount(array $schemaIds, int $hours): int
    {
        if (empty($schemaIds) === true) {
            return 0;
        }

        if (method_exists($this->auditTrailMapper, 'getDistinctActorCount') === false) {
            $this->logger->debug(
                'OpenBuilt: getDistinctActorCount not available on AuditTrailMapper — '
                .'degrade to 0 (depends on openregister-distinct-actor-aggregation)'
            );
            return 0;
        }

        try {
            return (int) $this->auditTrailMapper->getDistinctActorCount(array_map('intval', $schemaIds), $hours);
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuilt: getDistinctActorCount failed: {message}',
                ['message' => $e->getMessage()]
            );
            return 0;
        }
    }//end safeDistinctActorCount()

    /**
     * Object-count KPI: sum of `count()` across each schema in the
     * schema-set (REQ-OBAI-004).
     *
     * Per OR's ObjectService::count() signature, we pass register +
     * schema via the config array. Schema-set may be empty (returns 0).
     *
     * @param array<int, string> $schemaIds    Unique schema IDs (strings — coerced as needed).
     * @param string             $registerSlug The version's register slug.
     *
     * @return int Total object count across the schema-set.
     */
    private function countObjects(array $schemaIds, string $registerSlug): int
    {
        if (empty($schemaIds) === true) {
            return 0;
        }

        $total = 0;
        foreach ($schemaIds as $schemaId) {
            try {
                $this->objectService->setRegister($registerSlug);
                $this->objectService->setSchema($schemaId);

                $total += (int) $this->objectService->count();
            } catch (Throwable $e) {
                // Per-schema failure should not kill the aggregate — log and continue.
                $this->logger->debug(
                    'OpenBuilt: count for schema={schemaId} on register={register} failed: {message}',
                    ['schemaId' => $schemaId, 'register' => $registerSlug, 'message' => $e->getMessage()]
                );
                continue;
            }
        }

        return $total;
    }//end countObjects()

    /**
     * Files-count KPI: count of OR-attached files across all objects in
     * the version's register (REQ-OBAI-004 v1 proxy for storage).
     *
     * No first-class OR aggregation exists today; we walk OR's audit
     * trail for `file.attach` actions on the schema-set as a defensive
     * fallback. The result is a v1 proxy — when the canonical
     * `FileService::countAttachedFilesForRegister` lands we should swap
     * the implementation in place without changing the spec contract.
     *
     * Returns 0 when the schema-set is empty.
     *
     * @param string             $registerSlug The version's register slug (reserved for future use).
     * @param array<int, string> $schemaIds    Unique schema IDs.
     *
     * @return int File count.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function countAttachedFiles(string $registerSlug, array $schemaIds): int
    {
        if (empty($schemaIds) === true) {
            return 0;
        }

        if (method_exists($this->auditTrailMapper, 'getStatisticsGroupedBySchema') === false) {
            return 0;
        }

        try {
            $stats = $this->auditTrailMapper->getStatisticsGroupedBySchema(array_map('intval', $schemaIds));

            $total = 0;
            foreach ($stats as $row) {
                if (is_array($row) === false) {
                    continue;
                }

                $total += (int) ($row['size'] ?? 0);
            }

            return $total;
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuilt: countAttachedFiles fallback failed: {message}',
                ['message' => $e->getMessage()]
            );
            return 0;
        }
    }//end countAttachedFiles()

    /**
     * Audit-events KPI: total audit-trail rows scoped to the schema-set
     * within the window (REQ-OBAI-004).
     *
     * Uses `getActionChartData` summed across actions when a dedicated
     * `countByRegisterAndWindow` is unavailable on the OR floor (today
     * it is unavailable; this method becomes a one-liner when it lands).
     *
     * @param array<int, string> $schemaIds Unique schema IDs.
     * @param int                $hours     Window hours.
     *
     * @return int Audit-event count.
     */
    private function countAuditEvents(array $schemaIds, int $hours): int
    {
        if (empty($schemaIds) === true) {
            return 0;
        }

        if (method_exists($this->auditTrailMapper, 'countByRegisterAndWindow') === true) {
            try {
                return (int) $this->auditTrailMapper->countByRegisterAndWindow(array_map('intval', $schemaIds), $hours);
            } catch (Throwable $e) {
                $this->logger->debug(
                    'OpenBuilt: countByRegisterAndWindow failed: {message}',
                    ['message' => $e->getMessage()]
                );
                return 0;
            }
        }

        // Fallback: sum chart rows.
        try {
            $from = new DateTime(sprintf('-%d hours', $hours));
            $till = new DateTime();

            $total = 0;
            foreach ($schemaIds as $schemaId) {
                $chart  = $this->auditTrailMapper->getActionChartData($from, $till, null, (int) $schemaId);
                $total += $this->sumChartSeries($chart);
            }

            return $total;
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuilt: audit-event fallback failed: {message}',
                ['message' => $e->getMessage()]
            );
            return 0;
        }//end try
    }//end countAuditEvents()

    /**
     * Sum every numeric data point in every series of a getActionChartData
     * payload. Defensively typed — accepts arbitrary input and returns 0
     * when any expected key is missing.
     *
     * Split out from {@see countAuditEvents()} to keep that method below
     * PHPMD's cyclomatic-complexity threshold.
     *
     * @param mixed $chart The chart payload.
     *
     * @return int
     */
    private function sumChartSeries(mixed $chart): int
    {
        if (is_array($chart) === false) {
            return 0;
        }

        $series = ($chart['series'] ?? []);
        if (is_array($series) === false) {
            return 0;
        }

        $total = 0;
        foreach ($series as $seriesEntry) {
            if (is_array($seriesEntry) === false) {
                continue;
            }

            $data = ($seriesEntry['data'] ?? []);
            if (is_array($data) === false) {
                continue;
            }

            foreach ($data as $count) {
                $total += (int) $count;
            }
        }

        return $total;
    }//end sumChartSeries()

    /**
     * Activity timeline (REQ-OBAI-005): one bucket per (date, total-events)
     * pair sourced from `AuditTrailMapper::getActionChartData`.
     *
     * Returns an empty array when the schema-set is empty.
     *
     * @param array<int, string> $schemaIds    Unique schema IDs.
     * @param int                $hours        Window hours.
     * @param string             $registerSlug The register slug (reserved for future use).
     *
     * @return array<int, array{timestamp: string, eventCount: int}>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @psalm-suppress UnusedParam
     */
    private function buildActivityTimeline(array $schemaIds, int $hours, string $registerSlug): array
    {
        if (empty($schemaIds) === true) {
            return [];
        }

        try {
            $from = new DateTime(sprintf('-%d hours', $hours));
            $till = new DateTime();

            $merged = [];
            foreach ($schemaIds as $schemaId) {
                $chart = $this->auditTrailMapper->getActionChartData(
                    $from,
                    $till,
                    null,
                    (int) $schemaId
                );

                $this->mergeChartIntoBuckets(chart: $chart, buckets: $merged);
            }

            ksort($merged);

            $timeline = [];
            foreach ($merged as $date => $count) {
                $timeline[] = [
                    'timestamp'  => sprintf('%sT00:00:00Z', $date),
                    'eventCount' => (int) $count,
                ];
            }

            return $timeline;
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuilt: buildActivityTimeline failed: {message}',
                ['message' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end buildActivityTimeline()

    /**
     * Merge one `getActionChartData` payload's series rows into the
     * accumulating date-keyed bucket map.
     *
     * @param mixed              $chart   The chart payload (or anything else —
     *                                    defensively typed).
     * @param array<string, int> $buckets Accumulator: date string → total event
     *                                    count.
     *
     * @return void
     */
    private function mergeChartIntoBuckets(mixed $chart, array &$buckets): void
    {
        if (is_array($chart) === false) {
            return;
        }

        $labels = ($chart['labels'] ?? []);
        $series = ($chart['series'] ?? []);
        if (is_array($labels) === false || is_array($series) === false) {
            return;
        }

        foreach ($series as $seriesEntry) {
            $this->mergeSeriesData(seriesEntry: $seriesEntry, labels: $labels, buckets: $buckets);
        }
    }//end mergeChartIntoBuckets()

    /**
     * Add one chart-series' `data[]` rows into the accumulating bucket map.
     *
     * Split out from {@see mergeChartIntoBuckets()} to keep that method
     * below PHPMD's cyclomatic-complexity threshold.
     *
     * @param mixed              $seriesEntry The series entry (or non-array junk).
     * @param array<int, mixed>  $labels      Label list parallel to series data.
     * @param array<string, int> $buckets     Accumulator (mutated by reference).
     *
     * @return void
     */
    private function mergeSeriesData(mixed $seriesEntry, array $labels, array &$buckets): void
    {
        if (is_array($seriesEntry) === false) {
            return;
        }

        $data = ($seriesEntry['data'] ?? []);
        if (is_array($data) === false) {
            return;
        }

        foreach ($data as $idx => $count) {
            $label = ($labels[$idx] ?? null);
            if (is_string($label) === false || $label === '') {
                continue;
            }

            $buckets[$label] = ($buckets[$label] ?? 0) + (int) $count;
        }
    }//end mergeSeriesData()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to a plain assoc array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string, mixed>
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
