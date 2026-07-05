<?php

/**
 * Unit tests for DashboardController.
 *
 * Covers the standalone virtual-app runtime routes (#100 fix): the bare
 * `/builder/{slug}` route, its trailing-slash alias, the new generic
 * `/builder/{slug}/{path}` deep-link route, and the reserved-designer
 * `/builder/{slug}/{designerPath}` route that must keep serving the
 * OpenBuild SPA shell.
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

use OCA\OpenBuild\Controller\DashboardController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see DashboardController}.
 */
class DashboardControllerTest extends TestCase
{
    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock initial-state writer.
     *
     * @var IInitialState&MockObject
     */
    private IInitialState&MockObject $initialState;

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
     * Controller under test.
     */
    private DashboardController $controller;

    /**
     * Build mocks and the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request      = $this->createMock(IRequest::class);
        $this->initialState = $this->createMock(IInitialState::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroups')->willReturn([]);

        $this->request->method('getParam')->willReturn('');

        $this->controller = new DashboardController(
            $this->request,
            $this->initialState,
            $this->userSession,
            $this->groupManager
        );
    }//end setUp()

    /**
     * Wire the initial-state mock to record every published key/value pair
     * instead of constraining a single call — builder()/builderSlash()/
     * builderPath() each publish THREE keys (currentUserGroups, builderSlug,
     * builderVersion) via {@see DashboardController::publishCurrentUserGroups()}
     * plus their own state, so a single `expects($this->once())->with(...)`
     * would under-count the real call total.
     *
     * Returns an {@see \ArrayObject} (not a plain array) because it is
     * returned BEFORE the controller method runs — a plain array would copy
     * its (still-empty) value at return time, before the mock callback below
     * ever fires. ArrayObject has reference/handle semantics, so mutations
     * the closure makes afterwards are visible through the same handle.
     *
     * @return \ArrayObject<string, mixed> A live-updated map, keyed by state key.
     */
    private function captureInitialState(): \ArrayObject
    {
        $published = new \ArrayObject();
        $this->initialState
            ->method('provideInitialState')
            ->willReturnCallback(function (string $key, $value) use ($published): void {
                $published[$key] = $value;
            });

        return $published;
    }//end captureInitialState()

    // -------------------------------------------------------------------------
    // builder() — bare /builder/{slug}
    // -------------------------------------------------------------------------

    /**
     * builder() renders the standalone 'builder' template and publishes the
     * slug via IInitialState.
     *
     * @return void
     */
    public function testBuilderRendersStandaloneTemplateAndPublishesSlug(): void
    {
        $published = $this->captureInitialState();

        $response = $this->controller->builder('spectr');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('builder', $response->getTemplateName());
        $this->assertSame('spectr', $published['builderSlug']);
    }//end testBuilderRendersStandaloneTemplateAndPublishesSlug()

    // -------------------------------------------------------------------------
    // builderSlash() — trailing-slash alias
    // -------------------------------------------------------------------------

    /**
     * builderSlash() delegates to builder() and serves the exact same page.
     *
     * @return void
     */
    public function testBuilderSlashDelegatesToBuilder(): void
    {
        $published = $this->captureInitialState();

        $response = $this->controller->builderSlash('spectr');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('builder', $response->getTemplateName());
        $this->assertSame('spectr', $published['builderSlug']);
    }//end testBuilderSlashDelegatesToBuilder()

    // -------------------------------------------------------------------------
    // builderPath() — generic virtual-app deep link (#100)
    // -------------------------------------------------------------------------

    /**
     * builderPath() serves the SAME standalone 'builder' template as the bare
     * route regardless of the app-defined sub-path — the deployed app's own
     * client-side router resolves {path}, not the server.
     *
     * @return void
     */
    public function testBuilderPathServesStandaloneTemplateForAnySubPath(): void
    {
        $published = $this->captureInitialState();

        $response = $this->controller->builderPath('spectr', 'tenders');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('builder', $response->getTemplateName());
        $this->assertSame('spectr', $published['builderSlug']);
    }//end testBuilderPathServesStandaloneTemplateForAnySubPath()

    /**
     * builderPath() ignores nested multi-segment sub-paths the same way —
     * the deployed app's own router resolves them client-side.
     *
     * @return void
     */
    public function testBuilderPathServesStandaloneTemplateForNestedSubPath(): void
    {
        $response = $this->controller->builderPath('spectr', 'tenders/123');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('builder', $response->getTemplateName());
    }//end testBuilderPathServesStandaloneTemplateForNestedSubPath()

    // -------------------------------------------------------------------------
    // builderDesigner() — reserved OpenBuild designer surfaces (#100)
    // -------------------------------------------------------------------------

    /**
     * builderDesigner() serves the OpenBuild SPA's own 'index' template — the
     * SAME page as catchAll() — for the reserved designer sub-paths, NOT the
     * standalone virtual-app runtime.
     *
     * @return void
     */
    public function testBuilderDesignerServesSpaIndexTemplate(): void
    {
        $response = $this->controller->builderDesigner('spectr', 'pages');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('index', $response->getTemplateName());
    }//end testBuilderDesignerServesSpaIndexTemplate()

    /**
     * builderDesigner() serves the same SPA index template for the
     * `schemas/{schemaId}` designer detail sub-path.
     *
     * @return void
     */
    public function testBuilderDesignerServesSpaIndexTemplateForSchemaDetail(): void
    {
        $response = $this->controller->builderDesigner('spectr', 'schemas/abc-123');

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertSame('index', $response->getTemplateName());
    }//end testBuilderDesignerServesSpaIndexTemplateForSchemaDetail()
}//end class
