<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Wire-contract tests for GitHubSyncController's `link` and `pull` endpoints.
 *
 * Both are `#[NoAdminRequired]`, both are owner-only, and neither had any
 * automated contract proof — gate-25 reported them as uncovered public
 * endpoints. These pin the three things that matter at the wire: the
 * anonymous guard, the owner-only guard (admin power deliberately does NOT
 * auto-grant, REQ-OBV-110), and the input validation that runs before any
 * outbound GitHub call.
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\GitHubSyncController;
use OCA\OpenBuild\Service\GitHubAppSyncService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for GitHubSyncController.
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */
class GitHubSyncControllerTest extends TestCase
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
     * Sync service mock.
     *
     * @var GitHubAppSyncService&MockObject
     */
    private $syncService;

    /**
     * Group manager mock backing the real PermissionResolver.
     *
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * Wire the collaborator mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request      = $this->createMock(IRequest::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->syncService  = $this->createMock(GitHubAppSyncService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->groupManager->method('isAdmin')->willReturn(false);

    }//end setUp()

    /**
     * Build the controller under test with a REAL PermissionResolver, so the
     * owner-only decision is genuinely exercised rather than stubbed.
     *
     * @return GitHubSyncController
     */
    private function controller(): GitHubSyncController
    {
        return new GitHubSyncController(
            request: $this->request,
            userSession: $this->userSession,
            syncService: $this->syncService,
            permissionResolver: new PermissionResolver(
                $this->groupManager,
                $this->createMock(LoggerInterface::class)
            )
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
     * link: anonymous callers get 401 and the app is never even loaded.
     *
     * @return void
     */
    public function testLinkRejectsAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->syncService->expects(self::never())->method('link');

        $response = $this->controller()->link(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testLinkRejectsAnonymous()

    /**
     * link: an unknown application slug is a 404 and nothing is linked.
     *
     * @return void
     */
    public function testLinkReturns404WhenApplicationMissing(): void
    {
        $this->authenticate();
        $this->syncService->method('loadApplicationBySlug')->willReturn(null);
        $this->syncService->expects(self::never())->method('link');

        $response = $this->controller()->link(slug: 'does-not-exist');

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testLinkReturns404WhenApplicationMissing()

    /**
     * link: a non-owner is forbidden, and nothing is linked.
     *
     * @return void
     */
    public function testLinkForbidsNonOwner(): void
    {
        $this->authenticate(uid: 'eve-outsider');
        $this->syncService->method('loadApplicationBySlug')->willReturn(
            ['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
        );
        $this->syncService->expects(self::never())->method('link');

        $response = $this->controller()->link(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testLinkForbidsNonOwner()

    /**
     * link: an owner supplying a malformed owner/name is a 400, and no
     * outbound call is made.
     *
     * This guard is what keeps an attacker-supplied string out of the GitHub
     * URL, so it is pinned explicitly.
     *
     * @return void
     */
    public function testLinkRejectsMalformedRepo(): void
    {
        $this->authenticate(uid: 'alice');
        $this->syncService->method('loadApplicationBySlug')->willReturn(
            ['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
        );
        $this->request->method('getParam')->willReturnMap(
            [
                ['owner', null, '../../etc'],
                ['name', null, 'bad name'],
                ['org', null, ''],
            ]
        );
        $this->syncService->expects(self::never())->method('link');

        $response = $this->controller()->link(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertSame('invalid_repo', $response->getData()['error']);

    }//end testLinkRejectsMalformedRepo()

    /**
     * pull: anonymous callers get 401 and nothing is pulled.
     *
     * @return void
     */
    public function testPullRejectsAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->syncService->expects(self::never())->method('pull');

        $response = $this->controller()->pull(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testPullRejectsAnonymous()

    /**
     * pull: a non-owner is forbidden, and nothing is pulled.
     *
     * @return void
     */
    public function testPullForbidsNonOwner(): void
    {
        $this->authenticate(uid: 'eve-outsider');
        $this->syncService->method('loadApplicationBySlug')->willReturn(
            ['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
        );
        $this->syncService->expects(self::never())->method('pull');

        $response = $this->controller()->pull(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testPullForbidsNonOwner()

    /**
     * pull: an owner supplying a malformed git ref is a 400 before any fetch.
     *
     * @return void
     */
    public function testPullRejectsMalformedRef(): void
    {
        $this->authenticate(uid: 'alice');
        $this->syncService->method('loadApplicationBySlug')->willReturn(
            ['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
        );
        $this->request->method('getParam')->willReturnMap(
            [
                ['ref', null, 'not a ref; rm -rf /'],
            ]
        );
        $this->syncService->expects(self::never())->method('pull');

        $response = $this->controller()->pull(slug: 'permit-tracker');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertSame('invalid_ref', $response->getData()['error']);

    }//end testPullRejectsMalformedRef()

}//end class
