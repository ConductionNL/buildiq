<?php

/**
 * Unit tests for the unhandled-failure answer of ApplicationCreationController.
 *
 * The wizard's own refusals (validate / rollback) are specified in
 * ApplicationCreationControllerTest through WizardCreationException. What was
 * left unasserted is the last catch: anything the creation service throws that
 * is NOT a WizardCreationException still has to come back in the wizard's
 * answer shape, because the client renders `failedAtStep` and `rollbackStatus`
 * unconditionally — an answer missing them renders as a blank failure.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Buildiq\Controller\ApplicationCreationController
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationCreationController;
use OCA\Buildiq\Service\ApplicationCreationService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the generic Throwable arm of wizard().
 */
class ApplicationCreationControllerUnhandledTest extends TestCase {
	/**
	 * Build the controller as a signed-in administrator.
	 *
	 * @param ApplicationCreationService $creationService The service double.
	 * @param LoggerInterface|null $logger The logger double.
	 *
	 * @return ApplicationCreationController The controller.
	 */
	private function makeAdmin(
		ApplicationCreationService $creationService,
		?LoggerInterface $logger = null
	): ApplicationCreationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['name' => 'Test App', 'slug' => 'test-app']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin')->willReturn(true);

		return new ApplicationCreationController(
			request: $request,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			creationService: $creationService,
			userSession: $session,
			groupManager: $groupManager,
		);
	}//end makeAdmin()

	/**
	 * A failure the wizard does not model still comes back in the wizard's
	 * answer shape, at 500, with the step and rollback state reported as
	 * unknown rather than omitted.
	 *
	 * @return void
	 */
	public function testAnUnmodelledFailureKeepsTheWizardAnswerShape(): void {
		$creationService = $this->createMock(ApplicationCreationService::class);
		$creationService->method('createApplication')
			->willThrowException(new RuntimeException('register backend unreachable'));

		$response = $this->makeAdmin($creationService)->wizard();
		$body = $response->getData();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('wizard_rollback', $body['code']);
		self::assertSame('unknown', $body['failedAtStep']);
		self::assertSame('unknown', $body['rollbackStatus']);
		self::assertSame('register backend unreachable', $body['message']);
		self::assertArrayNotHasKey(
			'orphanedResources',
			$body,
			'Nothing is known to be orphaned on a failure the wizard did not model.'
		);
	}//end testAnUnmodelledFailureKeepsTheWizardAnswerShape()

	/**
	 * The unmodelled failure is logged with its exception attached, so the
	 * cause is recoverable from the log even though the answer is generic.
	 *
	 * @return void
	 */
	public function testAnUnmodelledFailureIsLoggedWithItsException(): void {
		$cause = new RuntimeException('register backend unreachable');

		$creationService = $this->createMock(ApplicationCreationService::class);
		$creationService->method('createApplication')->willThrowException($cause);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('error')
			->with(
				self::stringContains('register backend unreachable'),
				['exception' => $cause]
			);

		$this->makeAdmin($creationService, $logger)->wizard();
	}//end testAnUnmodelledFailureIsLoggedWithItsException()
}//end class
