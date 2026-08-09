<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Wire-contract tests for ShopController's two GitHub endpoints.
 *
 * Both are `#[NoAdminRequired]` and network-facing, and neither had any
 * automated contract proof — gate-25 reported them as uncovered public
 * endpoints. These pin the parts that matter at the wire: the anonymous
 * guard, the input validation that runs BEFORE any outbound call, and that
 * a failing upstream is translated rather than escaping as a 500.
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ApplicationsController;
use OCA\OpenBuild\Controller\ShopController;
use OCA\OpenBuild\Service\AppRepoParser;
use OCA\OpenBuild\Service\GitHubCatalogService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for ShopController.
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */
class ShopControllerTest extends TestCase
{

    /**
     * Request mock.
     *
     * @var IRequest&MockObject
     */
    private $request;

    /**
     * Session mock.
     *
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * GitHub catalogue service mock.
     *
     * @var GitHubCatalogService&MockObject
     */
    private $catalogService;

    /**
     * Repo parser mock.
     *
     * @var AppRepoParser&MockObject
     */
    private $repoParser;

    /**
     * Applications controller mock (install delegates to it).
     *
     * @var ApplicationsController&MockObject
     */
    private $appsController;

    /**
     * Wire the collaborator mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request        = $this->createMock(IRequest::class);
        $this->userSession    = $this->createMock(IUserSession::class);
        $this->catalogService = $this->createMock(GitHubCatalogService::class);
        $this->repoParser     = $this->createMock(AppRepoParser::class);
        $this->appsController = $this->createMock(ApplicationsController::class);

    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return ShopController
     */
    private function controller(): ShopController
    {
        return new ShopController(
            request: $this->request,
            logger: $this->createMock(LoggerInterface::class),
            userSession: $this->userSession,
            catalogService: $this->catalogService,
            repoParser: $this->repoParser,
            appsController: $this->appsController
        );

    }//end controller()

    /**
     * Wire an authenticated user into the session mock.
     *
     * @param string $uid The UID.
     *
     * @return void
     */
    private function authenticate(string $uid='bob'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end authenticate()

    /**
     * githubSearch: anonymous callers get 401 and no outbound call is made.
     *
     * @return void
     */
    public function testGithubSearchRejectsAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->catalogService->expects(self::never())->method('search');

        $response = $this->controller()->githubSearch();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubSearchRejectsAnonymous()

    /**
     * githubSearch: an authenticated caller reaches the catalogue service and
     * gets its outcome back.
     *
     * @return void
     */
    public function testGithubSearchReturnsCards(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturn(null);
        $this->catalogService->expects(self::once())
            ->method('search')
            ->willReturn(
                // The full documented return shape of
                // GitHubCatalogService::search() —
                // {outcome, cards, brokerUsed, rateLimited}. Returning less
                // makes the controller read an undefined key, which is a
                // defect in the fixture rather than in the controller.
                [
                    'outcome'     => 'ok',
                    'cards'       => [['slug' => 'demo-app']],
                    'brokerUsed'  => false,
                    'rateLimited' => false,
                ]
            );

        $response = $this->controller()->githubSearch();

        self::assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testGithubSearchReturnsCards()

    /**
     * githubInstall: anonymous callers get 401 before any validation or work.
     *
     * @return void
     */
    public function testGithubInstallRejectsAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->repoParser->expects(self::never())->method(self::anything());

        $response = $this->controller()->githubInstall();

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubInstallRejectsAnonymous()

    /**
     * githubInstall: an owner/repo that fails the pattern is a 400, and
     * nothing is fetched.
     *
     * This is the guard that keeps an attacker-supplied string out of the
     * outbound GitHub URL, so it is worth pinning explicitly.
     *
     * @return void
     */
    public function testGithubInstallRejectsMalformedRepo(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnMap(
            [
                ['owner', null, 'not a valid owner/../..'],
                ['repo', null, 'also bad'],
                ['ref', null, null],
                ['name', null, 'Demo'],
                ['slug', null, 'demo-app'],
            ]
        );

        $response = $this->controller()->githubInstall();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertSame('invalid_repo', $response->getData()['error']);

    }//end testGithubInstallRejectsMalformedRepo()

    /**
     * githubInstall: a well-formed repo with a missing name/slug is a 400.
     *
     * @return void
     */
    public function testGithubInstallRequiresNameAndSlug(): void
    {
        $this->authenticate();
        $this->request->method('getParam')->willReturnMap(
            [
                ['owner', null, 'ConductionNL'],
                ['repo', null, 'openbuild'],
                ['ref', null, null],
                ['name', null, ''],
                ['slug', null, ''],
            ]
        );

        $response = $this->controller()->githubInstall();

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testGithubInstallRequiresNameAndSlug()

}//end class
