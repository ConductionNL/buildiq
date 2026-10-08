<?php

/**
 * The searches, pushes and pulls that report to integriq.
 *
 * A store search, a GitHub catalogue search, a push and a pull are the moments
 * Buildiq learns whether its store and GitHub work. If these callers stop
 * handing the outcome over, the Integrations page keeps an old status and looks
 * like an answer. Every test here asserts the outcome the caller actually hands
 * over, and that the response is the same with and without a reporter.
 *
 * @category Tests
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Controller\GitHubSyncController;
use OCA\Buildiq\Controller\ShopController;
use OCA\Buildiq\Controller\StoreController;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Buildiq\Service\GitHubAppSyncService;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the connection reports sent from the store, shop and sync controllers.
 *
 * @covers \OCA\Buildiq\Controller\StoreController
 * @covers \OCA\Buildiq\Controller\ShopController
 * @covers \OCA\Buildiq\Controller\GitHubSyncController
 * @uses \OCA\Buildiq\Service\PermissionResolver
 */
class ConnectionReportCallersTest extends TestCase {

	/**
	 * Every report handed over, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $reports = [];

	/**
	 * The recording reporter.
	 *
	 * @var ConnectionReporter&MockObject
	 */
	private ConnectionReporter&MockObject $reporter;

	/**
	 * The mocked session, signed in as alice.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up a reporter that records instead of dispatching.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->reports  = [];
		$this->reporter = $this->getMockBuilder(className: ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['reportStoreSearch', 'reportGitHubSearch', 'reportGitHubSync'])
			->getMock();
		foreach (['reportStoreSearch', 'reportGitHubSearch', 'reportGitHubSync'] as $method) {
			$this->reporter->method($method)->willReturnCallback(
				function (mixed ...$args) use ($method): bool {
					$this->reports[] = [$method, $args];
					return true;
				}
			);
		}

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * The store controller around a store service that answers the given outcome.
	 *
	 * @param string|RuntimeException  $outcome  The search outcome, or an exception to throw.
	 * @param ConnectionReporter|null $reporter The reporter, or none.
	 *
	 * @return StoreController
	 */
	private function storeController(string|RuntimeException $outcome, ?ConnectionReporter $reporter): StoreController {
		$store = $this->createMock(originalClassName: GenericStoreService::class);
		if ($outcome instanceof RuntimeException) {
			$store->method('search')->willThrowException($outcome);
		} else {
			$store->method('search')->willReturn(['outcome' => $outcome, 'cards' => []]);
		}

		return new StoreController(
			request: $this->createMock(originalClassName: IRequest::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			userSession: $this->userSession,
			storeService: $store,
			appsController: $this->createMock(originalClassName: ApplicationsController::class),
			connectionReporter: $reporter
		);
	}//end storeController()

	/**
	 * The shop controller around a catalogue that answers the given outcome.
	 *
	 * @param string|RuntimeException $outcome The search outcome, or an exception to throw.
	 * @param bool                    $broker  Whether the broker is installed.
	 *
	 * @return ShopController
	 */
	private function shopController(string|RuntimeException $outcome, bool $broker): ShopController {
		$catalog = $this->createMock(originalClassName: GitHubCatalogService::class);
		$catalog->method('isBrokerAvailable')->willReturn($broker);
		if ($outcome instanceof RuntimeException) {
			$catalog->method('search')->willThrowException($outcome);
		} else {
			$catalog->method('search')->willReturn(['outcome' => $outcome, 'cards' => [], 'brokerUsed' => false, 'rateLimited' => false]);
		}

		return new ShopController(
			request: $this->createMock(originalClassName: IRequest::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			userSession: $this->userSession,
			catalogService: $catalog,
			repoParser: $this->createMock(originalClassName: AppRepoParser::class),
			appsController: $this->createMock(originalClassName: ApplicationsController::class),
			connectionReporter: $this->reporter
		);
	}//end shopController()

	/**
	 * The sync controller around a sync service whose push and pull answer the given outcome.
	 *
	 * @param string $outcome The push or pull outcome.
	 *
	 * @return GitHubSyncController
	 */
	private function syncController(string $outcome): GitHubSyncController {
		$sync = $this->createMock(originalClassName: GitHubAppSyncService::class);
		$sync->method('loadApplicationBySlug')->willReturn(
			['id' => 'app-1', 'slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
		);
		$sync->method('push')->willReturn(['outcome' => $outcome]);
		$sync->method('pull')->willReturn(['outcome' => $outcome]);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap(
			[
				['credentialId', null, 'cred-1'],
				['ref', null, 'main'],
				['versionSlug', null, null],
				['repo', null, null],
				['visibility', null, null],
			]
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);

		return new GitHubSyncController(
			request: $request,
			userSession: $this->userSession,
			syncService: $sync,
			permissionResolver: new PermissionResolver(
				groupManager: $groups,
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			connectionReporter: $this->reporter
		);
	}//end syncController()

	/**
	 * A store search hands its outcome over, and the response is the same without a reporter.
	 *
	 * @return void
	 */
	public function testAStoreSearchHandsItsOutcomeOver(): void {
		$with    = $this->storeController(outcome: 'store_invalid_response', reporter: $this->reporter)->search();
		$without = $this->storeController(outcome: 'store_invalid_response', reporter: null)->search();

		$this->assertSame(expected: [['reportStoreSearch', ['store_invalid_response']]], actual: $this->reports);
		$this->assertSame(expected: $without->getData(), actual: $with->getData());
		$this->assertSame(expected: $without->getStatus(), actual: $with->getStatus());
	}//end testAStoreSearchHandsItsOutcomeOver()

	/**
	 * A store search that throws reports the store as unreachable.
	 *
	 * @return void
	 */
	public function testAThrowingStoreSearchReportsUnreachable(): void {
		$response = $this->storeController(outcome: new RuntimeException('connection refused'), reporter: $this->reporter)->search();

		$this->assertSame(expected: 'store_unreachable', actual: $response->getData()['outcome']);
		$this->assertSame(expected: [['reportStoreSearch', ['store_unreachable']]], actual: $this->reports);
	}//end testAThrowingStoreSearchReportsUnreachable()

	/**
	 * An anonymous store search reports nothing: nothing was searched.
	 *
	 * @return void
	 */
	public function testAnAnonymousStoreSearchReportsNothing(): void {
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);

		$this->storeController(outcome: 'ok', reporter: $this->reporter)->search();

		$this->assertSame(expected: [], actual: $this->reports);
	}//end testAnAnonymousStoreSearchReportsNothing()

	/**
	 * A GitHub search hands over its outcome and whether the broker is installed.
	 *
	 * @return void
	 */
	public function testAGitHubSearchHandsOverItsOutcomeAndTheBroker(): void {
		$this->shopController(outcome: 'ok', broker: false)->githubSearch();
		$this->shopController(outcome: new RuntimeException('dns'), broker: true)->githubSearch();

		$this->assertSame(
			expected: [
				['reportGitHubSearch', ['ok', false]],
				['reportGitHubSearch', ['github_unreachable', true]],
			],
			actual: $this->reports
		);
	}//end testAGitHubSearchHandsOverItsOutcomeAndTheBroker()

	/**
	 * A push and a pull hand their outcome over, and the response is unchanged.
	 *
	 * @return void
	 */
	public function testAPushAndAPullHandTheirOutcomeOver(): void {
		$push = $this->syncController(outcome: 'broker_unavailable')->push(slug: 'permit-tracker');
		$pull = $this->syncController(outcome: 'ok')->pull(slug: 'permit-tracker');

		$this->assertInstanceOf(expected: JSONResponse::class, actual: $push);
		$this->assertSame(expected: 403, actual: $push->getStatus());
		$this->assertSame(expected: 200, actual: $pull->getStatus());
		$this->assertSame(
			expected: [
				['reportGitHubSync', ['broker_unavailable']],
				['reportGitHubSync', ['ok']],
			],
			actual: $this->reports
		);
	}//end testAPushAndAPullHandTheirOutcomeOver()
}//end class
