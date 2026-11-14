<?php

/**
 * Buildiq AdminAuthoringGuard
 *
 * The admin gate the authoring controllers share. A page layout and a
 * registration form are both administrative acts, so both controllers refuse
 * an anonymous caller with a 401 and a signed-in non-admin with a 403 whose
 * sentence says what is being authored, and answer a list without a scope
 * with a 400.
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

/**
 * Refuses non-admin callers of an authoring controller.
 */
final class AdminAuthoringGuard {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The calling user.
	 * @param IGroupManager $groupManager Resolves admin membership.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Refuse anyone who is not a signed-in administrator.
	 *
	 * @param string $forbiddenDetail The sentence a signed-in non-admin is refused with.
	 *
	 * @return JSONResponse|null Null on allow, a refusal otherwise.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
	 */
	public function refuseNonAdmin(string $forbiddenDetail): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated', 'message' => 'Sign in first.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'forbidden', 'message' => $forbiddenDetail], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseNonAdmin()

	/**
	 * The register and schema a read is scoped to, once the caller is an administrator.
	 *
	 * @param IRequest $request The current request.
	 * @param string $forbiddenDetail The sentence a signed-in non-admin is refused with.
	 *
	 * @return AuthoringScope|JSONResponse The scope, or the refusal when the caller is not an
	 *                                     administrator or the request names no full scope.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
	 */
	public function adminScope(IRequest $request, string $forbiddenDetail): AuthoringScope|JSONResponse {
		$refusal = $this->refuseNonAdmin(forbiddenDetail: $forbiddenDetail);
		if ($refusal !== null) {
			return $refusal;
		}

		$scope = $this->scopeOf(request: $request);
		if ($scope === null) {
			return new JSONResponse(
				['error' => 'missing_scope', 'message' => 'Name the register and the schema.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $scope;
	}//end adminScope()

	/**
	 * The scope the request names.
	 *
	 * @param IRequest $request The current request.
	 *
	 * @return AuthoringScope|null The scope, or null when the register or the schema is missing.
	 */
	private function scopeOf(IRequest $request): ?AuthoringScope {
		$register = (string)$request->getParam('register', '');
		$schema = (string)$request->getParam('schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return new AuthoringScope(register: $register, schema: $schema);
	}//end scopeOf()
}//end class
