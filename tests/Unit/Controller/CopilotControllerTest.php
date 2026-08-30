<?php

/**
 * Unit tests for CopilotController (spec ai-copilot).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\CopilotController;
use OCA\Buildiq\Exception\CopilotException;
use OCA\Buildiq\Service\CopilotService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for CopilotController.
 */
class CopilotControllerTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var CopilotService&MockObject
	 */
	private CopilotService&MockObject $copilotService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Controller under test.
	 */
	private CopilotController $controller;

	/**
	 * Set up shared mocks + SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->copilotService = $this->createMock(CopilotService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new CopilotController(
			request: $this->request,
			logger: $this->logger,
			copilotService: $this->copilotService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Wire an authenticated user into the session mock.
	 *
	 * @param string $uid UID.
	 *
	 * @return void
	 */
	private function wireUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end wireUser()

	// -------------------------------------------------------------------
	// Auth attributes present (route-auth / semantic-auth gates)
	// -------------------------------------------------------------------

	/**
	 * Every controller action carries a `#[NoAdminRequired]` attribute.
	 *
	 * @return void
	 */
	public function testEveryActionHasNoAdminRequiredAttribute(): void {
		$reflection = new ReflectionClass(CopilotController::class);
		foreach (['health', 'plan', 'execute', 'discard'] as $method) {
			$attributes = $reflection->getMethod($method)->getAttributes();
			$names = array_map(static fn ($a) => $a->getName(), $attributes);
			self::assertContains(
				'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired',
				$names,
				"Method {$method}() must carry #[NoAdminRequired]."
			);
		}
	}//end testEveryActionHasNoAdminRequiredAttribute()

	// -------------------------------------------------------------------
	// health()
	// -------------------------------------------------------------------

	/**
	 * health() returns 200 when the service reports available.
	 *
	 * @return void
	 */
	public function testHealthReturns200WhenAvailable(): void {
		$this->copilotService->method('health')->willReturn(['available' => true]);
		$response = $this->controller->health();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('ok', $response->getData()['status']);
	}//end testHealthReturns200WhenAvailable()

	/**
	 * health() returns 503 with the reason when the service reports unavailable.
	 *
	 * @return void
	 */
	public function testHealthReturns503WithReason(): void {
		$this->copilotService->method('health')->willReturn(['available' => false, 'reason' => 'no_provider']);
		$response = $this->controller->health();
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('no_provider', $response->getData()['reason']);
	}//end testHealthReturns503WithReason()

	// -------------------------------------------------------------------
	// plan()
	// -------------------------------------------------------------------

	/**
	 * plan() returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testPlanReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->plan();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testPlanReturns401WhenUnauthenticated()

	/**
	 * plan() maps each CopilotException http status/error code onto the response.
	 *
	 * @return void
	 */
	public function testPlanMapsExceptionStatuses(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['brief', '', 'A tool library'],
				['appSlug', null, null],
			]
		);

		$this->copilotService->method('plan')->willThrowException(
			new CopilotException(errorCode: 'plan_invalid', message: 'bad plan', httpStatus: 422)
		);

		$response = $this->controller->plan();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('plan_invalid', $response->getData()['error']);
	}//end testPlanMapsExceptionStatuses()

	/**
	 * plan() returns 503 when the copilot is unavailable.
	 *
	 * @return void
	 */
	public function testPlanReturns503WhenUnavailable(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['brief', '', 'A tool library'],
				['appSlug', null, null],
			]
		);
		$this->copilotService->method('plan')->willThrowException(
			new CopilotException(errorCode: 'no_provider', message: 'unavailable', httpStatus: 503)
		);

		$response = $this->controller->plan();
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testPlanReturns503WhenUnavailable()

	/**
	 * plan() returns 200 with the service's plan payload on success.
	 *
	 * @return void
	 */
	public function testPlanReturns200OnSuccess(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['brief', '', 'A tool library'],
				['appSlug', null, null],
			]
		);
		$this->copilotService->method('plan')->willReturn(['summary' => 'x', 'steps' => [], 'manifests' => []]);

		$response = $this->controller->plan();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('x', $response->getData()['summary']);
	}//end testPlanReturns200OnSuccess()

	/**
	 * plan() reads `agentId` from the request and passes it straight through to the service
	 * (agent-workspace REQ "Agents page provides CRUD and a per-agent chat panel").
	 *
	 * @return void
	 */
	public function testPlanPassesAgentIdThrough(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['brief', '', 'Add a page'],
				['appSlug', null, null],
				['agentId', null, 'agent-uuid-1'],
			]
		);
		$this->copilotService->expects(self::once())->method('plan')
			->with(brief: 'Add a page', appSlug: null, userId: 'alice', agentId: 'agent-uuid-1')
			->willReturn(['summary' => 'x', 'steps' => [], 'manifests' => []]);

		$response = $this->controller->plan();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPlanPassesAgentIdThrough()

	// -------------------------------------------------------------------
	// execute()
	// -------------------------------------------------------------------

	/**
	 * execute() returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testExecuteReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->execute();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testExecuteReturns401WhenUnauthenticated()

	/**
	 * execute() returns 403 when the service denies a viewer-role caller against an existing app.
	 *
	 * @return void
	 */
	public function testExecuteReturns403ForViewerRole(): void {
		$this->wireUser(uid: 'bob');
		$this->request->method('getParam')->willReturnMap(
			[
				['summary', '', 'x'],
				['steps', [], [['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'hello']]]],
			]
		);
		$this->copilotService->method('execute')->willThrowException(
			new CopilotException(errorCode: 'forbidden', message: 'no access', httpStatus: 403)
		);

		$response = $this->controller->execute();
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('forbidden', $response->getData()['error']);
	}//end testExecuteReturns403ForViewerRole()

	/**
	 * execute() succeeds for a createApp-only plan submitted by any authenticated
	 * user — the caller becomes owner of the created app (REQ-OBAIC-005); no
	 * existing-app RBAC is required, so the controller passes it straight through.
	 *
	 * @return void
	 */
	public function testExecuteSucceedsForCreateAppOnlyPlan(): void {
		$this->wireUser(uid: 'newcomer');
		$this->request->method('getParam')->willReturnMap(
			[
				['summary', '', 'x'],
				['steps', [], [['tool' => 'buildiq.createApp', 'arguments' => ['slug' => 'my-app', 'name' => 'My App']]]],
			]
		);
		$this->copilotService->method('execute')->willReturn(
			[
				'results' => [['success' => true, 'created' => true, 'app' => ['uuid' => 'u1', 'slug' => 'my-app']]],
			]
		);

		$response = $this->controller->execute();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $response->getData()['results']);
	}//end testExecuteSucceedsForCreateAppOnlyPlan()

	/**
	 * execute() maps a mid-plan failure to 422 with the step index forwarded.
	 *
	 * @return void
	 */
	public function testExecuteReturns422WithStepIndexOnFailure(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['summary', '', 'x'],
				['steps', [], [['tool' => 'buildiq.upsertPage', 'arguments' => []]]],
			]
		);
		$this->copilotService->method('execute')->willThrowException(
			new CopilotException(
				errorCode: 'execution_failed',
				message: 'boom',
				httpStatus: 422,
				stepIndex: 2,
				context: ['handler' => ['isError' => true]]
			)
		);

		$response = $this->controller->execute();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(2, $response->getData()['stepIndex']);
	}//end testExecuteReturns422WithStepIndexOnFailure()

	/**
	 * An unhandled Throwable maps to a 500 internal_error envelope.
	 *
	 * @return void
	 */
	public function testExecuteReturns500OnUnhandledException(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['summary', '', 'x'],
				['steps', [], []],
			]
		);
		$this->copilotService->method('execute')->willThrowException(new \RuntimeException('kaboom'));

		$response = $this->controller->execute();
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $response->getData()['error']);
	}//end testExecuteReturns500OnUnhandledException()

	/**
	 * execute() reads `agentId`/`prompt` from the request and passes them straight
	 * through to the service (agent-workspace REQ "Every agent run is transparently
	 * logged and reviewable").
	 *
	 * @return void
	 */
	public function testExecutePassesAgentIdAndPromptThrough(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['summary', '', 'x'],
				['steps', [], [['tool' => 'buildiq.upsertPage', 'arguments' => ['appSlug' => 'hello']]]],
				['agentId', null, 'agent-uuid-1'],
				['prompt', '', 'Add a page'],
			]
		);
		$this->copilotService->expects(self::once())->method('execute')
			->with(plan: self::anything(), userId: 'alice', agentId: 'agent-uuid-1', prompt: 'Add a page')
			->willReturn(['results' => []]);

		$response = $this->controller->execute();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testExecutePassesAgentIdAndPromptThrough()

	// -------------------------------------------------------------------
	// discard()
	// -------------------------------------------------------------------

	/**
	 * discard() returns 401 when unauthenticated.
	 *
	 * @return void
	 */
	public function testDiscardReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->discard();
		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testDiscardReturns401WhenUnauthenticated()

	/**
	 * discard() returns 422 when `agentId` is missing — it exists ONLY for the
	 * agent-scoped chat surface.
	 *
	 * @return void
	 */
	public function testDiscardReturns422WhenAgentIdMissing(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['agentId', '', ''],
			]
		);

		$response = $this->controller->discard();
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testDiscardReturns422WhenAgentIdMissing()

	/**
	 * discard() calls the service and returns 200 `{status: "logged"}` on success.
	 *
	 * @return void
	 */
	public function testDiscardReturns200OnSuccess(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['agentId', '', 'agent-uuid-1'],
				['prompt', '', 'Add a page'],
				['summary', '', 'x'],
				['steps', [], []],
			]
		);
		$this->copilotService->expects(self::once())->method('discard')
			->with(agentId: 'agent-uuid-1', userId: 'alice', prompt: 'Add a page', plan: ['summary' => 'x', 'steps' => []])
			->willReturn([]);

		$response = $this->controller->discard();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('logged', $response->getData()['status']);
	}//end testDiscardReturns200OnSuccess()

	/**
	 * discard() maps a CopilotException (e.g. unknown agent) onto the response.
	 *
	 * @return void
	 */
	public function testDiscardMapsExceptionStatuses(): void {
		$this->wireUser(uid: 'alice');
		$this->request->method('getParam')->willReturnMap(
			[
				['agentId', '', 'unknown-agent'],
				['prompt', '', 'x'],
				['summary', '', 'x'],
				['steps', [], []],
			]
		);
		$this->copilotService->method('discard')->willThrowException(
			new CopilotException(errorCode: 'not_found', message: 'no such agent', httpStatus: 404)
		);

		$response = $this->controller->discard();
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('not_found', $response->getData()['error']);
	}//end testDiscardMapsExceptionStatuses()
}//end class
