// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// An installable built app's run page links its own web app manifest
// (templates/builder.php). Nextcloud's theming app links one on every page as
// well, earlier in <head>, and a browser installs from the FIRST manifest link,
// so the app was installed under Nextcloud's name and colour instead of the
// maker's (REQ-BQOM-001).

/** The href builder.php gives the app's own manifest link. */
export const OWN_MANIFEST_HREF = 'manifest.webmanifest'

/**
 * Keep only the app's own manifest link on a run page that has one.
 *
 * @param {Document} doc The document.
 * @return {number} How many other manifest links were removed.
 *
 * @spec openspec/changes/pages-installable-offline-and-mobile/specs/installable-offline-apps/spec.md#requirement-a-maker-makes-an-app-installable-req-bqom-001
 */
export function keepOwnManifestLink(doc) {
	const links = Array.from(doc?.querySelectorAll?.('link[rel~="manifest"]') ?? [])
	const own = links.find((link) => link.getAttribute('href') === OWN_MANIFEST_HREF)
	if (!own) {
		return 0
	}
	const others = links.filter((link) => link !== own)
	others.forEach((link) => link.remove())
	return others.length
}
