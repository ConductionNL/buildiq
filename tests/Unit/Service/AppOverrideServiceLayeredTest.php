<?php

/**
 * Unit tests for AppOverrideService layered resolution (layered-versioned-app-deltas).
 *
 * Exercises resolveLayeredDelta — the `base ⊕ admin ⊕ user` collapse served to
 * the client loader: the caller's own user delta is deep-merged over the admin
 * delta ONLY when the parent Application's allowUserOverrides is true and a
 * user-scoped row owned by the caller exists; otherwise the result is exactly
 * the admin delta (back-compat).
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

use OCA\OpenBuild\Service\AppOverrideService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppOverrideService::resolveLayeredDelta.
 */
class AppOverrideServiceLayeredTest extends TestCase
{

    /**
     * Mock OR object service.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Service under test.
     *
     * @var AppOverrideService
     */
    private AppOverrideService $service;

    /**
     * Wire mocks + the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService = $this->createMock(originalClassName: ObjectService::class);
        $logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $appManager    = $this->createMock(originalClassName: IAppManager::class);
        $this->service = new AppOverrideService(
            objectService: $this->objectService,
            logger: $logger,
            appManager: $appManager
        );
    }//end setUp()

    /**
     * Arrange the hybrid Application + admin version lookups.
     *
     * @param bool                      $allowUserOverrides Whether the app permits user overrides.
     * @param array<string, mixed>|null $userDelta          The user-scoped row to return (null = none).
     *
     * @return void
     */
    private function arrange(bool $allowUserOverrides, ?array $userDelta): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
            static function (string $registerSlug, string $schemaSlug, array $filters) use ($allowUserOverrides, $userDelta) {
                if (($filters['appType'] ?? null) === 'hybrid') {
                    return [
                        [
                            'id'                 => 'app-A',
                            'slug'               => 'demo',
                            'appType'            => 'hybrid',
                            'allowUserOverrides' => $allowUserOverrides,
                            'productionVersion'  => 'admin-uuid',
                        ],
                    ];
                }

                if (($filters['scope'] ?? null) === 'user') {
                    if ($userDelta === null) {
                        return [];
                    }

                    return [$userDelta];
                }

                return [];
            }
        );

        $adminEntity = $this->createMock(originalClassName: ObjectEntity::class);
        $adminEntity->method('jsonSerialize')->willReturn(
            [
                'id'            => 'admin-uuid',
                'manifestDelta' => ['pages' => ['dashboard' => ['title' => 'Admin']]],
            ]
        );
        $this->objectService->method('find')->willReturn($adminEntity);
    }//end arrange()

    /**
     * With the flag on and a user delta present, admin ⊕ user is deep-merged.
     *
     * @return void
     */
    public function testMergesAdminAndUserWhenAllowed(): void
    {
        $this->arrange(
            allowUserOverrides: true,
            userDelta: [
                'id'            => 'user-uuid',
                'scope'         => 'user',
                'owner'         => 'alice',
                'application'   => 'app-A',
                'manifestDelta' => ['pages' => ['dashboard' => ['subtitle' => 'Mine']]],
            ]
        );

        $record = $this->service->resolveLayeredDelta(appId: 'demo', uid: 'alice');

        self::assertNotNull(actual: $record);
        self::assertSame(
            expected: ['pages' => ['dashboard' => ['title' => 'Admin', 'subtitle' => 'Mine']]],
            actual: $record['manifestDelta']
        );
    }//end testMergesAdminAndUserWhenAllowed()

    /**
     * With the flag off the user delta is ignored — admin delta only.
     *
     * @return void
     */
    public function testAdminOnlyWhenFlagOff(): void
    {
        $this->arrange(
            allowUserOverrides: false,
            userDelta: [
                'id'            => 'user-uuid',
                'scope'         => 'user',
                'owner'         => 'alice',
                'application'   => 'app-A',
                'manifestDelta' => ['pages' => ['dashboard' => ['subtitle' => 'Mine']]],
            ]
        );

        $record = $this->service->resolveLayeredDelta(appId: 'demo', uid: 'alice');

        self::assertSame(
            expected: ['pages' => ['dashboard' => ['title' => 'Admin']]],
            actual: $record['manifestDelta']
        );
    }//end testAdminOnlyWhenFlagOff()

    /**
     * An anonymous caller (null uid) gets the admin delta only.
     *
     * @return void
     */
    public function testAdminOnlyWhenAnonymous(): void
    {
        $this->arrange(allowUserOverrides: true, userDelta: null);

        $record = $this->service->resolveLayeredDelta(appId: 'demo', uid: null);

        self::assertSame(
            expected: ['pages' => ['dashboard' => ['title' => 'Admin']]],
            actual: $record['manifestDelta']
        );
    }//end testAdminOnlyWhenAnonymous()
}//end class
