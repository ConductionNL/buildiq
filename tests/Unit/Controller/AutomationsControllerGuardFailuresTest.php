<?php

/**
 * Unit tests for the guard and dry-run failure arms of AutomationsController.
 *
 * `withAutomation()` is the shared gate every automation endpoint goes
 * through. Its happy path and its 403 are specified elsewhere; what had no
 * test is what happens when the automation names an application that is no
 * longer there, and when the load itself throws — the latter being the arm
 * that decides whether a broken object store reads as a 500 or silently as a
 * 403 "you may not". The dry-run's own refusals are covered here too.
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
 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#5.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Buildiq\Controller\AutomationsController
 *
 * @uses \OCA\Buildiq\Service\CallerContext
 * @uses \OCA\Buildiq\Service\PermissionResolver
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AutomationsController;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\AutomationWriteService;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ConditionActionExecutor;
use OCA\Buildiq\Service\PermissionResolver;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the not-found, internal-error and dry-run refusal arms.
 */
class AutomationsControllerGuardFailuresTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var AutomationCompilerService&MockObject
	 */
	private AutomationCompilerService&MockObject $compiler;

	/**
	 * @var ConditionActionExecutor&MockObject
	 */
	private ConditionActionExecutor&MockObject $conditionExecutor;

	/**
	 * @var AutomationWriteService&MockObject
	 */
	private AutomationWriteService&MockObject $writeService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Request params for this test.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The controller under test.
	 */
	private AutomationsController $controller;

	/**
	 * Wire the controller over mocked boundaries, signed in as the owner.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn (): array => $this->params);

		$this->compiler = $this->createMock(AutomationCompilerService::class);
		$this->conditionExecutor = $this->createMock(ConditionActionExecutor::class);
		$this->writeService = $this->createMock(AutomationWriteService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);

		$this->controller = new AutomationsController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			compiler: $this->compiler,
			conditionExecutor: $this->conditionExecutor,
			caller: new CallerContext(
				userSession: $this->userSession,
				groupManager: $groupManager,
				permissionResolver: new PermissionResolver(
					$groupManager,
					$this->createMock(LoggerInterface::class)
				)
			),
			writeService: $this->writeService
		);
	}//end setUp()

	/**
	 * An automation whose parent application is gone is a 404 naming the
	 * slug, not a 403 — the caller is not being refused, the app is missing.
	 *
	 * @return void
	 */
	public function testAnAutomationWhoseApplicationIsGoneIsANotFound(): void {
		$this->writeService->method('loadAutomation')->willReturn([
			'id' => 'auto-1',
			'applicationSlug' => 'permit-tracker',
		]);
		$this->writeService->method('loadApplication')->willReturn(null);

		$response = $this->controller->dryRun(uuid: 'auto-1');
		$body = $response->getData();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('not_found', $body['error']);
		self::assertStringContainsString('permit-tracker', $body['detail']);
	}//end testAnAutomationWhoseApplicationIsGoneIsANotFound()

	/**
	 * A load that throws is an internal error, never a quiet refusal: a
	 * broken object store must not read as "you may not".
	 *
	 * @return void
	 */
	public function testALoadFailureIsAnInternalErrorNotARefusal(): void {
		$this->writeService->method('loadAutomation')
			->willThrowException(new RuntimeException('object store down'));

		$response = $this->controller->dryRun(uuid: 'auto-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $response->getData()['error']);
	}//end testALoadFailureIsAnInternalErrorNotARefusal()

	/**
	 * dryRun: a payload that is not an object is refused before the rule is
	 * ever compiled.
	 *
	 * @return void
	 */
	public function testDryRunRefusesANonObjectPayload(): void {
		$this->grantOwner();
		$this->compiler->expects(self::never())->method('compileDryRunRule');
		$this->params = ['payload' => 'not-an-object'];

		$response = $this->controller->dryRun(uuid: 'auto-1');
		$body = $response->getData();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_payload', $body['error']);
		self::assertSame('payload must be an object', $body['detail']);
	}//end testDryRunRefusesANonObjectPayload()

	/**
	 * dryRun: an executor that throws is reported as a dry-run failure the
	 * author can act on, without the engine's own message.
	 *
	 * @return void
	 */
	public function testDryRunReportsAnExecutorFailureWithoutItsMessage(): void {
		$this->grantOwner();
		$this->compiler->method('compileDryRunRule')->willReturn(['id' => 'rule-1']);
		$this->conditionExecutor->method('execute')
			->willThrowException(new RuntimeException('expression engine segfault'));
		$this->params = ['payload' => ['field' => 'value']];

		$response = $this->controller->dryRun(uuid: 'auto-1');
		$body = $response->getData();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('dry_run_failed', $body['error']);
		self::assertSame('Dry-run evaluation failed', $body['detail']);
		self::assertStringNotContainsString('segfault', (string)json_encode($body));
	}//end testDryRunReportsAnExecutorFailureWithoutItsMessage()

	/**
	 * An automation pinned to no version is not on production, so the
	 * ordinary write roles apply rather than the stricter production set.
	 *
	 * @return void
	 */
	public function testAnAutomationWithNoVersionIsNotTreatedAsProduction(): void {
		$this->writeService->method('loadAutomation')->willReturn([
			'id' => 'auto-1',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => '',
		]);
		$this->writeService->method('loadApplication')->willReturn([
			'slug' => 'permit-tracker',
			'productionVersion' => ['id' => 'ver-prod'],
			'permissions' => ['editors' => ['user:alice']],
		]);
		$this->compiler->method('compileDryRunRule')->willReturn(['id' => 'rule-1']);
		$this->conditionExecutor->method('execute')
			->willReturn(['triggeredRules' => [], 'errors' => []]);
		$this->params = ['payload' => []];

		$response = $this->controller->dryRun(uuid: 'auto-1');

		self::assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'An editor may dry-run an automation that is not on the production version.'
		);
	}//end testAnAutomationWithNoVersionIsNotTreatedAsProduction()

	/**
	 * Grant the signed-in caller the write role on a loadable automation.
	 *
	 * @return void
	 */
	private function grantOwner(): void {
		$this->writeService->method('loadAutomation')->willReturn([
			'id' => 'auto-1',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'ver-1',
		]);
		$this->writeService->method('loadApplication')->willReturn([
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		]);
	}//end grantOwner()
}//end class
