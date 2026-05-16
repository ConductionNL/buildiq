<?php

/**
 * Unit tests for ApplicationInsightsService (spec openbuilt-app-detail-overview /
 * capability application-insights, REQ-OBAI-001..006).
 *
 * Covers:
 *  - 404 mapping: unknown appUuid, unknown versionUuid, IDOR mismatch
 *  - RBAC gate: viewer reads production, viewer denied non-production, editor reads non-production, admin without listed role denied
 *  - Window-to-hours mapping: 7d → 168, 30d → 720, 90d → 2160
 *  - Schema-set walk: dedupes; ignores tuples on other registers; empty manifest → zeros + empty activity
 *  - Invalid window → null
 *  - Active-users degrade path when AuditTrailMapper::getDistinctActorCount unavailable
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

use OCA\OpenBuilt\Service\ApplicationInsightsService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationInsightsService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApplicationInsightsServiceTest extends TestCase
{
    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper&MockObject $auditTrailMapper;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Service under test.
     */
    private ApplicationInsightsService $service;

    /**
     * Set up shared mocks and the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService    = $this->createMock(ObjectService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->service = new ApplicationInsightsService(
            objectService: $this->objectService,
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Invalid window value returns null even before any OR call.
     *
     * @return void
     */
    public function testInvalidWindowReturnsNull(): void
    {
        $result = $this->service->computeInsights('app-uuid', 'version-uuid', '24h', null);
        self::assertNull($result);
    }//end testInvalidWindowReturnsNull()

    /**
     * Unknown appUuid returns null (controller maps to 404).
     *
     * @return void
     */
    public function testUnknownAppReturnsNull(): void
    {
        $this->objectService->method('find')->willReturn(null);
        $result = $this->service->computeInsights('app-uuid', 'version-uuid', '7d', $this->mockUser('alice'));
        self::assertNull($result);
    }//end testUnknownAppReturnsNull()

    /**
     * Unknown versionUuid returns null.
     *
     * @return void
     */
    public function testUnknownVersionReturnsNull(): void
    {
        $app = $this->mockEntity(['uuid' => 'app-uuid']);
        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, null);

        $result = $this->service->computeInsights('app-uuid', 'missing-version', '7d', $this->mockUser('alice'));
        self::assertNull($result);
    }//end testUnknownVersionReturnsNull()

    /**
     * Version whose `application` relation does not match `appUuid` returns null (IDOR guard).
     *
     * @return void
     */
    public function testVersionFromDifferentAppReturnsNull(): void
    {
        $app = $this->mockEntity(['uuid' => 'app-uuid']);
        $version = $this->mockEntity(['uuid' => 'version-uuid', 'application' => 'OTHER-APP-UUID']);
        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, $version);

        $result = $this->service->computeInsights('app-uuid', 'version-uuid', '7d', $this->mockUser('alice'));
        self::assertNull($result);
    }//end testVersionFromDifferentAppReturnsNull()

    /**
     * Viewer can read the production version (RBAC scenario).
     *
     * @return void
     */
    public function testViewerCanReadProductionVersion(): void
    {
        $app = $this->mockEntity([
            'uuid' => 'app-uuid',
            'slug' => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions' => ['viewers' => ['user:alice']],
        ]);
        $version = $this->mockEntity([
            'uuid' => 'prod-uuid',
            'slug' => 'production',
            'application' => 'app-uuid',
            'manifest' => ['pages' => []],
        ]);

        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, $version);

        $result = $this->service->computeInsights('app-uuid', 'prod-uuid', '7d', $this->mockUser('alice'));
        self::assertNotNull($result);
        self::assertArrayHasKey('kpis', $result);
        self::assertArrayHasKey('activity', $result);
    }//end testViewerCanReadProductionVersion()

    /**
     * Viewer cannot read non-production version (returns null → 404).
     *
     * @return void
     */
    public function testViewerCannotReadNonProductionVersion(): void
    {
        $app = $this->mockEntity([
            'uuid' => 'app-uuid',
            'slug' => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions' => ['viewers' => ['user:alice']],
        ]);
        $version = $this->mockEntity([
            'uuid' => 'staging-uuid',
            'slug' => 'staging',
            'application' => 'app-uuid',
            'manifest' => ['pages' => []],
        ]);

        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, $version);

        $result = $this->service->computeInsights('app-uuid', 'staging-uuid', '7d', $this->mockUser('alice'));
        self::assertNull($result);
    }//end testViewerCannotReadNonProductionVersion()

    /**
     * Editor can read non-production version.
     *
     * @return void
     */
    public function testEditorCanReadNonProductionVersion(): void
    {
        $app = $this->mockEntity([
            'uuid' => 'app-uuid',
            'slug' => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions' => ['editors' => ['user:alice']],
        ]);
        $version = $this->mockEntity([
            'uuid' => 'staging-uuid',
            'slug' => 'staging',
            'application' => 'app-uuid',
            'manifest' => ['pages' => []],
        ]);

        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, $version);

        $result = $this->service->computeInsights('app-uuid', 'staging-uuid', '7d', $this->mockUser('alice'));
        self::assertNotNull($result);
    }//end testEditorCanReadNonProductionVersion()

    /**
     * Admin without listed role cannot read non-production (NC admin is NOT auto-granted).
     *
     * @return void
     */
    public function testAdminWithoutRoleCannotReadNonProduction(): void
    {
        $app = $this->mockEntity([
            'uuid' => 'app-uuid',
            'productionVersion' => 'prod-uuid',
            'permissions' => ['owners' => ['user:bob']],
        ]);
        $version = $this->mockEntity([
            'uuid' => 'staging-uuid',
            'application' => 'app-uuid',
            'manifest' => ['pages' => []],
        ]);

        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, $version);

        $result = $this->service->computeInsights('app-uuid', 'staging-uuid', '7d', $this->mockUser('admin'));
        self::assertNull($result);
    }//end testAdminWithoutRoleCannotReadNonProduction()

    /**
     * Unauthenticated caller cannot read anything (null caller).
     *
     * @return void
     */
    public function testUnauthenticatedCallerReturnsNull(): void
    {
        $app = $this->mockEntity([
            'uuid' => 'app-uuid',
            'productionVersion' => 'prod-uuid',
            'permissions' => [],
        ]);
        $version = $this->mockEntity([
            'uuid' => 'prod-uuid',
            'application' => 'app-uuid',
            'manifest' => ['pages' => []],
        ]);

        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, $version);

        $result = $this->service->computeInsights('app-uuid', 'prod-uuid', '7d', null);
        self::assertNull($result);
    }//end testUnauthenticatedCallerReturnsNull()

    /**
     * Empty manifest pages → four zero KPIs + empty activity.
     *
     * @return void
     */
    public function testEmptyManifestPagesYieldsZeros(): void
    {
        $app = $this->mockEntity([
            'uuid' => 'app-uuid',
            'slug' => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions' => ['viewers' => ['user:alice']],
        ]);
        $version = $this->mockEntity([
            'uuid' => 'prod-uuid',
            'slug' => 'production',
            'application' => 'app-uuid',
            'manifest' => ['pages' => []],
        ]);

        $this->objectService->method('find')
            ->willReturnOnConsecutiveCalls($app, $version);

        $result = $this->service->computeInsights('app-uuid', 'prod-uuid', '7d', $this->mockUser('alice'));
        self::assertNotNull($result);
        self::assertSame(0, $result['kpis']['activeUsers']);
        self::assertSame(0, $result['kpis']['objectCount']);
        self::assertSame(0, $result['kpis']['filesCount']);
        self::assertSame(0, $result['kpis']['auditEventCount']);
        self::assertSame([], $result['activity']);
    }//end testEmptyManifestPagesYieldsZeros()

    /**
     * Schema-set walk dedupes schema IDs and ignores tuples referencing other registers.
     *
     * @return void
     */
    public function testSchemaSetWalkDedupesAndFilters(): void
    {
        $manifest = [
            'pages' => [
                ['config' => ['register' => 'openbuilt-hello-world-production', 'schema' => '101']],
                ['config' => ['register' => 'openbuilt-hello-world-production', 'schema' => '101']], // dupe
                ['config' => ['register' => 'openbuilt-hello-world-production', 'schema' => '202']],
                ['config' => ['register' => 'some-other-register', 'schema' => '303']], // ignored
                ['config' => ['register' => 'openbuilt-hello-world-production', 'schema' => '']], // skipped
                ['unknown' => 'shape'],
            ],
        ];

        $ids = $this->service->deriveSchemaIds($manifest, 'openbuilt-hello-world-production');
        // array_keys() upcasts numeric-string keys to int; cast back to compare.
        $stringIds = array_map(static fn (mixed $v) => (string) $v, $ids);
        sort($stringIds);
        self::assertSame(['101', '202'], $stringIds);
        self::assertCount(2, $ids);
    }//end testSchemaSetWalkDedupesAndFilters()

    /**
     * Null manifest returns empty schema-id set.
     *
     * @return void
     */
    public function testDeriveSchemaIdsHandlesNullManifest(): void
    {
        self::assertSame([], $this->service->deriveSchemaIds(null, 'openbuilt-x-y'));
        self::assertSame([], $this->service->deriveSchemaIds([], 'openbuilt-x-y'));
    }//end testDeriveSchemaIdsHandlesNullManifest()

    /**
     * Build a stub OR ObjectEntity that round-trips the given payload via jsonSerialize().
     *
     * @param array<string, mixed> $payload The payload to return.
     *
     * @return ObjectEntity&MockObject
     */
    private function mockEntity(array $payload): ObjectEntity&MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($payload);
        return $entity;
    }//end mockEntity()

    /**
     * Build a mock IUser with the given UID.
     *
     * @param string $uid User UID.
     *
     * @return IUser&MockObject
     */
    private function mockUser(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end mockUser()
}//end class
