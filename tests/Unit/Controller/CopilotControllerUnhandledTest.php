<?php

/**
 * Unit tests for CopilotController's unhandled-failure arms.
 *
 * `plan()` and `discard()` each map a CopilotException to a typed envelope,
 * which CopilotControllerTest already specifies. Anything else the service
 * throws falls to a last catch whose job is to keep the provider's own
 * message — which may quote the brief back, or name an upstream endpoint —
 * out of the answer and in the log instead. That containment had no test.
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
 * @spec openspec/specs/ai-copilot/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Buildiq\Controller\CopilotController
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\CopilotController;
use OCA\Buildiq\Service\CopilotService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the generic Throwable arms of plan() and discard().
 */
class CopilotControllerUnhandledTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var CopilotService&MockObject
	 */
	private CopilotService&MockObject $copilotService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Request params for this test.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The controller under test.
	 */
	private CopilotController $controller;

	/**
	 * Wire the controller over mocked boundaries, signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		$this->copilotService = $this->createMock(CopilotService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new CopilotController(
			request: $this->request,
			logger: $this->logger,
			copilotService: $this->copilotService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * plan: a failure the copilot does not model answers a generic 500 and
	 * does NOT pass the provider's own message on to the caller.
	 *
	 * @return void
	 */
	public function testPlanContainsAnUnmodelledProviderFailure(): void {
		$this->params = ['brief' => 'Build me a permit tracker'];
		$this->copilotService->method('plan')->willThrowException(
			new RuntimeException('upstream llm.internal:8443 refused the token')
		);

		$response = $this->controller->plan();
		$body = $response->getData();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $body['error']);
		self::assertStringNotContainsString(
			'llm.internal',
			(string)json_encode($body),
			'The provider failure belongs in the log, not in the answer.'
		);
	}//end testPlanContainsAnUnmodelledProviderFailure()

	/**
	 * plan: that same failure is logged with its exception attached.
	 *
	 * @return void
	 */
	public function testPlanLogsTheUnmodelledFailureWithItsException(): void {
		$cause = new RuntimeException('provider exploded');
		$this->params = ['brief' => 'anything'];
		$this->copilotService->method('plan')->willThrowException($cause);

		$this->logger->expects(self::once())
			->method('error')
			->with(self::stringContains('provider exploded'), ['exception' => $cause]);

		$this->controller->plan();
	}//end testPlanLogsTheUnmodelledFailureWithItsException()

	/**
	 * discard: an unmodelled failure while writing the audit record answers a
	 * generic 500 rather than reporting the run as logged.
	 *
	 * @return void
	 */
	public function testDiscardDoesNotReportLoggedWhenTheWriteFailed(): void {
		$this->params = ['agentId' => 'agent-1', 'prompt' => 'p', 'summary' => 's', 'steps' => []];
		$this->copilotService->method('discard')->willThrowException(
			new RuntimeException('object store down')
		);

		$response = $this->controller->discard();
		$body = $response->getData();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $body['error']);
		self::assertArrayNotHasKey('status', $body);
	}//end testDiscardDoesNotReportLoggedWhenTheWriteFailed()

	/**
	 * discard: a `steps` value that is not an array is normalised to an empty
	 * list rather than handed to the service as-is.
	 *
	 * @return void
	 */
	public function testDiscardNormalisesANonArrayStepsPayload(): void {
		$this->params = [
			'agentId' => 'agent-1',
			'prompt' => 'p',
			'summary' => 'Did a thing',
			'steps' => 'not-an-array',
		];

		$this->copilotService->expects(self::once())
			->method('discard')
			->with(
				agentId: 'agent-1',
				userId: 'alice',
				prompt: 'p',
				plan: ['summary' => 'Did a thing', 'steps' => []]
			);

		$response = $this->controller->discard();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('logged', $response->getData()['status']);
	}//end testDiscardNormalisesANonArrayStepsPayload()

	/**
	 * plan: blank optional strings are sent as null, so the service sees
	 * "not given" rather than an empty slug it would try to resolve.
	 *
	 * @return void
	 */
	public function testPlanSendsBlankOptionalStringsAsNull(): void {
		$this->params = ['brief' => 'b', 'appSlug' => '', 'agentId' => null, 'versionSlug' => 'v1'];

		$this->copilotService->expects(self::once())
			->method('plan')
			->with(
				brief: 'b',
				appSlug: null,
				userId: 'alice',
				agentId: null,
				versionSlug: 'v1'
			)
			->willReturn(['summary' => 's', 'steps' => [], 'manifests' => []]);

		$response = $this->controller->plan();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testPlanSendsBlankOptionalStringsAsNull()
}//end class
