<?php

/**
 * Unit tests for ManifestResolverService (spec openbuilt-version-routing).
 *
 * Covers REQ-OBVR-001 through REQ-OBVR-009:
 *  - REQ-OBVR-001 / REQ-OBVR-002: resolve() returns productionVersion manifest
 *    when no versionSlug is provided.
 *  - REQ-OBVR-003: resolve() returns null (→ 404) for unknown version slug.
 *  - REQ-OBVR-003: resolve() returns null (→ 404) for unauthorised caller on
 *    a non-production version (security-shaped 404, no existence leak).
 *  - REQ-OBVR-003: resolve() returns null when application slug is not found.
 *  - REQ-OBVR-004: resolve() allows authorised caller (owner) to access
 *    non-production version.
 *  - REQ-OBVR-004: resolve() allows authorised caller (editor) to access
 *    non-production version.
 *  - REQ-OBVR-005: resolve() returns productionVersion manifest via UUID
 *    when production UUID matches the resolved version.
 *  - NC admins are NOT auto-granted — absent from permissions still returns null.
 *  - Returns null when ApplicationVersion has no manifest.
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

use OCA\OpenBuilt\Service\ManifestResolverService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ManifestResolverService.
 */
class ManifestResolverServiceTest extends TestCase
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
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * Service under test.
     */
    private ManifestResolverService $service;

    /**
     * Set up shared mocks and the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);

        // RegisterMapper stub: find() returns a Register whose getId() returns 1.
        $register = $this->getMockBuilder(Register::class)
            ->addMethods(['getId'])
            ->getMock();
        $register->method('getId')->willReturn(1);

        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->registerMapper->method('find')->willReturn($register);

        // SchemaMapper stub: find() returns a Schema whose getId() returns 2.
        $schema = $this->getMockBuilder(Schema::class)
            ->addMethods(['getId'])
            ->getMock();
        $schema->method('getId')->willReturn(2);

        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->service = new ManifestResolverService(
            objectService: $this->objectService,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * REQ-OBVR-002: resolve() returns null when Application is not found by slug.
     *
     * @return void
     */
    public function testResolveReturnsNullWhenApplicationNotFound(): void
    {
        $this->objectService->method('searchObjects')->willReturn([]);

        $result = $this->service->resolve(
            appSlug: 'unknown-app',
            versionSlug: null,
            caller: null
        );

        self::assertNull($result);
    }//end testResolveReturnsNullWhenApplicationNotFound()

    /**
     * REQ-OBVR-002: resolve() returns production manifest from Application.manifest
     * (backwards-compat fallback) when versionSlug is null and no productionVersion.
     *
     * @return void
     */
    public function testResolveReturnsApplicationManifestFallbackWhenNoVersionSlug(): void
    {
        $manifest    = ['version' => '1.0.0', 'pages' => []];
        $application = [
            'slug'              => 'hello-world',
            'manifest'          => $manifest,
            'productionVersion' => null,
        ];

        $this->objectService->method('searchObjects')->willReturn([$application]);

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: null,
            caller: null
        );

        self::assertSame($manifest, $result);
    }//end testResolveReturnsApplicationManifestFallbackWhenNoVersionSlug()

    /**
     * REQ-OBVR-003: resolve() returns null for unknown versionSlug (no version found).
     * The null return value maps to 404 at the controller level (no existence leak).
     *
     * @return void
     */
    public function testResolveReturnsNullForUnknownVersionSlug(): void
    {
        $application = [
            'slug'              => 'hello-world',
            'productionVersion' => null,
            'permissions'       => ['owners' => [], 'editors' => []],
        ];

        // First call: find Application by slug.
        // Second call: find ApplicationVersion by slug (returns empty).
        $this->objectService->method('searchObjects')
            ->willReturnOnConsecutiveCalls(
                [$application],
                []
            );

        $caller = $this->createMock(IUser::class);
        $caller->method('getUID')->willReturn('user1');

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: 'no-such-version',
            caller: $caller
        );

        self::assertNull($result);
    }//end testResolveReturnsNullForUnknownVersionSlug()

    /**
     * REQ-OBVR-003: resolve() returns null (security-shaped 404) for an
     * unauthorised caller on a non-production version.
     *
     * The response is identical to "unknown version" to prevent slug enumeration.
     *
     * @return void
     */
    public function testResolveReturnsNullForUnauthorisedCallerOnNonProductionVersion(): void
    {
        $application = [
            'slug'              => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions'       => ['owners' => ['user:alice'], 'editors' => []],
        ];
        $version     = [
            'uuid'        => 'staging-uuid',
            'slug'        => 'staging',
            'application' => 'hello-world-app-uuid',
            'manifest'    => ['version' => '1.0.0', 'pages' => []],
        ];

        $this->objectService->method('searchObjects')
            ->willReturnOnConsecutiveCalls(
                [$application],
                [$version]
            );

        // Caller is NOT in permissions.owners or permissions.editors.
        $caller = $this->createMock(IUser::class);
        $caller->method('getUID')->willReturn('user:bob');

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: 'staging',
            caller: $caller
        );

        self::assertNull($result);
    }//end testResolveReturnsNullForUnauthorisedCallerOnNonProductionVersion()

    /**
     * NC admins are NOT auto-granted (Decision 7 / REQ-OBVR-003).
     * An admin user who is NOT in permissions still gets null.
     *
     * @return void
     */
    public function testResolveReturnsNullForAdminNotInPermissions(): void
    {
        $application = [
            'slug'              => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions'       => ['owners' => [], 'editors' => []],
        ];
        $version     = [
            'uuid'     => 'staging-uuid',
            'slug'     => 'staging',
            'manifest' => ['version' => '1.0.0', 'pages' => []],
        ];

        $this->objectService->method('searchObjects')
            ->willReturnOnConsecutiveCalls(
                [$application],
                [$version]
            );

        // Admin user — but NOT in permissions.owners or permissions.editors.
        $admin = $this->createMock(IUser::class);
        $admin->method('getUID')->willReturn('admin');

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: 'staging',
            caller: $admin
        );

        // Must return null — NC admin bypass does NOT exist in ManifestResolverService.
        self::assertNull($result);
    }//end testResolveReturnsNullForAdminNotInPermissions()

    /**
     * REQ-OBVR-004: resolve() returns the version manifest for a caller
     * who is listed in permissions.owners.
     *
     * @return void
     */
    public function testResolveReturnsManifestForOwner(): void
    {
        $manifest    = ['version' => '1.0.0', 'pages' => ['home']];
        $application = [
            'slug'              => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions'       => ['owners' => ['user:alice'], 'editors' => []],
        ];
        $version     = [
            'uuid'     => 'staging-uuid',
            'slug'     => 'staging',
            'manifest' => $manifest,
        ];

        $this->objectService->method('searchObjects')
            ->willReturnOnConsecutiveCalls(
                [$application],
                [$version]
            );

        $caller = $this->createMock(IUser::class);
        $caller->method('getUID')->willReturn('alice');

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: 'staging',
            caller: $caller
        );

        self::assertSame($manifest, $result);
    }//end testResolveReturnsManifestForOwner()

    /**
     * REQ-OBVR-004: resolve() returns the version manifest for a caller
     * who is listed in permissions.editors.
     *
     * @return void
     */
    public function testResolveReturnsManifestForEditor(): void
    {
        $manifest    = ['version' => '1.0.0', 'pages' => []];
        $application = [
            'slug'              => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions'       => ['owners' => [], 'editors' => ['user:bob']],
        ];
        $version     = [
            'uuid'     => 'staging-uuid',
            'slug'     => 'staging',
            'manifest' => $manifest,
        ];

        $this->objectService->method('searchObjects')
            ->willReturnOnConsecutiveCalls(
                [$application],
                [$version]
            );

        $caller = $this->createMock(IUser::class);
        $caller->method('getUID')->willReturn('bob');

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: 'staging',
            caller: $caller
        );

        self::assertSame($manifest, $result);
    }//end testResolveReturnsManifestForEditor()

    /**
     * REQ-OBVR-005: resolve() allows any authenticated caller to access the
     * production version when versionSlug identifies the production version UUID.
     *
     * Even an unauthenticated caller (null) can access the production version.
     *
     * @return void
     */
    public function testResolveAllowsAnyCallerOnProductionVersion(): void
    {
        $manifest    = ['version' => '2.0.0', 'pages' => ['home', 'about']];
        $application = [
            'slug'              => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions'       => ['owners' => [], 'editors' => []],
        ];
        // The requested version IS the production version.
        $prodVersion = [
            'uuid'     => 'prod-uuid',
            'slug'     => 'production',
            'manifest' => $manifest,
        ];

        $this->objectService->method('searchObjects')
            ->willReturnOnConsecutiveCalls(
                [$application],
                [$prodVersion]
            );

        // No caller — public access should be allowed to the production version.
        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: 'production',
            caller: null
        );

        self::assertSame($manifest, $result);
    }//end testResolveAllowsAnyCallerOnProductionVersion()

    /**
     * REQ-OBVR-009: resolve() returns null when ApplicationVersion has no manifest.
     * An authorised caller on a version with missing manifest still gets null.
     *
     * @return void
     */
    public function testResolveReturnsNullWhenVersionHasNoManifest(): void
    {
        $application = [
            'slug'              => 'hello-world',
            'productionVersion' => 'prod-uuid',
            'permissions'       => ['owners' => ['user:alice'], 'editors' => []],
        ];
        $version     = [
            'uuid' => 'staging-uuid',
            'slug' => 'staging',
            // No 'manifest' key.
        ];

        $this->objectService->method('searchObjects')
            ->willReturnOnConsecutiveCalls(
                [$application],
                [$version]
            );

        $caller = $this->createMock(IUser::class);
        $caller->method('getUID')->willReturn('alice');

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: 'staging',
            caller: $caller
        );

        self::assertNull($result);
    }//end testResolveReturnsNullWhenVersionHasNoManifest()

    /**
     * REQ-OBVR-002: resolve() with empty versionSlug behaves like null versionSlug
     * (backwards-compat: empty string → production path, not versioned path).
     *
     * @return void
     */
    public function testResolveWithEmptyVersionSlugUsesProductionPath(): void
    {
        $manifest    = ['version' => '1.0.0', 'pages' => []];
        $application = [
            'slug'              => 'hello-world',
            'manifest'          => $manifest,
            'productionVersion' => null,
        ];

        $this->objectService->method('searchObjects')->willReturn([$application]);

        $result = $this->service->resolve(
            appSlug: 'hello-world',
            versionSlug: '',
            caller: null
        );

        self::assertSame($manifest, $result);
    }//end testResolveWithEmptyVersionSlugUsesProductionPath()
}//end class
