<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\AppTemplate\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
?>
<div id="content"></div>
