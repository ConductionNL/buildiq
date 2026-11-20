<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AgentPlanController: session user, status mapping, delegation.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AgentPlanController;
use OCA\Buildiq\Service\AgentPlanService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Buildiq\Controller\AgentPlanController
 */
class AgentPlanControllerTest extends TestCase {

	/**
	 * Anonymous callers get 401 and the service is never asked.
	 *
	 * @return void
	 */
	public function testAnonymousCallersAreRefused(): void {
		$plans = $this->createMock(AgentPlanService::class);
		$plans->expects(self::never())->method(self::anything());
		$controller = $this->controller(user: null, plans: $plans);

		foreach (['show', 'approve', 'reject'] as $action) {
			self::assertSame(Http::STATUS_UNAUTHORIZED, $controller->$action(id: 'p')->getStatus(), $action);
		}
	}//end testAnonymousCallersAreRefused()

	/**
	 * approve and reject pass the session user and the decision through.
	 *
	 * @return void
	 */
	public function testDecisionsActAsTheSessionUser(): void {
		$user = $this->createMock(IUser::class);
		$plans = $this->createMock(AgentPlanService::class);
		$plans->expects(self::exactly(2))->method('decide')->willReturnCallback(
			static function (string $planId, bool $approve, IUser $caller) use ($user): array {
				self::assertSame('plan-1', $planId);
				self::assertSame($user, $caller);
				return ['planId' => $planId, 'status' => $approve === true ? 'approved' : 'rejected'];
			}
		);
		$controller = $this->controller(user: $user, plans: $plans);

		self::assertSame(['planId' => 'plan-1', 'status' => 'approved'], $controller->approve(id: 'plan-1')->getData());
		self::assertSame(['planId' => 'plan-1', 'status' => 'rejected'], $controller->reject(id: 'plan-1')->getData());
	}//end testDecisionsActAsTheSessionUser()

	/**
	 * Service errors map to their HTTP status.
	 *
	 * @return void
	 */
	public function testErrorsMapToStatuses(): void {
		$cases = ['not_found' => Http::STATUS_NOT_FOUND, 'forbidden' => Http::STATUS_FORBIDDEN, 'plan_decided' => Http::STATUS_CONFLICT, 'plan_store_failed' => Http::STATUS_BAD_REQUEST];
		foreach ($cases as $error => $status) {
			$plans = $this->createMock(AgentPlanService::class);
			$plans->method('show')->willReturn(['isError' => true, 'error' => $error, 'message' => 'm']);
			$response = $this->controller(user: $this->createMock(IUser::class), plans: $plans)->show(id: 'p');
			self::assertSame($status, $response->getStatus(), $error);
			self::assertSame(['error' => $error, 'message' => 'm'], $response->getData(), $error);
		}
	}//end testErrorsMapToStatuses()

	/**
	 * The controller with a session user.
	 *
	 * @param IUser|null       $user  The session user.
	 * @param AgentPlanService $plans The plan store.
	 *
	 * @return AgentPlanController
	 */
	private function controller(?IUser $user, AgentPlanService $plans): AgentPlanController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new AgentPlanController(request: $this->createMock(IRequest::class), userSession: $session, planService: $plans);
	}//end controller()
}//end class
