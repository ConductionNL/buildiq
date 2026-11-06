// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * app-version — the version the bundle reports about itself.
 *
 * `@nextcloud/vue` prints the global `appVersion` next to the app name, for
 * example in the footer of the user settings dialog. webpack used to define it
 * from package.json, whose version the release process never moves, so a
 * 0.7.10 install announced itself as "buildiq 0.2.0". appinfo/info.xml is the
 * file every release bump writes, so the bundle reads its version from there.
 *
 * @spec exclude Build-time helper with no requirement of its own; covered by
 *  tests/scripts/app-version.spec.js.
 */

const fs = require('node:fs')
const path = require('node:path')

/**
 * Read the `<version>` element from an appinfo/info.xml file.
 *
 * @param {string} [infoXmlPath] Path to info.xml; defaults to this app's.
 * @return {string} The version string, or '' when the file has none.
 */
function readAppVersion(infoXmlPath) {
	const file = infoXmlPath || path.resolve(__dirname, '..', 'appinfo', 'info.xml')
	const xml = fs.readFileSync(file, 'utf8')
	// Anchored to the element that sits directly in <info>: the first match.
	// Dependency blocks use attributes (min-version="…"), never a <version> element.
	const match = xml.match(/<version>\s*([^<\s]+)\s*<\/version>/)
	return match ? match[1] : ''
}

module.exports = { readAppVersion }
