<?php

/**
 * OpenBuild Admin Settings
 *
 * One-line AppHost stub. Nextcloud instantiates the admin-settings panel by
 * the class name in info.xml `<settings><admin>`, and the
 * `#[AuthorizedAdminSetting(AdminSettings::class)]` attribute targets this
 * FQCN, so the class must physically exist in the OpenBuild namespace. All
 * behaviour (form rendering, version initial-state, the IDelegatedSettings
 * #299 fail-closed admin gating) lives in the engine base; OpenBuild's
 * Application::register() binds this class to it via Bootstrap::register().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\OpenBuild\Settings
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

namespace OCA\OpenBuild\Settings;

use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;

/**
 * AppHost-backed admin settings panel for OpenBuild (ADR-040).
 *
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md — Requirement: Boilerplate Adoption
 */
class AdminSettings extends GenericAdminSettings
{
}//end class
