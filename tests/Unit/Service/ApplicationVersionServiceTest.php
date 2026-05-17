<?php

/**
 * Unit tests for ApplicationVersionService.
 *
 * Covers spec REQ-OBV-102 (initial semver), REQ-OBV-103 (manifest-hash
 * auto-bump), REQ-OBV-104 (promotesTo cycle prevention), REQ-OBV-105
 * (productionVersion back-reference guard), and REQ-OBV-108 (strategy-aware
 * deletion).
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

use OCA\OpenBuilt\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApplicationVersionService.
 */
class ApplicationVersionServiceTest extends TestCase
{
    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock object service.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock register service.
     *
     * @var RegisterService&MockObject
     */
    private RegisterService&MockObject $registerService;

    /**
     * Mock register mapper.
     *
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * Service under test.
     */
    private ApplicationVersionService $service;

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

        $this->service = new ApplicationVersionService(
            logger: $this->logger,
            objectService: $this->objectService,
            registerService: $this->registerService,
            registerMapper: $this->registerMapper,
        );
    }//end setUp()

    /**
     * Canonicalisation must be deterministic: identical semantic content
     * encoded with re-ordered keys produces the same canonical string.
     *
     * @return void
     */
    public function testCanonicaliseManifestIsKeyOrderIndependent(): void
    {
        $manifest1 = ['version' => '1.0.0', 'menu' => [], 'pages' => [['type' => 'index', 'id' => 'Home']]];
        $manifest2 = ['pages' => [['id' => 'Home', 'type' => 'index']], 'menu' => [], 'version' => '1.0.0'];

        self::assertSame(
            $this->service->canonicaliseManifest(manifest: $manifest1),
            $this->service->canonicaliseManifest(manifest: $manifest2)
        );
    }//end testCanonicaliseManifestIsKeyOrderIndependent()

    /**
     * SHA-256 hash is stable across runs and unique per content.
     *
     * @return void
     */
    public function testHashManifestIsStableAndDistinct(): void
    {
        $hashA = $this->service->hashManifest(manifest: ['version' => '1.0.0']);
        $hashB = $this->service->hashManifest(manifest: ['version' => '2.0.0']);

        self::assertSame($hashA, $this->service->hashManifest(manifest: ['version' => '1.0.0']));
        self::assertNotSame($hashA, $hashB);
        self::assertSame(64, strlen($hashA));
    }//end testHashManifestIsStableAndDistinct()

    /**
     * Patch bump arithmetic, including suffix stripping and error path.
     *
     * @return void
     */
    public function testBumpPatchHappyPathsAndError(): void
    {
        self::assertSame('0.1.1', $this->service->bumpPatch(semver: '0.1.0'));
        self::assertSame('2.5.8', $this->service->bumpPatch(semver: '2.5.7'));
        self::assertSame('1.0.1', $this->service->bumpPatch(semver: '1.0.0-beta.5+build.7'));

        $this->expectException(RuntimeException::class);
        $this->service->bumpPatch(semver: 'not-a-semver');
    }//end testBumpPatchHappyPathsAndError()

    /**
     * onSave on a CREATE defaults semver to 0.1.0 and stamps manifestHash.
     *
     * @return void
     */
    public function testOnSaveCreateDefaultsSemverAndStampsHash(): void
    {
        $next = ['manifest' => ['version' => '1.0.0', 'menu' => [], 'pages' => []]];

        $result = $this->service->onSave(current: null, next: $next);

        self::assertSame(ApplicationVersionService::INITIAL_SEMVER, $result['semver']);
        self::assertArrayHasKey('manifestHash', $result);
        self::assertSame(64, strlen($result['manifestHash']));
    }//end testOnSaveCreateDefaultsSemverAndStampsHash()

    /**
     * Metadata-only edit leaves semver + manifestHash untouched.
     *
     * @return void
     */
    public function testOnSaveMetadataOnlyEditDoesNotBump(): void
    {
        $manifest = ['version' => '1.0.0', 'menu' => [], 'pages' => []];
        $hash     = $this->service->hashManifest(manifest: $manifest);

        $current = ['semver' => '0.2.3', 'manifestHash' => $hash, 'manifest' => $manifest];
        $next    = ['semver' => '0.2.3', 'manifest' => $manifest, 'name' => 'New display name'];

        $result = $this->service->onSave(current: $current, next: $next);

        self::assertSame('0.2.3', $result['semver']);
        self::assertSame($hash, $result['manifestHash']);
    }//end testOnSaveMetadataOnlyEditDoesNotBump()

    /**
     * Manifest content change patch-bumps semver.
     *
     * @return void
     */
    public function testOnSaveManifestChangeBumpsPatch(): void
    {
        $oldManifest = ['version' => '1.0.0', 'menu' => [], 'pages' => []];
        $newManifest = ['version' => '1.0.0', 'menu' => [], 'pages' => [['id' => 'Home', 'type' => 'index']]];
        $oldHash     = $this->service->hashManifest(manifest: $oldManifest);

        $current = ['semver' => '0.1.5', 'manifestHash' => $oldHash, 'manifest' => $oldManifest];
        $next    = ['semver' => '0.1.5', 'manifest' => $newManifest];

        $result = $this->service->onSave(current: $current, next: $next);

        self::assertSame('0.1.6', $result['semver']);
        self::assertNotSame($oldHash, $result['manifestHash']);
        self::assertSame($this->service->hashManifest(manifest: $newManifest), $result['manifestHash']);
    }//end testOnSaveManifestChangeBumpsPatch()

    /**
     * Cycle guard rejects an explicit self-loop.
     *
     * @return void
     */
    public function testGuardNoCycleRejectsSelfLoop(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/promotesTo/');
        $this->service->guardNoCycle(currentUuid: 'uuid-a', proposedTargetUuid: 'uuid-a');
    }//end testGuardNoCycleRejectsSelfLoop()

    /**
     * Cycle guard accepts a null target (terminal version).
     *
     * @return void
     */
    public function testGuardNoCycleAcceptsNullTarget(): void
    {
        $this->service->guardNoCycle(currentUuid: 'uuid-a', proposedTargetUuid: null);
        // No throw — test passes if we reach this point.
        self::assertTrue(true);
    }//end testGuardNoCycleAcceptsNullTarget()

    /**
     * Cycle guard catches an indirect cycle via promotesTo chain.
     *
     * Chain: A → B → C; saving C with promotesTo=A would close the loop.
     *
     * @return void
     */
    public function testGuardNoCycleDetectsIndirectCycle(): void
    {
        // C.promotesTo = A. Walk: A → A.promotesTo (B), B → B.promotesTo (C).
        // Once cursor lands on C (== currentUuid), the guard throws.
        $aEntity = $this->mockVersion(promotesTo: 'uuid-b');
        $bEntity = $this->mockVersion(promotesTo: 'uuid-c');

        $this->objectService->method('find')
            ->willReturnCallback(static function (string|int $id) use ($aEntity, $bEntity): ?ObjectEntity {
                return match ($id) {
                    'uuid-a' => $aEntity,
                    'uuid-b' => $bEntity,
                    default  => null,
                };
            });

        $this->expectException(RuntimeException::class);
        $this->service->guardNoCycle(currentUuid: 'uuid-c', proposedTargetUuid: 'uuid-a');
    }//end testGuardNoCycleDetectsIndirectCycle()

    /**
     * Cycle guard accepts a valid linear chain extension.
     *
     * Chain: A → B; saving B.promotesTo = C (which has no promotesTo) is fine.
     *
     * @return void
     */
    public function testGuardNoCycleAcceptsLinearExtension(): void
    {
        $cEntity = $this->mockVersion(promotesTo: null);

        $this->objectService->method('find')
            ->willReturnCallback(static fn (string|int $id): ?ObjectEntity => $id === 'uuid-c' ? $cEntity : null);

        $this->service->guardNoCycle(currentUuid: 'uuid-b', proposedTargetUuid: 'uuid-c');
        self::assertTrue(true);
    }//end testGuardNoCycleAcceptsLinearExtension()

    /**
     * productionVersion guard rejects a foreign ApplicationVersion.
     *
     * @return void
     */
    public function testGuardProductionVersionOwnershipRejectsForeignVersion(): void
    {
        $foreignVersion = $this->mockVersion(application: 'uuid-other-app');
        $this->objectService->method('find')->willReturn($foreignVersion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/back-reference mismatch/');
        $this->service->guardProductionVersionOwnership(
            applicationUuid: 'uuid-this-app',
            proposedVersionUuid: 'uuid-v'
        );
    }//end testGuardProductionVersionOwnershipRejectsForeignVersion()

    /**
     * productionVersion guard accepts a back-referencing version.
     *
     * @return void
     */
    public function testGuardProductionVersionOwnershipAcceptsValidVersion(): void
    {
        $validVersion = $this->mockVersion(application: 'uuid-this-app');
        $this->objectService->method('find')->willReturn($validVersion);

        $this->service->guardProductionVersionOwnership(
            applicationUuid: 'uuid-this-app',
            proposedVersionUuid: 'uuid-v'
        );
        self::assertTrue(true);
    }//end testGuardProductionVersionOwnershipAcceptsValidVersion()

    /**
     * deleteVersion(unknown strategy) throws (controller maps to 400).
     *
     * @return void
     */
    public function testDeleteVersionRejectsUnknownStrategy(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown deletion strategy/');
        $this->service->deleteVersion(versionUuid: 'uuid-v', strategy: 'bogus');
    }//end testDeleteVersionRejectsUnknownStrategy()

    /**
     * deleteVersion refuses to delete an Application's production version.
     *
     * @return void
     */
    public function testDeleteVersionRefusesProductionVersion(): void
    {
        $version = $this->mockVersion(application: 'uuid-app', register: 'openbuilt-foo-prod', uuid: 'uuid-v');
        $application = $this->mockApplication(productionVersion: 'uuid-v');

        $this->objectService->method('find')
            ->willReturnCallback(static function (string|int $id) use ($version, $application): ?ObjectEntity {
                return match ($id) {
                    'uuid-v'   => $version,
                    'uuid-app' => $application,
                    default    => null,
                };
            });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/production version/');
        $this->service->deleteVersion(
            versionUuid: 'uuid-v',
            strategy: ApplicationVersionService::STRATEGY_DELETE_NOW
        );
    }//end testDeleteVersionRefusesProductionVersion()

    /**
     * delete-now drops the per-version register via RegisterService.
     *
     * @return void
     */
    public function testDeleteVersionDeleteNowDropsRegister(): void
    {
        $version     = $this->mockVersion(application: 'uuid-app', register: 'openbuilt-foo-staging', uuid: 'uuid-v');
        $application = $this->mockApplication(productionVersion: 'uuid-prod');

        $this->objectService->method('find')
            ->willReturnCallback(static function (string|int $id) use ($version, $application): ?ObjectEntity {
                return match ($id) {
                    'uuid-v'   => $version,
                    'uuid-app' => $application,
                    default    => null,
                };
            });

        $register = $this->getMockBuilder(Register::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->registerMapper->method('find')->with('openbuilt-foo-staging')->willReturn($register);

        $this->registerService->expects(self::once())->method('delete')->with($register);
        $this->objectService->expects(self::once())->method('deleteObject')->with('uuid-v');

        $this->service->deleteVersion(
            versionUuid: 'uuid-v',
            strategy: ApplicationVersionService::STRATEGY_DELETE_NOW
        );
    }//end testDeleteVersionDeleteNowDropsRegister()

    /**
     * keep-register leaves the register untouched (no delete call).
     *
     * @return void
     */
    public function testDeleteVersionKeepRegisterDoesNotDropRegister(): void
    {
        $version     = $this->mockVersion(application: 'uuid-app', register: 'openbuilt-foo-staging', uuid: 'uuid-v');
        $application = $this->mockApplication(productionVersion: 'uuid-prod');

        $this->objectService->method('find')
            ->willReturnCallback(static function (string|int $id) use ($version, $application): ?ObjectEntity {
                return match ($id) {
                    'uuid-v'   => $version,
                    'uuid-app' => $application,
                    default    => null,
                };
            });

        $this->registerService->expects(self::never())->method('delete');
        $this->objectService->expects(self::once())->method('deleteObject')->with('uuid-v');

        $this->service->deleteVersion(
            versionUuid: 'uuid-v',
            strategy: ApplicationVersionService::STRATEGY_KEEP_REGISTER
        );
    }//end testDeleteVersionKeepRegisterDoesNotDropRegister()

    /**
     * Build a mock ObjectEntity standing in for an ApplicationVersion row.
     *
     * @param string|null $promotesTo  Optional promotesTo target UUID
     * @param string|null $application Optional parent Application UUID
     * @param string|null $register    Optional per-version register slug
     * @param string|null $uuid        Optional own UUID
     *
     * @return ObjectEntity&MockObject
     */
    private function mockVersion(
        ?string $promotesTo=null,
        ?string $application=null,
        ?string $register=null,
        ?string $uuid=null
    ): ObjectEntity&MockObject {
        $entity = $this->createMock(ObjectEntity::class);
        $payload = [];
        if ($promotesTo !== null) {
            $payload['promotesTo'] = $promotesTo;
        }

        if ($application !== null) {
            $payload['application'] = $application;
        }

        if ($register !== null) {
            $payload['register'] = $register;
        }

        if ($uuid !== null) {
            $payload['id'] = $uuid;
        }

        $entity->method('jsonSerialize')->willReturn($payload);
        return $entity;
    }//end mockVersion()

    /**
     * Build a mock ObjectEntity standing in for an Application row.
     *
     * @param string|null $productionVersion Optional productionVersion UUID
     *
     * @return ObjectEntity&MockObject
     */
    private function mockApplication(?string $productionVersion=null): ObjectEntity&MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $payload = [];
        if ($productionVersion !== null) {
            $payload['productionVersion'] = $productionVersion;
        }

        $entity->method('jsonSerialize')->willReturn($payload);
        return $entity;
    }//end mockApplication()
}//end class
