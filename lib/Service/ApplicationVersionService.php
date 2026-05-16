<?php

/**
 * OpenBuilt ApplicationVersionService
 *
 * Owns the imperative business logic for the versioned-app model
 * (ADR-002 / openbuilt-versioning-model):
 *
 *   - Semver auto-bump on manifest content change (SHA-256 hash diff
 *     over the canonicalised manifest; ADR-031 §Exceptions(2) — stateful
 *     diff outside OR's calc vocabulary).
 *   - `promotesTo` cross-row cycle detection (ADR-031 §Exceptions(1) —
 *     traversal that OR's per-row x-openregister-validation cannot
 *     perform).
 *   - `Application.productionVersion` back-reference integrity guard
 *     (ADR-031 §Exceptions(1) — cross-row).
 *   - Version-deletion strategy branching (`delete-now`,
 *     `orphan-grace`, `keep-register`) — three branching side-effect
 *     chains conditional on a query param, outside the declarative
 *     `on_delete` vocabulary.
 *
 * All persistence flows through OpenRegister abstractions per ADR-022.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Imperative business-logic surface for ApplicationVersion (ADR-002).
 *
 * Note: this service intentionally owns only the surface that ADR-031's
 * declarative-vs-imperative table classifies as imperative. Lifecycle
 * transitions, route upserts on publish, and the same-row promotesTo
 * self-loop check are declared in `lib/Settings/openbuilt_register.json`.
 */
class ApplicationVersionService
{
    /**
     * Shared register that hosts both Application and ApplicationVersion.
     */
    public const REGISTER_SLUG = 'openbuilt';

    /**
     * Schema slug of the parent Application object.
     */
    public const APPLICATION_SCHEMA = 'application';

    /**
     * Schema slug of the versioned-model ApplicationVersion object.
     */
    public const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

    /**
     * Hard cap on the `promotesTo` chain walk in {@see guardNoCycle()}.
     * Prevents runaway traversal on data corruption (spec REQ-OBV-104).
     */
    private const CYCLE_GUARD_HOPS = 100;

    /**
     * Initial semver assigned to a freshly-created ApplicationVersion
     * (spec REQ-OBV-102).
     */
    public const INITIAL_SEMVER = '0.1.0';

    /**
     * Valid strategy values accepted by {@see deleteVersion()}.
     *
     * @var array<int,string>
     */
    private const VALID_STRATEGIES = [
        self::STRATEGY_DELETE_NOW,
        self::STRATEGY_ORPHAN_GRACE,
        self::STRATEGY_KEEP_REGISTER,
    ];

    /**
     * Strategy: drop the per-version register and the ApplicationVersion row.
     */
    public const STRATEGY_DELETE_NOW = 'delete-now';

    /**
     * Strategy: mark the per-version register orphaned; drop only the row.
     */
    public const STRATEGY_ORPHAN_GRACE = 'orphan-grace';

