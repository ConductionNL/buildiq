<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GitHubSyncController: a Nextcloud admin is not an owner (REQ-GHAS-001).
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\GitHubSyncController;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\PermissionResolver;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins that admin power does not auto-grant a GitHub write: an admin who is
 * an editor or viewer, or holds no role, is refused link, push and pull with
 * 403 and the service never acts; the same admin listed as an owner passes.
 *
 * @covers \OCA\Buildiq\Controller\GitHubSyncController
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Service\GitHub\SyncRequestParams
 * @uses   \OCA\Buildiq\Kernel\Values\AppPushRequest
 * @uses   \OCA\Buildiq\Kernel\Values\BrokerAccess
 */
class GitHubSyncControllerAdminTest extends TestCase {

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Sync service mock.
	 *
	 * @var GitHubAppSyncService&MockObject
	 */
	private GitHubAppSyncService&MockObject $syncService;

	/**
	 * The controller with an authenticated admin `root` in the admin group.
	 *
	 * @return GitHubSyncController
	 */
	private function controller(): GitHubSyncController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getGID')->willReturn('admin');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$groups->method('getUserGroups')->willReturn([$adminGroup]);

		return new GitHubSyncController(
			request: $this->request,
			userSession: $session,
			syncService: $this->syncService,
			permissionResolver: new PermissionResolver($groups, $this->createMock(LoggerInterface::class))
		);
	}//end controller()

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnMap(
			[
				['credentialId', null, 'cred-1'],
				['ref', null, 'main'],
				['owner', null, 'Conduction'],
				['name', null, 'permit-tracker'],
			]
		);
		$this->syncService = $this->createMock(GitHubAppSyncService::class);
	}//end setUp()

	/**
	 * An admin who is not an owner is refused every write and nothing acts.
	 *
	 * @return void
	 */
	public function testAnAdminWhoIsNotAnOwnerIsRefusedEveryWrite(): void {
		$permissionSets = [
			'editor' => ['owners' => ['user:alice'], 'editors' => ['user:root']],
			'viewer' => ['owners' => ['user:alice'], 'viewers' => ['user:root']],
			'admin group as editor' => ['owners' => ['user:alice'], 'editors' => ['group:admin']],
			'no role' => ['owners' => ['user:alice']],
		];

		foreach ($permissionSets as $name => $permissions) {
			$this->syncService = $this->createMock(GitHubAppSyncService::class);
			$this->syncService->method('loadApplicationBySlug')->willReturn(
				['uuid' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => $permissions]
			);
			$this->syncService->expects(self::never())->method('link');
			$this->syncService->expects(self::never())->method('push');
			$this->syncService->expects(self::never())->method('pull');
			$controller = $this->controller();

			foreach (['link', 'push', 'pull'] as $action) {
				$response = $controller->$action(slug: 'permit-tracker');
				self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $name . ' ' . $action);
				self::assertSame('forbidden', $response->getData()['error'], $name . ' ' . $action);
			}
		}
	}//end testAnAdminWhoIsNotAnOwnerIsRefusedEveryWrite()

	/**
	 * The same admin listed as an owner reaches the service.
	 *
	 * @return void
	 */
	public function testAnAdminListedAsOwnerIsLetThrough(): void {
		$this->syncService->method('loadApplicationBySlug')->willReturn(
			['uuid' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:root']]]
		);
		$this->syncService->expects(self::once())->method('push')->willReturn(
			['outcome' => 'ok', 'commitSha' => 'abc', 'branch' => 'main', 'repoUrl' => 'https://github.com/Conduction/permit-tracker']
		);

		$response = $this->controller()->push(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('abc', $response->getData()['commitSha']);
	}//end testAnAdminListedAsOwnerIsLetThrough()
}//end class
