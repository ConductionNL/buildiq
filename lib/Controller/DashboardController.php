<?php

/**
 * OpenBuilt Dashboard Controller
 *
 * Controller for the main OpenBuilt dashboard page. Also publishes
 * the caller's Nextcloud group IDs to the frontend via
 * `IInitialState` (REQ-OBR-009) so the editor can derive per-Application
 * roles client-side without DOM data-attribute reads (ADR-004 hard rule
 * `gate-initial-state`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuilt\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\Controller;

use OCA\OpenBuilt\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the main OpenBuilt dashboard page.
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
     * Publishes `openbuilt.currentUserGroups` to IInitialState so the
     * frontend's `useRole(application)` composable and the
     * `ApplicationEditor` list filter can derive per-Application roles
     * without DOM data-attribute reads (REQ-OBR-009, ADR-004 hard rule).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function page(): TemplateResponse
    {
        $this->publishCurrentUserGroups();
        return new TemplateResponse(Application::APP_ID, 'index');
    }//end page()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function catchAll(): TemplateResponse
    {
        return $this->page();
    }//end catchAll()

    /**
     * Publish the caller's group IDs via IInitialState.
     *
     * Per REQ-OBR-009 the frontend consumes `loadState('openbuilt',
     * 'currentUserGroups')` to drive per-Application role derivation.
     * Empty array is published for an absent user session (defensive).
     *
     * @return void
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
