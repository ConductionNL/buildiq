<?php

/**
 * Unit tests for AutomationsController::runs() and ::runsSummary().
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AutomationsController;
use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\AutomationWriteService;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ConditionActionExecutor;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An editor reads the runs; a user without a role gets the status refusal; the summary leaves out what the caller may not read.
 */
final class AutomationsControllerRunsTest extends TestCase {

	/**
	 * The automations and applications OpenRegister holds, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const OBJECTS = [
		'aut-1' => ['id' => 'aut-1', 'applicationSlug' => 'pipelinq', 'versionUuid' => 'v-1'],
		'aut-2' => ['id' => 'aut-2', 'applicationSlug' => 'other', 'versionUuid' => 'v-9'],
		'pipelinq' => ['id' => 'app-1', 'slug' => 'pipelinq', 'permissions' => ['owners' => ['user:alice'], 'editors' => ['user:bob']]],
		'other' => ['id' => 'app-2', 'slug' => 'other', 'permissions' => ['owners' => ['user:carol']]],
	];

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The run log.
	 *
	 * @var AutomationRunRecorderInterface&MockObject
	 */
	private AutomationRunRecorderInterface&MockObject $runs;

	/**
	 * The controller under test.
	 *
	 * @var AutomationsController
	 */
	private AutomationsController $controller;

	/**
	 * Wire the controller with mocked boundaries and the real write service and permission resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request     = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->runs        = $this->createMock(AutomationRunRecorderInterface::class);
		$groupManager      = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => isset(self::OBJECTS[$id]) ? $this->entity(payload: self::OBJECTS[$id]) : null);

		$compiler           = $this->createMock(AutomationCompilerService::class);
		$permissionResolver = new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class));
		$writeService       = new AutomationWriteService(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
			compiler: $compiler,
			permissionResolver: $permissionResolver,
			userSession: $this->userSession
		);

		$this->controller = new AutomationsController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			compiler: $compiler,
			conditionExecutor: $this->createMock(ConditionActionExecutor::class),
			caller: new CallerContext(userSession: $this->userSession, groupManager: $groupManager, permissionResolver: $permissionResolver),
			writeService: $writeService,
			runs: $this->runs
		);
	}//end setUp()

	/**
	 * An object entity serialising to this payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * Sign in a user.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * The request carries these query parameters.
	 *
	 * @param array<string, string> $params The parameters.
	 *
	 * @return void
	 */
	private function query(array $params): void {
		$this->request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
	}//end query()

	/**
	 * An editor reads the runs with the outcome filter and the page passed through.
	 *
	 * @return void
	 */
	public function testAnEditorReadsTheRuns(): void {
		$this->signIn(uid: 'bob');
		$this->query(params: ['outcome' => 'failed', 'since' => '2026-11-01', 'limit' => '500', 'offset' => '-3']);
		$page = ['results' => [['id' => 'r1']], 'total' => 1, 'summary' => ['lastRunAt' => 'x', 'lastOutcome' => 'failed', 'lastError' => 'boom']];
		$this->runs->expects($this->once())->method('runsOf')
			->with('aut-1', ['outcome' => 'failed', 'since' => '2026-11-01'], 100, 0)
			->willReturn($page);

		$response = $this->controller->runs(uuid: 'aut-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($page, $response->getData());
	}//end testAnEditorReadsTheRuns()

	/**
	 * Without a limit the page holds 25.
	 *
	 * @return void
	 */
	public function testTheDefaultPageIs25(): void {
		$this->signIn(uid: 'alice');
		$this->query(params: []);
		$this->runs->expects($this->once())->method('runsOf')->with('aut-1', [], 25, 0)->willReturn(['results' => [], 'total' => 0, 'summary' => []]);

		$this->assertSame(Http::STATUS_OK, $this->controller->runs(uuid: 'aut-1')->getStatus());
	}//end testTheDefaultPageIs25()

	/**
	 * A user without a role on the application gets the refusal status() gives, and no run is read.
	 *
	 * @return void
	 */
	public function testAUserWithoutARoleReadsNothing(): void {
		$this->signIn(uid: 'eve');
		$this->query(params: []);
		$this->runs->expects($this->never())->method('runsOf');

		$runs   = $this->controller->runs(uuid: 'aut-1');
		$status = $this->controller->status(uuid: 'aut-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $runs->getStatus());
		$this->assertSame($status->getStatus(), $runs->getStatus());
		$this->assertSame($status->getData(), $runs->getData());
	}//end testAUserWithoutARoleReadsNothing()

	/**
	 * The summary answers the automations the caller may read and leaves out the others and the unknown.
	 *
	 * @return void
	 */
	public function testTheSummaryLeavesOutWhatTheCallerMayNotRead(): void {
		$this->signIn(uid: 'bob');
		$this->query(params: ['uuids' => 'aut-1, aut-2,nope,aut-1']);
		$this->runs->expects($this->once())->method('summaryOf')->with('aut-1')
			->willReturn(['lastRunAt' => '2026-11-14T08:00:00Z', 'lastOutcome' => 'succeeded', 'lastError' => null]);

		$response = $this->controller->runsSummary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals(['summaries' => (object)['aut-1' => ['lastRunAt' => '2026-11-14T08:00:00Z', 'lastOutcome' => 'succeeded', 'lastError' => null]]], $response->getData());
	}//end testTheSummaryLeavesOutWhatTheCallerMayNotRead()

	/**
	 * Signed out, the summary is refused.
	 *
	 * @return void
	 */
	public function testTheSummaryNeedsASignedInUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->runs->expects($this->never())->method('summaryOf');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->runsSummary()->getStatus());
	}//end testTheSummaryNeedsASignedInUser()
}//end class
