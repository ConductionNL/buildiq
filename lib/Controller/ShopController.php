<?php

/**
 * OpenBuild ShopController
 *
 * HTTP surface for the GitHub shop source (github-shop-catalogue), kept distinct
 * from the OR-registry StoreController so each source's contract stays isolated.
 * Two endpoints, both `#[NoAdminRequired]` with an in-body 401 guard (browsing +
 * installing is any-authenticated-user, identical to the local + registry installs;
 * an instance-shared read has no per-object IDOR surface):
 *   - GET  /api/shop/github/search  — search `topic:openbuild-app` repos (cards).
 *   - POST /api/shop/github/install — fetch a repo, strictly parse it via
 *            github-app-repo-format's AppRepoParser, and clone it locally through
 *            the shared ApplicationsController::installFromTemplateArray seam.
 *
 * The install caller becomes the new app's owner. A repo that fails the strict
 * all-or-nothing parse yields a generic-but-actionable 4xx carrying the parser's
 * error code + offending file path (ADR-005-safe) with nothing created; the raw
 * GitHub body and any token are never surfaced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Exception\AppRepoParseException;
use OCA\OpenBuild\Service\AppRepoParser;
use OCA\OpenBuild\Service\GitHubCatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the GitHub shop search + install endpoints.
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */
class ShopController extends Controller {
	/**
	 * Kebab-case slug pattern (matches the Application slug pattern).
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Safe GitHub owner/repo pattern.
	 */
	private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Safe git-ref pattern.
	 */
	private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param LoggerInterface $logger PSR logger.
	 * @param IUserSession $userSession Current NC user session.
	 * @param GitHubCatalogService $catalogService Fixed-host GitHub source.
	 * @param AppRepoParser $repoParser Strict repo-file-map parser (change 1).
	 * @param ApplicationsController $appsController Shared clone/install seam.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly GitHubCatalogService $catalogService,
		private readonly AppRepoParser $repoParser,
		private readonly ApplicationsController $appsController,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Search GitHub for `topic:openbuild-app` repos.
	 *
	 * Login-required (in-body 401 guard). Returns the normalised cards plus a
	 * `brokerCredentialAvailable` / `rateLimited` hint; never exposes the raw
	 * GitHub body or any token.
	 *
	 * @return JSONResponse 200 with `{outcome, cards, brokerCredentialAvailable, rateLimited}`; 401 anonymous.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
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

		$credentialId = $this->credentialParam();

		try {
			$result = $this->catalogService->search(
				query: $query,
				actingUserId: $user->getUID(),
				credentialId: $credentialId
			);
		} catch (Throwable $e) {
			$this->logger->error('OpenBuild shop: GitHub search failed: ' . $e->getMessage());
			return new JSONResponse(
				data: [
					'outcome' => GitHubCatalogService::OUTCOME_UNREACHABLE,
					'cards' => [],
					'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
					'rateLimited' => false,
				],
				statusCode: Http::STATUS_OK
			);
		}

		return new JSONResponse(
			data: [
				'outcome' => $result['outcome'],
				'cards' => $result['cards'],
				'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
				'brokerUsed' => $result['brokerUsed'],
				'rateLimited' => $result['rateLimited'],
			],
			statusCode: Http::STATUS_OK
		);
	}//end githubSearch()

	/**
	 * Install a GitHub app: fetch → strictly parse → reuse the clone seam.
	 *
	 * Login-required (in-body 401 guard). Validates `owner`/`repo`/`ref` against
	 * safe patterns and `slug` against the kebab-case Application pattern, fetches
	 * the repo file map, parses it strictly (all-or-nothing), and hands the parsed
	 * template array (with the user-supplied name + slug) to the shared install
	 * seam. The caller becomes the app owner.
	 *
	 * @return JSONResponse 201 with the new Application; 400/401/404/422 on failure.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	#[NoAdminRequired]
	public function githubInstall(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		$owner = (string)($this->request->getParam('owner') ?? '');
		$repo = (string)($this->request->getParam('repo') ?? '');
		$refRaw = $this->request->getParam('ref');
		$ref = null;
		if (is_string($refRaw) === true && $refRaw !== '') {
			$ref = $refRaw;
		}

		$name = (string)($this->request->getParam('name') ?? '');
		$newSlug = (string)($this->request->getParam('slug') ?? '');

		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			return $this->error(code: 'invalid_repo', status: Http::STATUS_BAD_REQUEST);
		}

		if ($ref !== null && preg_match(self::REF_PATTERN, $ref) !== 1) {
			return $this->error(code: 'invalid_ref', status: Http::STATUS_BAD_REQUEST);
		}

		if ($name === '' || preg_match(self::SLUG_PATTERN, $newSlug) !== 1) {
			return $this->error(
				code: 'invalid_request',
				status: Http::STATUS_BAD_REQUEST,
				detail: 'name and kebab-case slug required'
			);
		}

		$files = $this->catalogService->fetchRepoFiles(
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $user->getUID(),
			credentialId: $this->credentialParam()
		);
		if ($files === []) {
			return $this->error(code: GitHubCatalogService::OUTCOME_UNREACHABLE, status: Http::STATUS_NOT_FOUND);
		}

		try {
			$template = $this->repoParser->parse(files: $files, repo: ['owner' => $owner, 'name' => $repo]);
		} catch (AppRepoParseException $e) {
			return new JSONResponse(data: $e->toArray(), statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$result = $this->appsController->installFromTemplateArray(
			template: $template,
			name: $name,
			newSlug: $newSlug,
			ownerUid: $user->getUID()
		);

		return new JSONResponse(data: $result['data'], statusCode: $result['status']);
	}//end githubInstall()

	/**
	 * Read the optional `credentialId` request param (broker upgrade).
	 *
	 * @return string|null The credential UUID, or null when absent.
	 */
	private function credentialParam(): ?string {
		$credentialId = $this->request->getParam('credentialId');
		if (is_string($credentialId) === true && $credentialId !== '') {
			return $credentialId;
		}

		return null;
	}//end credentialParam()

	/**
	 * Build a uniform error JSONResponse.
	 *
	 * @param string $code The error code.
	 * @param int $status The HTTP status code.
	 * @param string|null $detail Optional detail message.
	 *
	 * @return JSONResponse
	 */
	private function error(string $code, int $status, ?string $detail = null): JSONResponse {
		$body = ['error' => $code];
		if ($detail !== null) {
			$body['detail'] = $detail;
		}

		return new JSONResponse(data: $body, statusCode: $status);
	}//end error()
}//end class
