<?php

/**
 * Unit tests for TemplateSeedService.
 *
 * Covers the shared idempotent seeding logic (openbuild-first-time-setup
 * task 1.3): seed-on-empty creates all fixtures; a re-run skips all; a
 * partial pre-existing set only creates the missing ones; a missing fixtures
 * directory returns an error entry WITHOUT throwing (non-throwing contract,
 * so the HTTP endpoint can report a partial result).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
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

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\TemplateSeedService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for TemplateSeedService::seed and ::countSeeded.
 */
class TemplateSeedServiceTest extends TestCase
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
        }

        $this->tempDir = null;
        parent::tearDown();
    }//end tearDown()

    /**
     * Create a temp app root with a Settings/templates dir containing fixtures.
     *
     * @param array<string,array<string,mixed>|null> $fixturesBySlug Map slug → fixture (null = skip file).
     * @param bool                                    $createDir      Whether to create the dir at all.
     *
     * @return void
     */
    private function seedFixturesDir(array $fixturesBySlug, bool $createDir = true): void
    {
        $appRoot     = sys_get_temp_dir().'/openbuild-svc-test-'.uniqid();
        $fixturesDir = $appRoot.'/lib/Settings/templates';

        if ($createDir === true) {
            $this->tempDir = $fixturesDir;
            mkdir($fixturesDir, 0777, true);
            foreach ($fixturesBySlug as $slug => $data) {
                if ($data === null) {
                    continue;
                }

                file_put_contents($fixturesDir.'/'.$slug.'.json', json_encode($data, JSON_PRETTY_PRINT));
            }
        }

        $this->appManager->method('getAppPath')->willReturn($appRoot);
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
            'slug'        => $slug,
            'title'       => 'Title for '.$slug,
            'description' => 'Description for '.$slug,
            'useCase'     => 'Use case for '.$slug,
            'category'    => 'government-services',
            'version'     => '1.0.0',
            'manifest'    => [
                'version' => '1.0.0',
                'pages'   => [['name' => 'p1', 'route' => '/', 'type' => 'index']],
            ],
        ];
    }//end validFixture()

    /**
     * Build the service under test.
     *
     * @return TemplateSeedService
     */
    private function service(): TemplateSeedService
    {
        return new TemplateSeedService(
            logger: $this->logger,
            appManager: $this->appManager,
            objectService: $this->objectService,
        );
    }//end service()

    /**
     * seed-on-empty creates all four fixtures and reports seeded=4, skipped=0.
     *
     * @return void
     */
    public function testSeedOnEmptyCreatesAll(): void
    {
        $fixtures = [];
        foreach (self::EXPECTED_SLUGS as $slug) {
            $fixtures[$slug] = $this->validFixture($slug);
        }

        $this->seedFixturesDir($fixtures);
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->expects(self::exactly(4))->method('saveObject');

        $result = $this->service()->seed();

        self::assertSame(4, $result['seeded']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);
    }//end testSeedOnEmptyCreatesAll()

    /**
     * A re-run when all slugs exist skips all and writes nothing.
     *
     * @return void
     */
    public function testReRunSkipsAll(): void
    {
        $fixtures = [];
        foreach (self::EXPECTED_SLUGS as $slug) {
            $fixtures[$slug] = $this->validFixture($slug);
        }

        $this->seedFixturesDir($fixtures);
        $this->objectService->method('findAll')->willReturnCallback(
            static fn (array $config): array => [['slug' => $config['filters']['slug'] ?? '']]
        );
        $this->objectService->expects(self::never())->method('saveObject');

        $result = $this->service()->seed();

        self::assertSame(0, $result['seeded']);
        self::assertSame(4, $result['skipped']);
        self::assertSame([], $result['errors']);
    }//end testReRunSkipsAll()

    /**
     * A partial pre-existing set only creates the missing ones.
     *
     * @return void
     */
    public function testPartialSetOnlyCreatesMissing(): void
    {
        $fixtures = [];
        foreach (self::EXPECTED_SLUGS as $slug) {
            $fixtures[$slug] = $this->validFixture($slug);
        }

        $this->seedFixturesDir($fixtures);

        // Two slugs already present, two absent.
        $present = ['permit-tracker', 'employee-onboarding'];
        $this->objectService->method('findAll')->willReturnCallback(
            static function (array $config) use ($present): array {
                $slug = $config['filters']['slug'] ?? '';
                return in_array($slug, $present, true) === true ? [['slug' => $slug]] : [];
            }
        );
        $this->objectService->expects(self::exactly(2))->method('saveObject');

        $result = $this->service()->seed();

        self::assertSame(2, $result['seeded']);
        self::assertSame(2, $result['skipped']);
        self::assertSame([], $result['errors']);
    }//end testPartialSetOnlyCreatesMissing()

    /**
     * A missing fixtures directory returns an error entry and does NOT throw.
     *
     * @return void
     */
    public function testMissingDirReturnsErrorWithoutThrowing(): void
    {
        // createDir=false → getAppPath points at a non-existent dir.
        $this->seedFixturesDir([], createDir: false);
        $this->objectService->expects(self::never())->method('saveObject');

        $result = $this->service()->seed();

        self::assertSame(0, $result['seeded']);
        self::assertSame(0, $result['skipped']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('fixtures directory missing', $result['errors'][0]);
    }//end testMissingDirReturnsErrorWithoutThrowing()

    /**
     * A missing individual fixture file is collected as an error, and the other
     * present fixtures still seed (non-throwing, continue-on-error).
     *
     * @return void
     */
    public function testMissingFixtureCollectsErrorAndContinues(): void
    {
        $fixtures = [
            'permit-tracker'           => $this->validFixture('permit-tracker'),
            'stakeholder-consultation' => $this->validFixture('stakeholder-consultation'),
            'employee-onboarding'      => $this->validFixture('employee-onboarding'),
            'incident-reporter'        => null,
        ];
        $this->seedFixturesDir($fixtures);
        $this->objectService->method('findAll')->willReturn([]);
        $this->objectService->expects(self::exactly(3))->method('saveObject');

        $result = $this->service()->seed();

        self::assertSame(3, $result['seeded']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('incident-reporter', $result['errors'][0]);
    }//end testMissingFixtureCollectsErrorAndContinues()

    /**
     * countSeeded reports how many of the four bundled slugs already exist.
     *
     * @return void
     */
    public function testCountSeededReportsExisting(): void
    {
        $this->seedFixturesDir([]);
        $present = ['permit-tracker', 'incident-reporter', 'employee-onboarding'];
        $this->objectService->method('findAll')->willReturnCallback(
            static function (array $config) use ($present): array {
                $slug = $config['filters']['slug'] ?? '';
                return in_array($slug, $present, true) === true ? [['slug' => $slug]] : [];
            }
        );

        self::assertSame(3, $this->service()->countSeeded());
    }//end testCountSeededReportsExisting()
}//end class
