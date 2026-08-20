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
 * `register: openbuild / schema: application`, so `CnIndexPage` fetches the rows
 * from OpenRegister's GENERIC objects endpoint
 * (`/apps/openregister/api/objects/openbuild/application`) — it never calls
 * OpenBuild's own `/api/applications`. Enriching the row payload the cards
 * receive is therefore not possible from the controller; the resolution has to
 * happen on the client.
 *
 * The lookup reads `/apps/openbuild/api/applications`, whose `listMine` resolves
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
 * @return {Promise<object>} The (possibly empty) uuid -> version map.
 */
export function ensureProductionVersionsLoaded() {
	if (inflight) {
		return inflight
	}
	inflight = (async () => {
		try {
			const url = generateUrl('/apps/openbuild/api/applications')
			const { data } = await axios.get(url)
			const rows = Array.isArray(data) ? data : (data?.results ?? [])
			for (const row of rows) {
				const uuid = row?.productionVersion
				const detail = row?.productionVersionDetail
				if (
					typeof uuid === 'string'
					&& uuid !== ''
					&& detail
					&& typeof detail === 'object'
				) {
					productionVersions[uuid] = detail
				}
			}
		} catch (e) {
			// eslint-disable-next-line no-console
			console.warn(
				'[openbuild] could not resolve production versions; app cards will show '
					+ 'their placeholder status/version until this succeeds',
				e,
			)
		}
		return productionVersions
	})()
	return inflight
}

/**
 * Reset the cache — test seam, and the hook a future refresh action would use.
 *
 * @return {void}
 */
export function resetProductionVersions() {
	inflight = null
	for (const key of Object.keys(productionVersions)) {
		delete productionVersions[key]
	}
}
