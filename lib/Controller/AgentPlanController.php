<?php

/**
 * Lets a person read, approve or reject an outside agent's builder plan.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Service\AgentPlanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Every action is `#[NoAdminRequired]`; AgentPlanService admits only a person
 * with an owners or editors role on the plan's app (an admin when the plan
 * creates its app). CSRF stays required, so the decision comes from Buildiq's
 * own pages.
 */
class AgentPlanController extends Controller {

	private const STATUS_BY_ERROR = [
		'not_found' => Http::STATUS_NOT_FOUND,
		'forbidden' => Http::STATUS_FORBIDDEN,
		'plan_decided' => Http::STATUS_CONFLICT,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest         $request     The request.
	 * @param IUserSession     $userSession The session.
	 * @param AgentPlanService $planService The plan store.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AgentPlanService $planService,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read one plan.
	 *
	 * @param string $id The plan uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return new JSONResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->respond(result: $this->planService->show(planId: $id, caller: $caller));
	}//end show()

	/**
	 * Approve a pending plan.
	 *
	 * @param string $id The plan uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		return $this->decide(id: $id, approve: true);
	}//end approve()

	/**
	 * Reject a pending plan.
	 *
	 * @param string $id The plan uuid.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		return $this->decide(id: $id, approve: false);
	}//end reject()

	/**
	 * Approve or reject as the session user.
	 *
	 * @param string $id      The plan uuid.
	 * @param bool   $approve Approve or reject.
	 *
	 * @return JSONResponse
	 */
	private function decide(string $id, bool $approve): JSONResponse {
		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return new JSONResponse(['error' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return $this->respond(result: $this->planService->decide(planId: $id, approve: $approve, caller: $caller));
	}//end decide()

	/**
	 * A service result as a response.
	 *
	 * @param array<string,mixed> $result The result.
	 *
	 * @return JSONResponse
	 */
	private function respond(array $result): JSONResponse {
		if (($result['isError'] ?? false) === true) {
			$status = (self::STATUS_BY_ERROR[(string)($result['error'] ?? '')] ?? Http::STATUS_BAD_REQUEST);
			return new JSONResponse(['error' => $result['error'], 'message' => $result['message']], $status);
		}

		return new JSONResponse($result);
	}//end respond()
}//end class
