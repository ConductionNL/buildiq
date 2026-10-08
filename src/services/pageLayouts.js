// SPDX-License-Identifier: EUPL-1.2
/**
 * pageLayouts — the network half of the page-layout and screen-override
 * authoring surface (specs `page-layout-per-type`, `screen-override-layers`).
 *
 * No state lives here. The panels own what is on screen; this module talks to
 * the three endpoints and normalises every failure into `{status, error,
 * message}` so a refusal keeps the sentence the server wrote. A refusal is the
 * point of these endpoints, and replacing it with "something went wrong" would
 * leave an administrator guessing at a rule that already told them what to fix.
 *
 * The base fingerprint is never sent. The server stamps it from the layouts
 * actually published, so anything this module put in `baseFingerprint` would be
 * discarded; not sending it keeps the two halves from looking like they
 * disagree.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/buildiq/api/page-layouts'

/**
 * The keys the server owns. A client that sent these back would be telling the
 * server what it already knows better, and a stale one would read as a base
 * nobody checked.
 *
 * @type {Array<string>}
 */
const SERVER_OWNED = ['baseFingerprint', 'baseCutAt', 'drifted', 'orphanedPaths']

/**
 * Normalise an axios rejection into the server's own refusal shape.
 *
 * @param {Error} err - the axios error.
 * @return {{status: number, error: string, message: string}} The refusal.
 */
function normaliseError(err) {
	const response = err && err.response
	const data = (response && response.data) || {}
	return {
		status: response ? response.status : 0,
		error: data.error || 'network_error',
		message: data.message || (err && err.message) || 'Request failed.',
	}
}

/**
 * GET the layouts published for one register and schema, each carrying whether
 * it has drifted and which of its paths are orphaned.
 *
 * @param {{register: string, schema: string}} scope - which schema to read.
 * @return {Promise<Array<object>>} The layouts.
 * @throws {{status: number, error: string, message: string}} Normalised refusal.
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md
 */
export async function fetchPageLayouts({ register, schema }) {
	try {
		const { data } = await axios.get(generateUrl(BASE), {
			params: { register, schema },
		})
		return (data && data.items) || []
	} catch (err) {
		throw normaliseError(err)
	}
}

/**
 * PUT one layout or override.
 *
 * @param {object} layout - the layout to store.
 * @return {Promise<{layout: object, warnings: Array<string>}>} What was stored.
 * @throws {{status: number, error: string, message: string}} Normalised refusal.
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md
 */
export async function savePageLayout(layout) {
	// The server stamps the fingerprint from the base it composes itself, and
	// answers `drifted` and `orphanedPaths` as a reading of it. Sending any of
	// them back would be a claim about a base this client never checked.
	const rest = { ...(layout || {}) }
	for (const key of SERVER_OWNED) {
		delete rest[key]
	}

	try {
		const { data } = await axios.put(generateUrl(BASE), rest)
		return {
			layout: (data && data.layout) || rest,
			warnings: (data && data.warnings) || [],
		}
	} catch (err) {
		throw normaliseError(err)
	}
}

/**
 * POST a re-cut of a drifted override: re-pin it to the base it has now.
 *
 * @param {string} layoutId - the override's id.
 * @return {Promise<{layout: object, dropped: Array<string>}>} What was re-cut,
 *   and which paths it dropped, so the answer can be shown rather than logged.
 * @throws {{status: number, error: string, message: string}} Normalised refusal.
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md
 */
export async function recutOverride(layoutId) {
	try {
		const { data } = await axios.post(
			generateUrl(`${BASE}/${encodeURIComponent(layoutId)}/recut`),
		)
		return {
			layout: (data && data.layout) || {},
			dropped: (data && data.dropped) || [],
		}
	} catch (err) {
		throw normaliseError(err)
	}
}
