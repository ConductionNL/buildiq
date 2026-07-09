<?php

/**
 * PHPStan scan stub for OpenRegister's AppHost Settings engine (ADR-040).
 *
 * Analysis-only — referenced from phpstan.neon `scanFiles` and NEVER loaded at
 * runtime (the runtime stubs live in tests/stubs/openregister-stubs.php, guarded
 * by class_exists). Lets SettingsSection and AdminSettings resolve the OR base
 * classes they extend when the openregister sibling app is absent from the
 * analysis path.
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\AppHost\Settings;

use OCP\IURLGenerator;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialState;
use OCP\Settings\IIconSection;
use OCP\Settings\ISettings;

/**
 * PHPStan-only stub for the AppHost admin settings section base class.
 *
 * The real class lives in the openregister sibling app (ADR-040).
 */
class GenericSettingsSection implements IIconSection
{
    /**
     * Construct a settings section.
     *
     * @param string       $sectionId    The section id.
     * @param string       $name         The display name.
     * @param string       $appId        The app id.
     * @param string       $iconFile     The icon file name.
     * @param int          $priority     The display priority.
     * @param IURLGenerator $urlGenerator The URL generator.
     */
    public function __construct(
        protected string $sectionId,
        protected string $name,
        protected string $appId,
        protected string $iconFile,
        protected int $priority,
        protected IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Return the section id.
     *
     * @return string
     */
    public function getID(): string
    {
        return $this->sectionId;
    }//end getID()

    /**
     * Return the display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }//end getName()

    /**
     * Return the display priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }//end getPriority()

    /**
     * Return the absolute URL to the section icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath($this->appId, $this->iconFile);
    }//end getIcon()
}//end class

/**
 * PHPStan-only stub for the AppHost admin settings panel base class.
 *
 * The real class lives in the openregister sibling app (ADR-040).
 */
class GenericAdminSettings implements ISettings
{
    /**
     * Construct an admin settings panel.
     *
     * @param string        $appId        The app id.
     * @param string        $sectionId    The section id.
     * @param int           $priority     The display priority.
     * @param IAppManager   $appManager   The app manager.
     * @param IInitialState $initialState The initial state service.
     * @param IAppConfig    $appConfig    The app config service.
     */
    public function __construct(
        protected string $appId,
        protected string $sectionId,
        protected int $priority,
        protected IAppManager $appManager,
        protected IInitialState $initialState,
        protected IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Return the settings form template response.
     *
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse(appName: $this->appId, templateName: 'settings-admin');
    }//end getForm()

    /**
     * Return the section id.
     *
     * @return string
     */
    public function getSection(): string
    {
        return $this->sectionId;
    }//end getSection()

    /**
     * Return the display priority.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }//end getPriority()
}//end class
