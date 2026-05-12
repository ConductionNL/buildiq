<?php

/**
 * OpenBuilt Seed Application Templates Repair Step
 *
 * Idempotent repair step that seeds the four Conduction-curated
 * ApplicationTemplate records on install. Modelled on the canonical
 * SeedHelloWorld.php pattern from chain spec #1 (bootstrap-openbuilt).
 *
 * Loads four JSON fixtures from lib/Settings/templates/ and writes them
 * into OpenRegister via the standard ObjectService. Per-slug existence
 * guard makes re-runs no-ops. Validation failure on any fixture fails
 * the repair step loudly (REQ-OBTC-009).
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

use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Seed Conduction-curated ApplicationTemplate records.
 */
class SeedApplicationTemplates implements IRepairStep
{

    /**
     * The four seeded template slugs (one fixture per slug).
     *
     * @var array<int,string>
     */
    private const TEMPLATE_SLUGS = [
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
     * Constructor for SeedApplicationTemplates.
     *
     * @param LoggerInterface $logger        The logger
     * @param IAppManager     $appManager    The app manager (for fixtures path)
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
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed Conduction-curated OpenBuilt ApplicationTemplate records';
    }//end getName()

    /**
     * Run the repair step — seed each fixture if its slug is not present.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding ApplicationTemplate records...');

        $fixturesDir = $this->appManager->getAppPath('openbuilt').'/lib/Settings/templates';
        if (is_dir($fixturesDir) === false) {
            $output->warning('Template fixtures directory missing: '.$fixturesDir);
            return;
        }

        $seeded = 0;
        foreach (self::TEMPLATE_SLUGS as $slug) {
            $fixturePath = $fixturesDir.'/'.$slug.'.json';
            if (is_file($fixturePath) === false) {
                throw new RuntimeException('Missing template fixture: '.$fixturePath);
            }

            $raw  = file_get_contents($fixturePath);
            $data = json_decode($raw, true);
            if (is_array($data) === false) {
                throw new RuntimeException('Invalid JSON in template fixture: '.$fixturePath);
            }

            $this->validateFixture(data: $data, slug: $slug);

            if ($this->findBySlug(slug: $slug) !== null) {
                $output->info('Template already seeded — skipping: '.$slug);
                continue;
            }

            try {
                $this->objectService->saveObject(
                    object: $data,
                    register: 'openbuilt',
                    schema: 'application-template'
                );
                $output->info('Seeded ApplicationTemplate: '.$slug);
                ++$seeded;
            } catch (Throwable $e) {
                $this->logger->error(
                    'OpenBuilt: failed to seed template',
                    ['slug' => $slug, 'exception' => $e->getMessage()]
                );
                throw new RuntimeException(
                    'Failed to seed template "'.$slug.'": '.$e->getMessage(),
                    0,
                    $e
                );
            }
        }//end foreach

        $output->info('OpenBuilt template seeding complete. New: '.$seeded);
    }//end run()

    /**
     * Validate a fixture has the minimum required fields per REQ-OBTC-009.
     *
     * @param array<string,mixed> $data The decoded fixture
     * @param string              $slug The slug for error messages
     *
     * @return void
     *
     * @throws RuntimeException When a required field is missing or empty.
     */
    private function validateFixture(array $data, string $slug): void
    {
        $required = ['slug', 'title', 'description', 'useCase', 'category', 'manifest', 'version'];
        foreach ($required as $key) {
            if (isset($data[$key]) === false || $data[$key] === '') {
                throw new RuntimeException('Template "'.$slug.'" missing required field: '.$key);
            }
        }

        if (($data['slug'] ?? '') !== $slug) {
            throw new RuntimeException(
                'Template fixture filename "'.$slug.'.json" does not match its slug "'.($data['slug'] ?? '').'".'
            );
        }

        if (is_array($data['manifest']) === false || isset($data['manifest']['pages']) === false) {
            throw new RuntimeException('Template "'.$slug.'" manifest is missing pages.');
        }

        if (in_array($data['category'], self::ALLOWED_CATEGORIES, true) === false) {
            throw new RuntimeException('Template "'.$slug.'" has unknown category: '.$data['category']);
        }
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
                        'register' => 'openbuilt',
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
                'OpenBuilt: template lookup failed — treating as absent',
                ['slug' => $slug, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end findBySlug()
}//end class