    /**
     * Strategy: leave the register intact; drop only the row.
     */
    public const STRATEGY_KEEP_REGISTER = 'keep-register';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger          PSR logger for diagnostics
     * @param ObjectService   $objectService   OpenRegister object service
     * @param RegisterService $registerService OpenRegister register-level service
     * @param RegisterMapper  $registerMapper  Resolves register slugs to entities
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterService $registerService,
        private readonly RegisterMapper $registerMapper,
    ) {
    }//end __construct()

    /**
     * Produce a canonical JSON string for the given manifest blob.
     *
     * Recursively sorts associative arrays by key so the resulting
     * string is byte-equal for any reordering of input keys. Encoded
     * without whitespace and with `JSON_THROW_ON_ERROR` so invalid
     * structures surface immediately. List arrays (numeric, ordered)
     * are preserved verbatim — order is part of the manifest's
     * semantic meaning (pages, menu, columns).
     *
     * @param array<string,mixed> $manifest The manifest blob
     *
     * @return string Canonical JSON
     *
     * @throws \JsonException When the structure contains non-encodable values
     */
    public function canonicaliseManifest(array $manifest): string
    {
        return json_encode(
            $this->canonicaliseValue(value: $manifest),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }//end canonicaliseManifest()

    /**
     * Return the SHA-256 hex digest of the canonicalised manifest.
     *
     * @param array<string,mixed> $manifest The manifest blob
     *
     * @return string 64-char lowercase hexadecimal digest
     *
     * @throws \JsonException When the manifest contains non-encodable values
     */
    public function hashManifest(array $manifest): string
    {
        return hash(algo: 'sha256', data: $this->canonicaliseManifest(manifest: $manifest));
    }//end hashManifest()

    /**
     * Patch-bump a semver string (X.Y.Z → X.Y.(Z+1)), dropping any
     * pre-release / build-metadata suffix on the way through.
     *
     * @param string $semver The current semver string
     *
     * @return string The patch-bumped semver
     *
     * @throws RuntimeException When the input is not a recognisable semver
     */
    public function bumpPatch(string $semver): string
    {
        $matches = [];
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $semver, $matches) !== 1) {
            throw new RuntimeException(message: sprintf('Invalid semver string "%s" — cannot bump.', $semver));
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        return sprintf('%d.%d.%d', $major, $minor, $patch + 1);
    }//end bumpPatch()

    /**
     * Apply the semver auto-bump rule (spec REQ-OBV-103) to a pending save.
     *
     * Given the previously-persisted state (`$current`, may be null on
     * create) and the candidate state (`$next` — mutated in place when a
     * bump is required), this method:
     *
     *   - Computes the manifest hash of `$next`.
     *   - Compares with `$current`'s stored `manifestHash` (mapper-internal
     *     bookkeeping; not part of the public schema). When different,
     *     `$next.semver` is patch-bumped and `$next.manifestHash` is set
     *     to the new hash.
     *   - On a brand-new row (no `$current`), `$next.semver` defaults to
     *     `0.1.0` when absent and `$next.manifestHash` is initialised.
     *
     * @param array<string,mixed>|null $current The persisted state, or null on create
     * @param array<string,mixed>      $next    The candidate next state (mutated in place)
     *
     * @return array<string,mixed> The mutated `$next` array
     *
     * @throws \JsonException When the manifest cannot be canonicalised
     */
    public function onSave(?array $current, array $next): array
    {
        $manifest = $next['manifest'] ?? null;
        if (is_array($manifest) === false) {
            // No manifest yet — nothing to hash or bump.
            return $next;
        }

        $newHash = $this->hashManifest(manifest: $manifest);

        if ($current === null) {
            // CREATE path — default the initial semver and stamp the hash.
            if (isset($next['semver']) === false || (string) $next['semver'] === '') {
                $next['semver'] = self::INITIAL_SEMVER;
            }

            $next['manifestHash'] = $newHash;
            return $next;
        }

        $oldHash = $current['manifestHash'] ?? null;

        if ((string) $oldHash === (string) $newHash) {
            // Metadata-only edit — preserve the existing semver / hash.
            $next['manifestHash'] = $oldHash;
            if (isset($next['semver']) === false || (string) $next['semver'] === '') {
                $next['semver'] = (string) ($current['semver'] ?? self::INITIAL_SEMVER);
            }

            return $next;
        }

        // Manifest content has changed — patch-bump and stamp the new hash.
        $next['semver']       = $this->bumpPatch(semver: (string) ($current['semver'] ?? self::INITIAL_SEMVER));
        $next['manifestHash'] = $newHash;
        return $next;
    }//end onSave()

    /**
     * Reject a `promotesTo` assignment that would form a cycle (spec REQ-OBV-104).
     *
     * Walks `promotesTo` forward from the proposed target up to
     * {@see self::CYCLE_GUARD_HOPS} hops. Throws when the current row's
     * UUID is encountered along the walk (cycle), when the proposed
     * target itself equals the current row's UUID (self-loop), or when
     * the hop cap is exceeded (chain corruption).
     *
     * @param string      $currentUuid        UUID of the row being saved
     * @param string|null $proposedTargetUuid Proposed `promotesTo` value
     *
     * @return void
     *
     * @throws RuntimeException When a cycle is detected or the cap is exceeded
     */
    public function guardNoCycle(string $currentUuid, ?string $proposedTargetUuid): void
    {
        if ($proposedTargetUuid === null || $proposedTargetUuid === '') {
            return;
        }

        if ($proposedTargetUuid === $currentUuid) {
            throw new RuntimeException(
                message: sprintf('promotesTo cycle: ApplicationVersion %s cannot promote to itself.', $currentUuid)
            );
        }

        $cursor = $proposedTargetUuid;
        $hops   = 0;
        while ($cursor !== null && $cursor !== '') {
            if ($hops >= self::CYCLE_GUARD_HOPS) {
                throw new RuntimeException(
                    message: sprintf(
                        'promotesTo chain exceeded %d hops starting from %s — chain corrupted, aborting cycle check.',
                        self::CYCLE_GUARD_HOPS,
                        $proposedTargetUuid
                    )
                );
            }

            if ($cursor === $currentUuid) {
                throw new RuntimeException(
                    message: sprintf(
                        'promotesTo cycle: setting promotesTo on %s would loop back through %s.',
                        $currentUuid,
                        $proposedTargetUuid
                    )
                );
            }

            $hops++;
            $cursor = $this->resolveNextPromotesTo(versionUuid: $cursor);
        }//end while
    }//end guardNoCycle()

    /**
     * Verify that `Application.productionVersion`'s back-reference is sound (REQ-OBV-105).
     *
     * Reads the proposed ApplicationVersion and asserts that its
     * `application` relation points back at the parent Application's
     * UUID. Throws otherwise — the caller surfaces a 422 to the client.
     *
     * @param string $applicationUuid     UUID of the parent Application being saved
     * @param string $proposedVersionUuid UUID proposed as `productionVersion`
     *
     * @return void
     *
     * @throws RuntimeException When the back-reference does not point at the parent
     */
    public function guardProductionVersionOwnership(string $applicationUuid, string $proposedVersionUuid): void
    {
        $version = $this->objectService->find(
            id: $proposedVersionUuid,
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_VERSION_SCHEMA
        );

        if ($version === null) {
            throw new RuntimeException(
                message: sprintf(
                    'productionVersion %s does not exist — cannot be assigned to Application %s.',
                    $proposedVersionUuid,
                    $applicationUuid
                )
            );
        }

        $data          = $this->normaliseObjectArray(object: $version);
        $backReference = (string) ($data['application'] ?? '');

        if ($backReference !== $applicationUuid) {
            $displayBack = $backReference;
            if ($displayBack === '') {
                $displayBack = '(unset)';
            }

            throw new RuntimeException(
                message: sprintf(
                    'productionVersion %s belongs to Application %s, not %s — back-reference mismatch.',
                    $proposedVersionUuid,
                    $displayBack,
                    $applicationUuid
                )
            );
        }
    }//end guardProductionVersionOwnership()

    /**
     * Delete an ApplicationVersion using the named strategy (spec REQ-OBV-108).
     *
     * Branching effect chain:
     *
     *   - `delete-now`: drop the per-version register (and every row inside
     *     it) via {@see RegisterService::delete()}, then delete the
     *     ApplicationVersion row.
     *   - `orphan-grace`: mark the per-version register orphaned by writing
     *     a timestamped flag into its `metadata` array, then delete the
     *     ApplicationVersion row. A background job (out of scope here)
     *     prunes registers orphaned for more than 30 days.
     *   - `keep-register`: leave the register untouched; delete only the
     *     ApplicationVersion row.
     *
     * Rejects deletion of an ApplicationVersion currently pointed at by its
     * parent Application's `productionVersion`.
     *
     * @param string $versionUuid UUID of the ApplicationVersion to delete
     * @param string $strategy    One of the STRATEGY_* constants
     *
     * @return void
     *
     * @throws RuntimeException On unknown strategy, missing version, or
     *                          production-version refusal
     */
    public function deleteVersion(string $versionUuid, string $strategy): void
    {
        $this->assertValidStrategy(strategy: $strategy);

        $version = $this->objectService->find(
            id: $versionUuid,
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_VERSION_SCHEMA
        );

        if ($version === null) {
            throw new RuntimeException(
                message: sprintf('ApplicationVersion %s does not exist — nothing to delete.', $versionUuid)
            );
        }

        $versionData = $this->normaliseObjectArray(object: $version);
        $this->assertNotProductionVersion(versionData: $versionData, versionUuid: $versionUuid);

        $registerSlug = (string) ($versionData['register'] ?? '');

        switch ($strategy) {
            case self::STRATEGY_DELETE_NOW:
                $this->dropPerVersionRegister(registerSlug: $registerSlug, versionUuid: $versionUuid);
                break;
            case self::STRATEGY_ORPHAN_GRACE:
                $this->flagRegisterOrphaned(registerSlug: $registerSlug, versionUuid: $versionUuid);
                break;
            case self::STRATEGY_KEEP_REGISTER:
                // No-op on the register — admin retains the data.
                $this->logger->info(
                    sprintf(
                        'OpenBuilt: keep-register strategy on ApplicationVersion %s — register %s left untouched.',
                        $versionUuid,
                        $registerSlug
                    )
                );
                break;
        }//end switch

        $this->objectService->deleteObject(uuid: $versionUuid);
    }//end deleteVersion()

    /**
     * Reject an unknown deletion strategy.
     *
     * @param string $strategy Strategy value to validate
     *
     * @return void
     *
     * @throws RuntimeException When the strategy is not recognised
     */
    private function assertValidStrategy(string $strategy): void
    {
        if (in_array($strategy, self::VALID_STRATEGIES, true) === false) {
            throw new RuntimeException(
                message: sprintf(
                    'Unknown deletion strategy "%s" — must be one of: %s',
                    $strategy,
                    implode(', ', self::VALID_STRATEGIES)
                )
            );
        }
    }//end assertValidStrategy()

    /**
     * Reject deletion when the version is the parent's production version.
     *
     * @param array<string,mixed> $versionData Normalised ApplicationVersion data
     * @param string              $versionUuid The version's UUID
     *
     * @return void
     *
     * @throws RuntimeException When the row is the parent's productionVersion
     */
    private function assertNotProductionVersion(array $versionData, string $versionUuid): void
    {
        $applicationUuid = (string) ($versionData['application'] ?? '');
        if ($applicationUuid === '') {
            return;
        }

        $application = $this->objectService->find(
            id: $applicationUuid,
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_SCHEMA
        );

        if ($application === null) {
            return;
        }

        $applicationData   = $this->normaliseObjectArray(object: $application);
        $productionVersion = (string) ($applicationData['productionVersion'] ?? '');
        if ($productionVersion === '' || $productionVersion !== $versionUuid) {
            return;
        }

        throw new RuntimeException(
            message: sprintf(
                'Cannot delete ApplicationVersion %s — it is the production version for Application %s.',
                $versionUuid,
                $applicationUuid
            )
        );
    }//end assertNotProductionVersion()

    /**
     * Drop a per-version register entirely (delete-now strategy).
     *
     * @param string $registerSlug The OR register slug to drop
     * @param string $versionUuid  The owning ApplicationVersion UUID (diagnostics)
     *
     * @return void
     */
    private function dropPerVersionRegister(string $registerSlug, string $versionUuid): void
    {
        if ($registerSlug === '') {
            $this->logger->warning(
                sprintf(
                    'OpenBuilt: ApplicationVersion %s has no register slug; nothing to drop.',
                    $versionUuid
                )
            );
            return;
        }

        try {
            $register = $this->registerMapper->find($registerSlug, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'OpenBuilt: register %s not found while deleting ApplicationVersion %s (%s) — continuing.',
                    $registerSlug,
                    $versionUuid,
                    $e->getMessage()
                )
            );
            return;
        }

        $this->registerService->delete(register: $register);
        $this->logger->info(
            sprintf(
                'OpenBuilt: dropped per-version register %s for ApplicationVersion %s.',
                $registerSlug,
                $versionUuid
            )
        );
    }//end dropPerVersionRegister()

    /**
     * Mark a per-version register as orphaned (orphan-grace strategy).
     *
     * Writes an `orphanedAt` ISO 8601 timestamp into the Register's
     * `metadata` JSON column via RegisterMapper::update(). A background
     * job (out of scope for this spec) prunes registers orphaned for
     * more than 30 days.
     *
     * @param string $registerSlug The OR register slug to flag
     * @param string $versionUuid  The owning ApplicationVersion UUID (diagnostics)
     *
     * @return void
     */
    private function flagRegisterOrphaned(string $registerSlug, string $versionUuid): void
    {
        if ($registerSlug === '') {
            $this->logger->warning(
                sprintf(
                    'OpenBuilt: ApplicationVersion %s has no register slug; nothing to orphan-flag.',
                    $versionUuid
                )
            );
            return;
        }

        try {
            $register = $this->registerMapper->find($registerSlug, _multitenancy: false);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'OpenBuilt: register %s not found while orphan-flagging for ApplicationVersion %s (%s).',
                    $registerSlug,
                    $versionUuid,
                    $e->getMessage()
                )
            );
            return;
        }

        $metadata = [];
        if (method_exists($register, 'getMetadata') === true) {
            $current = $register->getMetadata();
            if (is_array($current) === true) {
                $metadata = $current;
            }
        }

        $metadata['orphanedAt'] = gmdate(format: 'Y-m-d\TH:i:s\Z');

        if (method_exists($register, 'setMetadata') === true) {
            $register->setMetadata($metadata);
            $this->registerMapper->update($register);
            $this->logger->info(
                sprintf(
                    'OpenBuilt: orphan-flagged register %s for ApplicationVersion %s at %s.',
                    $registerSlug,
                    $versionUuid,
                    $metadata['orphanedAt']
                )
            );
            return;
        }

        $this->logger->warning(
            sprintf(
                'OpenBuilt: Register entity for %s has no setMetadata; falling back to PSR-logged orphan event for %s.',
                $registerSlug,
                $versionUuid
            )
        );
    }//end flagRegisterOrphaned()

    /**
     * Read the `promotesTo` UUID of one ApplicationVersion row (helper for cycle walk).
     *
     * Returns null when the row does not exist or has no `promotesTo`,
     * which terminates the walk in {@see guardNoCycle()}.
     *
     * @param string $versionUuid UUID of the version row to inspect
     *
     * @return string|null Next UUID in the chain, or null on terminal/missing
     */
    private function resolveNextPromotesTo(string $versionUuid): ?string
    {
        try {
            $entity = $this->objectService->find(
                id: $versionUuid,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_VERSION_SCHEMA
            );
        } catch (Throwable $e) {
            $this->logger->debug(
                sprintf('OpenBuilt: cycle-check lookup for %s failed (%s) — treating as terminal.', $versionUuid, $e->getMessage())
            );
            return null;
        }

        if ($entity === null) {
            return null;
        }

        $data = $this->normaliseObjectArray(object: $entity);
        $next = $data['promotesTo'] ?? null;
        if (is_string($next) === true && $next !== '') {
            return $next;
        }

        return null;
    }//end resolveNextPromotesTo()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
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
     * Recursively canonicalise a value for stable JSON serialisation.
     *
     * Associative arrays are sorted by key; sequential (list) arrays
     * are preserved in order; scalars pass through unchanged.
     *
     * @param mixed $value The value to canonicalise
     *
     * @return mixed The canonicalised value
     */
    private function canonicaliseValue(mixed $value): mixed
    {
        if (is_array($value) === false) {
            return $value;
        }

        // Detect list vs assoc-array. array_is_list() is PHP 8.1+, available per composer.json.
        if (array_is_list($value) === true) {
            return array_map(fn ($item): mixed => $this->canonicaliseValue(value: $item), $value);
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $entry) {
            $out[$key] = $this->canonicaliseValue(value: $entry);
        }

        return $out;
    }//end canonicaliseValue()

    /**
     * Describe a Register entity for diagnostic strings.
     *
     * Helper used by the parent Application guard listener and tests when
     * they need a human-readable identifier for a Register; returns an
     * empty string for a null input so callers can concatenate safely.
     *
     * @param Register|null $register The register entity to introspect
     *
     * @return string The register's slug, or empty string when unavailable
     *
     * @internal Exposed only to internal callers; not part of the public API.
     */
    public function describeRegister(?Register $register): string
    {
        if ($register === null) {
            return '';
        }

        return (string) $register->getSlug();
    }//end describeRegister()
}//end class
