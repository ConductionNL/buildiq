<?php

/**
 * Unit tests for AutomationsController::runs() and ::runsSummary() with OpenRegister's outcome records.
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
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-notifications-runs-come-from-openregisters-record
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AutomationsController;
use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\Buildiq\Service\AutomationRunHistory;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\AutomationWriteService;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ConditionActionExecutor;
use OCA\Buildiq\Service\OpenRegisterAutomationOutcomeReader;
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
 * The runs and the last run include what OpenRegister recorded for the automation's notifications; without a record the run log answers as before.
 */
final class AutomationsControllerRunHistoryTest extends TestCase {

	/**
	 * The automations and applications OpenRegister holds, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const OBJECTS = [
		'aut-1' => [
			'id' => 'aut-1',
			'applicationSlug' => 'pipelinq',
			'versionUuid' => 'v-1',
			'trigger' => ['type' => 'object-created', 'schema' => 'lead'],
			'actions' => [['type' => 'send-notification']],
		],
		'aut-2' => [
			'id' => 'aut-2',
			'applicationSlug' => 'pipelinq',
			'versionUuid' => 'v-1',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'send-notification']],
		],
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
	 * What OpenRegister recorded.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $outcomes = [];

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
		$this->request     = $this->createMock(originalClassName: IRequest::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->runs        = $this->createMock(originalClassName: AutomationRunRecorderInterface::class);
		$groupManager      = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntity {
				if (isset(self::OBJECTS[$id]) === false) {
					return null;
				}

				return $this->entity(payload: self::OBJECTS[$id]);
			}
		);

		$reader = $this->createMock(originalClassName: OpenRegisterAutomationOutcomeReader::class);
		$reader->method('outcomes')->willReturnCallback(fn (): array => $this->outcomes);

		$compiler           = $this->createMock(originalClassName: AutomationCompilerService::class);
		$permissionResolver = new PermissionResolver(groupManager: $groupManager, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$writeService       = new AutomationWriteService(
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $objects,
			compiler: $compiler,
			permissionResolver: $permissionResolver,
			userSession: $this->userSession
		);

		$this->controller = new AutomationsController(
			request: $this->request,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			compiler: $compiler,
			conditionExecutor: $this->createMock(originalClassName: ConditionActionExecutor::class),
			caller: new CallerContext(userSession: $this->userSession, groupManager: $groupManager, permissionResolver: $permissionResolver),
			writeService: $writeService,
			runs: new AutomationRunHistory(recorder: $this->runs, reader: $reader)
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
		$user = $this->createMock(originalClassName: IUser::class);
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
	 * A sent notification shows as a run beside the recorded ones, newest first.
	 *
	 * @return void
	 */
	public function testTheRunsIncludeOpenRegistersRecord(): void {
		$this->signIn(uid: 'bob');
		$this->query(params: []);
		$this->outcomes = [
			[
				'kind' => 'notification',
				'key' => 'aut-aut-1-1',
				'at' => '2026-11-24T09:30:00+00:00',
				'subject' => ['id' => 'lead-7'],
				'outcome' => 'sent',
				'reason' => null,
				'recipients' => 2,
			],
		];
		$this->runs->expects($this->once())->method('runsOf')
			->with('aut-1', [], AutomationRunHistory::RECORDED_LIMIT, 0)
			->willReturn(['results' => [['id' => 'r1', 'startedAt' => '2026-11-20T10:00:00+00:00', 'outcome' => 'succeeded']], 'total' => 1, 'summary' => []]);

		$response = $this->controller->runs(uuid: 'aut-1');
		$data     = $response->getData();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 2, actual: $data['total']);
		$this->assertSame(expected: 'openregister', actual: $data['results'][0]['source']);
		$this->assertSame(expected: ['schema' => 'lead', 'uuid' => 'lead-7'], actual: $data['results'][0]['subject']);
		$this->assertSame(expected: 'r1', actual: $data['results'][1]['id']);
		$this->assertSame(expected: '2026-11-24T09:30:00+00:00', actual: $data['summary']['lastRunAt']);
	}//end testTheRunsIncludeOpenRegistersRecord()

	/**
	 * Without an OpenRegister record the run log's own page is answered unchanged.
	 *
	 * @return void
	 */
	public function testWithoutARecordTheRunLogAnswersAsBefore(): void {
		$this->signIn(uid: 'bob');
		$this->query(params: ['outcome' => 'failed']);
		$page = ['results' => [['id' => 'r1']], 'total' => 1, 'summary' => ['lastRunAt' => 'x', 'lastOutcome' => 'failed', 'lastError' => 'boom']];
		$this->runs->expects($this->once())->method('runsOf')->with('aut-1', ['outcome' => 'failed'], 25, 0)->willReturn($page);

		$this->assertSame(expected: $page, actual: $this->controller->runs(uuid: 'aut-1')->getData());
	}//end testWithoutARecordTheRunLogAnswersAsBefore()

	/**
	 * The last run on the list counts OpenRegister's record; a manual automation keeps the run log's summary.
	 *
	 * @return void
	 */
	public function testTheSummaryCountsOpenRegistersRecord(): void {
		$this->signIn(uid: 'bob');
		$this->query(params: ['uuids' => 'aut-1,aut-2']);
		$this->outcomes = [
			[
				'kind' => 'notification',
				'key' => 'aut-aut-1-1',
				'at' => '2026-11-24T09:30:00+00:00',
				'subject' => ['id' => 'lead-7'],
				'outcome' => 'failed',
				'reason' => 'mail server down',
			],
		];
		$recorded = [['id' => 'r1', 'startedAt' => '2026-11-20T10:00:00+00:00', 'outcome' => 'succeeded']];
		$this->runs->method('runsOf')->willReturn(['results' => $recorded, 'total' => 1, 'summary' => []]);
		$manual = ['lastRunAt' => null, 'lastOutcome' => null, 'lastError' => null];
		$this->runs->expects($this->once())->method('summaryOf')->with('aut-2')->willReturn($manual);

		$summaries = (array)$this->controller->runsSummary()->getData()['summaries'];

		$this->assertSame(
			expected: ['lastRunAt' => '2026-11-24T09:30:00+00:00', 'lastOutcome' => 'failed', 'lastError' => 'mail server down'],
			actual: $summaries['aut-1']
		);
		$this->assertSame(expected: $manual, actual: $summaries['aut-2']);
	}//end testTheSummaryCountsOpenRegistersRecord()
}//end class
