<?php

/**
 * Buildiq RegistrationFormController
 *
 * The authoring surface for the registration forms a case type carries. Two
 * routes:
 *   - GET /api/registration-forms?register=&schema= — the stored forms.
 *   - PUT /api/registration-forms — validate and store one form.
 *
 * ADMIN, NOT "BUILDIQ IS ENABLED FOR YOU"
 * ---------------------------------------
 * A registration form is what a citizen fills in, and a preset on it can carry
 * a value the citizen never sees. That is an administrative act, so the gate is
 * NC's admin middleware plus an `isAdmin()` check in the body, and a signed-in
 * non-admin is the principal the tests probe with.
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
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use InvalidArgumentException;
use OCA\Buildiq\Service\RegistrationFormAuthoringService;
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
 * HTTP surface for authoring registration forms.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
 */
class RegistrationFormController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request.
	 * @param RegistrationFormAuthoringService $authoring The save path.
	 * @param IUserSession $userSession The calling user.
	 * @param IGroupManager $groupManager Resolves admin membership.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly RegistrationFormAuthoringService $authoring,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: 'buildiq', request: $request);
	}//end __construct()

	/**
	 * The stored forms for one schema.
	 *
	 * @return JSONResponse 200 with `{items, total}`; 400 without a register and schema.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
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
			return $this->unexpected(what: 'listing registration forms', e: $e);
		}

		return new JSONResponse(['items' => $items, 'total' => count($items)], Http::STATUS_OK);
	}//end index()

	/**
	 * What the consuming schema declares: its property names and the channels
	 * it accepts, so the builder offers pickers instead of free text.
	 *
	 * @return JSONResponse 200 with `{properties, channels, note}`; 400 without a register and schema.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-007)
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function target(): JSONResponse {
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
			$target = $this->authoring->targetFor(
				$register,
				$schema,
				(string)$this->request->getParam('channelProperty', '')
			);
		} catch (Throwable $e) {
			return $this->unexpected(what: 'reading the target schema', e: $e);
		}

		return new JSONResponse($target, Http::STATUS_OK);
	}//end target()

	/**
	 * Store one form.
	 *
	 * @return JSONResponse 200 with `{form, warnings}`; 422 when a rule refuses it.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005)
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function save(): JSONResponse {
		$guard = $this->requireAdmin();
		if ($guard !== null) {
			return $guard;
		}

		$form = $this->body();
		if ($form === null) {
			return $this->error(code: 'invalid_form', status: Http::STATUS_UNPROCESSABLE_ENTITY, detail: 'The body has to be one form object.');
		}

		try {
			$result = $this->authoring->save($form);
		} catch (InvalidArgumentException $e) {
			return $this->error(code: 'refused', status: Http::STATUS_UNPROCESSABLE_ENTITY, detail: $e->getMessage());
		} catch (Throwable $e) {
			return $this->unexpected(what: 'saving a registration form', e: $e);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end save()

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
				detail: 'A registration form is what a citizen fills in, so authoring one takes an administrator.'
			);
		}

		return null;
	}//end requireAdmin()

	/**
	 * The request body as one form object.
	 *
	 * @return array<string, mixed>|null The form, or null when the body is not one object.
	 */
	private function body(): ?array {
		$params = $this->request->getParams();
		unset($params['_route']);

		if ($params === [] || array_is_list($params) === true) {
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
