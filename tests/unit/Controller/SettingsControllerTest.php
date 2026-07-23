<?php

/**
 * Unit tests for SettingsController.
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

use OCA\OpenBuild\Controller\SettingsController;
use OCA\OpenBuild\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for SettingsController.
 */
class SettingsControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var SettingsController
     */
    private SettingsController $controller;

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->groupManager    = $this->createMock(IGroupManager::class);

        // Default: authenticated admin user.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin-user');
        $this->userSession->method('getUser')->willReturn($user);
        // Default: user IS in the admin group.
        $this->groupManager->method('isInGroup')->willReturn(true);

        $this->controller = new SettingsController(
            $this->request,
            $this->settingsService,
            $this->userSession,
            $this->groupManager,
        );

    }//end setUp()

    /**
     * Test that index() returns a JSONResponse containing the settings from the service.
     *
     * @return void
     */
    public function testIndexReturnsJsonResponseWithSettings(): void
    {
        $settings = [
            'register'      => 'some-uuid',
            'openregisters' => true,
            'isAdmin'       => false,
        ];

        $this->settingsService->expects($this->once())
            ->method('getSettings')
            ->willReturn($settings);

        $result = $this->controller->index();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame($settings, $result->getData());

    }//end testIndexReturnsJsonResponseWithSettings()

    /**
     * Test that create() calls updateSettings with request params and returns success.
     *
     * @return void
     */
    public function testCreateCallsUpdateSettingsAndReturnsSuccess(): void
    {
        $params  = ['register' => 'new-uuid'];
        $updated = ['register' => 'new-uuid', 'openregisters' => true, 'isAdmin' => false];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($params);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($params)
            ->willReturn($updated);

        $result = $this->controller->create();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertTrue($result->getData()['success']);
        self::assertArrayHasKey('config', $result->getData());

    }//end testCreateCallsUpdateSettingsAndReturnsSuccess()

    /**
     * Test that load() returns the result of loadConfiguration.
     *
     * @return void
     */
    public function testLoadReturnsConfigurationResult(): void
    {
        $loadResult = [
            'success' => true,
            'message' => 'Configuration imported successfully.',
            'version' => '0.1.0',
        ];

        $this->settingsService->expects($this->once())
            ->method('reloadConfiguration')
            ->willReturn($loadResult);

        $result = $this->controller->load();

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertTrue($result->getData()['success']);

    }//end testLoadReturnsConfigurationResult()

    /**
     * Test that unauthenticated requests return 401.
     *
     * @return void
     */
    public function testIndexReturns401WhenNoSession(): void
    {
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new SettingsController(
            $this->request,
            $this->settingsService,
            $unauthSession,
            $this->groupManager,
        );

        $this->settingsService->expects($this->never())->method('getSettings');

        $result = $controller->index();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testIndexReturns401WhenNoSession()

    /**
     * H6 guard: non-admin caller on create() must receive 403.
     *
     * @return void
     */
    public function testCreateReturns403WhenCallerIsNotAdmin(): void
    {
        $nonAdminGroupManager = $this->createMock(IGroupManager::class);
        $nonAdminGroupManager->method('isInGroup')->willReturn(false);

        $controller = new SettingsController(
            $this->request,
            $this->settingsService,
            $this->userSession,
            $nonAdminGroupManager,
        );

        $this->settingsService->expects($this->never())->method('updateSettings');

        $result = $controller->create();

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testCreateReturns403WhenCallerIsNotAdmin()

    /**
     * H6 guard: non-admin caller on load() must receive 403.
     *
     * @return void
     */
    public function testLoadReturns403WhenCallerIsNotAdmin(): void
    {
        $nonAdminGroupManager = $this->createMock(IGroupManager::class);
        $nonAdminGroupManager->method('isInGroup')->willReturn(false);

        $controller = new SettingsController(
            $this->request,
            $this->settingsService,
            $this->userSession,
            $nonAdminGroupManager,
        );

        $this->settingsService->expects($this->never())->method('reloadConfiguration');

        $result = $controller->load();

        self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

    }//end testLoadReturns403WhenCallerIsNotAdmin()

    /**
     * CSRF hardening: the state-changing `create` POST must NOT carry
     * `#[NoCSRFRequired]` (it must be CSRF-protected), while remaining
     * `#[NoAdminRequired]`.
     *
     * @return void
     */
    public function testCreateEnforcesCsrf(): void
    {
        $method = new ReflectionMethod(SettingsController::class, 'create');
        self::assertCount(0, $method->getAttributes(NoCSRFRequired::class));
        self::assertCount(1, $method->getAttributes(NoAdminRequired::class));

    }//end testCreateEnforcesCsrf()

    /**
     * CSRF hardening: the state-changing `load` POST must NOT carry
     * `#[NoCSRFRequired]`.
     *
     * @return void
     */
    public function testLoadEnforcesCsrf(): void
    {
        $method = new ReflectionMethod(SettingsController::class, 'load');
        self::assertCount(0, $method->getAttributes(NoCSRFRequired::class));
        self::assertCount(1, $method->getAttributes(NoAdminRequired::class));

    }//end testLoadEnforcesCsrf()

    /**
     * Regression guard: the read-only `index` GET legitimately keeps
     * `#[NoCSRFRequired]` (a browser navigation cannot send a request token).
     *
     * @return void
     */
    public function testIndexKeepsNoCsrf(): void
    {
        $method = new ReflectionMethod(SettingsController::class, 'index');
        self::assertCount(1, $method->getAttributes(NoCSRFRequired::class));

    }//end testIndexKeepsNoCsrf()
}//end class
