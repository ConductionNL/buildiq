<?php

/**
 * Unit tests for the failure and body-shape answers of PageLayoutController.
 *
 * The admin boundary and the two happy refusals already have a spec
 * (PageLayoutControllerTest). What is left unasserted is what the endpoint
 * answers when the body is not one layout object, when the authoring service
 * throws something other than a rule refusal, and what a successful save or
 * re-cut actually returns — all of which a caller depends on.
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
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Buildiq\Controller\PageLayoutController
 *
 * @uses \OCA\Buildiq\Controller\AdminAuthoringGuard
 * @uses \OCA\Buildiq\Controller\UnexpectedFailureAnswer
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Controller\PageLayoutController;
use OCA\Buildiq\Service\PageLayoutAuthoringService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the refusal shapes and the success answers.
 */
class PageLayoutControllerFailuresTest extends TestCase {
	/**
	 * Build the controller as a signed-in administrator.
	 *
	 * @param PageLayoutAuthoringService|null $authoring The service double.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return PageLayoutController The controller.
	 */
	private function makeAdmin(
		?PageLayoutAuthoringService $authoring = null,
		array $params = []
	): PageLayoutController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		return new PageLayoutController(
			$request,
			($authoring ?? $this->authoringDouble()),
			$session,
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end makeAdmin()

	/**
	 * A service double that may not invent a method the real class lacks.
	 *
	 * @return PageLayoutAuthoringService The double.
	 */
	private function authoringDouble(): PageLayoutAuthoringService {
		return $this->getMockBuilder(PageLayoutAuthoringService::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'recut', 'listFor'])
			->getMock();
	}//end authoringDouble()

	/**
	 * An empty body is not one layout object, so the save is refused before
	 * the service is asked to store anything.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyIsRefusedAsAnInvalidLayout(): void {
		$authoring = $this->authoringDouble();
		$authoring->expects(self::never())->method('save');

		$response = $this->makeAdmin(authoring: $authoring, params: [])->save();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_layout', $response->getData()['error']);
	}//end testAnEmptyBodyIsRefusedAsAnInvalidLayout()

	/**
	 * A list-shaped body is a collection, not one layout, and is refused the
	 * same way — the route params the controller strips do not make it one.
	 *
	 * @return void
	 */
	public function testAListShapedBodyIsRefusedAsAnInvalidLayout(): void {
		$authoring = $this->authoringDouble();
		$authoring->expects(self::never())->method('save');

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['one', 'two'],
		)->save();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_layout', $response->getData()['error']);
	}//end testAListShapedBodyIsRefusedAsAnInvalidLayout()

	/**
	 * A stored layout is answered with what the service returned, at 200.
	 *
	 * @return void
	 */
	public function testAStoredLayoutIsAnsweredWithWhatTheServiceReturned(): void {
		$result = ['layout' => ['id' => 'l1'], 'warnings' => []];

		$authoring = $this->authoringDouble();
		$authoring->expects(self::once())
			->method('save')
			->with(['name' => 'Intake'], 'admin')
			->willReturn($result);

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['name' => 'Intake', 'layoutId' => 'ignored', '_route' => 'ignored'],
		)->save();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($result, $response->getData());
	}//end testAStoredLayoutIsAnsweredWithWhatTheServiceReturned()

	/**
	 * A failure that is not a rule refusal is answered as an internal error,
	 * and the cause is kept out of the body.
	 *
	 * @return void
	 */
	public function testAnUnexpectedSaveFailureIsAnInternalError(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('save')->willThrowException(new RuntimeException('disk on fire'));

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['name' => 'Intake'],
		)->save();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'disk on fire',
			(string)json_encode($response->getData()),
			'The cause belongs in the log, not in the answer.'
		);
	}//end testAnUnexpectedSaveFailureIsAnInternalError()

	/**
	 * A rule that refuses a re-cut keeps its own sentence, at 422.
	 *
	 * @return void
	 */
	public function testARefusedRecutKeepsItsSentence(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('recut')
			->willThrowException(new InvalidArgumentException('That override has no base.'));

		$response = $this->makeAdmin(authoring: $authoring)->recut(layoutId: 'o1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('refused', $response->getData()['error']);
		self::assertSame('That override has no base.', $response->getData()['message']);
	}//end testARefusedRecutKeepsItsSentence()

	/**
	 * An unexpected re-cut failure is an internal error, not a refusal.
	 *
	 * @return void
	 */
	public function testAnUnexpectedRecutFailureIsAnInternalError(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('recut')->willThrowException(new \LogicException('broken'));

		$response = $this->makeAdmin(authoring: $authoring)->recut(layoutId: 'o1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $response->getData()['error']);
	}//end testAnUnexpectedRecutFailureIsAnInternalError()

	/**
	 * A failure while listing is answered the same way, so a broken read does
	 * not leak its cause either.
	 *
	 * @return void
	 */
	public function testAnUnexpectedListFailureIsAnInternalError(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('listFor')->willThrowException(new RuntimeException('no index'));

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['register' => 'r1', 'schema' => 's1'],
		)->index();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $response->getData()['error']);
	}//end testAnUnexpectedListFailureIsAnInternalError()
}//end class
