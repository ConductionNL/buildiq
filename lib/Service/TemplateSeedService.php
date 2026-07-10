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
     * Seed each bundled ApplicationTemplate fixture whose slug is not already
     * present. Idempotent by slug — never overwrites an existing record.
     *
     * @return array{seeded:int,skipped:int,errors:array<int,string>} Per-run counts and collected errors.
     *
     * @spec openspec/changes/openbuild-first-time-setup/tasks.md#task-11
     */
    public function seed(): array
    {
        $seeded  = 0;
        $skipped = 0;
        $errors  = [];

        $fixturesDir = $this->appManager->getAppPath('openbuild').'/lib/Settings/templates';
        if (is_dir($fixturesDir) === false) {
            $errors[] = 'Template fixtures directory missing: '.$fixturesDir;
            return ['seeded' => $seeded, 'skipped' => $skipped, 'errors' => $errors];
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

            if ($this->findBySlug(slug: $slug) !== null) {
                ++$skipped;
                continue;
            }

            try {
                // Runs as a system/anonymous or admin user which cannot satisfy
                // the ApplicationTemplate schema's create:[admin] guard from the
                // repair context — write in system context (OR RBAC +
                // multitenancy bypassed), matching the repair step.
                $this->objectService->saveObject(
                    object: $data,
                    register: 'openbuild',
                    schema: 'application-template',
                    _rbac: false,
                    _multitenancy: false
                );
                ++$seeded;
            } catch (DoesNotExistException $e) {
                // The openbuild register / application-template schema is not
                // provisioned yet — the configuration import (InitializeSettings)
                // has not completed on this pass (e.g. install ordering). Defer
                // seeding rather than failing the install: a subsequent
                // `occ maintenance:repair` (or the admin re-import) runs the
                // seeding again once the register exists. Non-fatal by design.
                $this->logger->warning(
                    'OpenBuild: register/application-template schema not available yet — deferring template seeding',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
                return ['seeded' => $seeded, 'skipped' => $skipped, 'errors' => [], 'deferred' => true];
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuild: failed to seed template',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
                $errors[] = 'Failed to seed template "'.$slug.'": '.$e->getMessage();
            }//end try
        }//end foreach

        return ['seeded' => $seeded, 'skipped' => $skipped, 'errors' => $errors];
    }//end seed()

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
