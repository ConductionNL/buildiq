<?php

/**
 * OpenBuild StoreController
 *
 * HTTP surface for the remote template "store" (openbuild-remote-template-
 * store):
 *   - GET  /api/store/templates            — search remote templates (cards).
 *   - POST /api/store/templates/{slug}/install — resolve the remote template by
 *            slug and install it LOCALLY by cloning through the shared
 *            ApplicationsController::installFromTemplateArray seam (so an
 *            installed store app is a normal local Application + per-app
 *            register, identical to a local template clone).
 *
 * ADR-080: DISCOVERY (configure / search / resolve) is OpenRegister's. This
 * controller INJECTS AppHost's GenericStoreService — which owns the
 * SSRF-guarded, redirect-refusing, token-private fetch that used to live in
 * this app's own RemoteTemplateStoreService. That service is deleted; its
 * behaviour and its SSRF negative controls moved to OpenRegister's
 * GenericStoreServiceTest. The old implementation reached OpenRegister's SSRF
 * guard through a dynamic class-string with a weaker local fallback — in the
 * engine the guard is simply always there.
 *
 * Composition, NOT inheritance, and deliberately so. A cross-app `extends` is
 * resolved by the AUTOLOADER rather than the container, which breaks in three
 * separate places: Nextcloud's router reflects every controller during route
 * MATCHING (an absent OpenRegister would 500 EVERY route in this app, not just
 * the store), the unit suite cannot load the class at all because OR is stubbed
 * rather than autoloaded, and phpstan/psalm reject "extends unknown class". An
 * injected type-hint has none of those problems — it is the same shape as the
 * OCA\OpenRegister\Service\ObjectService dependency 8 other controllers here
 * already carry, resolved for static analysis by one stub entry.
 *
 * INSTALL stays here, and only install: cloning an application template into a
 * local virtual app is OpenBuild-specific (companion namespacing, manifest
 * rewrite, per-app register, owner-tagged persist) and has different
 * authorization from the connector-adapter and agent-template installs in other
 * apps. That is the ADR-080 Decision 3 seam.
 *
 * Both endpoints carry #[NoAdminRequired] and an in-body authentication guard
 * (any authenticated OpenBuild user may search + install; the install caller
 * becomes the new app's owner — mirrors the local createFromTemplate posture).
 * No publishing in this cut.
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
 * @spec openspec/specs/openbuild-remote-template-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Store search + OpenBuild's own template install.
 *
 * @spec openspec/specs/openbuild-remote-template-store/spec.md
 */
class StoreController extends Controller {
	/**
	 * Kebab-case slug pattern shared by store item slugs.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param LoggerInterface $logger PSR logger.
	 * @param IUserSession $userSession Current NC user session.
	 * @param GenericStoreService $storeService Engine-owned store client.
	 * @param ApplicationsController $appsController Shared clone/install seam.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly GenericStoreService $storeService,
		private readonly ApplicationsController $appsController,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * OpenBuild's store parameters: `application-template` objects in the
	 * `openbuild` register of the configured remote catalogue.
	 *
	 * @return StoreDescriptor
	 */
	private function descriptor(): StoreDescriptor {
		return new StoreDescriptor(
			appId: Application::APP_ID,
			schema: 'application-template',
			defaultRegister: 'openbuild',
			cardFields: [
				'slug' => 'slug',
				'title' => 'title',
				'description' => 'description',
				'useCase' => 'useCase',
				'category' => 'category',
				'version' => 'version',
				'screenshotUrl' => 'screenshotUrl',
			]
		);
	}//end descriptor()

