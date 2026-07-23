<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\OpenBuild\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-public-form');
?>
<div id="content"></div>
