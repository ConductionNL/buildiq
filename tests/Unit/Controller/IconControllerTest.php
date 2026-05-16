<?php

/**
 * Unit tests for IconController.
 *
 * Covers REQ-OBICON-002 / REQ-OBICON-003: correct Content-Type and
 * Cache-Control headers on the light and dark icon endpoints.
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

use OCA\OpenBuilt\Controller\IconController;
use OCA\OpenBuilt\Service\IconService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see IconController}.
 */
class IconControllerTest extends TestCase
{
    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock icon service.
     *
     * @var IconService&MockObject
     */
    private IconService&MockObject $iconService;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Controller under test.
     */
    private IconController $controller;

    /**
     * Build mocks and the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->iconService = $this->createMock(IconService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        // Default: authenticated user.
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new IconController(
            $this->request,
            $this->iconService,
            $this->userSession,
            $this->logger
        );
    }//end setUp()

    // -------------------------------------------------------------------------
    // iconLight — happy path
    // -------------------------------------------------------------------------

    /**
     * iconLight returns 200, Content-Type: image/svg+xml, Cache-Control: public, max-age=60.
     *
     * @return void
     */
    public function testIconLightReturnsCorrectHeaders(): void
    {
        $stream = fopen(filename: 'php://memory', mode: 'r+');
        fwrite($stream, '<svg></svg>');
        rewind($stream);

        $this->iconService
            ->expects($this->once())
            ->method('getIconStream')
            ->with('hello-world', false)
            ->willReturn(['stream' => $stream, 'mimeType' => 'image/svg+xml']);

        $response = $this->controller->iconLight('hello-world');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        // getHeaders() requires OC::$server in unit context; read via Reflection.
        $headersProp = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $headersProp->setAccessible(true);
        $headers = $headersProp->getValue($response);

        $this->assertSame('image/svg+xml', $headers['Content-Type']);
        $this->assertSame('public, max-age=60', $headers['Cache-Control']);

        fclose($stream);
    }//end testIconLightReturnsCorrectHeaders()

    // -------------------------------------------------------------------------
    // iconDark — happy path
    // -------------------------------------------------------------------------

    /**
     * iconDark returns 200, Content-Type: image/svg+xml, Cache-Control: public, max-age=60.
     *
     * @return void
     */
    public function testIconDarkReturnsCorrectHeaders(): void
    {
        $stream = fopen(filename: 'php://memory', mode: 'r+');
        fwrite($stream, '<svg fill="#fff"></svg>');
        rewind($stream);

        $this->iconService
            ->expects($this->once())
            ->method('getIconStream')
            ->with('hello-world', true)
            ->willReturn(['stream' => $stream, 'mimeType' => 'image/svg+xml']);

        $response = $this->controller->iconDark('hello-world');

        $this->assertInstanceOf(StreamResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        // getHeaders() requires OC::$server in unit context; read via Reflection.
        $headersProp = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $headersProp->setAccessible(true);
        $headers = $headersProp->getValue($response);

        $this->assertSame('image/svg+xml', $headers['Content-Type']);
        $this->assertSame('public, max-age=60', $headers['Cache-Control']);

        fclose($stream);
    }//end testIconDarkReturnsCorrectHeaders()

    // -------------------------------------------------------------------------
    // null stream → 404
    // -------------------------------------------------------------------------

    /**
     * When IconService returns a null stream, the controller returns 404.
     *
     * @return void
     */
    public function testIconLightReturns404WhenStreamIsNull(): void
    {
        $this->iconService
            ->method('getIconStream')
            ->willReturn(['stream' => null, 'mimeType' => 'image/svg+xml']);

        $response = $this->controller->iconLight('unknown-app');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testIconLightReturns404WhenStreamIsNull()

    // -------------------------------------------------------------------------
    // No session → 401
    // -------------------------------------------------------------------------

    /**
     * Unauthenticated request returns 401.
     *
     * @return void
     */
    public function testIconLightReturns401WhenNoSession(): void
    {
        // Build a fresh controller with a session that returns null user.
        $unauthSession = $this->createMock(IUserSession::class);
        $unauthSession->method('getUser')->willReturn(null);

        $controller = new IconController(
            $this->request,
            $this->iconService,
            $unauthSession,
            $this->logger
        );

        $this->iconService->expects($this->never())->method('getIconStream');

        $response = $controller->iconLight('hello-world');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testIconLightReturns401WhenNoSession()

    // -------------------------------------------------------------------------
    // IconService throws → 500
    // -------------------------------------------------------------------------

    /**
     * When IconService throws, the controller returns 500 and logs the error.
     *
     * @return void
     */
    public function testIconLightReturns500OnException(): void
    {
        $this->iconService
            ->method('getIconStream')
            ->willThrowException(new \RuntimeException('unexpected'));

        $this->logger->expects($this->once())->method('error');

        $response = $this->controller->iconLight('hello-world');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }//end testIconLightReturns500OnException()
}//end class
