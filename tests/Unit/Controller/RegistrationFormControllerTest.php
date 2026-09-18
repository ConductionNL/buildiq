<?php

/**
 * Unit tests for RegistrationFormController.
 *
 * Probed with the least privileged principal that should be refused: a
 * signed-in user who is not an administrator. A preset on a registration form
 * can carry a value the citizen filling it in never sees, so the boundary is
 * asserted at the middleware layer as well as in the body.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Controller\RegistrationFormController;
use OCA\Buildiq\Service\RegistrationFormAuthoringService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Covers the write boundary and the shape of each answer.
 */
class RegistrationFormControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 * @param bool $isAdmin Whether that user is an administrator.
	 * @param RegistrationFormAuthoringService|null $authoring The service double.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return RegistrationFormController The controller.
	 */
	private function make(
		?string $uid,
		bool $isAdmin,
		?RegistrationFormAuthoringService $authoring = null,
		array $params = []
	): RegistrationFormController {
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

		return new RegistrationFormController(
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
	 * @return RegistrationFormAuthoringService The double.
	 */
	private function authoringDouble(): RegistrationFormAuthoringService {
		return $this->getMockBuilder(RegistrationFormAuthoringService::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'listFor'])
			->getMock();
	}//end authoringDouble()

	/**
	 * A visitor with no account is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnonymousIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->make(null, false)->save()->getStatus());
	}//end testAnonymousIsRefused()

	/**
	 * A signed-in non-administrator is refused, and the save is never reached.
	 *
	 * @return void
	 */
	public function testASignedInNonAdminIsRefused(): void {
		$authoring = $this->authoringDouble();
		$authoring->expects($this->never())->method('save');

		$response = $this->make('ayse', false, $authoring, ['name' => 'Aanvraag'])->save();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testASignedInNonAdminIsRefused()

	/**
	 * A refusal from the rules keeps the sentence the rule wrote.
	 *
	 * @return void
	 */
	public function testARuleRefusalKeepsItsSentence(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('save')->willThrowException(
			new InvalidArgumentException('A form with that name already stands on this type.')
		);

		$response = $this->make('beheerder', true, $authoring, ['name' => 'Aanvraag'])->save();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			'A form with that name already stands on this type.',
			$response->getData()['message']
		);
	}//end testARuleRefusalKeepsItsSentence()

	/**
	 * A list without a register and a schema is a bad request, not every form on
	 * the instance.
	 *
	 * @return void
	 */
	public function testAListWithoutAScopeIsRefused(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->make('beheerder', true, null, [])->index()->getStatus());
	}//end testAListWithoutAScopeIsRefused()

	/**
	 * The admin posture is declared to the middleware too.
	 *
	 * @return void
	 */
	public function testTheAdminPostureIsDeclaredToTheMiddleware(): void {
		foreach (['index', 'save'] as $method) {
			$attributes = (new ReflectionMethod(RegistrationFormController::class, $method))
				->getAttributes(AuthorizedAdminSetting::class);

			$this->assertCount(1, $attributes, $method . ' has to carry the admin attribute.');
		}
	}//end testTheAdminPostureIsDeclaredToTheMiddleware()
}//end class
