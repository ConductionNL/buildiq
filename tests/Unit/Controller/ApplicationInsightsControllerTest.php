<?php

/**
 * Unit tests for ApplicationInsightsController (spec openbuilt-app-detail-overview /
 * capability application-insights, REQ-OBAI-001 / REQ-OBAI-006).
 *
 * Covers:
 *  - 400 with the spec-defined body when `window` is missing
 *  - 400 with the spec-defined body when `window` is invalid (e.g. `24h`)
 *  - 200 + Cache-Control: public, max-age=60 on success
 *  - 404 without the cache header when the service returns null
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

use OCA\OpenBuilt\Controller\ApplicationInsightsController;
use OCA\OpenBuilt\Service\ApplicationInsightsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApplicationInsightsController.
 */
class ApplicationInsightsControllerTest extends TestCase
{
    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * @var ApplicationInsightsService&MockObject
     */
    private ApplicationInsightsService&MockObject $service;

    /**
     * Controller under test.
     */
    private ApplicationInsightsController $controller;

    /**
     * Set up shared mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->service     = $this->createMock(ApplicationInsightsService::class);

        $this->controller = new ApplicationInsightsController(
            request: $this->request,
            userSession: $this->userSession,
            service: $this->service,
        );
    }//end setUp()

    /**
     * Missing `window` parameter → 400 with the spec-defined body.
     *
     * @return void
     */
    public function testMissingWindowReturns400(): void
    {
        $this->request->method('getParam')->willReturn('');

        $response = $this->controller->getInsights('app-uuid', 'version-uuid');
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

        $body = $response->getData();
        self::assertSame(Http::STATUS_BAD_REQUEST, $body['status']);
        self::assertStringContainsString('Invalid window parameter', $body['message']);
    }//end testMissingWindowReturns400()

    /**
     * Invalid `window` value (`24h`) → 400.
     *
     * @return void
     */
    public function testInvalidWindowReturns400(): void
    {
        $this->request->method('getParam')->willReturn('24h');

        $response = $this->controller->getInsights('app-uuid', 'version-uuid');
        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testInvalidWindowReturns400()

    /**
     * Successful response carries 200 + `Cache-Control: public, max-age=60`.
     *
     * @return void
     */
    public function testSuccessfulResponseCarriesCacheHeader(): void
    {
        $this->request->method('getParam')->willReturn('7d');
        $payload = [
            'kpis' => [
                'activeUsers' => 12,
                'objectCount' => 487,
                'filesCount' => 89,
                'auditEventCount' => 1043,
            ],
            'activity' => [
                ['timestamp' => '2026-05-08T00:00:00Z', 'eventCount' => 142],
            ],
        ];
        $this->service->method('requireAuthorisedCaller')->willReturn([
            ['uuid' => 'app-uuid'],
            ['uuid' => 'version-uuid', 'application' => 'app-uuid'],
        ]);
        $this->service->method('computeInsights')->willReturn($payload);

        $response = $this->controller->getInsights('app-uuid', 'version-uuid');
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame($payload, $response->getData());

        // getHeaders() requires OC::$server in unit context — read via Reflection.
        $headersProp = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $headersProp->setAccessible(true);
        $headers = $headersProp->getValue($response);
        self::assertArrayHasKey('Cache-Control', $headers);
        self::assertSame('public, max-age=60', $headers['Cache-Control']);
    }//end testSuccessfulResponseCarriesCacheHeader()

    /**
     * 404 response does NOT carry the Cache-Control header from this spec.
     *
     * @return void
     */
    public function testServiceNullMapsToNotFoundWithoutCacheHeader(): void
    {
        $this->request->method('getParam')->willReturn('7d');
        // requireAuthorisedCaller returns null on RBAC failure / 404; the
        // controller short-circuits to 404 before reaching computeInsights.
        $this->service->method('requireAuthorisedCaller')->willReturn(null);

        $response = $this->controller->getInsights('app-uuid', 'version-uuid');
        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

        // getHeaders() requires OC::$server in unit context — read via Reflection.
        $headersProp = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
        $headersProp->setAccessible(true);
        $headers = $headersProp->getValue($response);
        $cacheHeader = $headers['Cache-Control'] ?? null;
        self::assertNotSame('public, max-age=60', $cacheHeader);
    }//end testServiceNullMapsToNotFoundWithoutCacheHeader()
}//end class
