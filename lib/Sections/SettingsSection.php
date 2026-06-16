<?php

/**
 * OpenBuild Settings Section
 *
 * Defines the OpenBuild section in the Nextcloud admin settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Sections
 * @package  OCA\OpenBuild\Sections
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

namespace OCA\OpenBuild\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Defines the OpenBuild section in the Nextcloud admin settings.
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
        return 'openbuild';
    }//end getID()

    /**
     * Get the display name of this section.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l->t('OpenBuild');
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
        return $this->urlGenerator->imagePath(appName: 'openbuild', file: 'app-dark.svg');
    }//end getIcon()
}//end class
