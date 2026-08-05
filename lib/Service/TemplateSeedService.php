<?php

/**
 * OpenBuild TemplateSeedService
 *
 * Shared, idempotent seeding of the four Conduction-curated
 * ApplicationTemplate records. Extracted from SeedApplicationTemplates so the
 * exact same create-missing-never-overwrite logic backs BOTH the install-time
 * repair step (SeedApplicationTemplates) AND the admin-facing first-time-setup
 * action endpoint (SetupController::seedTemplates) — ADR-042. Seeding by slug
 * is idempotent: a slug that already exists is counted as skipped, never
 * overwritten.
 *
 * `seed()` is non-throwing: hard failures (missing fixtures directory, missing
 * or invalid fixture, validation failure, OR write failure) are collected into
 * the returned `errors` array rather than raised, so the HTTP endpoint can
 * report a partial result. The repair-step wrapper re-raises on any collected
 * error to preserve its loud-fail contract (REQ-OBTC-009).
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
 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seed Conduction-curated ApplicationTemplate records (idempotent, shared).
 *
 * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-11
 */
class TemplateSeedService
{

    /**
     * The four seeded template slugs (one fixture per slug).
     *
     * @var array<int,string>
     */
    public const TEMPLATE_SLUGS = [
        'permit-tracker',
        'stakeholder-consultation',
        'employee-onboarding',
        'incident-reporter',
    ];

    /**
     * The allowed categories per REQ-OBTC-009.
     *
     * @var array<int,string>
     */
    private const ALLOWED_CATEGORIES = [
        'government-services',
        'internal-operations',
        'citizen-engagement',
        'field-work',
    ];

