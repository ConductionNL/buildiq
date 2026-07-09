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
use OCA\OpenBuild\Service\AppNavigationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\INavigationManager;
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
     * @param IRequest           $request           The request object
     * @param IInitialState      $initialState      Initial-state writer (ADR-004)
     * @param IUserSession       $userSession       Current Nextcloud user session
     * @param IGroupManager      $groupManager      Group membership resolver
     * @param INavigationManager $navigationManager Top-bar navigation manager
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IInitialState $initialState,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly INavigationManager $navigationManager,
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
     * routing from GET /api/applications/{slug}/manifest. The bare
     * `/builder/{slug}` route uses this directly; {@see builderSlash()} and
     * {@see builderPath()} (any other app-defined sub-path, #100) delegate
     * here too. The reserved designer sub-routes (`/builder/{slug}/pages`,
     * `/schemas`, `/schemas/{schemaId}`, `/walkthrough`) stay in the SPA via
     * {@see builderDesigner()} instead.
     *
     * @param string $slug The virtual app slug (path param).
     *
     * @return TemplateResponse
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-52
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function builder(string $slug): TemplateResponse
    {
        // Mark the virtual app's own nav entry (registered per published
        // Application by AppNavigationService) as active — otherwise Nextcloud
        // resolves the active entry from the URL's app id and the top bar
        // shows OpenBuild's name and icon instead of the virtual app's.
        $this->navigationManager->setActiveEntry(AppNavigationService::ENTRY_ID_PREFIX.$slug);
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
     * Render the STANDALONE runtime page for a virtual-app SUB-path.
     *
     * Closes #100: direct navigation (fresh load / refresh / bookmark) to a
     * deep link like `/builder/{slug}/tenders` previously fell through to the
     * OpenBuild SPA catch-all because {@see builder()}'s slug pattern excludes
     * slashes. That served the WRONG shell — OpenBuild's own SPA nests the
     * virtual app inside its own chrome and shares OpenBuild's router (which
     * has none of the app's page routes), so the deep-linked page never
     * resolved. This route matches ANY `/builder/{slug}/{path}` — except the
     * reserved designer literals handled by {@see builderDesigner()}, which is
     * registered before this route and therefore wins on those exact URLs —
     * and serves the SAME standalone `builder` template as the bare
     * `/builder/{slug}` route. The path itself is deliberately unused here:
     * builder.js boots its OWN history-mode vue-router (base
     * `/apps/openbuild/builder/{slug}`) built from the deployed app's manifest,
     * and resolves `{path}` client-side exactly like the direct clicking-
     * within-the-app case already does.
     *
     * @param string $slug The virtual app slug (path param).
     * @param string $path The virtual app's own sub-path (path param, unused —
     *                     resolved client-side by the app's own router).
     *
     * @return TemplateResponse
     *
     * @spec exclude Defect fix (#100) — routing plumbing for an existing runtime page; no new domain behaviour.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function builderPath(string $slug, string $path): TemplateResponse
    {
        return $this->builder(slug: $slug);
    }//end builderPath()

    /**
     * Render the OpenBuild SPA for a reserved designer sub-path.
     *
     * `/builder/{slug}/pages`, `/schemas`, `/schemas/{schemaId}` and
     * `/walkthrough` are OpenBuild's OWN in-app designer surfaces (page
     * designer, schema designer, walkthrough designer) — declared as regular
     * pages in `src/manifest.json` and matched by the SPA's own vue-router
     * (`main.js`) BEFORE its `BuilderHost` wildcard. They must keep rendering
     * OpenBuild's own SPA shell (identical to {@see catchAll()}), NOT the
     * standalone virtual-app runtime that {@see builderPath()} now serves for
     * every other `/builder/{slug}/...` sub-path. Registered before
     * `builderPath()` in `appinfo/routes.php` so these more-specific literal
     * patterns win (NC/Symfony route matching is order-sensitive,
     * first-match-wins).
     *
     * @param string $slug         The virtual app slug (path param, unused —
     *                             the SPA resolves it client-side).
     * @param string $designerPath One of `pages`, `schemas`,
     *                             `schemas/{schemaId}` or `walkthrough`
     *                             (path param, unused server-side).
     *
     * @return TemplateResponse
     *
     * @spec exclude Defect fix (#100) — routing plumbing preserving an existing designer surface; no new domain behaviour.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function builderDesigner(string $slug, string $designerPath): TemplateResponse
    {
        return $this->catchAll();
    }//end builderDesigner()

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
