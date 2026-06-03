<?php

/**
 * Unit tests for HealthController.
 *
 * Covers REQ-OBS-005 (liveness probe: authenticated → {"status":"ok"}/200,
 * unauthenticated → 401).
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

use OCA\OpenBuild\Controller\HealthController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see HealthController}.
 */
final class HealthControllerTest extends TestCase
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
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->request     = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @param bool $authenticated Whether the session has a logged-in user.
     *
     * @return HealthController
     */
    private function buildController(bool $authenticated=true): HealthController
    {
        if ($authenticated === true) {
            $user = $this->createMock(IUser::class);
            $this->userSession->method('getUser')->willReturn($user);
        } else {
            $this->userSession->method('getUser')->willReturn(null);
        }

        return new HealthController(
            request: $this->request,
            userSession: $this->userSession,
        );
    }//end buildController()

    /**
     * REQ-OBS-005 — index() returns 401 for unauthenticated requests.
     *
     * @return void
     */
    public function testIndexReturns401ForUnauthenticated(): void
    {
        $response = $this->buildController(authenticated: false)->index();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        self::assertSame('Unauthenticated.', $response->getData()['error']);
    }//end testIndexReturns401ForUnauthenticated()

    /**
     * REQ-OBS-005 — index() returns {"status":"ok"} with HTTP 200 for authenticated caller.
     *
     * @return void
     */
    public function testIndexReturnsStatusOkForAuthenticatedCaller(): void
    {
        $response = $this->buildController()->index();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('ok', $response->getData()['status']);
    }//end testIndexReturnsStatusOkForAuthenticatedCaller()
}//end class
