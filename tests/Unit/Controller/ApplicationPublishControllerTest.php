<?php

/**
 * Unit tests for ApplicationPublishController.
 *
 * Covers the explicit publish / unpublish action that flips Application.status
 * (which gates the app-menu entry in AppNavigationService):
 *   - publish() sets status=published, saves, returns 200
 *   - unpublish() sets status=draft, returns 200
 *   - the OR-managed @self envelope is stripped before saving
 *   - 401 when unauthenticated
 *   - 404 when the application is not found
 *   - 403 when the caller is not an owner
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
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

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ApplicationPublishController;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationPublishController.
 */
class ApplicationPublishControllerTest extends TestCase
{
    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Controller under test.
     */
    private ApplicationPublishController $controller;

    /**
     * Set up shared mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);

        $permissionResolver = new PermissionResolver($this->groupManager, $this->createMock(LoggerInterface::class));

        $this->controller = new ApplicationPublishController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            objectService: $this->objectService,
            userSession: $this->userSession,
            permissionResolver: $permissionResolver,
        );
    }//end setUp()

    /**
     * Build an ObjectEntity whose serialisers return the given payload.
     *
     * @param array<string,mixed> $payload The object data.
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

    /**
     * Mock a signed-in user with the given UID.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function signInAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end signInAs()

    /**
     * publish() flips status to published, strips @self, and returns 200.
     *
     * @return void
     */
    public function testPublishSetsStatusPublished(): void
    {
        $this->signInAs(uid: 'alice');
        $app = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'demo',
            'name'        => 'Demo',
            'status'      => 'draft',
            'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
            '@self'       => ['id' => 'u-app'],
        ]);
        $this->objectService->method('find')->willReturn($app);

        $captured = null;
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(function (array $object) use (&$captured) {
                $captured = $object;
                return $this->buildEntity(payload: $object);
            });

        $response = $this->controller->publish('u-app');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('published', $response->getData()['status']);
        self::assertSame('published', $captured['status']);
        self::assertArrayNotHasKey('@self', $captured, '@self must be stripped before saving');
    }//end testPublishSetsStatusPublished()

    /**
     * unpublish() flips status back to draft and returns 200.
     *
     * @return void
     */
    public function testUnpublishSetsStatusDraft(): void
    {
        $this->signInAs(uid: 'alice');
        $app = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'demo',
            'name'        => 'Demo',
            'status'      => 'published',
            'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []],
        ]);
        $this->objectService->method('find')->willReturn($app);
        $this->objectService->method('saveObject')->willReturnCallback(
            fn (array $object): ObjectEntity => $this->buildEntity(payload: $object)
        );

        $response = $this->controller->unpublish('u-app');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('draft', $response->getData()['status']);
    }//end testUnpublishSetsStatusDraft()

    /**
     * 401 when no user is signed in.
     *
     * @return void
     */
    public function testPublishUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller->publish('u-app');
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testPublishUnauthenticated()

    /**
     * 404 when the application does not exist.
     *
     * @return void
     */
    public function testPublishNotFound(): void
    {
        $this->signInAs(uid: 'alice');
        $this->objectService->method('find')->willReturn(null);
        $response = $this->controller->publish('u-missing');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testPublishNotFound()

    /**
     * 403 when the caller is not an owner of the application.
     *
     * @return void
     */
    public function testPublishForbiddenForNonOwner(): void
    {
        $this->signInAs(uid: 'mallory');
        $this->groupManager->method('isAdmin')->willReturn(false);
        $app = $this->buildEntity(payload: [
            'id'          => 'u-app',
            'slug'        => 'demo',
            'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob'], 'viewers' => []],
        ]);
        $this->objectService->method('find')->willReturn($app);
        $this->objectService->expects($this->never())->method('saveObject');

        $response = $this->controller->publish('u-app');
        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testPublishForbiddenForNonOwner()
}//end class
