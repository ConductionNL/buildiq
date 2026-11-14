<?php

/**
 * Unit tests for JourneyDesignerController.
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
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\JourneyDesignerController;
use OCA\Buildiq\Kernel\Contracts\JourneyStoreInterface;
use OCA\Buildiq\Service\JourneyWritesCheck;
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
 * The journey check refuses everyone but an administrator, and says why.
 *
 * @covers \OCA\Buildiq\Controller\JourneyDesignerController
 * @uses   \OCA\Buildiq\Controller\AdminAuthoringGuard
 * @uses   \OCA\Buildiq\Controller\UnexpectedFailureAnswer
 */
final class JourneyDesignerControllerTest extends TestCase {

	/**
	 * The write check double.
	 *
	 * @var JourneyWritesCheck&MockObject
	 */
	private JourneyWritesCheck $writes;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The journey store double.
	 *
	 * @var JourneyStoreInterface&MockObject
	 */
	private JourneyStoreInterface $store;

	/**
	 * A Page Designer editor who is no administrator is refused, and the refusal names journey authoring.
	 *
	 * @return void
	 */
	public function testAPageDesignerUserWhoIsNoAdminIsRefused(): void {
		$controller = $this->controller(uid: 'editor', admin: false, journey: ['steps' => []]);
		$this->writes->expects($this->never())->method('findings');

		$response = $controller->check();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'forbidden', 'message' => JourneyDesignerController::FORBIDDEN_DETAIL], $response->getData());
		self::assertStringContainsString('Authoring a journey', $response->getData()['message']);
		self::assertStringContainsString('any register', $response->getData()['message']);
	}//end testAPageDesignerUserWhoIsNoAdminIsRefused()

	/**
	 * Nobody signed in is asked to sign in.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsAskedToSignIn(): void {
		$controller = $this->controller(uid: null, admin: false, journey: ['steps' => []]);
		$this->writes->expects($this->never())->method('findings');

		$response = $controller->check();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('unauthenticated', $response->getData()['error']);
	}//end testAnAnonymousCallerIsAskedToSignIn()

	/**
	 * An administrator gets every finding on the journey's writes.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsTheFindings(): void {
		$journey    = ['steps' => [['id' => 'applicant', 'writes' => []]]];
		$finding    = ['step' => 'applicant', 'write' => 'org', 'property' => 'naam', 'code' => 'property-unknown', 'message' => 'x'];
		$controller = $this->controller(uid: 'admin', admin: true, journey: $journey);
		$this->writes->expects($this->once())->method('findings')->with($journey)->willReturn([$finding]);

		$response = $controller->check();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['accepted' => false, 'findings' => [$finding]], $response->getData());
	}//end testAnAdministratorGetsTheFindings()

	/**
	 * A journey without findings is accepted.
	 *
	 * @return void
	 */
	public function testAJourneyWithoutFindingsIsAccepted(): void {
		$controller = $this->controller(uid: 'admin', admin: true, journey: ['steps' => []]);
		$this->writes->method('findings')->willReturn([]);

		self::assertSame(['accepted' => true, 'findings' => []], $controller->check()->getData());
	}//end testAJourneyWithoutFindingsIsAccepted()

	/**
	 * A body without a journey is a 400.
	 *
	 * @return void
	 */
	public function testABodyWithoutAJourneyIsRefused(): void {
		$controller = $this->controller(uid: 'admin', admin: true, journey: 'not a journey');
		$this->writes->expects($this->never())->method('findings');

		$response = $controller->check();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('invalid_journey', $response->getData()['error']);
	}//end testABodyWithoutAJourneyIsRefused()

	/**
	 * A failure that is not a refusal is logged and answered as a 500 without its cause.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotShown(): void {
		$controller = $this->controller(uid: 'admin', admin: true, journey: ['steps' => []]);
		$this->writes->method('findings')->willThrowException(new RuntimeException('register store down'));
		$this->logger->expects($this->once())->method('error')->with('Buildiq: checking a journey failed: register store down');

		$response = $controller->check();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('register store down', (string)json_encode($response->getData()));
	}//end testAFailureIsLoggedNotShown()

	/**
	 * A Page Designer editor who is no administrator cannot save a journey, and the refusal names journey authoring.
	 *
	 * @return void
	 */
	public function testSavingIsRefusedToAPageDesignerUserWhoIsNoAdmin(): void {
		$controller = $this->controller(uid: 'editor', admin: false, journey: ['title' => 'J', 'steps' => []]);
		$this->writes->expects($this->never())->method('findings');
		$this->store->expects($this->never())->method('save');

		$response = $controller->save();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'forbidden', 'message' => JourneyDesignerController::FORBIDDEN_DETAIL], $response->getData());
		self::assertStringContainsString('Authoring a journey', $response->getData()['message']);
	}//end testSavingIsRefusedToAPageDesignerUserWhoIsNoAdmin()

	/**
	 * Nobody signed in cannot save a journey.
	 *
	 * @return void
	 */
	public function testSavingAsksAnAnonymousCallerToSignIn(): void {
		$controller = $this->controller(uid: null, admin: false, journey: ['steps' => []]);
		$this->store->expects($this->never())->method('save');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->save()->getStatus());
	}//end testSavingAsksAnAnonymousCallerToSignIn()

	/**
	 * A journey with a refused write is not stored; the findings come back as a 422.
	 *
	 * @return void
	 */
	public function testAJourneyWithARefusedWriteIsNotStored(): void {
		$journey    = ['title' => 'J', 'steps' => [['id' => 'a', 'type' => 'form', 'formId' => 'f', 'writes' => [['as' => 'org']]]]];
		$finding    = ['step' => 'a', 'write' => 'org', 'property' => 'naam', 'code' => 'property-unknown', 'message' => 'x'];
		$controller = $this->controller(uid: 'admin', admin: true, journey: $journey);
		$this->writes->expects($this->once())->method('findings')->with($journey)->willReturn([$finding]);
		$this->store->expects($this->never())->method('save');

		$response = $controller->save();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('journey_refused', $response->getData()['error']);
		self::assertSame([$finding], $response->getData()['findings']);
	}//end testAJourneyWithARefusedWriteIsNotStored()

	/**
	 * Save and check judge the writes with the same check, so they refuse the same journey alike.
	 *
	 * @return void
	 */
	public function testSaveAndCheckRefuseTheSameWriteAlike(): void {
		$journey    = ['steps' => [['id' => 'a', 'writes' => [['as' => 'org']]]]];
		$finding    = ['step' => 'a', 'write' => 'org', 'property' => 'naam', 'code' => 'property-unknown', 'message' => 'x'];
		$controller = $this->controller(uid: 'admin', admin: true, journey: $journey);
		$this->writes->expects($this->exactly(2))->method('findings')->with($journey)->willReturn([$finding]);

		self::assertSame($controller->check()->getData()['findings'], $controller->save()->getData()['findings']);
	}//end testSaveAndCheckRefuseTheSameWriteAlike()

	/**
	 * An administrator's journey without findings is stored and its id returned.
	 *
	 * @return void
	 */
	public function testAnAdministratorSavesAJourney(): void {
		$journey    = ['title' => 'J', 'steps' => [['id' => 'r', 'type' => 'review']]];
		$stored     = ['title' => 'J', 'description' => '', 'steps' => [['id' => 'r', 'type' => 'review']], 'id' => 'uuid-1'];
		$controller = $this->controller(uid: 'admin', admin: true, journey: $journey);
		$this->writes->method('findings')->willReturn([]);
		$this->store->expects($this->once())->method('save')->with($journey)->willReturn($stored);

		$response = $controller->save();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['id' => 'uuid-1', 'journey' => $stored], $response->getData());
	}//end testAnAdministratorSavesAJourney()

	/**
	 * A save without a journey is a 400, and a failing store is logged and answered as a 500 without its cause.
	 *
	 * @return void
	 */
	public function testSavingRefusesAMissingJourneyAndHidesAFailure(): void {
		$controller = $this->controller(uid: 'admin', admin: true, journey: null);
		self::assertSame(Http::STATUS_BAD_REQUEST, $controller->save()->getStatus());

		$controller = $this->controller(uid: 'admin', admin: true, journey: ['steps' => []]);
		$this->writes->method('findings')->willReturn([]);
		$this->store->method('save')->willThrowException(new RuntimeException('register store down'));
		$this->logger->expects($this->once())->method('error')->with('Buildiq: saving a journey failed: register store down');

		$response = $controller->save();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('register store down', (string)json_encode($response->getData()));
	}//end testSavingRefusesAMissingJourneyAndHidesAFailure()

	/**
	 * The controller for this caller and body.
	 *
	 * @param string|null $uid     The caller, or null when nobody is signed in.
	 * @param bool        $admin   Whether the caller is an administrator.
	 * @param mixed       $journey The `journey` parameter.
	 *
	 * @return JourneyDesignerController The controller.
	 */
	private function controller(?string $uid, bool $admin, mixed $journey): JourneyDesignerController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => ($key === 'journey' ? $journey : null));

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $id): bool => ($admin === true && $id === $uid));

		$this->writes = $this->createMock(JourneyWritesCheck::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->store  = $this->createMock(JourneyStoreInterface::class);

		return new JourneyDesignerController(
			request: $request,
			writes: $this->writes,
			userSession: $session,
			groupManager: $groups,
			logger: $this->logger,
			store: $this->store,
		);
	}//end controller()
}//end class
