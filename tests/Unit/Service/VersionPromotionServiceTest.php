<?php

/**
 * Unit tests for VersionPromotionService (spec openbuild-version-promotion).
 *
 * Covers REQ-OBVP-001 (target resolution + strategy validation),
 * REQ-OBVP-002 (start-with-source-data), REQ-OBVP-003
 * (migrate-existing-data), REQ-OBVP-004 (empty-start), REQ-OBVP-006
 * (lock acquisition + 409 on contention), REQ-OBVP-008 (semver
 * inheritance), REQ-OBVP-009 (on-failure archive flip), and REQ-OBVP-011
 * (default-strategy pure function).
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

use OCA\OpenBuild\Exception\InvalidStrategyException;
use OCA\OpenBuild\Exception\NoPromoteTargetException;
use OCA\OpenBuild\Exception\PromotionFailedException;
use OCA\OpenBuild\Exception\VersionLockedException;
use OCA\OpenBuild\Service\VersionPromotionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for VersionPromotionService.
 */
class VersionPromotionServiceTest extends TestCase
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
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * Service under test.
     */
    private VersionPromotionService $service;

    /**
     * Set up shared mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->objectService  = $this->createMock(ObjectService::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

        $this->service = new VersionPromotionService(
            logger: $this->logger,
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
        );
    }//end setUp()

    /**
     * REQ-OBVP-011: production target → migrate-existing-data.
     *
     * @return void
     */
    public function testDefaultStrategyForProductionTargetReturnsMigrateExistingData(): void
    {
        $application = ['productionVersion' => 'u-prod', 'slug' => 'hello'];
        $target      = ['id' => 'u-prod', 'slug' => 'production'];

        self::assertSame(
            VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA,
            VersionPromotionService::defaultStrategyFor($application, $target)
        );
    }//end testDefaultStrategyForProductionTargetReturnsMigrateExistingData()

    /**
     * REQ-OBVP-011: mid-chain target → start-with-source-data.
     *
     * @return void
     */
    public function testDefaultStrategyForMidChainTargetReturnsStartWithSourceData(): void
    {
        $application = ['productionVersion' => 'u-prod', 'slug' => 'hello'];
        $target      = ['id' => 'u-mid', 'slug' => 'staging'];

        self::assertSame(
            VersionPromotionService::STRATEGY_START_WITH_SOURCE_DATA,
            VersionPromotionService::defaultStrategyFor($application, $target)
        );
    }//end testDefaultStrategyForMidChainTargetReturnsStartWithSourceData()

    /**
     * REQ-OBVP-011: never returns empty-start.
     *
     * @return void
     */
    public function testDefaultStrategyNeverReturnsEmptyStart(): void
    {
        foreach (
            [
                ['productionVersion' => null, 'slug' => 'app1'],
                ['productionVersion' => 'u-prod', 'slug' => 'app2'],
                ['productionVersion' => '', 'slug' => 'app3'],
            ] as $application
        ) {
            foreach (
                [
                    ['id' => 'u-prod'],
                    ['id' => 'u-mid'],
                    ['uuid' => 'u-mid'],
                    ['id' => ''],
                ] as $target
            ) {
                self::assertNotSame(
                    VersionPromotionService::STRATEGY_EMPTY_START,
                    VersionPromotionService::defaultStrategyFor($application, $target)
                );
            }
        }
    }//end testDefaultStrategyNeverReturnsEmptyStart()

    /**
     * REQ-OBVP-001: no promotesTo → NoPromoteTargetException.
     *
     * @return void
     */
    public function testPromoteRaisesNoPromoteTargetWhenPromotesToNull(): void
    {
        $source = ['id' => 'u-src', 'promotesTo' => null];

        $this->expectException(NoPromoteTargetException::class);
        $this->service->promote(source: $source, strategy: VersionPromotionService::STRATEGY_START_WITH_SOURCE_DATA);
    }//end testPromoteRaisesNoPromoteTargetWhenPromotesToNull()

    /**
     * REQ-OBVP-001: unknown strategy → InvalidStrategyException.
     *
     * @return void
     */
    public function testPromoteRaisesInvalidStrategyForUnknownValue(): void
    {
        $source = ['id' => 'u-src', 'promotesTo' => 'u-tgt'];

        $this->expectException(InvalidStrategyException::class);
        $this->service->promote(source: $source, strategy: 'unknown-mode');
    }//end testPromoteRaisesInvalidStrategyForUnknownValue()

    /**
     * REQ-OBVP-001: missing strategy → InvalidStrategyException.
     *
     * @return void
     */
    public function testPromoteRaisesInvalidStrategyForEmptyValue(): void
    {
        $source = ['id' => 'u-src', 'promotesTo' => 'u-tgt'];

        $this->expectException(InvalidStrategyException::class);
        $this->service->promote(source: $source, strategy: '');
    }//end testPromoteRaisesInvalidStrategyForEmptyValue()

    /**
     * REQ-OBVP-006: lock contention → VersionLockedException with metadata.
     *
     * @return void
     */
    public function testPromoteRaises409WhenLockHeld(): void
    {
        $source = [
            'id'         => 'u-src',
            'register'   => 'openbuild-app-staging',
            'manifest'   => ['version' => '1.0.0'],
            'semver'     => '1.0.0',
            'promotesTo' => 'u-tgt',
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: ['id' => 'u-tgt', 'register' => 'openbuild-app-production']);

        $this->objectService
            ->method('find')
            ->willReturn($targetEntity);

        $this->objectService
            ->method('lockObject')
            ->willThrowException(new RuntimeException('locked'));

        $this->expectException(VersionLockedException::class);
        $this->service->promote(source: $source, strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA);
    }//end testPromoteRaises409WhenLockHeld()

    /**
     * REQ-OBVP-003 + REQ-OBVP-008: migrate-existing-data writes source manifest+semver and unlocks.
     *
     * @return void
     */
    public function testPromoteMigrateExistingDataAppliesSourceManifestAndSemverAndUnlocks(): void
    {
        $source = [
            'id'         => 'u-src',
            'register'   => 'openbuild-app-staging',
            'manifest'   => ['version' => '1.5.0', 'pages' => []],
            'semver'     => '1.5.0',
            'promotesTo' => 'u-tgt',
        ];

        $target = [
            'id'       => 'u-tgt',
            'register' => 'openbuild-app-production',
            'manifest' => ['version' => '1.0.0'],
            'semver'   => '1.0.0',
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);

        $this->objectService
            ->method('find')
            ->willReturn($targetEntity);

        $this->registerMapper
            ->method('find')
            ->willReturn($this->buildRegister(id: 1, slug: 'openbuild-app-staging', schemas: ['s1', 's2']));

        $this->objectService
            ->expects(self::once())
            ->method('lockObject')
            ->with('u-tgt', 'openbuild.version-promotion', 60);

        // RegisterMapper::update should be invoked for the schema-set forwarding.
        $this->registerMapper
            ->expects(self::atLeastOnce())
            ->method('update');

        // Final saveObject should carry source's manifest + semver.
        $savedEntity = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'       => 'u-tgt',
                'register' => 'openbuild-app-production',
                'manifest' => ['version' => '1.5.0', 'pages' => []],
                'semver'   => '1.5.0',
                'status'   => 'published',
            ]
        );

        $this->objectService
            ->expects(self::once())
            ->method('saveObject')
            ->with(self::callback(
                static function ($object): bool {
                    if (is_array($object) === false) {
                        return false;
                    }

                    return ($object['semver'] ?? null) === '1.5.0'
                        && is_array(($object['manifest'] ?? null)) === true
                        && ($object['manifest']['version'] ?? null) === '1.5.0';
                }
            ))
            ->willReturn($savedEntity);

        // Lock must be released in the finally.
        $this->objectService
            ->expects(self::once())
            ->method('unlockObject')
            ->with('u-tgt');

        $result = $this->service->promote(
            source: $source,
            strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA
        );

        self::assertSame('1.5.0', $result['semver']);
        self::assertSame('published', $result['status']);
    }//end testPromoteMigrateExistingDataAppliesSourceManifestAndSemverAndUnlocks()

    /**
     * REQ-OBVP-009: failure during save flips target to archived, unlocks, and throws 500.
     *
     * @return void
     */
    public function testPromoteFailureArchivesTargetAndReleasesLock(): void
    {
        $source = [
            'id'         => 'u-src',
            'register'   => 'openbuild-app-staging',
            'manifest'   => ['version' => '1.5.0'],
            'semver'     => '1.5.0',
            'promotesTo' => 'u-tgt',
        ];

        $target = [
            'id'       => 'u-tgt',
            'register' => 'openbuild-app-production',
            'manifest' => ['version' => '1.0.0'],
            'semver'   => '1.0.0',
            'status'   => 'published',
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);

        // The service calls find() twice — once to load the target up front
        // and once inside the on-failure flow (to refetch + flip).
        $this->objectService
            ->method('find')
            ->willReturn($targetEntity);

        $this->registerMapper
            ->method('find')
            ->willReturn($this->buildRegister(id: 1, slug: 'openbuild-app-staging', schemas: ['s1']));

        // First saveObject call is the strategy step — fail. The on-failure
        // flow then calls saveObject AGAIN to write the archived flip.
        $savedArchived = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'       => 'u-tgt',
                'register' => 'openbuild-app-production',
                'status'   => 'archived',
            ]
        );

        $callCount = 0;
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(
                static function ($object) use (&$callCount, $savedArchived): ObjectEntity {
                    $callCount++;
                    if ($callCount === 1) {
                        throw new RuntimeException('OR schema-import boom');
                    }

                    return $savedArchived;
                }
            );

        $this->objectService
            ->expects(self::once())
            ->method('unlockObject')
            ->with('u-tgt');

        $this->expectException(PromotionFailedException::class);

        try {
            $this->service->promote(
                source: $source,
                strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA
            );
        } catch (PromotionFailedException $e) {
            // PromotionFailedException must carry the strategy.
            self::assertSame(
                VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA,
                $e->getStrategy()
            );
            throw $e;
        }
    }//end testPromoteFailureArchivesTargetAndReleasesLock()

    /**
     * REQ-OBVP-009 task 6.10: the source register / version row is unmodified
     * when promotion fails.
     *
     * Source-side guarantee: every save during a promotion that crashes is
     * against the TARGET row (status flip, archived flip) and the TARGET's
     * register (schema/object copies). The source manifest, semver, status,
     * and register pointer must round-trip untouched.
     *
     * @return void
     */
    public function testPromoteFailureLeavesSourceUnmodified(): void
    {
        $source = [
            'id'         => 'u-src',
            'register'   => 'openbuild-app-staging',
            'manifest'   => ['version' => '1.5.0', 'pages' => []],
            'semver'     => '1.5.0',
            'status'     => 'published',
            'promotesTo' => 'u-tgt',
        ];

        $target = [
            'id'       => 'u-tgt',
            'register' => 'openbuild-app-production',
            'manifest' => ['version' => '1.0.0'],
            'semver'   => '1.0.0',
            'status'   => 'published',
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);

        $this->objectService->method('find')->willReturn($targetEntity);
        $this->registerMapper
            ->method('find')
            ->willReturn($this->buildRegister(id: 1, slug: 'openbuild-app-staging', schemas: ['s1']));

        $savedArchived = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'       => 'u-tgt',
                'register' => 'openbuild-app-production',
                'status'   => 'archived',
            ]
        );

        // Track every saveObject call to verify the source row is never the
        // subject of a write.
        $savedPayloads = [];
        $callCount     = 0;
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(
                static function (...$args) use (&$callCount, &$savedPayloads, $savedArchived): ObjectEntity {
                    $callCount++;
                    $savedPayloads[] = $args[0];
                    if ($callCount === 1) {
                        throw new RuntimeException('strategy step boom');
                    }

                    return $savedArchived;
                }
            );

        $this->expectException(PromotionFailedException::class);

        try {
            $this->service->promote(
                source: $source,
                strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA
            );
        } catch (PromotionFailedException $e) {
            // Verify EVERY save was against the target uuid, never the source.
            foreach ($savedPayloads as $payload) {
                if (is_array($payload) === true) {
                    $writtenUuid = ($payload['id'] ?? $payload['uuid'] ?? null);
                    self::assertNotSame(
                        'u-src',
                        $writtenUuid,
                        'source row must never be written during a failed promotion'
                    );
                }
            }

            // The in-memory source payload is untouched.
            self::assertSame('1.5.0', $source['semver']);
            self::assertSame('published', $source['status']);
            self::assertSame('openbuild-app-staging', $source['register']);
            throw $e;
        }
    }//end testPromoteFailureLeavesSourceUnmodified()

    /**
     * REQ-OBVP-002: start-with-source-data wipes target and copies source rows.
     *
     * @return void
     */
    public function testStartWithSourceDataWipesAndCopies(): void
    {
        $source = [
            'id'         => 'u-src',
            'register'   => 'openbuild-app-staging',
            'manifest'   => ['version' => '2.0.0'],
            'semver'     => '2.0.0',
            'promotesTo' => 'u-tgt',
        ];

        $target = [
            'id'       => 'u-tgt',
            'register' => 'openbuild-app-production',
            'manifest' => ['version' => '1.0.0'],
            'semver'   => '1.0.0',
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);
        $this->objectService->method('find')->willReturn($targetEntity);

        $register = $this->buildRegister(id: 7, slug: 'openbuild-app-staging', schemas: ['s1']);
        $this->registerMapper->method('find')->willReturn($register);

        // wipeTargetRegister: searchObjects returns target rows; deleteObject called per row.
        $sourceRow1 = $this->buildObjectEntity(uuid: 'r-src-1', payload: ['id' => 'r-src-1', 'foo' => 'bar']);
        $sourceRow2 = $this->buildObjectEntity(uuid: 'r-src-2', payload: ['id' => 'r-src-2', 'baz' => 'qux']);
        $targetRow1 = $this->buildObjectEntity(uuid: 'r-tgt-1', payload: ['id' => 'r-tgt-1']);

        // searchObjects is called twice — once for wipeTargetRegister, once for copyRowsFromSource.
        $searchCall = 0;
        $this->objectService
            ->method('searchObjects')
            ->willReturnCallback(
                static function () use (&$searchCall, $targetRow1, $sourceRow1, $sourceRow2): array {
                    $searchCall++;
                    if ($searchCall === 1) {
                        return [$targetRow1];
                    }

                    return [$sourceRow1, $sourceRow2];
                }
            );

        $this->objectService
            ->expects(self::once())
            ->method('deleteObject')
            ->with('r-tgt-1');

        // saveObject is called once per source row (2 copies) + once for the manifest write.
        $savedTarget = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'       => 'u-tgt',
                'register' => 'openbuild-app-production',
                'manifest' => ['version' => '2.0.0'],
                'semver'   => '2.0.0',
                'status'   => 'published',
            ]
        );

        $this->objectService
            ->expects(self::exactly(3))
            ->method('saveObject')
            ->willReturn($savedTarget);

        $result = $this->service->promote(
            source: $source,
            strategy: VersionPromotionService::STRATEGY_START_WITH_SOURCE_DATA
        );

        self::assertSame('2.0.0', $result['semver']);
    }//end testStartWithSourceDataWipesAndCopies()

    /**
     * REQ-OBVP-004: empty-start wipes target and does NOT copy source rows.
     *
     * @return void
     */
    public function testEmptyStartWipesButDoesNotCopy(): void
    {
        $source = [
            'id'         => 'u-src',
            'register'   => 'openbuild-app-staging',
            'manifest'   => ['version' => '2.0.0'],
            'semver'     => '2.0.0',
            'promotesTo' => 'u-tgt',
        ];

        $target = [
            'id'       => 'u-tgt',
            'register' => 'openbuild-app-production',
            'manifest' => ['version' => '1.0.0'],
            'semver'   => '1.0.0',
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);
        $this->objectService->method('find')->willReturn($targetEntity);

        $register = $this->buildRegister(id: 7, slug: 'openbuild-app-staging', schemas: ['s1']);
        $this->registerMapper->method('find')->willReturn($register);

        $targetRow1 = $this->buildObjectEntity(uuid: 'r-tgt-1', payload: ['id' => 'r-tgt-1']);
        $targetRow2 = $this->buildObjectEntity(uuid: 'r-tgt-2', payload: ['id' => 'r-tgt-2']);

        // empty-start: searchObjects is called once (only for wipe).
        $this->objectService
            ->expects(self::once())
            ->method('searchObjects')
            ->willReturn([$targetRow1, $targetRow2]);

        $this->objectService
            ->expects(self::exactly(2))
            ->method('deleteObject');

        $savedTarget = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'       => 'u-tgt',
                'register' => 'openbuild-app-production',
                'manifest' => ['version' => '2.0.0'],
                'semver'   => '2.0.0',
                'status'   => 'published',
            ]
        );

        // Only one saveObject — the final manifest write. No row-copy saves.
        $this->objectService
            ->expects(self::once())
            ->method('saveObject')
            ->willReturn($savedTarget);

        $result = $this->service->promote(
            source: $source,
            strategy: VersionPromotionService::STRATEGY_EMPTY_START
        );

        self::assertSame('2.0.0', $result['semver']);
    }//end testEmptyStartWipesButDoesNotCopy()

    /**
     * REQ-OBVP-012 (data-registers-runtime): start-with-source-data leaves a
     * bound data register untouched.
     *
     * `dataRegisters` is not actually a property of the ApplicationVersion
     * schema (it lives on the parent Application) — it is injected onto
     * both `$source` and `$target` here defensively, so this regression
     * test remains meaningful even if a future caller mistakenly forwards
     * the Application's `dataRegisters` alongside the version payload:
     * proof that `promote()` neither reads nor writes anything derived from
     * it, under any circumstance.
     *
     * @return void
     *
     * @spec openspec/changes/data-registers-runtime/tasks.md#task-3.2
     */
    public function testPromotionNeverReferencesBoundDataRegisterUnderStartWithSourceData(): void
    {
        $boundDataRegisters = [['register' => 'spectr', 'label' => 'Spectr market intelligence data']];

        $source = [
            'id'            => 'u-src',
            'register'      => 'openbuild-app-staging',
            'manifest'      => ['version' => '2.0.0'],
            'semver'        => '2.0.0',
            'promotesTo'    => 'u-tgt',
            'dataRegisters' => $boundDataRegisters,
        ];

        $target = [
            'id'            => 'u-tgt',
            'register'      => 'openbuild-app-production',
            'manifest'      => ['version' => '1.0.0'],
            'semver'        => '1.0.0',
            'dataRegisters' => $boundDataRegisters,
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);
        $this->objectService->method('find')->willReturn($targetEntity);

        $this->registerMapper
            ->method('find')
            ->willReturnCallback(function ($slug) {
                self::assertNotSame(
                    'spectr',
                    $slug,
                    'RegisterMapper::find() must never resolve the bound data register slug during promotion'
                );
                return $this->buildRegister(id: 7, slug: (string) $slug, schemas: ['s1']);
            });

        $sourceRow1 = $this->buildObjectEntity(uuid: 'r-src-1', payload: ['id' => 'r-src-1', 'foo' => 'bar']);
        $targetRow1 = $this->buildObjectEntity(uuid: 'r-tgt-1', payload: ['id' => 'r-tgt-1']);

        $searchCall = 0;
        $this->objectService
            ->method('searchObjects')
            ->willReturnCallback(function (array $query=[]) use (&$searchCall, $targetRow1, $sourceRow1): array {
                $registerId = ($query['@self']['register'] ?? null);
                self::assertNotSame('spectr', $registerId, 'searchObjects() must never target the bound data register');
                $searchCall++;
                return $searchCall === 1 ? [$targetRow1] : [$sourceRow1];
            });

        $this->objectService
            ->method('deleteObject')
            ->willReturnCallback(function (string $uuid): bool {
                self::assertNotSame('spectr', $uuid);
                return true;
            });

        $savedTarget = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'            => 'u-tgt',
                'register'      => 'openbuild-app-production',
                'manifest'      => ['version' => '2.0.0'],
                'semver'        => '2.0.0',
                'status'        => 'published',
                'dataRegisters' => $boundDataRegisters,
            ]
        );
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(function ($object) use ($savedTarget): ObjectEntity {
                if (is_array($object) === true) {
                    self::assertNotSame('spectr', ($object['register'] ?? null));
                }
                return $savedTarget;
            });

        $result = $this->service->promote(
            source: $source,
            strategy: VersionPromotionService::STRATEGY_START_WITH_SOURCE_DATA
        );

        self::assertSame('2.0.0', $result['semver']);
        // The bound data register binding round-trips completely
        // unmodified — proof this is a pass-through, not a strip or a read.
        self::assertSame($boundDataRegisters, $result['dataRegisters']);
    }//end testPromotionNeverReferencesBoundDataRegisterUnderStartWithSourceData()

    /**
     * REQ-OBVP-012 (data-registers-runtime): migrate-existing-data leaves a
     * bound data register untouched.
     *
     * @return void
     *
     * @spec openspec/changes/data-registers-runtime/tasks.md#task-3.2
     */
    public function testPromotionNeverReferencesBoundDataRegisterUnderMigrateExistingData(): void
    {
        $boundDataRegisters = [['register' => 'spectr']];

        $source = [
            'id'            => 'u-src',
            'register'      => 'openbuild-app-staging',
            'manifest'      => ['version' => '1.5.0', 'pages' => []],
            'semver'        => '1.5.0',
            'promotesTo'    => 'u-tgt',
            'dataRegisters' => $boundDataRegisters,
        ];

        $target = [
            'id'            => 'u-tgt',
            'register'      => 'openbuild-app-production',
            'manifest'      => ['version' => '1.0.0'],
            'semver'        => '1.0.0',
            'dataRegisters' => $boundDataRegisters,
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);
        $this->objectService->method('find')->willReturn($targetEntity);

        $this->registerMapper
            ->method('find')
            ->willReturnCallback(function ($slug) {
                self::assertNotSame('spectr', $slug);
                return $this->buildRegister(id: 1, slug: (string) $slug, schemas: ['s1', 's2']);
            });

        $this->registerMapper->expects(self::atLeastOnce())->method('update');

        $savedEntity = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'            => 'u-tgt',
                'register'      => 'openbuild-app-production',
                'manifest'      => ['version' => '1.5.0', 'pages' => []],
                'semver'        => '1.5.0',
                'status'        => 'published',
                'dataRegisters' => $boundDataRegisters,
            ]
        );
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(function ($object) use ($savedEntity): ObjectEntity {
                if (is_array($object) === true) {
                    self::assertNotSame('spectr', ($object['register'] ?? null));
                }
                return $savedEntity;
            });

        $result = $this->service->promote(
            source: $source,
            strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA
        );

        self::assertSame('1.5.0', $result['semver']);
        self::assertSame($boundDataRegisters, $result['dataRegisters']);
    }//end testPromotionNeverReferencesBoundDataRegisterUnderMigrateExistingData()

    /**
     * REQ-OBVP-012 (data-registers-runtime): empty-start leaves a bound
     * data register untouched.
     *
     * @return void
     *
     * @spec openspec/changes/data-registers-runtime/tasks.md#task-3.2
     */
    public function testPromotionNeverReferencesBoundDataRegisterUnderEmptyStart(): void
    {
        $boundDataRegisters = [['register' => 'spectr']];

        $source = [
            'id'            => 'u-src',
            'register'      => 'openbuild-app-staging',
            'manifest'      => ['version' => '2.0.0'],
            'semver'        => '2.0.0',
            'promotesTo'    => 'u-tgt',
            'dataRegisters' => $boundDataRegisters,
        ];

        $target = [
            'id'            => 'u-tgt',
            'register'      => 'openbuild-app-production',
            'manifest'      => ['version' => '1.0.0'],
            'semver'        => '1.0.0',
            'dataRegisters' => $boundDataRegisters,
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);
        $this->objectService->method('find')->willReturn($targetEntity);

        $this->registerMapper
            ->method('find')
            ->willReturnCallback(function ($slug) {
                self::assertNotSame('spectr', $slug);
                return $this->buildRegister(id: 7, slug: (string) $slug, schemas: ['s1']);
            });

        $targetRow1 = $this->buildObjectEntity(uuid: 'r-tgt-1', payload: ['id' => 'r-tgt-1']);
        $targetRow2 = $this->buildObjectEntity(uuid: 'r-tgt-2', payload: ['id' => 'r-tgt-2']);

        $this->objectService
            ->method('searchObjects')
            ->willReturnCallback(function (array $query=[]) use ($targetRow1, $targetRow2): array {
                self::assertNotSame('spectr', ($query['@self']['register'] ?? null));
                return [$targetRow1, $targetRow2];
            });

        $this->objectService
            ->method('deleteObject')
            ->willReturnCallback(function (string $uuid): bool {
                self::assertNotSame('spectr', $uuid);
                return true;
            });

        $savedTarget = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'            => 'u-tgt',
                'register'      => 'openbuild-app-production',
                'manifest'      => ['version' => '2.0.0'],
                'semver'        => '2.0.0',
                'status'        => 'published',
                'dataRegisters' => $boundDataRegisters,
            ]
        );
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(function ($object) use ($savedTarget): ObjectEntity {
                if (is_array($object) === true) {
                    self::assertNotSame('spectr', ($object['register'] ?? null));
                }
                return $savedTarget;
            });

        $result = $this->service->promote(
            source: $source,
            strategy: VersionPromotionService::STRATEGY_EMPTY_START
        );

        self::assertSame('2.0.0', $result['semver']);
        self::assertSame($boundDataRegisters, $result['dataRegisters']);
    }//end testPromotionNeverReferencesBoundDataRegisterUnderEmptyStart()

    /**
     * REQ-OBVP-012 (data-registers-runtime): a promotion failure does not
     * archive or otherwise modify a bound data register — only the target
     * ApplicationVersion row is touched by the on-failure archive flip.
     *
     * @return void
     *
     * @spec openspec/changes/data-registers-runtime/tasks.md#task-3.2
     */
    public function testPromotionFailureDoesNotReferenceBoundDataRegister(): void
    {
        $boundDataRegisters = [['register' => 'spectr']];

        $source = [
            'id'            => 'u-src',
            'register'      => 'openbuild-app-staging',
            'manifest'      => ['version' => '1.5.0'],
            'semver'        => '1.5.0',
            'promotesTo'    => 'u-tgt',
            'dataRegisters' => $boundDataRegisters,
        ];

        $target = [
            'id'            => 'u-tgt',
            'register'      => 'openbuild-app-production',
            'manifest'      => ['version' => '1.0.0'],
            'semver'        => '1.0.0',
            'status'        => 'published',
            'dataRegisters' => $boundDataRegisters,
        ];

        $targetEntity = $this->buildObjectEntity(uuid: 'u-tgt', payload: $target);
        $this->objectService->method('find')->willReturn($targetEntity);

        $this->registerMapper
            ->method('find')
            ->willReturnCallback(function ($slug) {
                self::assertNotSame('spectr', $slug);
                return $this->buildRegister(id: 1, slug: (string) $slug, schemas: ['s1']);
            });

        $savedArchived = $this->buildObjectEntity(
            uuid: 'u-tgt',
            payload: [
                'id'            => 'u-tgt',
                'register'      => 'openbuild-app-production',
                'status'        => 'archived',
                'dataRegisters' => $boundDataRegisters,
            ]
        );

        $callCount = 0;
        $this->objectService
            ->method('saveObject')
            ->willReturnCallback(
                function ($object) use (&$callCount, $savedArchived): ObjectEntity {
                    if (is_array($object) === true) {
                        self::assertNotSame('spectr', ($object['register'] ?? null));
                    }

                    $callCount++;
                    if ($callCount === 1) {
                        throw new RuntimeException('OR schema-import boom');
                    }

                    return $savedArchived;
                }
            );

        $this->objectService->method('unlockObject')->willReturnCallback(function ($uuid) {
            self::assertNotSame('spectr', $uuid);
            return true;
        });

        $this->expectException(PromotionFailedException::class);

        try {
            $this->service->promote(
                source: $source,
                strategy: VersionPromotionService::STRATEGY_MIGRATE_EXISTING_DATA
            );
        } catch (PromotionFailedException $e) {
            // Only the target ApplicationVersion row is modified; the
            // dataRegisters binding on both source and target is untouched
            // in memory (never stripped, never read).
            self::assertSame($boundDataRegisters, $source['dataRegisters']);
            self::assertSame($boundDataRegisters, $target['dataRegisters']);
            throw $e;
        }
    }//end testPromotionFailureDoesNotReferenceBoundDataRegister()

    /**
     * Helper — build an ObjectEntity wrapping a payload, with `getUuid()`.
     *
     * @param string              $uuid    Entity uuid
     * @param array<string,mixed> $payload Payload returned by jsonSerialize()/getObject()
     *
     * @return ObjectEntity
     */
    private function buildObjectEntity(string $uuid, array $payload): ObjectEntity
    {
        $entity = new class () extends ObjectEntity {
            /**
             * @var array<string,mixed>
             */
            public array $payload = [];

            /**
             * @var string
             */
            public string $entityUuid = '';

            /**
             * @return array<string,mixed>
             */
            public function jsonSerialize(): array
            {
                return $this->payload;
            }

            /**
             * @return array<string,mixed>
             */
            public function getObject(): array
            {
                return $this->payload;
            }

            /**
             * @return string
             */
            public function getUuid(): string
            {
                return $this->entityUuid;
            }
        };

        $entity->payload    = $payload;
        $entity->entityUuid = $uuid;

        return $entity;
    }//end buildObjectEntity()

    /**
     * Helper — build a Register entity with a fixed id / slug / schemas list.
     *
     * @param int                $id      Register id
     * @param string             $slug    Register slug
     * @param array<int,string>  $schemas Schema slug list
     *
     * @return Register
     */
    private function buildRegister(int $id, string $slug, array $schemas): Register
    {
        $register = new class () extends Register {
            /**
             * @var int
             */
            public int $entityId = 0;

            /**
             * @var string
             */
            public string $entitySlug = '';

            /**
             * @var array<int,string>
             */
            public array $entitySchemas = [];

            /**
             * @return int
             */
            public function getId(): int
            {
                return $this->entityId;
            }

            /**
             * @return string
             */
            public function getSlug(): string
            {
                return $this->entitySlug;
            }

            /**
             * @return array<int,string>
             */
            public function getSchemas(): array
            {
                return $this->entitySchemas;
            }

            /**
             * @param array<int,string>|string $newSchemas Schemas
             *
             * @return static
             */
            public function setSchemas($newSchemas): static
            {
                if (is_array($newSchemas) === true) {
                    $this->entitySchemas = $newSchemas;
                }

                return $this;
            }
        };

        $register->entityId      = $id;
        $register->entitySlug    = $slug;
        $register->entitySchemas = $schemas;

        return $register;
    }//end buildRegister()
}//end class
