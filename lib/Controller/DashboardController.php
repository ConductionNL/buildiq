<?php

/**
 * OpenBuild Dashboard Controller
 *
 * Controller for the main OpenBuild dashboard page. Also publishes
 * the caller's Nextcloud group IDs to the frontend via
 * `IInitialState` (REQ-OBR-009) so the editor can derive per-Application
 * roles client-side without DOM data-attribute reads (ADR-004 hard rule
 * `gate-initial-state`).
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-52
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the main OpenBuild dashboard page.
 */
class DashboardController extends Controller
{
    /**
     * Constructor for the DashboardController.
     *
     * @param IRequest      $request      The request object
     * @param IInitialState $initialState Initial-state writer (ADR-004)
     * @param IUserSession  $userSession  Current Nextcloud user session
     * @param IGroupManager $groupManager Group membership resolver
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IInitialState $initialState,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Render the main dashboard page.
     *
     * Publishes `openbuild.currentUserGroups` to IInitialState so the
     * frontend's `useRole(application)` composable and the
     * `ApplicationEditor` list filter can derive per-Application roles
     * without DOM data-attribute reads (REQ-OBR-009, ADR-004 hard rule).
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-52
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse
    {
        $this->publishCurrentUserGroups();
        return new TemplateResponse(Application::APP_ID, 'index');
    }//end page()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-52
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(): TemplateResponse
    {
        return $this->page();
    }//end catchAll()

    /**
     * Render the STANDALONE runtime page for a published virtual app.
     *
     * Unlike the SPA (which renders apps nested inside OpenBuild's own shell),
     * this serves a dedicated template (`builder`) whose JS entry mounts the
     * virtual app's CnAppRoot at the top level — its own menu, pages and
     * routing from GET /api/applications/{slug}/manifest. Only the bare
     * `/builder/{slug}` runtime uses this; the designer sub-routes
     * (`/builder/{slug}/pages`, `/schemas`) stay in the SPA via the catch-all.
     *
     * @param string $slug The virtual app slug (path param).
     *
     * @return TemplateResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function builder(string $slug): TemplateResponse
    {
        $this->publishCurrentUserGroups();
        $this->initialState->provideInitialState('builderSlug', $slug);
        $this->initialState->provideInitialState(
            'builderVersion',
            (string) $this->request->getParam('_version', '')
        );
        return new TemplateResponse(Application::APP_ID, 'builder');
    }//end builder()

    /**
     * Trailing-slash alias of {@see builder()}.
     *
     * Browsers and pasted links often append a `/`, e.g.
     * `/builder/{slug}/`. The bare runtime route's slug pattern excludes
     * slashes, so the trailing-slash form would otherwise fall through to the
     * SPA catch-all and render OpenBuild's own shell instead of the app. This
     * alias keeps a DISTINCT route name (the AppHost Routes::standard() guard
     * throws on duplicate names) while serving the exact same page.
     *
     * @param string $slug The virtual app slug (path param).
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-52
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function builderSlash(string $slug): TemplateResponse
    {
        return $this->builder(slug: $slug);
    }//end builderSlash()

    /**
     * Deep-path alias of {@see builder()} for a virtual app's OWN page routes.
     *
     * A published virtual app renders in the standalone runtime (`builder.js`),
     * whose router base is `/builder/{slug}` and whose routes come from the
     * app's manifest — so its pages live at `/builder/{slug}/{path}` (e.g.
     * `/builder/pet-store/pets`). The bare-runtime route's slug pattern excludes
     * slashes, so without this alias every such deep link falls through to the
     * SPA catch-all and renders OpenBuild's own shell (the nested BuilderHost,
     * which cannot resolve the app's pages) instead of the app.
     *
     * The route's `{path}` requirement excludes the OpenBuild DESIGNER
     * sub-routes (`pages`, `schemas`, `schemas/{id}`, `walkthrough`) so those
     * keep falling through to the SPA catch-all as before; everything else
     * serves the standalone runtime. Uses a DISTINCT route name (the AppHost
     * Routes::standard() guard throws on duplicate names) while serving the
     * exact same page. `$path` is consumed client-side by the runtime's router;
     * the server ignores it.
     *
     * @param string $slug The virtual app slug (path param).
     * @param string $path The remaining in-app route path (client-side only).
     *
     * @return TemplateResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function builderDeep(string $slug, string $path): TemplateResponse
    {
        return $this->builder(slug: $slug);
    }//end builderDeep()

    /**
     * Publish the caller's group IDs via IInitialState.
     *
     * Per REQ-OBR-009 the frontend consumes `loadState('openbuild',
     * 'currentUserGroups')` to drive per-Application role derivation.
     * Empty array is published for an absent user session (defensive).
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-52
     */
    private function publishCurrentUserGroups(): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            $this->initialState->provideInitialState('currentUserGroups', []);
            return;
        }

        $groups = $this->groupManager->getUserGroups($user);
        $gids   = [];
        foreach ($groups as $group) {
            $gids[] = $group->getGID();
        }

        $this->initialState->provideInitialState('currentUserGroups', $gids);
    }//end publishCurrentUserGroups()
}//end class
