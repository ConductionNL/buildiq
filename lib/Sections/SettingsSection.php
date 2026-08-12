<?php

/**
 * OpenBuild Settings Section
 *
 * One-line AppHost stub. Nextcloud instantiates the admin settings section by
 * the class name in info.xml `<settings><admin-section>`, so the class must
 * physically exist in the OpenBuild namespace. The section id ("openbuild"),
 * display name ("OpenBuild"), icon ("app-dark.svg") and priority (75) are
 * supplied by OpenBuild's Application::register() via Bootstrap::register();
 * the engine base owns all IIconSection behaviour.
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

use OCA\OpenRegister\AppHost\Settings\GenericSettingsSection;

/**
 * AppHost-backed admin settings section for OpenBuild (ADR-040).
 *
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md — Requirement: Boilerplate Adoption
 */
class SettingsSection extends GenericSettingsSection {
}//end class
