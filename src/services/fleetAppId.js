// SPDX-License-Identifier: EUPL-1.2
/**
 * fleetAppId — resolve a Conduction fleet app's installed id in the browser.
 *
 * The fleet renamed: `openconnector` became `integriq`, `procest` became
 * `dossiq`, `nldesign` became `thematiq`, `docudesk` became `filinq`. Both
 * spellings are in the field at once — a current instance answers to the new
 * id, one pinned to an older release only to the old one.
 *
 * Nextcloud mounts routes under the id an app actually REGISTERED, so a
 * literal in a URL is a 404 on half the fleet. And a 404 here is invisible:
 * every caller in this app treats a failed request as "that app is not
 * installed" and degrades to an empty list or a disabled control. Nothing
 * errors, nothing is logged, the feature simply stops working. A hard swap to
 * the new literal has the identical failure pointing the other way.
 *
 * So the app segment is RESOLVED, never written. `OC.appswebroots` is keyed by
 * installed app id, which makes membership the same duck-typed question
 * `IAppManager::isInstalled()` answers on the server. This is the browser-side
 * counterpart of `lib/Support/FleetAppId.php`.
 *
 * @spec exclude Infrastructure utility with no feature requirement of its own;
 *  it is exercised through the features that call it.
 */

/**
 * Candidate ids per canonical app, NEWEST FIRST.
 *
 * Order is the contract, as it is in `FleetAppId::CANDIDATES` on the PHP side:
 * the first id the instance actually has wins, so a migrated instance resolves
 * to the new name and one still on an older release falls back to the old one.
 * Adding a rename means PREPENDING, never replacing — dropping an old id
 * re-breaks every instance that has not migrated yet.
 *
 * @type {Readonly<Record<string, string[]>>}
 */
export const FLEET_APP_CANDIDATES = Object.freeze({
	integriq: ['integriq', 'openconnector'],
	filinq: ['filinq', 'docudesk'],
	thematiq: ['thematiq', 'nldesign'],
	stackiq: ['stackiq', 'softwarecatalog'],
	larpinq: ['larpinq', 'larpingapp'],
	dossiq: ['dossiq', 'procest'],
	learniq: ['learniq', 'scholiq'],
	decidiq: ['decidiq', 'decidesk'],
	buildiq: ['buildiq', 'openbuild'],
	keepiq: ['keepiq', 'doriath'],
	humaniq: ['humaniq', 'hrmq'],
	planninq: ['planninq', 'planix'],
	launchpad: ['launchpad', 'mydash'],
})

/**
 * Every id a canonical app may answer to on this instance, newest first.
 *
 * An app with no rename on record yields just itself, so callers can pass any
 * app id without checking whether it is in the map.
 *
 * @param {string} canonical - canonical (new) app name, e.g. `integriq`.
 * @return {string[]} - candidate ids, newest first.
 */
export function fleetAppCandidates(canonical) {
	return FLEET_APP_CANDIDATES[canonical] || [canonical]
}

/**
 * The id a canonical app is installed under on THIS instance.
 *
 * Resolved per call rather than at module load: this module is imported by the
 * webpack entry, and reading a global at import time races whatever populated
 * it.
 *
 * @param {string} canonical - canonical (new) app name, e.g. `integriq`.
 * @return {string} - the installed id, or the canonical name when neither
 *  candidate is present. A request that 404s is a better signal than one that
 *  is never sent.
 */
export function resolveFleetAppId(canonical) {
	const candidates = fleetAppCandidates(canonical)
	let roots = {}
	try {
		roots =
			(typeof window !== 'undefined' && window.OC && window.OC.appswebroots)
			|| (typeof OC !== 'undefined' && OC.appswebroots)
			|| {}
	} catch {
		// A missing `OC` global (unit tests, early boot) is not an error: fall
		// through to the canonical name.
		roots = {}
	}
	const found = candidates.find((id) => roots[id] !== undefined)
	return found || candidates[0]
}

/**
 * An app-scoped path built from the id this instance actually has.
 *
 * @param {string} canonical - canonical (new) app name, e.g. `integriq`.
 * @param {string} [suffix] - path after the app segment, with or without a
 *  leading slash.
 * @return {string} - e.g. `/apps/integriq/api/endpoint/kvk/companies`. Not run
 *  through `generateUrl` — callers do that, because some of them build the
 *  suffix from already-encoded input.
 */
export function fleetAppPath(canonical, suffix = '') {
	const base = `/apps/${resolveFleetAppId(canonical)}`
	if (!suffix) {
		return base
	}
	return `${base}/${String(suffix).replace(/^\/+/, '')}`
}
