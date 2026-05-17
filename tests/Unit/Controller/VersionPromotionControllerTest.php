<?php

/**
 * Unit tests for VersionPromotionController (spec openbuilt-version-promotion).
 *
 * Exercises the endpoint contract: 200/422/400/409/403/500 mapping and the
 * IDOR-safe parent-uuid back-reference check (spec REQ-OBVP-007).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Controller
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

namespace OCA\OpenBuilt\Tests\Unit\Controller;

use OCA\OpenBuilt\Controller\VersionPromotionController;
use OCA\OpenBuilt\Exception\InvalidStrategyException;
use OCA\OpenBuilt\Exception\NoPromoteTargetException;
use OCA\OpenBuilt\Exception\PromotionFailedException;
use OCA\OpenBuilt\Exception\VersionLockedException;
use OCA\OpenBuilt\Service\VersionPromotionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for VersionPromotionController.
 */
class VersionPromotionControllerTest extends TestCase
{
    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * @var VersionPromotionService&MockObject
     */
    private VersionPromotionService&MockObject $promotionService;

    /**
     * Controller under test.
     */
    private VersionPromotionController $controller;

    /**
     * Set up shared mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request          = $this->createMock(IRequest::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->objectService    = $this->createMock(ObjectService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->promotionService = $this->createMock(VersionPromotionService::class);

        $this->controller = new VersionPromotionController(
            request: $this->request,
            logger: $this->logger,
            objectService: $this->objectService,
            userSession: $this->userSession,
            promotionService: $this->promotionService,
        );
    }//end setUp()

    /**
     * 401 when no session.
     *
     * @return void
     */
    public function testReturns401WhenUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testReturns401WhenUnauthenticated()

    /**
     * 404 when Application is missing.
     *
     * @return void
     */
    public function testReturns404WhenApplicationMissing(): void
    {
        $this->wireUser(uid: 'alice');
        $this->objectService->method('find')->willReturn(null);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testReturns404WhenApplicationMissing()

    /**
     * 404 when source's `application` does not match URL appUuid (IDOR guard).
     *
     * @return void
     */
    public function testReturns404OnApplicationMismatch(): void
    {
        $this->wireUser(uid: 'alice');

        $appEntity    = $this->buildEntity(payload: ['id' => 'u-app', 'permissions' => ['owners' => ['user:alice']]]);
        $sourceEntity = $this->buildEntity(payload: ['id' => 'u-ver', 'application' => 'u-other-app']);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($appEntity, $sourceEntity);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testReturns404OnApplicationMismatch()

    /**
     * 403 for a viewer.
     *
     * @return void
     */
    public function testReturns403ForViewer(): void
    {
        $this->wireUser(uid: 'viewer-bob');

        $appEntity = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'hello',
            'permissions' => [
                'owners'  => ['user:alice'],
                'editors' => [],
                'viewers' => ['user:viewer-bob'],
            ],
        ]);
        $sourceEntity = $this->buildEntity(payload: ['id' => 'u-ver', 'application' => 'u-app']);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($appEntity, $sourceEntity);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testReturns403ForViewer()

    /**
     * 403 for a user who has no role at all on the Application (REQ-OBVP-007 task 7.5).
     *
     * Distinct from viewer-403 (a viewer holds a role; promote still requires
     * editor or owner) and admin-403 (admin bypass is deliberately disabled):
     * here the caller is a plain authenticated user with NO entry in any of
     * the three permission buckets.
     *
     * @return void
     */
    public function testReturns403ForNonMember(): void
    {
        $this->wireUser(uid: 'eve-outsider');

        $appEntity = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'hello',
            'permissions' => [
                'owners'  => ['user:alice'],
                'editors' => ['user:bob'],
                'viewers' => [],
            ],
        ]);
        $sourceEntity = $this->buildEntity(payload: ['id' => 'u-ver', 'application' => 'u-app']);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($appEntity, $sourceEntity);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testReturns403ForNonMember()

