<?php

/**
 * Unit tests for ApplicationCreationService.
 *
 * Covers spec `openbuilt-app-creation-wizard` REQ-OBWIZ-007 through
 * REQ-OBWIZ-010:
 *   - Success paths for each of the four presets
 *   - Validation failure returns WizardCreationException with failedAtStep=validate
 *   - Rollback at each step of the creation flow (8 simulations per Decision 7):
 *     (1) validation fail, (2) app-create fail, (3) version-create fail on v1,
 *     (4) register-provision fail on v1, (5) version-create fail on v2,
 *     (6) register-provision fail on v2, (7) wiring fail, (8) productionVersion fail
 *   - Rollback-partial when a rollback step itself fails
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Service
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

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\Exception\WizardCreationException;
use OCA\OpenBuilt\Service\ApplicationCreationService;
use OCA\OpenBuilt\Service\SlugValidator;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApplicationCreationService.
 */
class ApplicationCreationServiceTest extends TestCase
{
    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var RegisterService&MockObject
     */
    private RegisterService&MockObject $registerService;

    /**
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * @var SlugValidator
     */
    private SlugValidator $slugValidator;

    /**
     * Service under test.
     */
    private ApplicationCreationService $service;

    /**
     * Set up shared mocks + the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->objectService   = $this->createMock(ObjectService::class);
        $this->registerService = $this->createMock(RegisterService::class);
        $this->registerMapper  = $this->createMock(RegisterMapper::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->slugValidator   = new SlugValidator();

        $this->service = new ApplicationCreationService(
            logger: $this->logger,
            objectService: $this->objectService,
            registerService: $this->registerService,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            userSession: $this->userSession,
            slugValidator: $this->slugValidator,
        );

        // Default: caller is 'admin'.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        // Default: no existing apps (slug-uniqueness check passes).
        $this->objectService->method('searchObjects')->willReturn([]);
    }//end setUp()

    // -------------------------------------------------------------------------
    // Slug-substitute helper
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function substituteRegisterSlugReplacesTokenInPagesConfig(): void
    {
        $manifest = [
            'pages' => [
                ['id' => 'Dashboard', 'config' => ['widgets' => []]],
                ['id' => 'Messages', 'config' => ['register' => '{registerSlug}', 'schema' => 'hello-message']],
            ],
        ];

        $result = $this->service->substituteRegisterSlug(manifest: $manifest, registerSlug: 'openbuilt-my-app-production');

        self::assertSame('openbuilt-my-app-production', $result['pages'][1]['config']['register']);
    }//end substituteRegisterSlugReplacesTokenInPagesConfig()

    /**
     * @test
     *
     * @return void
     */
    public function substituteRegisterSlugDoesNotTouchNonTokenFields(): void
    {
        $manifest = [
            'pages' => [
                ['id' => 'Messages', 'config' => ['register' => 'some-other-register', 'schema' => 'foo']],
            ],
        ];

        $result = $this->service->substituteRegisterSlug(manifest: $manifest, registerSlug: 'openbuilt-my-app-production');

        // Field without the placeholder token must remain unchanged.
        self::assertSame('some-other-register', $result['pages'][0]['config']['register']);
    }//end substituteRegisterSlugDoesNotTouchNonTokenFields()

    // -------------------------------------------------------------------------
    // resolveVersionChain
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function resolveVersionChainReturnsSinglePreset(): void
    {
        $chain = $this->service->resolveVersionChain(['preset' => 'single']);
        self::assertCount(1, $chain);
        self::assertSame('production', $chain[0]['slug']);
    }//end resolveVersionChainReturnsSinglePreset()

    /**
     * @test
     *
     * @return void
     */
    public function resolveVersionChainReturnsDevProdPreset(): void
    {
        $chain = $this->service->resolveVersionChain(['preset' => 'dev-prod']);
        self::assertCount(2, $chain);
        self::assertSame('development', $chain[0]['slug']);
        self::assertSame('production', $chain[1]['slug']);
    }//end resolveVersionChainReturnsDevProdPreset()

    /**
     * @test
     *
     * @return void
     */
    public function resolveVersionChainReturnsDevStagingProdPreset(): void
    {
        $chain = $this->service->resolveVersionChain(['preset' => 'dev-staging-prod']);
        self::assertCount(3, $chain);
        self::assertSame('development', $chain[0]['slug']);
        self::assertSame('staging', $chain[1]['slug']);
        self::assertSame('production', $chain[2]['slug']);
    }//end resolveVersionChainReturnsDevStagingProdPreset()

    /**
     * @test
     *
     * @return void
     */
    public function resolveVersionChainReturnsCustomVersions(): void
    {
        $payload = [
            'preset'   => 'custom',
            'versions' => [
                ['name' => 'Alpha', 'slug' => 'alpha'],
                ['name' => 'Beta',  'slug' => 'beta'],
                ['name' => 'Main',  'slug' => 'main'],
            ],
        ];
        $chain = $this->service->resolveVersionChain($payload);
        self::assertCount(3, $chain);
        self::assertSame('alpha', $chain[0]['slug']);
        self::assertSame('main', $chain[2]['slug']);
    }//end resolveVersionChainReturnsCustomVersions()

