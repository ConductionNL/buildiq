<?php

/**
 * OpenBuild AppOverrideService
 *
 * Store-and-serve for a fleet app's manifest customization. Since
 * unify-apps-with-app-type the customization is persisted in the UNIFIED app
 * model — a HYBRID `Application` (`appType: "hybrid"`, `slug` = the fleet
 * appId, `baseRef.id` = the fleet appId) plus a delta-only `ApplicationVersion`
 * (`manifest: {}`, `baseRef.kind: "fleet-app"`, `manifestDelta` = the keyed
 * structural delta) — NOT in the retired standalone `AppOverride` schema. The
 * service keeps its name and method shapes so the `/api/app-overrides/{appId}`
 * compatibility shim (AppOverrideController) and its tests are unchanged; only
 * the backing storage moved.
 *
 * It does NOT merge the delta — the merge runs client-side in the fleet app's
 * loader over its own bundled base, because OpenBuild does not hold fleet apps'
 * bundled manifests. The same delta-only ApplicationVersion is what the unified
 * Apps list renders as a hybrid app, so the in-app builder and the fleet-app
 * shim read and write one shared record.
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
 * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OR-backed store for fleet-app manifest overrides, on the unified app model.
 *
 * One hybrid Application per fleet `appId` (per-instance shared); the stored
 * delta is served back raw for a client-side merge; the write path validates
 * the delta shape and refuses an app-blanking delta (design D4).
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
 */
class AppOverrideService
{
    /**
     * OpenRegister register slug that hosts the OpenBuild control schemas.
     */
    public const REGISTER_SLUG = 'openbuild';

    /**
     * OpenRegister schema slug for the logical app record.
     */
    public const APPLICATION_SCHEMA = 'application';

    /**
     * OpenRegister schema slug for the deployable version record.
     */
    public const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

    /**
     * The fixed version slug used for a hybrid app's single production version.
     */
    private const HYBRID_VERSION_SLUG = 'production';

