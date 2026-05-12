<?php

/**
 * OpenBuilt Settings Section
 *
 * Defines the OpenBuilt section in the Nextcloud admin settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Sections
 * @package  OCA\OpenBuilt\Sections
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

namespace OCA\OpenBuilt\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Defines the OpenBuilt section in the Nextcloud admin settings.
 */
class SettingsSection implements IIconSection
{
    /**
     * Constructor for SettingsSection.
     *
     * @param IL10N         $l            The localization service
     * @param IURLGenerator $urlGenerator The URL generator service
     *
     * @return void
     */
    public function __construct(
        private readonly IL10N $l,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the section identifier.
     *
     * @return string
     */
    public function getID(): string
    {
        return 'openbuilt';
    }//end getID()

    /**
     * Get the display name of this section.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l->t('OpenBuilt');
    }//end getName()

    /**
     * Get the priority for ordering this section.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 75;
    }//end getPriority()

    /**
     * Get the icon path for this section.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(appName: 'openbuilt', file: 'app-dark.svg');
    }//end getIcon()
}//end class
