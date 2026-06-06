<?php

/**
 * Unit tests for RulesController.
 *
 * Covers REQ-BRE-006 / REQ-BRE-004: evaluate returns 200 with the result,
 * 404 on an unknown RuleSet, 401 when unauthenticated, and the NoAdminRequired
 * posture (non-admin authenticated users may evaluate).
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

use OCA\OpenBuild\Controller\RulesController;
use OCA\OpenBuild\Service\RuleEngineService;
use OCA\OpenBuild\Service\RuleSetVersioningService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for {@see RulesController}.
 */
final class RulesControllerTest extends TestCase
{

    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * @var RuleEngineService&MockObject
     */
    private RuleEngineService&MockObject $ruleEngine;

    /**
     * @var RuleSetVersioningService&MockObject
     */
    private RuleSetVersioningService&MockObject $versioningService;

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Build mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request           = $this->createMock(IRequest::class);
        $this->ruleEngine        = $this->createMock(RuleEngineService::class);
        $this->versioningService = $this->createMock(RuleSetVersioningService::class);
        $this->objectService     = $this->createMock(ObjectService::class);
        $this->userSession       = $this->createMock(IUserSession::class);

    }//end setUp()

    /**
     * Construct the controller under test.
     *
     * @return RulesController
     */
    private function controller(): RulesController
    {
        return new RulesController(
            $this->request,
            $this->createMock(LoggerInterface::class),
            $this->ruleEngine,
            $this->versioningService,
            $this->objectService,
            $this->userSession
        );

    }//end controller()

    /**
     * Authenticate the session as a non-admin user.
     *
     * @return void
     */
    private function authenticate(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $this->userSession->method('getUser')->willReturn($user);

    }//end authenticate()

    /**
     * evaluate returns 200 and the engine outcome for an authenticated user.
     *
     * @return void
     */
    public function testEvaluateOk(): void
    {
        $this->authenticate();
        $this->request->method('getParams')->willReturn(['payload' => ['x' => 1]]);
        $this->ruleEngine->method('evaluate')->willReturn(
            ['result' => ['decision' => 'approve'], 'geraaktRegels' => ['r1'], 'executieDuur' => 3, 'fouten' => []]
        );

        $response = $this->controller()->evaluate('loan-eligibility');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('approve', $response->getData()['result']['decision']);

    }//end testEvaluateOk()

    /**
     * evaluate returns 404 when the engine reports the RuleSet missing.
     *
     * @return void
     */
    public function testEvaluateNotFound(): void
    {
        $this->authenticate();
        $this->request->method('getParams')->willReturn(['payload' => []]);
        $this->ruleEngine->method('evaluate')->willThrowException(new RuntimeException('missing', 404));

        $response = $this->controller()->evaluate('ghost');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testEvaluateNotFound()

    /**
     * evaluate returns 401 when there is no authenticated user.
     *
     * @return void
     */
    public function testEvaluateUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller()->evaluate('loan-eligibility');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testEvaluateUnauthenticated()

    /**
     * evaluate surfaces a 408 when the engine reports a timeout.
     *
     * @return void
     */
    public function testEvaluateTimeout(): void
    {
        $this->authenticate();
        $this->request->method('getParams')->willReturn(['payload' => []]);
        $this->ruleEngine->method('evaluate')->willReturn(
            ['result' => [], 'geraaktRegels' => [], 'executieDuur' => 999, 'fouten' => ['Evaluation exceeded the 500ms soft timeout (999ms).']]
        );

        $response = $this->controller()->evaluate('slow');
        $this->assertSame(Http::STATUS_REQUEST_TIMEOUT, $response->getStatus());

    }//end testEvaluateTimeout()

    /**
     * The evaluate method declares #[NoAdminRequired] (ADR-005 posture).
     *
     * @return void
     */
    public function testEvaluateIsNoAdminRequired(): void
    {
        $method     = new ReflectionMethod(RulesController::class, 'evaluate');
        $attributes = $method->getAttributes(NoAdminRequired::class);
        $this->assertCount(1, $attributes);

    }//end testEvaluateIsNoAdminRequired()
}//end class
