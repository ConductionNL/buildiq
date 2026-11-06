<?php

/**
 * Buildiq PageLayoutController
 *
 * The authoring surface for detail-page layouts and the screen overrides that
 * patch them. Three routes:
 *   - GET  /api/page-layouts?register=&schema= — the stored layouts for one
 *          schema, each saying whether it has drifted and which of its paths
 *          are orphaned, so the editor can offer a re-cut.
 *   - PUT  /api/page-layouts — validate and store one layout, stamping the
 *          base fingerprint server-side.
 *   - POST /api/page-layouts/{layoutId}/recut — re-pin a drifted override to
 *          the base it has now, dropping the parts that no longer apply and
 *          saying which those were.
 *
 * ADMIN, NOT "BUILDIQ IS ENABLED FOR YOU"
 * ---------------------------------------
 * A page layout decides what every user of a case type sees, and an override
 * decides it for a group of them. That is an administrative act, so the gate is
 * NC's admin middleware plus an `isAdmin()` check in the body, the same posture
 * the app-creation wizard uses for the same reason. A signed-in non-admin is
 * refused, which is the principal these routes are probed with.
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
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Service\PageLayoutAuthoringService;
use OCA\Buildiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * HTTP surface for authoring page layouts and screen overrides.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002, REQ-OBSO-003)
 */
class PageLayoutController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 * @param PageLayoutAuthoringService $authoring The save path.
	 * @param IUserSession $userSession The calling user.
	 * @param IGroupManager $groupManager Resolves admin membership.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PageLayoutAuthoringService $authoring,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: 'buildiq', request: $request);
	}//end __construct()

	/**
	 * The stored layouts for one schema, with their drift state.
	 *
	 * @return JSONResponse 200 with `{items, total}`; 400 without a register and schema.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function index(): JSONResponse {
		$guard = $this->requireAdmin();
		if ($guard !== null) {
			return $guard;
		}

		$register = (string)$this->request->getParam('register', '');
		$schema = (string)$this->request->getParam('schema', '');
		if ($register === '' || $schema === '') {
			return $this->error(code: 'missing_scope', status: Http::STATUS_BAD_REQUEST, detail: 'Name the register and the schema.');
		}

		try {
			$items = $this->authoring->listFor($register, $schema);
		} catch (Throwable $e) {
			return $this->unexpected(what: 'listing page layouts', e: $e);
		}

		return new JSONResponse(['items' => $items, 'total' => count($items)], Http::STATUS_OK);
	}//end index()

	/**
	 * Store one layout or override.
	 *
	 * @return JSONResponse 200 with `{layout, warnings}`; 422 when a rule refuses it.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-001)
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-002)
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function save(): JSONResponse {
		$guard = $this->requireAdmin();
		if ($guard !== null) {
			return $guard;
		}

		$layout = $this->body();
		if ($layout === null) {
			return $this->error(code: 'invalid_layout', status: Http::STATUS_UNPROCESSABLE_ENTITY, detail: 'The body has to be one layout object.');
		}

		try {
			$result = $this->authoring->save($layout, (string)$this->userSession->getUser()->getUID());
		} catch (InvalidArgumentException $e) {
			// A refusal is the point of this endpoint, so it is answered with
			// the sentence the rule wrote rather than a generic 422.
			return $this->error(code: 'refused', status: Http::STATUS_UNPROCESSABLE_ENTITY, detail: $e->getMessage());
		} catch (Throwable $e) {
			return $this->unexpected(what: 'saving a page layout', e: $e);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end save()

	/**
	 * Re-pin a drifted override to the base it has now.
	 *
	 * @param string $layoutId The override's id.
	 *
	 * @return JSONResponse 200 with `{layout, dropped}`; 404 when there is no such override.
	 *
	 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md (REQ-OBSO-003)
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function recut(string $layoutId): JSONResponse {
		$guard = $this->requireAdmin();
		if ($guard !== null) {
			return $guard;
		}

		try {
			$result = $this->authoring->recut($layoutId);
		} catch (RuntimeException $e) {
			return $this->error(code: 'not_found', status: Http::STATUS_NOT_FOUND, detail: $e->getMessage());
		} catch (InvalidArgumentException $e) {
			return $this->error(code: 'refused', status: Http::STATUS_UNPROCESSABLE_ENTITY, detail: $e->getMessage());
		} catch (Throwable $e) {
			return $this->unexpected(what: 're-cutting a screen override', e: $e);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end recut()

	/**
	 * Refuse anyone who is not a signed-in administrator.
	 *
	 * @return JSONResponse|null Null on allow, a refusal otherwise.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED, detail: 'Sign in first.');
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return $this->error(
				code: 'forbidden',
				status: Http::STATUS_FORBIDDEN,
				detail: 'Authoring a page layout changes what everyone using that case type sees, so it takes an administrator.'
			);
		}

		return null;
	}//end requireAdmin()

	/**
	 * The request body as one layout object.
	 *
	 * @return array<string, mixed>|null The layout, or null when the body is not one object.
	 */
	private function body(): ?array {
		$params = $this->request->getParams();
		unset($params['layoutId'], $params['_route']);

		if ($params !== [] && array_is_list($params) === true) {
			return null;
		}

		if ($params === []) {
			return null;
		}

		return $params;
	}//end body()

	/**
	 * One refusal.
	 *
	 * @param string $code The machine-readable code.
	 * @param int $status The HTTP status.
	 * @param string $detail What went wrong, in a sentence.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function error(string $code, int $status, string $detail): JSONResponse {
		return new JSONResponse(['error' => $code, 'message' => $detail], $status);
	}//end error()

	/**
	 * A failure that is not a refusal: logged with its cause, answered without
	 * it, because the cause names stored objects.
	 *
	 * @param string $what What was being attempted.
	 * @param Throwable $e The failure.
	 *
	 * @return JSONResponse The 500.
	 */
	private function unexpected(string $what, Throwable $e): JSONResponse {
		$this->logger->error('Buildiq: ' . $what . ' failed: ' . $e->getMessage(), ['exception' => $e]);

		return $this->error(code: 'internal_error', status: Http::STATUS_INTERNAL_SERVER_ERROR, detail: 'That did not work. The log says why.');
	}//end unexpected()
}//end class
