<?php

/**
 * GitHub sync answers "not found" for another organisation's scoped app.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\GitHubSyncController;
use OCA\Buildiq\Service\AppOrganisationGuard;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\PermissionResolver;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The status endpoint stands for every GitHub sync route: they share
 * requireRole(), which consults the organisation guard before any role.
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-a-scoped-app-is-invisible-to-other-organisations-req-bqos-002
 */
class GitHubSyncControllerOrganisationScopeTest extends TestCase {

	/**
	 * The controller over a scoped app alice owns.
	 *
	 * @param AppOrganisationGuard|null $guard        The guard, if any.
	 * @param string                    $organisation The app's scope.
	 *
	 * @return GitHubSyncController
	 */
	private function controller(?AppOrganisationGuard $guard, string $organisation = 'holder'): GitHubSyncController {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$sync = $this->createMock(originalClassName: GitHubAppSyncService::class);
		$sync->method('loadApplicationBySlug')->willReturn([
			'id' => 'app-1',
			'slug' => 'permit-tracker',
			'organisationScope' => $organisation,
			'githubRepo' => 'acme/permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);

		return new GitHubSyncController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			syncService: $sync,
			permissionResolver: new PermissionResolver(groupManager: $groupManager, logger: $this->createMock(originalClassName: LoggerInterface::class)),
			organisationGuard: $guard
		);
	}//end controller()

	/**
	 * A guard answering `canSee` with the given value.
	 *
	 * @param bool $member Whether the caller belongs to the holder.
	 *
	 * @return AppOrganisationGuard
	 */
	private function guard(bool $member): AppOrganisationGuard {
		$guard = $this->createMock(originalClassName: AppOrganisationGuard::class);
		$guard->method('canSee')->willReturn($member);
		return $guard;
	}//end guard()

	/**
	 * An owner outside the holder organisation gets 404, like an unknown slug.
	 *
	 * @return void
	 */
	public function testAnOwnerOutsideTheOrganisationGetsNotFound(): void {
		$outsider = $this->controller(guard: $this->guard(member: false))->status(slug: 'permit-tracker');
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $outsider->getStatus());
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller(guard: null)->status(slug: 'permit-tracker')->getStatus());
	}//end testAnOwnerOutsideTheOrganisationGetsNotFound()

	/**
	 * A member owner, and any owner of a shared app, get the status.
	 *
	 * @return void
	 */
	public function testAMemberAndASharedAppPass(): void {
		$response = $this->controller(guard: $this->guard(member: true))->status(slug: 'permit-tracker');
		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(expected: 'acme/permit-tracker', actual: $response->getData()['githubRepo']);

		$shared = $this->controller(guard: null, organisation: 'all')->status(slug: 'permit-tracker');
		self::assertSame(expected: Http::STATUS_OK, actual: $shared->getStatus());
	}//end testAMemberAndASharedAppPass()
}//end class
