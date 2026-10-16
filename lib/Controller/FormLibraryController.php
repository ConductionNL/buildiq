<?php

/**
 * Buildiq FormLibraryController
 *
 * HTTP surface of the form library's GitHub source
 * (reuse-gallery-categories-and-form-library, REQ-BQGL-005): one login-required
 * search over repositories with the topic `buildiq-form`. Installing a card is
 * done in the browser, which validates the card's export and creates the local
 * library form through OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\GitHubFormCatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the form library's GitHub search.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */
class FormLibraryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request     The current HTTP request.
	 * @param LoggerInterface          $logger      PSR logger.
	 * @param IUserSession             $userSession Current NC user session.
	 * @param GitHubFormCatalogService $formCatalog GitHub source of shared forms.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly GitHubFormCatalogService $formCatalog,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Search GitHub for shared forms (`topic:buildiq-form`, a form.json at the root).
	 *
	 * Login-required (in-body 401 guard). Each card carries the form export
	 * envelope its repository publishes; installing one is creating a local
	 * library form from that envelope, which the browser does through
	 * OpenRegister after validating it (src/services/formExport.js).
	 *
	 * @return JSONResponse 200 with `{outcome, cards, rateLimited}`; 401 anonymous.
	 *
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
	 */
	#[NoAdminRequired]
	public function githubSearch(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		$query = $this->request->getParam('q');
		if (is_string($query) === false) {
			$query = null;
		}

		try {
			$result = $this->formCatalog->searchForms(
				query: $query,
				actingUserId: $user->getUID(),
				credentialId: $this->credentialParam()
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq shop: GitHub form search failed: ' . $e->getMessage());
			return new JSONResponse(
				data: ['outcome' => GitHubCatalogService::OUTCOME_UNREACHABLE, 'cards' => [], 'rateLimited' => false],
				statusCode: Http::STATUS_OK
			);
		}

		return new JSONResponse(
			data: ['outcome' => $result['outcome'], 'cards' => $result['cards'], 'rateLimited' => $result['rateLimited']],
			statusCode: Http::STATUS_OK
		);
	}//end githubSearch()

	/**
	 * The advisory `credentialId` query parameter, or null.
	 *
	 * @return string|null
	 */
	private function credentialParam(): ?string {
		$credentialId = $this->request->getParam('credentialId');
		if (is_string($credentialId) === true && $credentialId !== '') {
			return $credentialId;
		}

		return null;
	}//end credentialParam()

	/**
	 * A JSON error body.
	 *
	 * @param string $code   The error code.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function error(string $code, int $status): JSONResponse {
		return new JSONResponse(data: ['error' => $code], statusCode: $status);
	}//end error()
}//end class
