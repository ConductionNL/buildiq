/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Resolve `Application.productionVersion` UUIDs to their ApplicationVersion
 * rows, once per page, for the virtual-apps LIST view.
 *
 * THE BUG THIS FIXES
 * ------------------
 * `Application.productionVersion` is a UUID *string* (the versioned model,
 * ADR-002). Spec C moved `status` and `semver` off the Application and onto the
 * ApplicationVersion, so a card cannot render either without resolving that
 * UUID. `ApplicationCard.vue` never did — it bailed unless `productionVersion`
 * was already an object, which on this data path it never is. The result:
 * EVERY card read "Draft" and "Version —" regardless of the app's real state.
 * Measured on the e2e instance — `hello-world` rendered "Draft / Version —"
 * while its production ApplicationVersion is
 * `{status: 'published', semver: '1.0.0'}`. REQ-OBR-007b's "newly published
 * Application shows published badge" was unsatisfiable from the list.
 *
 * WHY CLIENT-SIDE, AND WHY THIS SOURCE
 * ------------------------------------
 * The index page is a manifest `type: index` page over
 * `register: buildiq / schema: application`, so `CnIndexPage` fetches the rows
 * from OpenRegister's GENERIC objects endpoint
 * (`/apps/openregister/api/objects/buildiq/built-app`) — it never calls
 * Buildiq's own `/api/applications`. Enriching the row payload the cards
 * receive is therefore not possible from the controller; the resolution has to
 * happen on the client.
 *
 * The lookup reads `/apps/buildiq/api/applications`, whose `listMine` resolves
 * each app's production version and projects it as `productionVersionDetail`
 * (`ApplicationsController::attachProductionVersionDetail`). Two alternatives
 * were measured and rejected:
 *
 *   - one `/applications/{slug}/versions` call per card — N requests from a
 *     grid, the pattern the detail views already avoid;
 *   - one bulk call to the OR objects endpoint for `applicationVersion` — 262
 *     rows on this instance, each carrying its whole `manifest` blob, for five
 *     scalar fields per card.
 *
 * `/api/applications` is ONE request, is already RBAC-filtered for the caller,
 * and carries no manifests.
 *
 * This also matches how the rest of the app treats the field:
 * `ApplicationDetailHeader` and `ApplicationDetailDashboard` both read the UUID
 * and look it up in a separately-fetched versions list.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'

/**
 * uuid -> `{ uuid, slug, name, semver, status }`.
 *
 * Reactive so a card rendered before the fetch settles re-renders when it does.
 */
export const productionVersions = reactive({})

/** In-flight/settled fetch, so N cards mounting together issue ONE request. */
let inflight = null

/** Whether the last load has finished. */
let settled = false

/** Production version UUIDs a reload was already tried for. */
const retried = new Set()

/**
 * Load the caller's applications once and index their resolved production
 * versions by UUID.
 *
 * De-duplicated: N cards mounting together share ONE request.
 *
 * Never throws: a failed lookup leaves the map empty and the cards fall back to
 * their placeholder rendering, which is the pre-existing behaviour rather than a
 * broken page. The failure IS logged — a silent catch here would recreate the
 * exact defect this module fixes.
 *
 * @param {string} [uuid] A production version UUID the caller needs; when it
 *   is missing from the settled index, the index is loaded once more.
 * @return {Promise<object>} The (possibly empty) uuid -> version map.
 *
 * @spec openspec/specs/application-versions/spec.md
 */
export function ensureProductionVersionsLoaded(uuid = '') {
	// A card whose production version is not in the index after the last load
	// (the app was created or changed since, or the load failed) asks once
	// more. Without this the cards read "Draft / Version —" after in-app
	// navigation until a full reload.
	if (needsReload(uuid)) {
		retried.add(uuid)
		inflight = null
	}
	if (inflight) {
		return inflight
	}
	settled = false
	inflight = loadProductionVersions()
	return inflight
}

/**
 * Whether the settled index lacks a uuid a card needs, and no reload was tried
 * for it yet.
 *
 * @param {unknown} uuid The production version UUID the caller needs.
 * @return {boolean}
 */
function needsReload(uuid) {
	return Boolean(inflight && settled && isUnknownUuid(uuid) && !retried.has(uuid))
}

/**
 * Whether a uuid is a non-empty string the index does not hold.
 *
 * @param {unknown} uuid The production version UUID.
 * @return {boolean}
 */
function isUnknownUuid(uuid) {
	return typeof uuid === 'string' && uuid !== '' && !(uuid in productionVersions)
}

/**
 * Load the caller's applications and index their production versions. A failed
 * load is logged and lets the next card try again.
 *
 * @return {Promise<object>} The uuid -> version map.
 */
async function loadProductionVersions() {
	try {
		const url = generateUrl('/apps/buildiq/api/applications')
		const { data } = await axios.get(url)
		indexProductionVersions(Array.isArray(data) ? data : (data?.results ?? []))
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			'[buildiq] could not resolve production versions; app cards will show '
				+ 'their placeholder status/version until this succeeds',
			e,
		)
		// Let the next card try again instead of pinning the failure.
		inflight = null
	}
	settled = true
	return productionVersions
}

/**
 * Index the resolved production version of every application row by its UUID.
 *
 * @param {Array<object>} rows The application rows.
 * @return {void}
 */
function indexProductionVersions(rows) {
	for (const row of rows) {
		const uuid = row?.productionVersion
		const detail = row?.productionVersionDetail
		if (isResolvedVersion(uuid, detail)) {
			productionVersions[uuid] = detail
		}
	}
}

/**
 * Whether a row carries a production version UUID and its resolved detail.
 *
 * @param {unknown} uuid The row's productionVersion.
 * @param {unknown} detail The row's productionVersionDetail.
 * @return {boolean}
 */
function isResolvedVersion(uuid, detail) {
	return typeof uuid === 'string' && uuid !== '' && Boolean(detail) && typeof detail === 'object'
}

/**
 * Reset the cache — test seam, and the hook a future refresh action would use.
 *
 * @return {void}
 * @spec openspec/specs/application-versions/spec.md
 */
export function resetProductionVersions() {
	inflight = null
	settled = false
	retried.clear()
	for (const key of Object.keys(productionVersions)) {
		delete productionVersions[key]
	}
}
