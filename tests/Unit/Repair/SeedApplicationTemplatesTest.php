<?php

/**
 * Unit tests for SeedApplicationTemplates repair step.
 *
 * Covers REQ-OBTC-002 (idempotent seeding of the four Conduction-curated
 * ApplicationTemplate records) and REQ-OBTC-009 (loud-fail when a fixture
 * is missing or invalid).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Repair
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

namespace OCA\OpenBuilt\Tests\Unit\Repair;

use OCA\OpenBuilt\Repair\SeedApplicationTemplates;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SeedApplicationTemplates::run.
 */
class SeedApplicationTemplatesTest extends TestCase
{
    /**
     * The four expected seeded template slugs.
     *
     * @var array<int,string>
     */
    private const EXPECTED_SLUGS = [
        'permit-tracker',
        'stakeholder-consultation',
        'employee-onboarding',
        'incident-reporter',
    ];

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock OR ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock IAppManager.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Mock IOutput.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * Path to a temp fixtures dir created per test (cleaned up on tearDown).
     *
     * @var string|null
     */
    private ?string $tempDir = null;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->output        = $this->createMock(IOutput::class);
        $this->appManager    = $this->createMock(IAppManager::class);
        $this->objectService = $this->createMock(ObjectService::class);
    }//end setUp()

    /**
     * Remove the temp fixtures dir if it was created.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir) === true) {
            foreach (glob($this->tempDir.'/*.json') ?: [] as $f) {
                @unlink($f);
            }

            @rmdir($this->tempDir);
            // Walk up to the parent app root dir we created.
            $parent = dirname($this->tempDir);
            if (basename($parent) === 'Settings') {
                @rmdir($parent);
                @rmdir(dirname($parent));
                @rmdir(dirname(dirname($parent)));
            }
        }

        $this->tempDir = null;
        parent::tearDown();
    }//end tearDown()

    /**
     * Create a temp app root with a Settings/templates dir containing fixtures.
     *
     * Returns the path that the SUT will receive from $appManager->getAppPath('openbuilt').
     *
     * @param array<string,array<string,mixed>|null> $fixturesBySlug Map of slug → fixture data (null = skip file).
     *
     * @return string The pseudo-app-root path.
     */
    private function seedFixturesDir(array $fixturesBySlug): string
    {
        $appRoot       = sys_get_temp_dir().'/openbuilt-test-'.uniqid();
        $fixturesDir   = $appRoot.'/lib/Settings/templates';
        $this->tempDir = $fixturesDir;
        mkdir($fixturesDir, 0777, true);

        foreach ($fixturesBySlug as $slug => $data) {
            if ($data === null) {
                continue;
            }

            file_put_contents($fixturesDir.'/'.$slug.'.json', json_encode($data, JSON_PRETTY_PRINT));
        }

        $this->appManager->method('getAppPath')->willReturn($appRoot);
        return $appRoot;
    }//end seedFixturesDir()

    /**
     * Build a valid fixture for the given slug.
     *
     * @param string $slug The fixture slug.
     *
     * @return array<string,mixed>
     */
    private function validFixture(string $slug): array
    {
        return [
            'slug'             => $slug,
            'title'            => 'Title for '.$slug,
            'description'      => 'Description for '.$slug,
            'useCase'          => 'Use case for '.$slug,
            'category'         => 'government-services',
            'version'          => '1.0.0',
            'manifest'         => [
                'version' => '1.0.0',
                'pages'   => [['name' => 'p1', 'route' => '/', 'type' => 'index']],
            ],
            'companionSchemas' => [],
        ];
    }//end validFixture()

    /**
     * Test 1 — fresh install: all four fixtures present and no existing
     * records → saveObject called four times.
     *
     * @return void
     */
    public function testSeedsFourTemplatesOnFreshInstall(): void
    {
        $fixtures = [];
        foreach (self::EXPECTED_SLUGS as $slug) {
            $fixtures[$slug] = $this->validFixture($slug);
        }

        $this->seedFixturesDir($fixtures);

        // No existing records — findAll returns empty for every slug.
        $this->objectService->method('findAll')->willReturn([]);

        $this->objectService->expects(self::exactly(4))
            ->method('saveObject');

        $step = new SeedApplicationTemplates(
            logger: $this->logger,
            appManager: $this->appManager,
            objectService: $this->objectService,
        );

        $step->run($this->output);
    }//end testSeedsFourTemplatesOnFreshInstall()

    /**
     * Test 2 — idempotent re-run: when all four slugs already exist, no
     * saveObject calls are made.
     *
     * @return void
     */
    public function testIdempotentReRunDoesNotDuplicate(): void
    {
        $fixtures = [];
        foreach (self::EXPECTED_SLUGS as $slug) {
            $fixtures[$slug] = $this->validFixture($slug);
        }

        $this->seedFixturesDir($fixtures);

        // Every findAll lookup returns a hit (the record already exists).
        $this->objectService->method('findAll')->willReturnCallback(
            static fn (array $config): array => [['slug' => $config['filters']['slug'] ?? '']]
        );

        $this->objectService->expects(self::never())->method('saveObject');

        $step = new SeedApplicationTemplates(
            logger: $this->logger,
            appManager: $this->appManager,
            objectService: $this->objectService,
        );

        $step->run($this->output);
    }//end testIdempotentReRunDoesNotDuplicate()

    /**
     * Test 3 — slugs persisted match the expected canonical list (cardinality
     * check + slug verification on saveObject payloads).
     *
     * @return void
     */
    public function testSeededRecordSlugsMatchExpectedList(): void
    {
        $fixtures = [];
        foreach (self::EXPECTED_SLUGS as $slug) {
            $fixtures[$slug] = $this->validFixture($slug);
        }

        $this->seedFixturesDir($fixtures);

        $this->objectService->method('findAll')->willReturn([]);

        $savedSlugs = [];
        $this->objectService->method('saveObject')
            ->willReturnCallback(
                function (array $object) use (&$savedSlugs): ObjectEntity {
                    $savedSlugs[] = $object['slug'] ?? null;
                    $entity        = $this->createMock(ObjectEntity::class);
                    $entity->method('jsonSerialize')->willReturn(['uuid' => 'fake-uuid-'.($object['slug'] ?? 'x')]);
                    return $entity;
                }
            );

        $step = new SeedApplicationTemplates(
            logger: $this->logger,
            appManager: $this->appManager,
            objectService: $this->objectService,
        );

        $step->run($this->output);

        sort($savedSlugs);
        $expected = self::EXPECTED_SLUGS;
        sort($expected);
        self::assertSame($expected, $savedSlugs);
    }//end testSeededRecordSlugsMatchExpectedList()

    /**
     * Test 4 — REQ-OBTC-009: when an expected fixture file is missing,
     * the repair step fails loudly with a RuntimeException naming the file.
     *
     * @return void
     */
    public function testFailsLoudWhenExpectedFixtureMissing(): void
    {
        // Only three of the four fixtures exist; `incident-reporter` is intentionally
        // omitted to simulate a packaging error.
        $fixtures = [
            'permit-tracker'           => $this->validFixture('permit-tracker'),
            'stakeholder-consultation' => $this->validFixture('stakeholder-consultation'),
            'employee-onboarding'      => $this->validFixture('employee-onboarding'),
            'incident-reporter'        => null,
        ];
        $this->seedFixturesDir($fixtures);

        $this->objectService->method('findAll')->willReturn([]);

        $step = new SeedApplicationTemplates(
            logger: $this->logger,
            appManager: $this->appManager,
            objectService: $this->objectService,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/incident-reporter/');

        $step->run($this->output);
    }//end testFailsLoudWhenExpectedFixtureMissing()
}//end class
