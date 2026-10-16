// SPDX-License-Identifier: EUPL-1.2
/**
 * Whether a virtual app shows the first-open support note.
 *
 * CnAppRoot mounts `CnSupportDialog` by default. That note is the founder
 * letter for Conduction's own apps: it asks for a donation and an App Store
 * review of the host app, and it derives both links and its title from
 * `appId`. For a virtual app the id is `openbuild-{slug}`, so every app a user
 * built opened with "Support Openbuild-{slug}", pointing at an App Store page
 * that does not exist. The note also stores "seen" at
 * `/apps/openbuild-{slug}/api/preferences/...`, a route no app serves, so the
 * 404 meant it came back on every fresh browser, over the running app and over
 * the page designer's preview.
 *
 * A virtual app therefore shows the note only when its author switched it on
 * in the support editor (`manifest.support.enabled === true`). Leaving the
 * block out, or switching it off, shows nothing.
 *
 * @param {object|null|undefined} manifest The virtual app's manifest.
 * @return {boolean} The value for CnAppRoot's `supportDialog` prop.
 * @spec openspec/specs/openbuild-runtime/spec.md
 */
export function virtualAppSupportDialog(manifest) {
	const support = manifest && manifest.support
	return !!(support && typeof support === 'object' && support.enabled === true)
}
