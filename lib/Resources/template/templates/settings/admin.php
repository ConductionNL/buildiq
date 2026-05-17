<?php

/**
 * OpenBuilt app-template admin settings view template.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Template
 * @package   OCA\AppTemplate
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

use OCP\Util;

$appId = OCA\AppTemplate\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="app-template-settings" data-version="<?php p($_['version'] ?? ''); ?>"></div>
