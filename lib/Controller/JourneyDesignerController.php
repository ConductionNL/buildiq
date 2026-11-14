<?php

/**
 * Buildiq JourneyDesignerController
 *
 * The server half of the Journey Designer (journey-designer, ADR-085 §6):
 *   - POST /api/journeys/check — every finding on a journey's `writes[]`;
 *   - POST /api/journeys — save a journey, refused while a write is refused.
 *
 * ITS OWN AUTHORISATION
 * ---------------------
 * A journey's `writes[]` creates objects in whatever register a mapping
 * names, so authoring one is an administrative act. Editing an app in the
 * Page Designer is not enough: an app's owners and editors who are not
 * administrators are refused, and the refusal says it is about journeys.
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
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\Contracts\JourneyStoreInterface;
use OCA\Buildiq\Kernel\Contracts\JourneyWritesCheckInterface;
use OCA\Buildiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks a journey's writes for an administrator, and only for one.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised
 */
class JourneyDesignerController extends Controller {

	/**
	 * The refusal a signed-in non-administrator gets; it names journey authoring.
	 *
	 * @var string
	 */
	public const FORBIDDEN_DETAIL = 'Authoring a journey takes an administrator, because a journey can write into any register its mappings name. '
		. 'Access to the Page Designer is not enough.';

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request      The request.
	 * @param JourneyWritesCheckInterface $writes       Judges the writes through OpenRegister's validator.
	 * @param IUserSession       $userSession  The caller.
	 * @param IGroupManager      $groupManager Whether the caller is an administrator.
	 * @param LoggerInterface    $logger       Logs a failure that is not a refusal.
	 * @param JourneyStoreInterface $store     Stores a journey that passed every check.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised
	 */
	public function __construct(
		IRequest $request,
		private readonly JourneyWritesCheckInterface $writes,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly JourneyStoreInterface $store,
	) {
		parent::__construct(appName: 'buildiq', request: $request);
	}//end __construct()

	/**
	 * Every finding on the posted journey's writes.
	 *
	 * @return JSONResponse `{accepted, findings}`; 400 without a journey, 401 or 403 for a caller who may not author journeys.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function check(): JSONResponse {
		$guard   = new AdminAuthoringGuard(userSession: $this->userSession, groupManager: $this->groupManager);
		$refusal = $guard->refuseNonAdmin(forbiddenDetail: self::FORBIDDEN_DETAIL);
		if ($refusal !== null) {
			return $refusal;
		}

		$journey = $this->request->getParam('journey');
		if (is_array($journey) === false) {
			return new JSONResponse(
				['error' => 'invalid_journey', 'message' => 'The body has to carry the journey to check.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$findings = $this->writes->findings(journey: $journey);
		} catch (Throwable $e) {
			return (new UnexpectedFailureAnswer(logger: $this->logger))->answer(what: 'checking a journey', e: $e);
		}

		return new JSONResponse(['accepted' => ($findings === []), 'findings' => $findings], Http::STATUS_OK);
	}//end check()

	/**
	 * Save a journey. Its writes are judged first by the same check as
	 * `check()`, which is the validator the submit path uses; a journey with
	 * a refused write is not stored.
	 *
	 * @return JSONResponse `{id, journey}`; 400 without a journey, 401 or 403 for a caller who may not author journeys, 422 with the findings.
	 *
	 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function save(): JSONResponse {
		$guard   = new AdminAuthoringGuard(userSession: $this->userSession, groupManager: $this->groupManager);
		$refusal = $guard->refuseNonAdmin(forbiddenDetail: self::FORBIDDEN_DETAIL);
		if ($refusal !== null) {
			return $refusal;
		}

		$journey = $this->request->getParam('journey');
		if (is_array($journey) === false) {
			return new JSONResponse(
				['error' => 'invalid_journey', 'message' => 'The body has to carry the journey to save.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$findings = $this->writes->findings(journey: $journey);
			if ($findings !== []) {
				return new JSONResponse(
					['error' => 'journey_refused', 'message' => 'A write of the journey does not fit its destination.', 'findings' => $findings],
					Http::STATUS_UNPROCESSABLE_ENTITY
				);
			}

			$stored = $this->store->save(journey: $journey);
		} catch (Throwable $e) {
			return (new UnexpectedFailureAnswer(logger: $this->logger))->answer(what: 'saving a journey', e: $e);
		}

		return new JSONResponse(['id' => $stored['id'], 'journey' => $stored], Http::STATUS_OK);
	}//end save()
}//end class
