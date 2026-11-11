<?php

/**
 * Buildiq ShopController
 *
 * HTTP surface for the GitHub shop source (github-shop-catalogue), kept distinct
 * from the OR-registry StoreController so each source's contract stays isolated.
 * Two endpoints, both `#[NoAdminRequired]` with an in-body 401 guard (browsing +
 * installing is any-authenticated-user, identical to the local + registry installs;
 * an instance-shared read has no per-object IDOR surface):
 *   - GET  /api/shop/github/search  — search `topic:buildiq-app` repos (cards).
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
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\GitHubCatalogService;
use OCA\Buildiq\Service\GitHubShopSearch;
use OCA\Buildiq\Service\RepoSource;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
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
	 * @param IUserSession $userSession Current NC user session.
	 * @param GitHubCatalogService $catalogService Fixed-host GitHub source.
	 * @param AppRepoParser $repoParser Strict repo-file-map parser (change 1).
	 * @param ApplicationsController $appsController Shared clone/install seam.
	 * @param GitHubShopSearch $shopSearch Shapes the GitHub search's answer and reports its outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly GitHubCatalogService $catalogService,
		private readonly AppRepoParser $repoParser,
		private readonly ApplicationsController $appsController,
		private readonly GitHubShopSearch $shopSearch,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Search GitHub for `topic:buildiq-app` repos.
	 *
	 * Login-required (in-body 401 guard). Returns the normalised cards plus a
	 * `brokerCredentialAvailable` / `rateLimited` hint; never exposes the raw
	 * GitHub body or any token.
	 *
	 * The outcome is also reported to integriq's connection registry, at most
	 * once an hour while it stays the same (adopt-connection-registry). The
	 * report never changes the response.
	 *
	 * @return JSONResponse 200 with `{outcome, cards, brokerCredentialAvailable, rateLimited}`; 401 anonymous.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-003-buildiq-reports-what-its-connection-calls-met
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
			return new JSONResponse(data: $this->shopSearch->failed(error: $e), statusCode: Http::STATUS_OK);
		}

		return new JSONResponse(data: $this->shopSearch->answered(result: $result), statusCode: Http::STATUS_OK);
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
	 *
	 * @no-admin-idor-exempt Addresses no buildiq-owned object: the slug identifies a
	 *   template in an EXTERNAL catalogue (the configured store registry / a GitHub
	 *   repo), so there is nothing of another tenant's to reach by guessing it. The
	 *   install path CREATES a new app owned by the calling user rather than reading
	 *   an existing one.
	 */
	#[NoAdminRequired]
	public function githubInstall(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		$source = $this->requestedSource();
		$refusal = ($this->repoRefusal(source: $source) ?? $this->refRefusal(source: $source) ?? $this->namingRefusal());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->installFromRepo(source: $source, user: $user);
	}//end githubInstall()

	/**
	 * The repository the install request names: owner, repo, an optional ref
	 * (an empty ref means the default branch) and the optional credential.
	 *
	 * @return RepoSource The requested repository, not yet validated.
	 */
	private function requestedSource(): RepoSource {
		$refRaw = $this->request->getParam('ref');
		$ref = null;
		if (is_string($refRaw) === true && $refRaw !== '') {
			$ref = $refRaw;
		}

		return new RepoSource(
			owner: (string)($this->request->getParam('owner') ?? ''),
			repo: (string)($this->request->getParam('repo') ?? ''),
			ref: $ref,
			credentialId: $this->credentialParam()
		);
	}//end requestedSource()

	/**
	 * Refuse an owner or repo that fails its safe pattern, so no caller-supplied
	 * string reaches the outbound GitHub URL.
	 *
	 * @param RepoSource $source The requested repository.
	 *
	 * @return JSONResponse|null The 400 refusal, or null when owner and repo are safe.
	 */
	private function repoRefusal(RepoSource $source): ?JSONResponse {
		if (preg_match(self::OWNER_REPO_PATTERN, $source->owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $source->repo) !== 1) {
			return $this->error(code: 'invalid_repo', status: Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end repoRefusal()

	/**
	 * Refuse a ref that fails its safe pattern; no ref means the default branch.
	 *
	 * @param RepoSource $source The requested repository.
	 *
	 * @return JSONResponse|null The 400 refusal, or null when the ref is absent or safe.
	 */
	private function refRefusal(RepoSource $source): ?JSONResponse {
		if ($source->ref !== null && preg_match(self::REF_PATTERN, $source->ref) !== 1) {
			return $this->error(code: 'invalid_ref', status: Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end refRefusal()

	/**
	 * Refuse a request without a name or with a slug that is not kebab-case.
	 *
	 * @return JSONResponse|null The 400 refusal, or null when name and slug are usable.
	 */
	private function namingRefusal(): ?JSONResponse {
		$name = (string)($this->request->getParam('name') ?? '');
		$newSlug = (string)($this->request->getParam('slug') ?? '');
		if ($name === '' || preg_match(self::SLUG_PATTERN, $newSlug) !== 1) {
			return $this->error(
				code: 'invalid_request',
				status: Http::STATUS_BAD_REQUEST,
				detail: 'name and kebab-case slug required'
			);
		}

		return null;
	}//end namingRefusal()

	/**
	 * Fetch and parse the repository, then clone it through the shared install
	 * seam with the caller as owner.
	 *
	 * @param RepoSource $source The validated repository.
	 * @param IUser $user The calling user, who becomes the app owner.
	 *
	 * @return JSONResponse The install seam's answer, or the 404/422 refusal.
	 */
	private function installFromRepo(RepoSource $source, IUser $user): JSONResponse {
		$template = $this->fetchTemplate(source: $source, actingUserId: $user->getUID());
		if ($template instanceof JSONResponse) {
			return $template;
		}

		$result = $this->appsController->installFromTemplateArray(
			template: $template,
			name: (string)($this->request->getParam('name') ?? ''),
			newSlug: (string)($this->request->getParam('slug') ?? ''),
			ownerUid: $user->getUID(),
			source: new RepoSource(owner: $source->owner, repo: $source->repo, ref: null, credentialId: $source->credentialId)
		);

		return new JSONResponse(data: $result['data'], statusCode: $result['status']);
	}//end installFromRepo()

	/**
	 * Fetch the repository's file map and parse it strictly (all-or-nothing).
	 *
	 * @param RepoSource $source The validated repository.
	 * @param string $actingUserId The user the fetch acts for.
	 *
	 * @return array<string,mixed>|JSONResponse The parsed template, or a 404 (unreachable) / 422 (parse failure).
	 */
	private function fetchTemplate(RepoSource $source, string $actingUserId): array|JSONResponse {
		$files = $this->catalogService->fetchRepoFiles(source: $source, actingUserId: $actingUserId);
		if ($files === []) {
			return $this->error(code: GitHubCatalogService::OUTCOME_UNREACHABLE, status: Http::STATUS_NOT_FOUND);
		}

		try {
			return $this->repoParser->parse(files: $files, repo: ['owner' => $source->owner, 'name' => $source->repo]);
		} catch (AppRepoParseException $e) {
			return new JSONResponse(data: $e->toArray(), statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}//end fetchTemplate()

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
