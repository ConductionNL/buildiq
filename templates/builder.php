<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Buildiq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-builder');
// The installable runtime (/run/{slug}/) links its web app manifest, relative to
// the page, and with the session, since the manifest is signed-in only.
if (($_['webManifest'] ?? false) === true) {
	Util::addHeader('link', ['rel' => 'manifest', 'href' => 'manifest.webmanifest', 'crossorigin' => 'use-credentials']);
}
?>
<div id="content"></div>
