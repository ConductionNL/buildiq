<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Buildiq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-builder');
// The installable runtime (/run/{slug}/) links its web app manifest, relative to
// the page, and with the session, since the manifest is signed-in only.
if (($_['webManifest'] ?? false) === true) {
	Util::addHeader('link', ['rel' => 'manifest', 'href' => 'manifest.webmanifest', 'crossorigin' => 'use-credentials']);
	// iOS reads its home-screen icon from this link, as a PNG only.
	$slug = (string)($_['slug'] ?? '');
	if (($_['touchIcon'] ?? false) === true && preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug) === 1) {
		Util::addHeader('link', ['rel' => 'apple-touch-icon', 'href' => '../../icons/' . $slug . '-touch.png']);
	}
}
?>
<div id="content"></div>
