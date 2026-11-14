<?php

/**
 * Unit tests for GitHubSyncController's status endpoint, outcome mapping and
 * the parameter refusals the existing owner-gate spec does not reach.
 *
 * Two of these matter beyond coverage. `status()` is the only viewer-readable
 * endpoint on the controller, so the role set it accepts is a boundary of its
 * own. And `outcomeResponse()` decides whether a failed push reads as "your
 * token lacks the scope" (403) or "GitHub is down" (502) — the comment in the
 * SUT says sending a permissions refusal out as a gateway error is what points
 * the reader at the network instead of at the token, so each arm is pinned.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Buildiq\Controller\GitHubSyncController
 *
 * @uses \OCA\Buildiq\Service\PermissionResolver
 * @uses \OCA\Buildiq\Service\GitHub\SyncRequestParams
 * @uses \OCA\Buildiq\Exception\AppRepoParseException
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\GitHubSyncController;
use OCA\Buildiq\Exception\AppRepoParseException;
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
 * Covers status(), the outcome→status map, and the parameter refusals.
 */
class GitHubSyncControllerStatusTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var GitHubAppSyncService&MockObject
	 */
	private GitHubAppSyncService&MockObject $syncService;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Request params for this test's controller.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Set up the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->syncService = $this->createMock(GitHubAppSyncService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}//end setUp()

	/**
	 * Build the controller with a REAL PermissionResolver, so the role
	 * decision is genuinely exercised rather than stubbed.
	 *
	 * @return GitHubSyncController The controller.
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
	private function authenticate(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end authenticate()

	// ------------------------------------------------------------------
	// status()
	// ------------------------------------------------------------------

	/**
	 * status: a viewer — not an owner — may read the linkage, and the answer
	 * reports the repo, the branch, the last pushed sha and the broker flags.
	 *
	 * @return void
	 */
	public function testStatusIsReadableByAViewerAndReportsTheLinkage(): void {
		$this->authenticate(uid: 'vera-viewer');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'id' => 'app-1',
			'slug' => 'permit-tracker',
			'githubRepo' => 'acme/permits',
			'githubDefaultBranch' => 'main',
			'lastPushedSha' => 'abc123',
			'permissions' => ['viewers' => ['user:vera-viewer']],
		]);
		$this->syncService->method('isBrokerAvailable')->willReturn(true);

		$response = $this->controller()->status(slug: 'permit-tracker');
		$body = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('acme/permits', $body['githubRepo']);
		self::assertSame('main', $body['githubDefaultBranch']);
		self::assertSame('abc123', $body['lastPushedSha']);
		self::assertTrue($body['brokerCredentialAvailable']);
		self::assertTrue($body['publishAvailable']);
		self::assertNull($body['lastPulledSha'], 'Pull provenance is not reported yet.');
	}//end testStatusIsReadableByAViewerAndReportsTheLinkage()

	/**
	 * status: an unlinked app reports nulls rather than omitting the keys, and
	 * an unavailable broker turns publishing off.
	 *
	 * @return void
	 */
	public function testStatusReportsNullsForAnUnlinkedAppAndNoBroker(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'id' => 'app-1',
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->method('isBrokerAvailable')->willReturn(false);

		$body = $this->controller()->status(slug: 'permit-tracker')->getData();

		self::assertNull($body['githubRepo']);
		self::assertNull($body['githubDefaultBranch']);
		self::assertNull($body['lastPushedSha']);
		self::assertFalse($body['brokerCredentialAvailable']);
		self::assertFalse($body['publishAvailable']);
	}//end testStatusReportsNullsForAnUnlinkedAppAndNoBroker()

	/**
	 * status: a sha that is not a string is not reported as one — the stored
	 * value is best-effort provenance, so a malformed one reads as absent.
	 *
	 * @return void
	 */
	public function testStatusDropsANonStringLastPushedSha(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'lastPushedSha' => ['not', 'a', 'sha'],
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->method('isBrokerAvailable')->willReturn(false);

		$body = $this->controller()->status(slug: 'permit-tracker')->getData();

		self::assertNull($body['lastPushedSha']);
	}//end testStatusDropsANonStringLastPushedSha()

	/**
	 * status: an outsider holding none of the three roles is forbidden.
	 *
	 * @return void
	 */
	public function testStatusForbidsSomeoneWithNoRole(): void {
		$this->authenticate(uid: 'eve-outsider');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);

		$response = $this->controller()->status(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testStatusForbidsSomeoneWithNoRole()

	/**
	 * A permissions field that is not an array grants nothing — it is read as
	 * an empty permission set, so the caller is forbidden rather than let in.
	 *
	 * @return void
	 */
	public function testMalformedPermissionsGrantNothing(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => 'owners:alice',
		]);

		$response = $this->controller()->status(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testMalformedPermissionsGrantNothing()

	// ------------------------------------------------------------------
	// Parameter refusals
	// ------------------------------------------------------------------

	/**
	 * link: a malformed org is refused before the repo is linked, even when
	 * the owner and name themselves are well-formed.
	 *
	 * @return void
	 */
	public function testLinkRefusesAMalformedOrg(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->expects(self::never())->method('link');
		$this->params = ['owner' => 'acme', 'name' => 'permits', 'org' => 'not a valid org!'];

		$response = $this->controller()->link(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('invalid_org', $response->getData()['error']);
	}//end testLinkRefusesAMalformedOrg()

	/**
	 * push: without a credential id there is nothing to push with, so the
	 * service is never called.
	 *
	 * @return void
	 */
	public function testPushRefusesAMissingCredential(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->expects(self::never())->method('push');
		$this->params = [];

		$response = $this->controller()->push(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('invalid_credential', $response->getData()['error']);
	}//end testPushRefusesAMissingCredential()

	/**
	 * push: a non-owner is refused before any parameter is read.
	 *
	 * @return void
	 */
	public function testPushForbidsANonOwner(): void {
		$this->authenticate(uid: 'eve-outsider');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->expects(self::never())->method('push');

		$response = $this->controller()->push(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testPushForbidsANonOwner()

	/**
	 * pull: a malformed credential id is refused, where an absent one is
	 * allowed (the broker's own credential is used then).
	 *
	 * @return void
	 */
	public function testPullRefusesAMalformedCredential(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->expects(self::never())->method('pull');
		$this->params = ['ref' => 'main', 'credentialId' => 'has spaces and $igns'];

		$response = $this->controller()->pull(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('invalid_credential', $response->getData()['error']);
	}//end testPullRefusesAMalformedCredential()

	/**
	 * pull: a repo whose app files cannot be parsed answers 422 with the
	 * exception's caller-safe payload — code and file, no wrapped cause.
	 *
	 * @return void
	 */
	public function testPullAnswersAParseFailureWithItsSafePayload(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->method('pull')->willThrowException(
			new AppRepoParseException(
				errorCode: 'manifest_invalid',
				message: 'The manifest is not valid JSON.',
				filePath: 'app/manifest.json'
			)
		);
		$this->params = ['ref' => 'main'];

		$response = $this->controller()->pull(slug: 'permit-tracker');
		$body = $response->getData();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('repo_parse_failed', $body['error']);
		self::assertSame('manifest_invalid', $body['code']);
		self::assertSame('app/manifest.json', $body['file']);
		self::assertSame('The manifest is not valid JSON.', $body['detail']);
	}//end testPullAnswersAParseFailureWithItsSafePayload()

	// ------------------------------------------------------------------
	// outcome → HTTP status
	// ------------------------------------------------------------------

	/**
	 * Every modelled push outcome maps to the status that sends the reader to
	 * the right place: a permissions refusal is a 403, not a 502.
	 *
	 * @param string $outcome The service outcome.
	 * @param int $expected The HTTP status it must answer with.
	 *
	 * @dataProvider pushOutcomes
	 *
	 * @return void
	 */
	public function testAPushOutcomeMapsToItsStatus(string $outcome, int $expected): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->method('push')->willReturn(['outcome' => $outcome]);
		$this->params = ['credentialId' => 'cred-1'];

		$response = $this->controller()->push(slug: 'permit-tracker');

		self::assertSame($expected, $response->getStatus());
		if ($outcome !== GitHubAppSyncService::OUTCOME_OK) {
			self::assertSame($outcome, $response->getData()['error']);
		}
	}//end testAPushOutcomeMapsToItsStatus()

	/**
	 * The outcome→status pairs.
	 *
	 * @return array<string, array{0: string, 1: int}> The cases.
	 */
	public static function pushOutcomes(): array {
		return [
			'ok' => [GitHubAppSyncService::OUTCOME_OK, Http::STATUS_OK],
			'not linked' => [GitHubAppSyncService::OUTCOME_NOT_LINKED, Http::STATUS_BAD_REQUEST],
			'version not found' => ['version_not_found', Http::STATUS_BAD_REQUEST],
			'push conflict' => [GitHubAppSyncService::OUTCOME_PUSH_CONFLICT, Http::STATUS_CONFLICT],
			'broker unavailable' => [GitHubAppSyncService::OUTCOME_BROKER_UNAVAILABLE, Http::STATUS_FORBIDDEN],
			'broker denied' => [GitHubAppSyncService::OUTCOME_BROKER_DENIED, Http::STATUS_FORBIDDEN],
			'github forbidden' => [GitHubAppSyncService::OUTCOME_FORBIDDEN, Http::STATUS_FORBIDDEN],
			'github unreachable' => [GitHubAppSyncService::OUTCOME_UNREACHABLE, Http::STATUS_BAD_GATEWAY],
			'an outcome nobody modelled' => ['something_new', Http::STATUS_BAD_GATEWAY],
		];
	}//end pushOutcomes()

	/**
	 * A result carrying no outcome at all is treated as unreachable, not as a
	 * success — a missing field must never read as "it worked".
	 *
	 * @return void
	 */
	public function testAResultWithNoOutcomeIsTreatedAsUnreachable(): void {
		$this->authenticate(uid: 'alice');
		$this->syncService->method('loadApplicationBySlug')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
		$this->syncService->method('push')->willReturn([]);
		$this->params = ['credentialId' => 'cred-1'];

		$response = $this->controller()->push(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		self::assertSame(
			GitHubAppSyncService::OUTCOME_UNREACHABLE,
			$response->getData()['error']
		);
	}//end testAResultWithNoOutcomeIsTreatedAsUnreachable()
}//end class