    /**
     * Constructor.
     *
     * @param ObjectService             $objectService  OpenRegister object service (hard dep via info.xml).
     * @param LoggerInterface           $logger         PSR logger for diagnostics.
     * @param IAppManager               $appManager     Resolves a fleet app's human-readable display name.
     * @param AppOverrideDeltaValidator $deltaValidator Stateless delta shape + blank-detection validator.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger,
        private readonly IAppManager $appManager,
        private readonly AppOverrideDeltaValidator $deltaValidator=new AppOverrideDeltaValidator(),
    ) {
    }//end __construct()

    /**
     * Find the delta record for a fleet app, if any, from the unified model.
     *
     * Resolves the hybrid Application for the appId, then its production
     * version, and returns a normalised record carrying the version's
     * `manifestDelta` under the same `manifestDelta` key the shim expects.
     *
     * @param string $appId The kebab-case Nextcloud app id (natural key).
     *
     * @return array<string, mixed>|null The record with a `manifestDelta` key, or null when none exists.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    public function findByAppId(string $appId): ?array
    {
        $version = $this->findHybridProductionVersion(appId: $appId);
        if ($version === null) {
            return null;
        }

        $delta = ($version['manifestDelta'] ?? []);
        if (is_array($delta) === false) {
            $delta = [];
        }

        return [
            'appId'         => $appId,
            'manifestDelta' => $delta,
        ];

    }//end findByAppId()

    /**
     * Create or update the hybrid app + delta-only version for a fleet app.
     *
     * Find-then-create-or-update keyed by `appId`: when a hybrid Application
     * already exists its production version's `manifestDelta` is updated in
     * place; otherwise a new hybrid Application + delta-only version are created
     * and linked via `productionVersion`.
     *
     * @param string               $appId         The kebab-case Nextcloud app id (natural key).
     * @param array<string, mixed> $delta         The keyed manifest delta (already shape-validated by the caller).
     * @param string|null          $baseRef       Optional JSON-encoded provenance note of the diffed-against base.
     * @param string               $updatedBy     The UID of the saving user (provenance).
     * @param bool                 $systemContext When true, write with OR RBAC + multitenancy bypassed — used by the
     *                                            migration repair step which runs as the Anonymous system user and so
     *                                            cannot satisfy the Application schema's create:[admin] guard. The HTTP
     *                                            shim leaves this false so the session user's RBAC applies.
     *
     * @return array<string, mixed> A normalised summary: appId, updatedBy, updatedAt, applicationUuid.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    public function upsert(string $appId, array $delta, ?string $baseRef, string $updatedBy, bool $systemContext=false): array
    {
        $now         = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $application = $this->findHybridApplication(appId: $appId);

        if ($application === null) {
            $applicationUuid = $this->createHybridApp(
                appId: $appId,
                delta: $delta,
                baseRef: $baseRef,
                systemContext: $systemContext
            );
        } else {
            $applicationUuid = $this->updateHybridVersionDelta(
                application: $application,
                delta: $delta,
                baseRef: $baseRef,
                systemContext: $systemContext
            );
        }

        return [
            'appId'           => $appId,
            'updatedBy'       => $updatedBy,
            'updatedAt'       => $now,
            'applicationUuid' => $applicationUuid,
        ];

    }//end upsert()

    /**
     * Remove the hybrid override for a fleet app (idempotent).
     *
     * Deletes the hybrid Application and its production version so the fleet app
     * reverts to its bundled manifest on the next manifest load. A missing
     * record is a no-op (no error).
     *
     * @param string $appId The kebab-case Nextcloud app id (natural key).
     *
     * @return void
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    public function delete(string $appId): void
    {
        $application = $this->findHybridApplication(appId: $appId);
        if ($application === null) {
            return;
        }

        $appUuid = $this->extractUuid(object: $application);

        // Delete the production version first (if resolvable), then the app.
        $version = $this->findHybridProductionVersion(appId: $appId, application: $application);
        if ($version !== null) {
            $versionUuid = $this->extractUuid(object: $version);
            if ($versionUuid !== '') {
                $this->deleteObjectSafely(uuid: $versionUuid, context: 'hybrid version for '.$appId);
            }
        }

        if ($appUuid !== '') {
            $this->deleteObjectSafely(uuid: $appUuid, context: 'hybrid application for '.$appId);
        }

    }//end delete()

    /**
     * Validate that a body is a well-formed keyed manifest delta.
     *
     * @param array<array-key, mixed> $delta The candidate delta body (may arrive list-shaped from a bad PUT).
     *
     * @return array<int, string> A list of violations; empty when the delta is valid.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    public function validateDeltaShape(array $delta): array
    {
        return $this->deltaValidator->validateDeltaShape(delta: $delta);

    }//end validateDeltaShape()

    /**
     * Detect a delta that resolves (over an empty base) to no renderable
     * pages/menu (an "app-blanking" delta).
     *
     * @param array<string, mixed> $delta The candidate delta body.
     *
     * @return bool True when the delta resolves (over an empty base) to no pages/menu.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    public function wouldBlankApp(array $delta): bool
    {
        return $this->deltaValidator->wouldBlankApp(delta: $delta);

    }//end wouldBlankApp()

    /**
     * Find the hybrid Application for a fleet appId, if any.
     *
     * The migration and the wizard both set a hybrid app's `slug` to the fleet
     * appId, so the natural key lookup filters on `slug` + `appType: hybrid`.
     *
     * @param string $appId The kebab-case Nextcloud app id (natural key).
     *
     * @return array<string, mixed>|null The normalised Application, or null when none exists.
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/app-override-persistence/spec.md
     */
    public function findHybridApplication(string $appId): ?array
    {
        try {
            $results = $this->objectService->searchObjectsBySlug(
                registerSlug: self::REGISTER_SLUG,
                schemaSlug: self::APPLICATION_SCHEMA,
                filters: [
                    'slug'    => $appId,
                    'appType' => 'hybrid',
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: hybrid Application lookup failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }

        if (is_array($results) === false || count($results) === 0) {
            return null;
        }

        return $this->normaliseObject(object: $results[0]);

    }//end findHybridApplication()

    /**
     * Resolve the production ApplicationVersion of the hybrid app for an appId.
     *
     * @param string                    $appId       The fleet app id (natural key).
     * @param array<string, mixed>|null $application Optional already-resolved Application (avoids a re-lookup).
     *
     * @return array<string, mixed>|null The normalised production version, or null when unresolvable.
     */
    private function findHybridProductionVersion(string $appId, ?array $application=null): ?array
    {
        if ($application === null) {
            $application = $this->findHybridApplication(appId: $appId);
        }

        if ($application === null) {
            return null;
        }

        $versionUuid = (string) ($application['productionVersion'] ?? '');
        if ($versionUuid === '') {
            return null;
        }

        try {
            $entity = $this->objectService->find(
                id: $versionUuid,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_VERSION_SCHEMA
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: hybrid production version lookup failed for appId '.$appId.': '.$e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->normaliseObject(object: $entity);

    }//end findHybridProductionVersion()

    /**
     * Create a fresh hybrid Application + delta-only production version.
     *
     * @param string               $appId         The fleet app id (natural key).
     * @param array<string, mixed> $delta         The keyed manifest delta.
     * @param string|null          $baseRef       Optional JSON-encoded provenance note.
     * @param bool                 $systemContext Bypass OR RBAC + multitenancy (migration/system writes).
     *
     * @return string The created Application's uuid (empty string when unresolved).
     */
    private function createHybridApp(string $appId, array $delta, ?string $baseRef, bool $systemContext=false): string
    {
        $baseRefObject = $this->buildBaseRef(appId: $appId, baseRef: $baseRef);
        $rbac          = ($systemContext === false);

        $application = $this->objectService->saveObject(
            object: [
                'slug'    => $appId,
                'name'    => $this->resolveAppName(appId: $appId),
                'appType' => 'hybrid',
                'baseRef' => $baseRefObject,
            ],
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_SCHEMA,
            _rbac: $rbac,
            _multitenancy: $rbac
        );

        $applicationData = $this->normaliseObject(object: $application);
        $applicationUuid = $this->extractUuid(object: $applicationData);

        $version = $this->objectService->saveObject(
            object: [
                'name'          => 'Production',
                'slug'          => self::HYBRID_VERSION_SLUG,
                'manifest'      => (object) [],
                'manifestDelta' => $delta,
                'baseRef'       => $baseRefObject,
                'register'      => self::REGISTER_SLUG.'-'.$appId,
                'semver'        => '0.1.0',
                'status'        => 'published',
                'application'   => $applicationUuid,
            ],
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_VERSION_SCHEMA,
            _rbac: $rbac,
            _multitenancy: $rbac
        );

        $versionData = $this->normaliseObject(object: $version);
        $versionUuid = $this->extractUuid(object: $versionData);

        // Point the Application's productionVersion at the new version. Use an
        // explicit assignment (NOT the `+` array-union, which keeps the existing
        // null productionVersion key and silently drops the link) and strip the
        // OR envelope so saveObject treats it as the object payload.
        if ($applicationUuid !== '' && $versionUuid !== '') {
            unset($applicationData['@self']);
            $applicationData['productionVersion'] = $versionUuid;
            $this->objectService->saveObject(
                object: $applicationData,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_SCHEMA,
                uuid: $applicationUuid,
                _rbac: $rbac,
                _multitenancy: $rbac
            );
        }

        return $applicationUuid;

    }//end createHybridApp()

    /**
     * Update the production version's delta of an existing hybrid app in place.
     *
     * @param array<string, mixed> $application   The resolved hybrid Application.
     * @param array<string, mixed> $delta         The new keyed manifest delta.
     * @param string|null          $baseRef       Optional JSON-encoded provenance note.
     * @param bool                 $systemContext Bypass OR RBAC + multitenancy (migration/system writes).
     *
     * @return string The hybrid Application's uuid (empty string when unresolved).
     */
    private function updateHybridVersionDelta(array $application, array $delta, ?string $baseRef, bool $systemContext=false): string
    {
        $appId   = (string) ($application['slug'] ?? '');
        $version = $this->findHybridProductionVersion(appId: $appId, application: $application);

        // No version yet (a half-created app) — create the version branch.
        if ($version === null) {
            return $this->createHybridApp(
                appId: $appId,
                delta: $delta,
                baseRef: $baseRef,
                systemContext: $systemContext
            );
        }

        $versionUuid = $this->extractUuid(object: $version);
        if ($versionUuid !== '') {
            $rbac = ($systemContext === false);

            $version['manifestDelta'] = $delta;
            $version['manifest']      = (object) ($version['manifest'] ?? []);
            $version['baseRef']       = $this->buildBaseRef(appId: $appId, baseRef: $baseRef);

            // Strip the OR envelope so saveObject treats it as the object payload.
            unset($version['@self']);

            $this->objectService->saveObject(
                object: $version,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_VERSION_SCHEMA,
                uuid: $versionUuid,
                _rbac: $rbac,
                _multitenancy: $rbac
            );
        }

        return $this->extractUuid(object: $application);

    }//end updateHybridVersionDelta()

    /**
     * Build the `baseRef` object for a hybrid app/version.
     *
     * Prefers a caller-supplied JSON-encoded provenance note; falls back to the
     * canonical `{ kind: fleet-app, id: appId }` link.
     *
     * @param string      $appId   The fleet app id.
     * @param string|null $baseRef Optional JSON-encoded provenance note.
     *
     * @return array<string, mixed> The baseRef object.
     */
    private function buildBaseRef(string $appId, ?string $baseRef): array
    {
        if ($baseRef !== null && $baseRef !== '') {
            $decoded = json_decode($baseRef, true);
            if (is_array($decoded) === true && ($decoded['id'] ?? null) !== null) {
                $decoded['kind'] = ($decoded['kind'] ?? 'fleet-app');
                return $decoded;
            }
        }

        return ['kind' => 'fleet-app', 'id' => $appId];

    }//end buildBaseRef()

    /**
     * Resolve a fleet app's human-readable display name, with a safe fallback.
     *
     * @param string $appId The fleet app id.
     *
     * @return string The display name, or a humanised fallback when unknown.
     */
    private function resolveAppName(string $appId): string
    {
        try {
            $name = $this->appManager->getAppInfo($appId)['name'] ?? null;
            if (is_string($name) === true && $name !== '') {
                return $name;
            }
        } catch (Throwable) {
            // Fall through to the humanised fallback.
        }

        return ucfirst(str_replace('-', ' ', $appId));

    }//end resolveAppName()

    /**
     * Delete an OR object by uuid, swallowing + logging failures (idempotent).
     *
     * @param string $uuid    The object uuid.
     * @param string $context A short human context for diagnostics.
     *
     * @return void
     */
    private function deleteObjectSafely(string $uuid, string $context): void
    {
        try {
            $this->objectService->deleteObject(uuid: $uuid);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: failed to delete '.$context.': '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end deleteObjectSafely()

    /**
     * Read the canonical uuid from a normalised object payload.
     *
     * @param array<string, mixed> $object The normalised object.
     *
     * @return string The uuid, or an empty string when absent.
     */
    private function extractUuid(array $object): string
    {
        $uuid = ($object['id'] ?? ($object['uuid'] ?? ($object['@self']['id'] ?? null)));
        if (is_string($uuid) === true) {
            return $uuid;
        }

        return '';

    }//end extractUuid()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
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
