<?php

/**
 * Unit tests for SettingsController.
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

use OCA\OpenBuilt\Controller\SettingsController;
use OCA\OpenBuilt\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

        // Default: authenticated user.
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new SettingsController(
            $this->request,
            $this->settingsService,
            $this->userSession,
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
        );

        $this->settingsService->expects($this->never())->method('getSettings');

        $result = $controller->index();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());

    }//end testIndexReturns401WhenNoSession()
}//end class
