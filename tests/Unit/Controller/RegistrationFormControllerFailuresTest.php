<?php

/**
 * Unit tests for the failure and body-shape answers of RegistrationFormController.
 *
 * The admin boundary is already specified (RegistrationFormControllerTest).
 * What is left unasserted is the destination refusal — the one refusal that
 * carries findings back so the designer can mark each on its field — and what
 * the endpoint answers when the body is not one form object or the service
 * throws something that is not a rule refusal.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Buildiq\Controller\RegistrationFormController
 *
 * @uses \OCA\Buildiq\Controller\AdminAuthoringGuard
 * @uses \OCA\Buildiq\Controller\UnexpectedFailureAnswer
 * @uses \OCA\Buildiq\Exception\FormDestinationRefusedException
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\RegistrationFormController;
use OCA\Buildiq\Exception\FormDestinationRefusedException;
use OCA\Buildiq\Service\RegistrationFormAuthoringService;
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
class RegistrationFormControllerFailuresTest extends TestCase {
	/**
	 * Build the controller as a signed-in administrator.
	 *
	 * @param RegistrationFormAuthoringService|null $authoring The service double.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return RegistrationFormController The controller.
	 */
	private function makeAdmin(
		?RegistrationFormAuthoringService $authoring = null,
		array $params = []
	): RegistrationFormController {
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

		return new RegistrationFormController(
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
	 * @return RegistrationFormAuthoringService The double.
	 */
	private function authoringDouble(): RegistrationFormAuthoringService {
		return $this->getMockBuilder(RegistrationFormAuthoringService::class)
			->disableOriginalConstructor()
			->onlyMethods(['save', 'listFor', 'targetFor'])
			->getMock();
	}//end authoringDouble()

	/**
	 * An empty body is not one form object, so nothing is stored.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyIsRefusedAsAnInvalidForm(): void {
		$authoring = $this->authoringDouble();
		$authoring->expects(self::never())->method('save');

		$response = $this->makeAdmin(authoring: $authoring, params: [])->save();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_form', $response->getData()['error']);
	}//end testAnEmptyBodyIsRefusedAsAnInvalidForm()

	/**
	 * A list-shaped body is a collection, not one form, and is refused too.
	 *
	 * @return void
	 */
	public function testAListShapedBodyIsRefusedAsAnInvalidForm(): void {
		$authoring = $this->authoringDouble();
		$authoring->expects(self::never())->method('save');

		$response = $this->makeAdmin(authoring: $authoring, params: ['a', 'b'])->save();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('invalid_form', $response->getData()['error']);
	}//end testAListShapedBodyIsRefusedAsAnInvalidForm()

	/**
	 * A stored form is answered with what the service returned, at 200, with
	 * the route marker stripped from what was handed to it.
	 *
	 * @return void
	 */
	public function testAStoredFormIsAnsweredWithWhatTheServiceReturned(): void {
		$result = ['form' => ['id' => 'f1'], 'warnings' => [], 'findings' => []];

		$authoring = $this->authoringDouble();
		$authoring->expects(self::once())
			->method('save')
			->with(['title' => 'Intake'])
			->willReturn($result);

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['title' => 'Intake', '_route' => 'ignored'],
		)->save();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($result, $response->getData());
	}//end testAStoredFormIsAnsweredWithWhatTheServiceReturned()

	/**
	 * A destination that refuses a non-draft form sends every finding back,
	 * so the designer can mark each one on its own field.
	 *
	 * @return void
	 */
	public function testADestinationRefusalCarriesEveryFindingBack(): void {
		$findings = [
			['property' => 'bsn', 'code' => 'missing', 'message' => 'The destination has no bsn.'],
			['property' => 'dob', 'code' => 'type', 'message' => 'The destination wants a date.'],
		];

		$authoring = $this->authoringDouble();
		$authoring->method('save')
			->willThrowException(new FormDestinationRefusedException($findings));

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['title' => 'Intake'],
		)->save();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame('destination_findings', $response->getData()['error']);
		self::assertSame($findings, $response->getData()['findings']);
		self::assertStringContainsString(
			'2 point(s)',
			$response->getData()['message'],
			'The refusal keeps the sentence the exception wrote.'
		);
	}//end testADestinationRefusalCarriesEveryFindingBack()

	/**
	 * An unexpected save failure is an internal error, and its cause stays in
	 * the log rather than in the answer.
	 *
	 * @return void
	 */
	public function testAnUnexpectedSaveFailureIsAnInternalError(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('save')->willThrowException(new RuntimeException('store down'));

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['title' => 'Intake'],
		)->save();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $response->getData()['error']);
		self::assertStringNotContainsStringIgnoringCase(
			'store down',
			(string)json_encode($response->getData())
		);
	}//end testAnUnexpectedSaveFailureIsAnInternalError()

	/**
	 * A failure while listing is answered the same way.
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

	/**
	 * A failure while reading the target schema is answered the same way.
	 *
	 * @return void
	 */
	public function testAnUnexpectedTargetFailureIsAnInternalError(): void {
		$authoring = $this->authoringDouble();
		$authoring->method('targetFor')->willThrowException(new RuntimeException('gone'));

		$response = $this->makeAdmin(
			authoring: $authoring,
			params: ['register' => 'r1', 'schema' => 's1'],
		)->target();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $response->getData()['error']);
	}//end testAnUnexpectedTargetFailureIsAnInternalError()
}//end class