    /**
     * 403 for a Nextcloud admin who is NOT in owners/editors (deliberate
     * constraint per spec REQ-OBVP-007).
     *
     * @return void
     */
    public function testReturns403ForNcAdminWithoutPerAppRole(): void
    {
        $this->wireUser(uid: 'admin');

        $appEntity = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'hello',
            'permissions' => [
                'owners'  => ['user:alice'],
                'editors' => ['user:bob'],
            ],
        ]);
        $sourceEntity = $this->buildEntity(payload: ['id' => 'u-ver', 'application' => 'u-app']);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($appEntity, $sourceEntity);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testReturns403ForNcAdminWithoutPerAppRole()

    /**
     * 422 on NoPromoteTargetException.
     *
     * @return void
     */
    public function testReturns422OnNoPromoteTarget(): void
    {
        $this->wirePromoteCall(uid: 'alice');

        $this->promotionService
            ->method('promote')
            ->willThrowException(new NoPromoteTargetException());

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        self::assertSame('no_promote_target', $response->getData()['error']);
    }//end testReturns422OnNoPromoteTarget()

    /**
     * 400 on InvalidStrategyException.
     *
     * @return void
     */
    public function testReturns400OnInvalidStrategy(): void
    {
        $this->wirePromoteCall(uid: 'alice');

        $this->promotionService
            ->method('promote')
            ->willThrowException(new InvalidStrategyException());

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertSame('invalid_strategy', $response->getData()['error']);
    }//end testReturns400OnInvalidStrategy()

    /**
     * 409 on VersionLockedException with lockedBy + expiresAt forwarded.
     *
     * @return void
     */
    public function testReturns409OnVersionLocked(): void
    {
        $this->wirePromoteCall(uid: 'alice');

        $this->promotionService
            ->method('promote')
            ->willThrowException(
                new VersionLockedException(
                    lockedBy: 'bob',
                    expiresAt: '2026-05-15T12:00:00Z'
                )
            );

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $body = $response->getData();
        self::assertSame('version_locked', $body['error']);
        self::assertSame('bob', $body['lockedBy']);
        self::assertSame('2026-05-15T12:00:00Z', $body['expiresAt']);
    }//end testReturns409OnVersionLocked()

    /**
     * 500 on PromotionFailedException with strategy + message forwarded.
     *
     * @return void
     */
    public function testReturns500OnPromotionFailed(): void
    {
        $this->wirePromoteCall(uid: 'alice');

        $this->promotionService
            ->method('promote')
            ->willThrowException(
                new PromotionFailedException(
                    strategy: VersionPromotionService::STRATEGY_START_WITH_SOURCE_DATA,
                    message: 'OR migration boom'
                )
            );

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $body = $response->getData();
        self::assertSame('promotion_failed', $body['error']);
        self::assertSame(VersionPromotionService::STRATEGY_START_WITH_SOURCE_DATA, $body['strategy']);
        self::assertSame('OR migration boom', $body['message']);
    }//end testReturns500OnPromotionFailed()

    /**
     * 200 happy-path: owner promotes, service returns updated target.
     *
     * @return void
     */
    public function testReturns200WithUpdatedTargetForOwner(): void
    {
        $this->wirePromoteCall(uid: 'alice');

        $updated = [
            'id'     => 'u-tgt',
            'semver' => '1.5.0',
            'status' => 'published',
        ];

        $this->promotionService
            ->method('promote')
            ->willReturn($updated);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame($updated, $response->getData());
    }//end testReturns200WithUpdatedTargetForOwner()

    /**
     * 200 happy-path: editor (not owner) also succeeds.
     *
     * @return void
     */
    public function testReturns200ForEditor(): void
    {
        $this->wireUser(uid: 'editor-eve');

        $appEntity = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'hello',
            'permissions' => [
                'owners'  => ['user:alice'],
                'editors' => ['user:editor-eve'],
            ],
        ]);
        $sourceEntity = $this->buildEntity(payload: ['id' => 'u-ver', 'application' => 'u-app']);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($appEntity, $sourceEntity);

        $this->request->method('getParam')->willReturn('migrate-existing-data');
        $this->promotionService
            ->method('promote')
            ->willReturn(['id' => 'u-tgt', 'semver' => '1.5.0', 'status' => 'published']);

        $response = $this->controller->promote(appUuid: 'u-app', versionUuid: 'u-ver');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testReturns200ForEditor()

    /**
     * Wire a successful auth + load path with `alice` as the owner.
     *
     * @param string $uid Acting user UID
     *
     * @return void
     */
    private function wirePromoteCall(string $uid): void
    {
        $this->wireUser(uid: $uid);

        $appEntity = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'hello',
            'permissions' => [
                'owners'  => ['user:alice'],
                'editors' => [],
            ],
        ]);
        $sourceEntity = $this->buildEntity(payload: ['id' => 'u-ver', 'application' => 'u-app']);

        $this->objectService
            ->method('find')
            ->willReturnOnConsecutiveCalls($appEntity, $sourceEntity);

        $this->request->method('getParam')->willReturn('start-with-source-data');
    }//end wirePromoteCall()

    /**
     * Wire a Nextcloud user with the given UID into the session mock.
     *
     * @param string $uid UID
     *
     * @return IUser&MockObject
     */
    private function wireUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        return $user;
    }//end wireUser()

    /**
     * Build an ObjectEntity carrying a JSON payload.
     *
     * @param array<string,mixed> $payload Inner payload
     *
     * @return ObjectEntity
     */
    private function buildEntity(array $payload): ObjectEntity
    {
        $entity = new class () extends ObjectEntity {
            /**
             * @var array<string,mixed>
             */
            public array $payload = [];

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
        };

        $entity->payload = $payload;
        return $entity;
    }//end buildEntity()
}//end class
