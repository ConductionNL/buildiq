<?php

/**
 * OpenBuild admin settings template.
 *
 * Renders the mount point for the openbuild-settings.js Vue bundle. Server
 * data (e.g. version) is delivered to the bundle via IInitialState +
 * loadState — not via DOM data-* attributes, per ADR-004 hard rule and the
 * hydra-gate-initial-state mechanical gate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

use OCP\Util;

$appId = OCA\OpenBuild\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="openbuild-settings"></div>
