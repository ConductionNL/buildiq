<?php

/**
 * Characterisation tests for the answers the two admin authoring controllers give.
 *
 * PageLayoutController and RegistrationFormController refuse in the same way:
 * an anonymous caller gets a 401, a signed-in non-admin a 403 with a sentence
 * that names what is being authored, a list without a scope a 400, and a
 * failure that is not a refusal a 500 whose cause goes to the log and not to
 * the caller. These tests pin every answer body and the log line exactly, so
 * the shared guard both controllers use cannot drift from what they answered
 * before it was shared.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\PageLayoutController;
use OCA\Buildiq\Controller\RegistrationFormController;
use OCA\Buildiq\Service\PageLayoutAuthoringService;
use OCA\Buildiq\Service\RegistrationFormAuthoringService;
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
 * Pins the refusal and failure bodies of both authoring controllers.
 */
class AdminAuthoringAnswersTest extends TestCase {

	/**
	 * The logger double of the controller built last.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The group-manager double of the controller built last.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Build the request, session and group doubles for a caller.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return array{0: IRequest, 1: IUserSession} The request and the session.
	 */
	private function caller(?string $uid, array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $id): bool => $id === 'beheerder');
		$this->logger = $this->createMock(LoggerInterface::class);

		return [$request, $session];
	}//end caller()

	/**
	 * A page-layout controller for a caller.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return array{0: PageLayoutController, 1: PageLayoutAuthoringService&MockObject} The controller and its service.
	 */
	private function pageLayouts(?string $uid, array $params = []): array {
		[$request, $session] = $this->caller(uid: $uid, params: $params);
		$authoring = $this->getMockBuilder(PageLayoutAuthoringService::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'recut', 'listFor'])
			->getMock();

		return [new PageLayoutController($request, $authoring, $session, $this->groupManager, $this->logger), $authoring];
	}//end pageLayouts()

	/**
	 * A registration-form controller for a caller.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return array{0: RegistrationFormController, 1: RegistrationFormAuthoringService&MockObject} The controller and its service.
	 */
	private function registrationForms(?string $uid, array $params = []): array {
		[$request, $session] = $this->caller(uid: $uid, params: $params);
		$authoring = $this->getMockBuilder(RegistrationFormAuthoringService::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'listFor', 'targetFor'])
			->getMock();

		return [new RegistrationFormController($request, $authoring, $session, $this->groupManager, $this->logger), $authoring];
	}//end registrationForms()

	/**
	 * An anonymous caller gets the same 401 body from every action.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerGetsTheSignInRefusal(): void {
		$expected = ['error' => 'unauthenticated', 'message' => 'Sign in first.'];
		foreach (['index', 'save', 'recut'] as $action) {
			[$controller] = $this->pageLayouts(uid: null, params: ['register' => 'r', 'schema' => 's']);
			$response = match ($action) {
				'recut' => $controller->recut('layout-1'),
				default => $controller->$action(),
			};
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $action);
			$this->assertSame($expected, $response->getData(), $action);
		}

		foreach (['index', 'target', 'save'] as $action) {
			[$controller] = $this->registrationForms(uid: null, params: ['register' => 'r', 'schema' => 's']);
			$response = $controller->$action();
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $action);
			$this->assertSame($expected, $response->getData(), $action);
		}
	}//end testAnAnonymousCallerGetsTheSignInRefusal()

	/**
	 * A signed-in non-admin gets the 403 sentence of the controller called, and the service is never reached.
	 *
	 * @return void
	 */
	public function testASignedInNonAdminGetsTheControllersOwnSentence(): void {
		[$layouts, $layoutService] = $this->pageLayouts(uid: 'medewerker', params: ['register' => 'r', 'schema' => 's']);
		$layoutService->expects($this->never())->method('listFor');
		$response = $layouts->index();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			[
				'error'   => 'forbidden',
				'message' => 'Authoring a page layout changes what everyone using that case type sees, so it takes an administrator.',
			],
			$response->getData()
		);

		[$forms, $formService] = $this->registrationForms(uid: 'medewerker', params: ['register' => 'r', 'schema' => 's']);
		$formService->expects($this->never())->method('targetFor');
		$response = $forms->target();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			[
				'error'   => 'forbidden',
				'message' => 'A registration form is what a citizen fills in, so authoring one takes an administrator.',
			],
			$response->getData()
		);
	}//end testASignedInNonAdminGetsTheControllersOwnSentence()

	/**
	 * An administrator is let through to the service.
	 *
	 * @return void
	 */
	public function testAnAdministratorReachesTheService(): void {
		[$layouts, $layoutService] = $this->pageLayouts(uid: 'beheerder', params: ['register' => 'r', 'schema' => 's']);
		$layoutService->expects($this->once())->method('listFor')->with('r', 's')->willReturn([['id' => 'a']]);
		$response = $layouts->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['items' => [['id' => 'a']], 'total' => 1], $response->getData());

		[$forms, $formService] = $this->registrationForms(uid: 'beheerder', params: ['register' => 'r', 'schema' => 's']);
		$formService->expects($this->once())->method('listFor')->with('r', 's')->willReturn([]);
		$response = $forms->index();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['items' => [], 'total' => 0], $response->getData());
	}//end testAnAdministratorReachesTheService()

	/**
	 * A list or target read with half a scope is answered with the same 400 body.
	 *
	 * @return void
	 */
	public function testAHalfScopeIsAMissingScope(): void {
		$expected = ['error' => 'missing_scope', 'message' => 'Name the register and the schema.'];

		[$layouts] = $this->pageLayouts(uid: 'beheerder', params: ['register' => 'r']);
		$this->assertSame($expected, $layouts->index()->getData());

		[$forms] = $this->registrationForms(uid: 'beheerder', params: ['schema' => 's']);
		$this->assertSame($expected, $forms->index()->getData());

		[$forms] = $this->registrationForms(uid: 'beheerder', params: ['register' => 'r']);
		$response = $forms->target();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame($expected, $response->getData());
	}//end testAHalfScopeIsAMissingScope()

	/**
	 * An unexpected failure is logged with its cause and answered without it.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsLoggedAndAnsweredWithoutItsCause(): void {
		$expected = ['error' => 'internal_error', 'message' => 'That did not work. The log says why.'];
		$cases = [
			'listing page layouts'        => function (RuntimeException $boom) {
				[$controller, $service] = $this->pageLayouts(uid: 'beheerder', params: ['register' => 'r', 'schema' => 's']);
				$service->method('listFor')->willThrowException($boom);
				return [$controller, 'index'];
			},
			'saving a registration form'  => function (RuntimeException $boom) {
				[$controller, $service] = $this->registrationForms(uid: 'beheerder', params: ['id' => 'f']);
				$service->method('save')->willThrowException($boom);
				return [$controller, 'save'];
			},
			'reading the target schema'   => function (RuntimeException $boom) {
				[$controller, $service] = $this->registrationForms(uid: 'beheerder', params: ['register' => 'r', 'schema' => 's']);
				$service->method('targetFor')->willThrowException($boom);
				return [$controller, 'target'];
			},
		];

		foreach ($cases as $what => $build) {
			$boom = new RuntimeException('object 42 is gone');
			[$controller, $action] = $build($boom);
			$this->logger->expects($this->once())->method('error')->with(
				'Buildiq: ' . $what . ' failed: object 42 is gone',
				['exception' => $boom]
			);
			$response = $controller->$action();
			$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus(), $what);
			$this->assertSame($expected, $response->getData(), $what);
		}
	}//end testAnUnexpectedFailureIsLoggedAndAnsweredWithoutItsCause()
}//end class