	/**
	 * Search the remote template store.
	 *
	 * Login-required (in-body guard, so an anonymous caller gets an explicit
	 * 401 rather than a redirect). Returns normalised cards or a generic
	 * outcome — NEVER the registry URL or token, which stay server-side.
	 *
	 * @return JSONResponse 200 with `{outcome, cards}`; 401 for anonymous.
	 *
	 * @spec openspec/specs/openbuild-remote-template-store/spec.md
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->storeError(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		$query = $this->request->getParam('q');
		if (is_string($query) === false) {
			$query = null;
		}

		$kind = $this->request->getParam('kind');
		if (is_string($kind) === false) {
			$kind = null;
		}

		try {
			$result = $this->storeService->search(
				descriptor: $this->descriptor(),
				query: $query,
				kind: $kind
			);
		} catch (Throwable $e) {
			// Detail to the log, generic outcome to the browser: a registry's
			// internals are not the caller's business.
			$this->logger->error('OpenBuild store: search failed: ' . $e->getMessage());
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []],
				statusCode: Http::STATUS_OK
			);
		}

		return new JSONResponse(
			data: ['outcome' => $result['outcome'], 'cards' => $result['cards']],
			statusCode: Http::STATUS_OK
		);
	}//end search()

	/**
	 * Validate a remote slug and resolve its FULL payload for install.
	 *
	 * @param string $slug The remote item slug.
	 *
	 * @return array<string, mixed>|null Null when the slug is malformed, unresolved, or the store errored.
	 */
	private function resolveForInstall(string $slug): ?array {
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return null;
		}

		try {
			return $this->storeService->resolve(descriptor: $this->descriptor(), slug: $slug);
		} catch (Throwable $e) {
			$this->logger->error('OpenBuild store: resolve failed for ' . $slug . ': ' . $e->getMessage());
			return null;
		}
	}//end resolveForInstall()

	/**
	 * Build a uniform error JSONResponse.
	 *
	 * @param string $code The error code.
	 * @param int $status The HTTP status code.
	 * @param string|null $detail Optional detail message.
	 *
	 * @return JSONResponse
	 */
	private function storeError(string $code, int $status, ?string $detail = null): JSONResponse {
		$body = ['error' => $code];
		if ($detail !== null) {
			$body['detail'] = $detail;
		}

		return new JSONResponse(data: $body, statusCode: $status);
	}//end storeError()

	/**
	 * Resolve a remote template by slug and install it locally (clone).
	 *
	 * Login-required (in-body guard). The remote `{slug}` is validated and
	 * resolved by the inherited helper; this action owns only the local clone
	 * and its response.
	 *
	 * @param string $slug The remote template slug to install.
	 *
	 * @return JSONResponse 201 with the new app; 400/401/404/5xx on failure.
	 *
	 * @spec openspec/specs/openbuild-remote-template-store/spec.md
	 */
	#[NoAdminRequired]
	public function install(string $slug): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->storeError(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return $this->storeError(code: 'invalid_template_slug', status: Http::STATUS_BAD_REQUEST);
		}

		$name = (string)($this->request->getParam('name') ?? '');
		$newSlug = (string)($this->request->getParam('slug') ?? '');
		if ($name === '' || preg_match(self::SLUG_PATTERN, $newSlug) !== 1) {
			return $this->storeError(
				code: 'invalid_request',
				status: Http::STATUS_BAD_REQUEST,
				detail: 'name and kebab-case slug required'
			);
		}

		// Resolves the FULL payload (manifest + companionSchemas) the clone
		// path needs; returns null on an unresolvable slug or a store error.
		$template = $this->resolveForInstall(slug: $slug);
		if ($template === null) {
			return $this->storeError(code: 'template_not_found', status: Http::STATUS_NOT_FOUND);
		}

		// Reuse the exact local clone path (companion namespacing, manifest
		// rewrite, per-app register, owner-tagged persist). The seam returns a
		// {status, data} result; this thin action owns the JSONResponse.
		$result = $this->appsController->installFromTemplateArray(
			template: $template,
			name: $name,
			newSlug: $newSlug,
			ownerUid: $user->getUID()
		);

		return new JSONResponse(data: $result['data'], statusCode: $result['status']);
	}//end install()
}//end class
