<?php

/**
 * OpenBuilt Admin Settings
 *
 * Provides the admin settings form for the OpenBuilt application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenBuilt\Settings
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

namespace OCA\OpenBuilt\Settings;

use OCA\OpenBuilt\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

/**
 * Provides the admin settings form for the OpenBuilt application.
 */
class AdminSettings implements ISettings
{
    /**
     * Constructor.
     *
     * @param IAppManager   $appManager   The app manager.
     * @param IInitialState $initialState The initial-state service used to
     *                                    deliver server-side data to the Vue
     *                                    bundle (per ADR-004 hard rule + the
     *                                    hydra-gate-initial-state mechanical
     *                                    gate — do NOT use DOM dataset attrs).
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IInitialState $initialState,
    ) {
    }//end __construct()

    /**
     * Get the settings form template.
     *
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        $version = $this->appManager->getAppVersion(appId: Application::APP_ID);

        // ADR-004 + hydra-gate-initial-state: hand server data to the bundle
        // via IInitialState + loadState, not via DOM data-* attributes.
        $this->initialState->provideInitialState(key: 'version', data: $version);

        return new TemplateResponse(Application::APP_ID, 'settings/admin');
    }//end getForm()

    /**
     * Get the section ID this settings page belongs to.
     *
     * @return string
     */
    public function getSection(): string
    {
        return 'openbuilt';
    }//end getSection()

    /**
     * Get the priority for ordering within the section.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 10;
    }//end getPriority()
}//end class
