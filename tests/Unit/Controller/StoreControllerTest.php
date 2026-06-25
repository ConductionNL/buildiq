<?php

/**
 * Unit tests for StoreController (remote template "store") auth + delegation.
 *
 * Covers openbuild-remote-template-store: anonymous search/install rejected
 * (401), authenticated search proxies the store service and returns its cards,
 * an unresolvable slug yields 404 without touching the install seam, and a
 * resolvable slug delegates exactly once to
 * ApplicationsController::installFromTemplateArray and returns its response.
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
 *
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ApplicationsController;
use OCA\OpenBuild\Controller\StoreController;
use OCA\OpenBuild\Service\RemoteTemplateStoreService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StoreController.
 */
class StoreControllerTest extends TestCase
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
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock store service.
     *
     * @var RemoteTemplateStoreService&MockObject
     */
    private RemoteTemplateStoreService&MockObject $storeService;

    /**
     * Mock shared install seam.
     *
     * @var ApplicationsController&MockObject
     */
    private ApplicationsController&MockObject $applicationsController;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request               = $this->createMock(IRequest::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->userSession           = $this->createMock(IUserSession::class);
        $this->storeService          = $this->createMock(RemoteTemplateStoreService::class);
        $this->applicationsController = $this->createMock(ApplicationsController::class);

    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return StoreController
     */
    private function controller(): StoreController
    {
        return new StoreController(
            request: $this->request,
            logger: $this->logger,
            userSession: $this->userSession,
            storeService: $this->storeService,
            applicationsController: $this->applicationsController
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
     * Anonymous search is rejected 401 and the store service is never queried.
     *
     * @return void
     */
    public function testAnonymousSearchIsRejected(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->storeService->expects(self::never())->method('searchTemplates');

        $response = $this->controller()->search();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAnonymousSearchIsRejected()

    /**
     * Anonymous install is rejected 401 and nothing is resolved/installed.
     *
     * @return void
     */
    public function testAnonymousInstallIsRejected(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->storeService->expects(self::never())->method('resolveTemplate');
        $this->applicationsController->expects(self::never())->method('installFromTemplateArray');

        $response = $this->controller()->install(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAnonymousInstallIsRejected()

    /**
     * An authenticated search proxies the store service and returns its cards.
     *
     * @return void
     */
    public function testAuthenticatedSearchProxiesStoreService(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->request->method('getParam')->willReturn('permits');

        $cards = [['slug' => 'permit-tracker', 'title' => 'Permit Tracker']];
        $this->storeService->expects(self::once())
            ->method('searchTemplates')
            ->with('permits')
            ->willReturn(['outcome' => RemoteTemplateStoreService::OUTCOME_OK, 'cards' => $cards]);

        $response = $this->controller()->search();

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        self::assertSame(RemoteTemplateStoreService::OUTCOME_OK, $data['outcome']);
        self::assertSame($cards, $data['cards']);

    }//end testAuthenticatedSearchProxiesStoreService()

    /**
     * An unresolvable slug (resolveTemplate → null) yields 404 and never
     * delegates to the install seam.
     *
     * @return void
     */
    public function testInstallUnresolvableSlugIsNotFound(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key): ?string {
                return match ($key) {
                    'name' => 'My Permits',
                    'slug' => 'my-permits',
                    default => null,
                };
            });

        $this->storeService->method('resolveTemplate')->willReturn(null);
        $this->applicationsController->expects(self::never())->method('installFromTemplateArray');

        $response = $this->controller()->install(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testInstallUnresolvableSlugIsNotFound()

    /**
     * A resolvable slug delegates exactly once to the install seam with the
     * resolved payload + body name/slug + owner UID, and returns its response.
     *
     * @return void
     */
    public function testInstallHappyPathDelegatesToInstallSeam(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->request->method('getParam')
            ->willReturnCallback(static function (string $key): ?string {
                return match ($key) {
                    'name' => 'My Permits',
                    'slug' => 'my-permits',
                    default => null,
                };
            });

        $template = ['slug' => 'permit-tracker', 'manifest' => ['pages' => []]];
        $this->storeService->method('resolveTemplate')->with('permit-tracker')->willReturn($template);

        // The seam returns a {status, data} result; the controller wraps it.
        $this->applicationsController->expects(self::once())
            ->method('installFromTemplateArray')
            ->with($template, 'My Permits', 'my-permits', 'alice')
            ->willReturn(['status' => Http::STATUS_CREATED, 'data' => ['uuid' => 'new-app', 'slug' => 'my-permits']]);

        $response = $this->controller()->install(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_CREATED, $response->getStatus());
        self::assertSame('new-app', $response->getData()['uuid']);

    }//end testInstallHappyPathDelegatesToInstallSeam()

    /**
     * An invalid (non-kebab) template slug is rejected 400 before resolution.
     *
     * @return void
     */
    public function testInstallInvalidSlugIsBadRequest(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->storeService->expects(self::never())->method('resolveTemplate');

        $response = $this->controller()->install(slug: 'Not_Valid');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testInstallInvalidSlugIsBadRequest()

    /**
     * A missing name / invalid new slug body is rejected 400 before resolution.
     *
     * @return void
     */
    public function testInstallMissingNameIsBadRequest(): void
    {
        $this->userSession->method('getUser')->willReturn($this->mockUser('alice'));
        $this->request->method('getParam')->willReturn(null);
        $this->storeService->expects(self::never())->method('resolveTemplate');

        $response = $this->controller()->install(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testInstallMissingNameIsBadRequest()
}//end class
