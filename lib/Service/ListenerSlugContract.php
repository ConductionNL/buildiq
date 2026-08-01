<?php

/**
 * Feature gate for openbuild's corrected listener schema matching.
 *
 * OpenBuild's OpenRegister listeners compared a schema **id** against a schema
 * **slug** literal, so their handler bodies had never run once. Correcting the
 * comparison is a one-line change, but it is not a behaviour-neutral one: the
 * listeners it wakes include a fail-closed validation guard that starts
 * REJECTING writes which succeed today, a bulk approval-chain initialiser, and
 * a document generator. None of them have ever executed in production, so none
 * of them have ever been exercised against real data.
 *
 * This gate exists so the fix can ship, be reviewed and be tested without
 * silently switching all of that on in one deploy. It mirrors the approach
 * openregister#2248 took for the sibling `ObjectTransitionedEvent` defect,
 * which is likewise shipped behind a default-off flag.
 *
 * Default: OFF. Enable per instance, after reviewing each handler body:
 *   occ config:app:set openbuild listener_slug_contract --value=yes
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://openbuild.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCP\IAppConfig;

/**
 * Reports whether the corrected listener schema matching is enabled.
 */
class ListenerSlugContract
{

    /**
     * The app id the flag is stored under.
     *
     * @var string
     */
    private const APP_ID = 'openbuild';

    /**
     * The config key holding the flag.
     *
     * @var string
     */
    private const CONFIG_KEY = 'listener_slug_contract';

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig Nextcloud app configuration.
     */
    public function __construct(private readonly IAppConfig $appConfig)
    {
    }//end __construct()

    /**
     * Whether the corrected slug comparison should be honoured.
     *
     * @return bool True when the contract is enabled for this instance.
     */
    public function isEnabled(): bool
    {
        return $this->appConfig->getValueBool(self::APP_ID, self::CONFIG_KEY, false);
    }//end isEnabled()
}//end class
