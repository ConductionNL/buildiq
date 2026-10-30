<?php

/**
 * Unit tests for PageLayoutController.
 *
 * The point of these is the boundary, probed with the least privileged
 * principal that should be refused: a signed-in user who is not an
 * administrator. A layout decides what everyone using a case type sees, so
 * "buildiq is enabled for you" is not enough, and the refusal is asserted at
 * the middleware layer as well as in the body, because either one alone can be
 * removed without a single test noticing.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Controller\PageLayoutController;
use OCA\Buildiq\Service\PageLayoutAuthoringService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Covers the write boundary and the shape of each answer.
 */
class PageLayoutControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 * @param bool $isAdmin Whether that user is an administrator.
	 * @param PageLayoutAuthoringService|null $authoring The service double.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return PageLayoutController The controller.
	 */
	private function make(
		?string $uid,
		bool $isAdmin,
		?PageLayoutAuthoringService $authoring = null,
		array $params = []
	): PageLayoutController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		return new PageLayoutController(
			$request,
			($authoring ?? $this->authoringDouble()),
			$session,
			$groupManager,
			$this->createMock(LoggerInterface::class),
		);
	}//end make()

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
	 * A visitor with no account is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefused(): void {
		$response = $this->make(null, false)->save();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnonymousIsRefused()

	/**
	 * A signed-in non-administrator is refused: this is the principal the
	 * boundary exists for.
	 *
	 * @return void
	 */
	public function testASignedInNonAdminIsRefused(): void {
		$authoring = $this->authoringDouble();
		$authoring->expects($this->never())->method('save');

		$response = $this->make('ayse', false, $authoring, ['register' => 'dossiq'])->save();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testASignedInNonAdminIsRefused()

	/**
	 * The same boundary on the re-cut, which changes what a whole group sees.
	 *
	 * @return void
	 */
	public function testANonAdminCannotRecut(): void {
		$authoring = $this->authoringDouble();
		$authoring->expects($this->never())->method('recut');

		$response = $this->make('ayse', false, $authoring)->recut('pl-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testANonAdminCannotRecut()

	/**
	 * A refusal from the rules is answered with the sentence the rule wrote, so
	 * an administrator learns what to change rather than that "it failed".
	 *
	 * @return void
	 */
	public function testARuleRefusalKeepsItsSentence(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('save')->willThrowException(
			new InvalidArgumentException('There is no published layout for this schema to patch.')
		);

		$response = $this->make('beheerder', true, $authoring, ['register' => 'dossiq'])->save();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			'There is no published layout for this schema to patch.',
			$response->getData()['message']
		);
	}//end testARuleRefusalKeepsItsSentence()

	/**
	 * A re-cut of an id nobody stored is a 404.
	 *
	 * @return void
	 */
	public function testRecuttingAnUnknownLayoutIsANotFound(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('recut')->willThrowException(new RuntimeException('No layout with that id.'));

		$response = $this->make('beheerder', true, $authoring)->recut('pl-weg');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testRecuttingAnUnknownLayoutIsANotFound()

	/**
	 * A re-cut says what it dropped, so nothing is lost silently.
	 *
	 * @return void
	 */
	public function testARecutAnswersWithWhatItDropped(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('recut')->willReturn(['layout' => ['id' => 'pl-1'], 'dropped' => ['tabs.documenten']]);

		$response = $this->make('beheerder', true, $authoring)->recut('pl-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['tabs.documenten'], $response->getData()['dropped']);
	}//end testARecutAnswersWithWhatItDropped()

	/**
	 * A list without a register and a schema is a bad request, not every layout
	 * on the instance.
	 *
	 * @return void
	 */
	public function testAListWithoutAScopeIsRefused(): void {
		$response = $this->make('beheerder', true, null, [])->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAListWithoutAScopeIsRefused()

	/**
	 * The admin posture is declared to the middleware too, so the refusal does
	 * not depend on the body check alone.
	 *
	 * @return void
	 */
	public function testTheAdminPostureIsDeclaredToTheMiddleware(): void {
		foreach (['index', 'save', 'recut'] as $method) {
			$attributes = (new ReflectionMethod(PageLayoutController::class, $method))
				->getAttributes(AuthorizedAdminSetting::class);

			$this->assertCount(1, $attributes, $method . ' has to carry the admin attribute.');
		}
	}//end testTheAdminPostureIsDeclaredToTheMiddleware()
}//end class