    /**
     * Constructor for TemplateSeedService.
     *
     * @param LoggerInterface $logger        The logger
     * @param IAppManager     $appManager    The app manager (for the fixtures path)
     * @param ObjectService   $objectService OpenRegister object service (hard dep via info.xml)
     *
     * @return void
     */
    public function __construct(
        private LoggerInterface $logger,
        private IAppManager $appManager,
        private ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Seed each bundled ApplicationTemplate fixture. A slug that is not present
     * is created; an already-seeded slug is re-written in place only when the
     * bundled fixture carries a newer `version` (semver) — so curated template
     * improvements propagate to upgraded installs instead of being frozen at
     * the first-seeded version. Admin-created rows (isSeeded !== true) are never
     * touched. A slug at or below the stored version is skipped (idempotent).
     *
     * @return array{seeded:int,updated:int,skipped:int,errors:array<int,string>,deferred?:bool} Per-run
     *         counts and collected errors. `seeded` counts new rows, `updated` counts in-place version
     *         upgrades, `skipped` counts up-to-date/admin rows. `deferred` is set when the register/schema
     *         is not provisioned yet (install ordering) — the caller re-runs on the next repair rather than
     *         failing the install. It was returned but never declared, so SeedApplicationTemplates' check
     *         for it read as statically impossible.
     *
     * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-11
     */
    public function seed(): array
    {
        $seeded  = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        $fixturesDir = $this->appManager->getAppPath('openbuild').'/lib/Settings/templates';
        if (is_dir($fixturesDir) === false) {
            $errors[] = 'Template fixtures directory missing: '.$fixturesDir;
            return ['seeded' => $seeded, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
        }

        foreach (self::TEMPLATE_SLUGS as $slug) {
            $fixturePath = $fixturesDir.'/'.$slug.'.json';
            if (is_file($fixturePath) === false) {
                $errors[] = 'Missing template fixture: '.$fixturePath;
                continue;
            }

            $raw  = file_get_contents($fixturePath);
            $data = json_decode($raw, true);
            if (is_array($data) === false) {
                $errors[] = 'Invalid JSON in template fixture: '.$fixturePath;
                continue;
            }

            $validationError = $this->validateFixture(data: $data, slug: $slug);
            if ($validationError !== null) {
                $errors[] = $validationError;
                continue;
            }

            // Upsert semantics: a brand-new slug is created; an already-seeded
            // slug is re-written in place ONLY when the bundled fixture carries a
            // newer version, so curated template improvements reach upgraded
            // installs instead of being frozen at their first-seeded version.
            $existing     = $this->findBySlug(slug: $slug);
            $existingUuid = null;
            if ($existing !== null) {
                if ($this->fixtureIsNewer(fixture: $data, stored: $existing) === false) {
                    ++$skipped;
                    continue;
                }

                $existingUuid = (string) ($existing['id'] ?? ($existing['uuid'] ?? ($existing['@self']['id'] ?? '')));
                if ($existingUuid === '') {
                    // Cannot target the existing row for an in-place update;
                    // skip rather than risk creating a duplicate-slug row.
                    $this->logger->warning(
                        'OpenBuild: cannot resolve uuid for existing template — skipping update',
                        ['slug' => $slug]
                    );
                    ++$skipped;
                    continue;
                }
            }

            try {
                // Runs as a system/anonymous or admin user which cannot satisfy
                // the ApplicationTemplate schema's create:[admin] guard from the
                // repair context — write in system context (OR RBAC +
                // multitenancy bypassed), matching the repair step. Passing the
                // existing uuid updates the stored row in place; omitting it
                // (uuid null) creates a new row.
                $this->objectService->saveObject(
                    object: $data,
                    register: 'openbuild',
                    schema: 'application-template',
                    uuid: $existingUuid,
                    _rbac: false,
                    _multitenancy: false
                );
                if ($existing === null) {
                    ++$seeded;
                }

                if ($existing !== null) {
                    ++$updated;
                }
            } catch (DoesNotExistException $e) {
                // The openbuild register / application-template schema is not
                // provisioned yet — the configuration import (InitializeSettings)
                // has not completed on this pass (e.g. install ordering). Defer
                // seeding rather than failing the install: a subsequent
                // `occ maintenance:repair` (or the admin re-import) runs the
                // seeding again once the register exists. Non-fatal by design.
                //
                // Returning (not `continue`) is deliberate: every slug targets
                // the SAME hardcoded register+schema below, so a missing schema
                // fails identically for all of them — deferring the whole batch
                // is correct, and the `deferred` flag is how the caller knows to
                // re-run rather than falsely reporting "seeding complete".
                // NOTE: if fixtures ever target heterogeneous schemas (i.e. the
                // register/schema is read per-fixture instead of hardcoded),
                // revisit this — a per-slug `continue` that still surfaces
                // `deferred` would then be needed so one missing schema does not
                // hide seedable slugs.
                $this->logger->warning(
                    'OpenBuild: register/application-template schema not available yet — deferring template seeding',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
                return ['seeded' => $seeded, 'updated' => $updated, 'skipped' => $skipped, 'errors' => [], 'deferred' => true];
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuild: failed to seed template',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
                $errors[] = 'Failed to seed template "'.$slug.'": '.$e->getMessage();
            }//end try
        }//end foreach

        return ['seeded' => $seeded, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }//end seed()

    /**
     * Decide whether a bundled fixture should overwrite the stored template row.
     *
     * True only when the stored row is one we seeded (isSeeded) AND the fixture
     * version is strictly newer than the stored version. A stored row with no
     * version predates versioning and is treated as older, so the first
     * versioned fixture upgrades it. A fixture with no version is never
     * propagated (nothing to compare — leave the existing row untouched).
     *
     * @param array<string,mixed> $fixture The bundled fixture data.
     * @param array<string,mixed> $stored  The existing stored template row.
     *
     * @return bool True when the stored row should be updated from the fixture.
     */
    private function fixtureIsNewer(array $fixture, array $stored): bool
    {
        // Never clobber a template an admin created themselves.
        if (($stored['isSeeded'] ?? false) !== true) {
            return false;
        }

        $fixtureVersion = (string) ($fixture['version'] ?? '');
        if ($fixtureVersion === '') {
            return false;
        }

        $storedVersion = (string) ($stored['version'] ?? '');
        if ($storedVersion === '') {
            return true;
        }

        return version_compare($fixtureVersion, $storedVersion, '>');
    }//end fixtureIsNewer()

    /**
     * Count how many of the bundled template slugs already exist in OpenRegister.
     * Used by the first-time-setup status endpoint to decide whether the seed
     * step is already satisfied (side-effect-free, unlike {@see seed()}).
     *
     * @return int Number of the four bundled slugs currently present (0-4).
     *
     * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-42
     */
    public function countSeeded(): int
    {
        $present = 0;
        foreach (self::TEMPLATE_SLUGS as $slug) {
            if ($this->findBySlug(slug: $slug) !== null) {
                ++$present;
            }
        }

        return $present;
    }//end countSeeded()

    /**
     * Validate a fixture has the minimum required fields per REQ-OBTC-009.
     *
     * @param array<string,mixed> $data The decoded fixture
     * @param string              $slug The slug for error messages
     *
     * @return string|null A human-readable error message, or null when valid.
     *
     * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-11
     */
    private function validateFixture(array $data, string $slug): ?string
    {
        $required = ['slug', 'title', 'description', 'useCase', 'category', 'manifest', 'version'];
        foreach ($required as $key) {
            if (isset($data[$key]) === false || $data[$key] === '') {
                return 'Template "'.$slug.'" missing required field: '.$key;
            }
        }

        if (($data['slug'] ?? '') !== $slug) {
            return 'Template fixture filename "'.$slug.'.json" does not match its slug "'.($data['slug'] ?? '').'".';
        }

        if (is_array($data['manifest']) === false || isset($data['manifest']['pages']) === false) {
            return 'Template "'.$slug.'" manifest is missing pages.';
        }

        if (in_array($data['category'], self::ALLOWED_CATEGORIES, true) === false) {
            return 'Template "'.$slug.'" has unknown category: '.$data['category'];
        }

        return null;
    }//end validateFixture()

    /**
     * Find an existing template by slug.
     *
     * @param string $slug The slug to look up
     *
     * @return array<string,mixed>|null The existing record or null when absent.
     */
    private function findBySlug(string $slug): ?array
    {
        try {
            $results = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => 'openbuild',
                        'schema'   => 'application-template',
                        'slug'     => $slug,
                    ],
                    'limit'   => 1,
                ]
            );

            if (is_array($results) === false || count($results) === 0) {
                return null;
            }

            $first = reset($results);
            if (is_array($first) === true) {
                return $first;
            }

            if (is_object($first) === true && method_exists($first, 'jsonSerialize') === true) {
                $serialised = $first->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }

                return null;
            }

            return null;
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuild: template lookup failed — treating as absent',
                ['slug' => $slug, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end findBySlug()
}//end class