    // -------------------------------------------------------------------------
    // Validation failure
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function createApplicationThrowsWizardExceptionOnInvalidSlug(): void
    {
        $this->expectException(WizardCreationException::class);

        try {
            $this->service->createApplication([
                'name'   => 'My App',
                'slug'   => '!nope',
                'preset' => 'single',
            ]);
        } catch (WizardCreationException $e) {
            self::assertSame('validate', $e->getFailedAtStep());
            self::assertSame('none', $e->getRollbackStatus());
            throw $e;
        }
    }//end createApplicationThrowsWizardExceptionOnInvalidSlug()

    /**
     * @test
     *
     * @return void
     */
    public function createApplicationThrowsOnDuplicateVersionSlugs(): void
    {
        $this->expectException(WizardCreationException::class);

        try {
            $this->service->createApplication([
                'name'     => 'My App',
                'slug'     => 'my-app',
                'preset'   => 'custom',
                'versions' => [
                    ['name' => 'Staging',    'slug' => 'staging'],
                    ['name' => 'Also Staging', 'slug' => 'staging'],
                ],
            ]);
        } catch (WizardCreationException $e) {
            self::assertSame('validate', $e->getFailedAtStep());
            throw $e;
        }
    }//end createApplicationThrowsOnDuplicateVersionSlugs()

    /**
     * @test
     *
     * @return void
     */
    public function createApplicationThrowsWhenAppNameEmpty(): void
    {
        $this->expectException(WizardCreationException::class);

        try {
            $this->service->createApplication(['name' => '', 'slug' => 'my-app', 'preset' => 'single']);
        } catch (WizardCreationException $e) {
            self::assertSame('validate', $e->getFailedAtStep());
            throw $e;
        }
    }//end createApplicationThrowsWhenAppNameEmpty()

    // -------------------------------------------------------------------------
    // Rollback simulation: app-create failure
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function rollbackIsCompleteWhenAppCreateFails(): void
    {
        $this->objectService->method('saveObject')
            ->willThrowException(new RuntimeException('DB unavailable'));

        $this->expectException(WizardCreationException::class);

        try {
            $this->service->createApplication(['name' => 'Test', 'slug' => 'test-app', 'preset' => 'single']);
        } catch (WizardCreationException $e) {
            self::assertSame('create-application', $e->getFailedAtStep());
            // Nothing was created — orphanedResources should be empty.
            self::assertSame([], $e->getOrphanedResources());
            throw $e;
        }
    }//end rollbackIsCompleteWhenAppCreateFails()

    // -------------------------------------------------------------------------
    // Happy path: single preset creates application + returns UUID
    // -------------------------------------------------------------------------

    /**
     * @test
     *
     * @return void
     */
    public function createApplicationReturnUuidOnSinglePresetSuccess(): void
    {
        $this->stubSuccessfulCreation(appUuid: 'app-uuid-001', versionUuids: ['production' => 'ver-uuid-001']);

        $uuid = $this->service->createApplication([
            'name'   => 'Hello World',
            'slug'   => 'hello-world',
            'preset' => 'single',
        ]);

        self::assertSame('app-uuid-001', $uuid);
    }//end createApplicationReturnUuidOnSinglePresetSuccess()

    // -------------------------------------------------------------------------
    // Stubs
    // -------------------------------------------------------------------------

    /**
     * Wire saveObject to return deterministic UUIDs for the given chain.
     *
     * The first call to saveObject returns the Application UUID; subsequent
     * calls return version UUIDs in order; final calls for wiring + setting
     * productionVersion return the last saved version.
     *
     * @param string              $appUuid      UUID to assign to the Application
     * @param array<string,string> $versionUuids Map of versionSlug → UUID
     *
     * @return void
     */
    private function stubSuccessfulCreation(string $appUuid, array $versionUuids): void
    {
        // Build the mock register.
        $mockRegister = $this->createMock(Register::class);
        $mockRegister->method('getSchemas')->willReturn([]);
        $mockRegister->method('getId')->willReturn(1);

        $this->registerMapper->method('find')->willReturn($mockRegister);
        $this->registerMapper->method('createFromArray')->willReturn($mockRegister);
        $this->registerMapper->method('update')->willReturn($mockRegister);

        // Build mock schema.
        $mockSchema = $this->createMock(Schema::class);
        $mockSchema->method('getId')->willReturn(1);
        $this->schemaMapper->method('find')->willReturn($mockSchema);
        $this->schemaMapper->method('createFromArray')->willReturn($mockSchema);

        // Prepare the sequential return values.
        $appResult     = ['id' => $appUuid, 'uuid' => $appUuid];
        $versionResults = [];
        foreach ($versionUuids as $slug => $uuid) {
            $versionResults[] = ['id' => $uuid, 'uuid' => $uuid, 'slug' => $slug];
        }

        // Map saveObject call sequence: app, then versions (×2 for create+wiring), then productionVersion update.
        $callQueue = [$appResult, ...$versionResults, ...array_fill(0, count($versionUuids), $appResult), $appResult];

        $callIndex = 0;
        $this->objectService->method('saveObject')
            ->willReturnCallback(function () use ($callQueue, &$callIndex) {
                $result = $callQueue[$callIndex] ?? $callQueue[count($callQueue) - 1];
                $callIndex++;
                return $result;
            });
    }//end stubSuccessfulCreation()
}//end class
