<?php

/**
 * OpenBuild StoreController
 *
 * HTTP surface for the remote template "store" (openbuild-remote-template-
 * store). Two endpoints, both consume-only against the configured remote
 * OpenRegister catalogue via RemoteTemplateStoreService (server-side proxy):
 *   - GET  /api/store/templates            — search remote templates (cards).
 *   - POST /api/store/templates/{slug}/install — resolve the remote template by
 *            slug and install it LOCALLY by cloning through the shared
 *            ApplicationsController::installFromTemplateArray seam (so an
 *            installed store app is a normal local Application + per-app
 *            register, identical to a local template clone).
 *
 * Both carry #[NoAdminRequired] and an in-body authentication guard (any
 * authenticated OpenBuild user may search + install; the install caller becomes
 * the new app's owner — mirrors the local createFromTemplate posture). No
 * publishing in this cut.
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
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\RemoteTemplateStoreService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the remote template-store search + install endpoints.
 *
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */
class StoreController extends Controller
{
    /**
     * Kebab-case slug pattern (matches the route requirement + the
     * application/template slug patterns).
     */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

    /**
     * Constructor.
     *
     * @param IRequest                   $request                The current HTTP request.
     * @param LoggerInterface            $logger                 PSR logger.
     * @param IUserSession               $userSession            Current NC user session.
     * @param RemoteTemplateStoreService $storeService           Remote catalogue proxy.
     * @param ApplicationsController     $applicationsController Shared clone/install seam.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly RemoteTemplateStoreService $storeService,
        private readonly ApplicationsController $applicationsController,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Search the remote catalogue for templates.
     *
     * Login-required (in-body guard); returns the normalised cards or a generic
     * error envelope. NEVER exposes the registry URL/token (server-side proxy).
     *
     * @return JSONResponse 200 with `{outcome, cards}`; 401 for anonymous.
     *
     * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
     */
    #[NoAdminRequired]
    public function search(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        $query = $this->request->getParam('q');
        if (is_string($query) === false) {
            $query = null;
        }

        try {
            $result = $this->storeService->searchTemplates(query: $query);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild store: search failed: '.$e->getMessage());
            return $this->error(code: RemoteTemplateStoreService::OUTCOME_UNREACHABLE, status: Http::STATUS_OK);
        }

        return new JSONResponse(
            data: ['outcome' => $result['outcome'], 'cards' => $result['cards']],
            statusCode: Http::STATUS_OK
        );
    }//end search()

    /**
     * Resolve a remote template by slug and install it locally (clone).
     *
     * Login-required (in-body guard). Validates the remote `{slug}`, resolves the
     * full remote payload, then delegates to the shared install seam with the
     * user-supplied name + new slug. The calling user becomes the app owner.
     *
     * @param string $slug The remote template slug to install.
     *
     * @return JSONResponse 201 with the new app; 400/401/404/5xx on failure.
     *
     * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
     */
    #[NoAdminRequired]
    public function install(string $slug): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
        }

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return $this->error(code: 'invalid_template_slug', status: Http::STATUS_BAD_REQUEST);
        }

        $name    = (string) ($this->request->getParam('name') ?? '');
        $newSlug = (string) ($this->request->getParam('slug') ?? '');
        if ($name === '' || preg_match(self::SLUG_PATTERN, $newSlug) !== 1) {
            return $this->error(
                code: 'invalid_request',
                status: Http::STATUS_BAD_REQUEST,
                detail: 'name and kebab-case slug required'
            );
        }

        try {
            $template = $this->storeService->resolveTemplate(slug: $slug);
        } catch (Throwable $e) {
            $this->logger->error('OpenBuild store: resolve failed for '.$slug.': '.$e->getMessage());
            $template = null;
        }

        if ($template === null) {
            return $this->error(code: 'template_not_found', status: Http::STATUS_NOT_FOUND);
        }

        // Reuse the exact local clone path (companion namespacing, manifest
        // rewrite, per-app register, owner-tagged persist). The seam returns a
        // {status, data} result; this thin action owns the JSONResponse.
        $result = $this->applicationsController->installFromTemplateArray(
            template: $template,
            name: $name,
            newSlug: $newSlug,
            ownerUid: $user->getUID()
        );

        return new JSONResponse(data: $result['data'], statusCode: $result['status']);
    }//end install()

    /**
     * Build a uniform error JSONResponse.
     *
     * @param string      $code   The error code.
     * @param int         $status The HTTP status code.
     * @param string|null $detail Optional detail message.
     *
     * @return JSONResponse
     */
    private function error(string $code, int $status, ?string $detail=null): JSONResponse
    {
        $body = ['error' => $code];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new JSONResponse(data: $body, statusCode: $status);
    }//end error()
}//end class
