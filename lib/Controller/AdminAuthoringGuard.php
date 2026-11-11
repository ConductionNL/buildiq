<?php

/**
 * Buildiq AdminAuthoringGuard
 *
 * The admin gate and the failure answer the authoring controllers share. A
 * page layout and a registration form are both administrative acts, so both
 * controllers refuse an anonymous caller with a 401 and a signed-in
 * non-admin with a 403 whose sentence says what is being authored, answer a
 * list without a scope with a 400, and log a failure that is not a refusal
 * with its cause while answering it without, because the cause names stored
 * objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses non-admin callers and answers unexpected failures for an authoring controller.
 */
final class AdminAuthoringGuard {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The calling user.
	 * @param IGroupManager $groupManager Resolves admin membership.
	 * @param LoggerInterface $logger Logger.
	 * @param string $forbiddenDetail The sentence a signed-in non-admin is refused with.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly string $forbiddenDetail,
	) {
	}//end __construct()

	/**
	 * Refuse anyone who is not a signed-in administrator.
	 *
	 * @return JSONResponse|null Null on allow, a refusal otherwise.
	 */
	public function refuseNonAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated', 'message' => 'Sign in first.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'forbidden', 'message' => $this->forbiddenDetail], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseNonAdmin()

	/**
	 * The register and schema a read is scoped to, once the caller is an administrator.
	 *
	 * @param IRequest $request The current request.
	 *
	 * @return AuthoringScope|JSONResponse The scope, or the refusal when the caller is not an
	 *                                     administrator or the request names no full scope.
	 */
	public function adminScope(IRequest $request): AuthoringScope|JSONResponse {
		$refusal = $this->refuseNonAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$scope = AuthoringScope::fromRequest($request);
		if ($scope === null) {
			return new JSONResponse(
				['error' => 'missing_scope', 'message' => 'Name the register and the schema.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $scope;
	}//end adminScope()

	/**
	 * A failure that is not a refusal: logged with its cause, answered without it.
	 *
	 * @param string $what What was being attempted.
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse The 500.
	 */
	public function unexpected(string $what, Throwable $e): JSONResponse {
		$this->logger->error('Buildiq: ' . $what . ' failed: ' . $e->getMessage(), ['exception' => $e]);

		return new JSONResponse(
			['error' => 'internal_error', 'message' => 'That did not work. The log says why.'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unexpected()
}//end class
