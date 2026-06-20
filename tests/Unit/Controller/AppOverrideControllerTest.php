<?php

/**
 * Unit tests for AppOverrideController auth posture.
 *
 * Covers the openbuild-inline-edit-persistence change (spec
 * app-override-persistence): anonymous write rejected (401), out-of-scope
 * write forbidden (403), in-scope write allowed (2xx), GET returns the stored
 * delta or an empty object, malformed body 422, app-blanking delta 422,
 * idempotent clear.
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

use OCA\OpenBuild\Controller\AppOverrideController;
use OCA\OpenBuild\Service\AppOverrideService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppOverrideController.
 */
class AppOverrideControllerTest extends TestCase
{
    /**
     * Mock HTTP request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock override service.
     *
     * @var AppOverrideService&MockObject
     */
    private AppOverrideService&MockObject $service;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock app manager.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
        $this->service     = $this->createMock(AppOverrideService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->appManager  = $this->createMock(IAppManager::class);

    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return AppOverrideController
     */
    private function controller(): AppOverrideController
    {
        return new AppOverrideController(
            request: $this->request,
            logger: $this->logger,
            appOverrideService: $this->service,
            userSession: $this->userSession,
            appManager: $this->appManager
        );

    }//end controller()

    /**
     * Build a mock IUser with the given UID.
     *
     * @param string $uid The user UID.
     *
     * @return IUser&MockObject
     */
    private function mockUser(string $uid): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;

    }//end mockUser()

    /**
     * Anonymous PUT is rejected with 401 and persists nothing.
     *
     * @return void
     */
    public function testAnonymousSaveIsRejected(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->service->expects(self::never())->method('upsert');

        $response = $this->controller()->save(appId: 'pipelinq');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAnonymousSaveIsRejected()

    /**
     * A logged-in user outside OpenBuild's group-restriction is forbidden (403).
     *
     * @return void
     */
    public function testOutOfScopeSaveIsForbidden(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('carol'));
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->service->expects(self::never())->method('upsert');

        $response = $this->controller()->save(appId: 'pipelinq');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testOutOfScopeSaveIsForbidden()

    /**
     * An in-scope user saving a valid delta succeeds and records updatedBy.
     *
     * @return void
     */
    public function testInScopeSaveIsAllowed(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->request->method('getParams')->willReturn(['pages' => [['id' => 'home', 'title' => 'X']]]);
        $this->request->method('getParam')->willReturn(null);

        $this->service->method('validateDeltaShape')->willReturn([]);
        $this->service->method('wouldBlankApp')->willReturn(false);
        $this->service->expects(self::once())
            ->method('upsert')
            ->willReturn(['appId' => 'pipelinq', 'updatedBy' => 'alice', 'updatedAt' => '2026-06-18T00:00:00+00:00']);

        $response = $this->controller()->save(appId: 'pipelinq');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        self::assertSame('alice', $data['updatedBy']);

    }//end testInScopeSaveIsAllowed()

    /**
     * A malformed (list-shaped) body is rejected 422 and persists nothing.
     *
     * @return void
     */
    public function testMalformedBodyIsUnprocessable(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        // A list-shaped params bag → readDeltaBody returns null.
        $this->request->method('getParams')->willReturn(['a', 'b']);
        $this->request->method('getParam')->willReturn(null);
        $this->service->expects(self::never())->method('upsert');

        $response = $this->controller()->save(appId: 'pipelinq');

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testMalformedBodyIsUnprocessable()

    /**
     * An app-blanking delta is rejected 422 and persists nothing.
     *
     * @return void
     */
    public function testAppBlankingDeltaIsUnprocessable(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->request->method('getParams')->willReturn(['pages' => [['id' => 'home', '$op' => 'remove']], 'menu' => []]);
        $this->request->method('getParam')->willReturn(null);
        $this->service->method('validateDeltaShape')->willReturn([]);
        $this->service->method('wouldBlankApp')->willReturn(true);
        $this->service->expects(self::never())->method('upsert');

        $response = $this->controller()->save(appId: 'pipelinq');

        self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testAppBlankingDeltaIsUnprocessable()

    /**
     * GET with no override returns 200 with an empty object.
     *
     * @return void
     */
    public function testGetReturnsEmptyObjectWhenNoOverride(): void
    {
        $this->service->method('findByAppId')->willReturn(null);

        $response = $this->controller()->get(appId: 'somefleetapp');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertEquals((object) [], $response->getData());

    }//end testGetReturnsEmptyObjectWhenNoOverride()

    /**
     * GET returns the stored manifestDelta unchanged.
     *
     * @return void
     */
    public function testGetReturnsStoredDelta(): void
    {
        $delta = ['pages' => [['id' => 'home', 'title' => 'Renamed']]];
        $this->service->method('findByAppId')->willReturn(['appId' => 'opencatalogi', 'manifestDelta' => $delta]);

        $response = $this->controller()->get(appId: 'opencatalogi');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertEquals((object) $delta, $response->getData());

    }//end testGetReturnsStoredDelta()

    /**
     * Anonymous DELETE is rejected with 401.
     *
     * @return void
     */
    public function testAnonymousClearIsRejected(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->service->expects(self::never())->method('delete');

        $response = $this->controller()->clear(appId: 'opencatalogi');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAnonymousClearIsRejected()

    /**
     * In-scope DELETE clears the override and returns 200.
     *
     * @return void
     */
    public function testInScopeClearSucceeds(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->service->expects(self::once())->method('delete')->with('opencatalogi');

        $response = $this->controller()->clear(appId: 'opencatalogi');

        self::assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testInScopeClearSucceeds()

    /**
     * An invalid (non-kebab) appId is rejected 400 before any service call.
     *
     * @return void
     */
    public function testInvalidAppIdIsBadRequest(): void
    {
        $response = $this->controller()->get(appId: 'Not_Valid');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testInvalidAppIdIsBadRequest()
}//end class
