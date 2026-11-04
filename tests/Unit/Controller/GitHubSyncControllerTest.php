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
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\GitHubSyncController;
use OCA\Buildiq\Service\GitHub\AppPushRequest;
use OCA\Buildiq\Service\GitHub\BrokerAccess;
use OCA\Buildiq\Service\GitHub\BrokeredRepo;
use OCA\Buildiq\Service\GitHub\PushTarget;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\PermissionResolver;
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
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */
class GitHubSyncControllerTest extends TestCase {

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
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->syncService = $this->createMock(GitHubAppSyncService::class);
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
	private function controller(): GitHubSyncController {
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
	private function authenticate(string $uid = 'bob'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end authenticate()

	/**
	 * link: anonymous callers get 401 and the app is never even loaded.
	 *
	 * @return void
	 */
	public function testLinkRejectsAnonymous(): void {
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
	public function testLinkReturns404WhenApplicationMissing(): void {
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
	public function testLinkForbidsNonOwner(): void {
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
	public function testLinkRejectsMalformedRepo(): void {
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
	 * push: the credential, version, new repo and visibility come from the
	 * request, and the acting user only from the session: a forged identity
	 * parameter changes nothing.
	 *
	 * @return void
	 */
	public function testPushActsAsTheSessionUserWithTheRequestedCredential(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn(
			['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
		);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => [
				'credentialId' => '11111111-1111-4111-8111-111111111111',
				'versionSlug' => 'v2',
				'repo' => ['owner' => 'ignored', 'name' => 'fresh', 'org' => 'acme'],
				'visibility' => 'PRIVATE',
				'userId' => 'mallory',
				'actingUserId' => 'mallory',
				'requestedBy' => 'mallory',
			][$key] ?? $default
		);
		$this->syncService->expects(self::once())
			->method('push')
			->with(
				self::anything(),
				self::equalTo(
					new AppPushRequest(
						access: new BrokerAccess(credentialId: '11111111-1111-4111-8111-111111111111', actingUserId: 'alice'),
						versionSlug: 'v2',
						newRepo: new PushTarget(org: 'acme', repo: 'fresh', visibility: 'private')
					)
				)
			)
			->willReturn(['outcome' => GitHubAppSyncService::OUTCOME_OK]);

		self::assertSame(Http::STATUS_OK, $this->controller()->push(slug: 'permit-tracker')->getStatus());

	}//end testPushActsAsTheSessionUserWithTheRequestedCredential()

	/**
	 * push: without a `repo` param nothing is created, whatever `visibility` says,
	 * and without a visibility a new repo is public.
	 *
	 * @return void
	 */
	public function testPushWithoutARepoParamCreatesNothing(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn(
			['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
		);
		$credentialId = '11111111-1111-4111-8111-111111111111';
		$params = ['credentialId' => $credentialId, 'visibility' => 'private'];
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use (&$params): mixed {
				return $params[$key] ?? $default;
			}
		);
		$seen = [];
		$this->syncService->method('push')->willReturnCallback(
			static function (array $application, AppPushRequest $request) use (&$seen): array {
				$seen[] = $request;
				return ['outcome' => GitHubAppSyncService::OUTCOME_OK];
			}
		);

		$this->controller()->push(slug: 'permit-tracker');
		$params = ['credentialId' => $credentialId, 'repo' => ['name' => 'fresh']];
		$this->controller()->push(slug: 'permit-tracker');

		self::assertNull($seen[0]->newRepo);
		self::assertNull($seen[0]->versionSlug);
		self::assertEquals(new PushTarget(org: '', repo: 'fresh', visibility: 'public'), $seen[1]->newRepo);

	}//end testPushWithoutARepoParamCreatesNothing()

	/**
	 * push: a `repo` param with a malformed name, owner or org creates nothing, so
	 * an attacker-supplied string never reaches a GitHub path.
	 *
	 * @return void
	 */
	public function testPushWithAMalformedRepoParamCreatesNothing(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn(
			['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
		);
		$params = [];
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use (&$params): mixed {
				return $params[$key] ?? $default;
			}
		);
		$seen = [];
		$this->syncService->method('push')->willReturnCallback(
			static function (array $application, AppPushRequest $request) use (&$seen): array {
				$seen[] = $request->newRepo;
				return ['outcome' => GitHubAppSyncService::OUTCOME_OK];
			}
		);

		$malformed = [
			['name' => '../etc'],
			['name' => 'fine', 'owner' => 'a/b'],
			['name' => 'fine', 'org' => 'a b'],
			'not an array',
		];
		foreach ($malformed as $repo) {
			$params = ['credentialId' => 'cred-1', 'repo' => $repo];
			$this->controller()->push(slug: 'permit-tracker');
		}

		self::assertSame([null, null, null, null], $seen);

	}//end testPushWithAMalformedRepoParamCreatesNothing()

	/**
	 * link: the repo and credential come from the request, the acting user only
	 * from the session; no credential means an empty one.
	 *
	 * @return void
	 */
	public function testLinkActsAsTheSessionUser(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn(
			['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
		);
		$params = ['owner' => 'acme', 'name' => 'app', 'credentialId' => 'cred-1', 'userId' => 'mallory', 'actingUserId' => 'mallory'];
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use (&$params): mixed {
				return $params[$key] ?? $default;
			}
		);
		$seen = [];
		$this->syncService->method('link')->willReturnCallback(
			static function (array $application, BrokeredRepo $repo) use (&$seen): array {
				$seen[] = $repo;
				return [];
			}
		);

		$this->controller()->link(slug: 'permit-tracker');
		unset($params['credentialId']);
		$this->controller()->link(slug: 'permit-tracker');

		$target = new PushTarget(org: 'acme', repo: 'app');
		self::assertEquals(new BrokeredRepo(target: $target, access: new BrokerAccess(credentialId: 'cred-1', actingUserId: 'alice')), $seen[0]);
		self::assertEquals(new BrokeredRepo(target: $target, access: new BrokerAccess(credentialId: '', actingUserId: 'alice')), $seen[1]);

	}//end testLinkActsAsTheSessionUser()

	/**
	 * pull: anonymous callers get 401 and nothing is pulled.
	 *
	 * @return void
	 */
	public function testPullRejectsAnonymous(): void {
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
	public function testPullForbidsNonOwner(): void {
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
	public function testPullRejectsMalformedRef(): void {
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
