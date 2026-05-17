// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useApplicationVersion — resolves an ApplicationVersion record for use by
// the four builder views (spec `openbuilt-version-routing` REQ-OBVR-005).
//
// Signature:
//   useApplicationVersion(appSlug: string, versionSlug: string | undefined)
//   → { applicationVersion: Ref<object|null>, loading: Ref<boolean>, error: Ref<Error|null> }
//
// When versionSlug is a non-empty string:
//   GET /apps/openbuilt/api/applications/{appSlug}/versions/{versionSlug}
//   Resolves the named ApplicationVersion record (spec C REQ-OBV-107).
//
// When versionSlug is undefined or empty:
//   GET /apps/openbuilt/api/applications/{appSlug}/versions (list)
//   Applies the "most-upstream non-production fallback" rule (Decision 2 /
//   REQ-OBVR-004 Scenario 2): find the version with no predecessor in the
//   promotesTo chain. Falls back to the production version when every version
//   has a predecessor (i.e. chain has a cycle or only production exists).
//
//   Algorithm: versions.filter(v => !versions.some(u => u.promotesTo === v.uuid))
//   selects versions that no other version promotes-to (upstream-most nodes).
//   From that set we prefer the one NOT identified as productionVersion by the
//   Application record. If no such version exists, fall back to production.
//
// The composable is the single source of truth for version resolution on the
// frontend. All four builder views delegate to it rather than duplicating the
// lookup (REQ-OBVR-005).

import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Pure function: from a list of ApplicationVersion records, find the
 * most-upstream non-production version. "Most-upstream" means the version
 * that no other version's `promotesTo` points at.
 *
 * Falls back to the production version when no non-production upstream exists
 * (e.g. only a single version is configured).
 *
 * This function is the implementation of Decision 2 of design.md and is
 * testable independently of the composable's fetch machinery.
 *
 * @param {Array<object>} versions          All ApplicationVersion records for the app.
 * @param {string|null}   productionUuid    UUID of the production ApplicationVersion.
 * @return {object|null} The selected ApplicationVersion or null when the list is empty.
 */
export function defaultEditableVersion(versions, productionUuid) {
	if (!Array.isArray(versions) || versions.length === 0) {
		return null
	}

	// Find all versions that no other version promotes-to (upstream-most nodes).
	const upstreamMost = versions.filter(
		(v) => !versions.some((u) => u.promotesTo === v.uuid)
	)

	// Prefer an upstream-most version that is NOT the production version.
	const nonProd = upstreamMost.find((v) => v.uuid !== productionUuid)
	if (nonProd) {
		return nonProd
	}

	// Fall back to production version when no non-production upstream exists.
	if (productionUuid) {
		const prod = versions.find((v) => v.uuid === productionUuid)
		if (prod) {
			return prod
		}
	}

	// Last resort: first version in the list.
	return versions[0] || null
}

/**
 * Composable: resolve an ApplicationVersion record for the given app + version slug.
 *
 * @param {string}           appSlug     The virtual-app slug (e.g. `hello-world`).
 * @param {string|undefined} versionSlug The version slug (e.g. `staging`), or undefined
 *                                       to trigger the most-upstream-non-production fallback.
 * @return {{ applicationVersion: import('vue').Ref<object|null>, loading: import('vue').Ref<boolean>, error: import('vue').Ref<Error|null> }}
 */
export function useApplicationVersion(appSlug, versionSlug) {
	/** @type {import('vue').Ref<object|null>} */
	const applicationVersion = ref(null)
	/** @type {import('vue').Ref<boolean>} */
	const loading = ref(false)
	/** @type {import('vue').Ref<Error|null>} */
	const error = ref(null)

	/**
	 * Fetch a named ApplicationVersion by slug.
	 * REQ-OBVR-005 / spec C REQ-OBV-107 GET /{versionSlug}.
	 *
	 * @return {Promise<void>}
	 */
	async function fetchBySlug() {
		loading.value = true
		error.value = null
		try {
			const url = generateUrl(
				`/apps/openbuilt/api/applications/${encodeURIComponent(appSlug)}/versions/${encodeURIComponent(versionSlug)}`
			)
			const { data } = await axios.get(url)
			applicationVersion.value = data || null
		} catch (e) {
			error.value = e instanceof Error ? e : new Error(String(e))
			applicationVersion.value = null
		} finally {
			loading.value = false
		}
	}

	/**
	 * Fetch the full version list and apply the most-upstream-non-production
	 * fallback rule (Decision 2 / REQ-OBVR-004 Scenario 2).
	 *
	 * Also fetches the Application record to read productionVersion UUID.
	 *
	 * @return {Promise<void>}
	 */
	async function fetchDefaultVersion() {
		loading.value = true
		error.value = null
		try {
			// Fetch all versions for this app (spec C REQ-OBV-107 list endpoint).
			const versionsUrl = generateUrl(
				`/apps/openbuilt/api/applications/${encodeURIComponent(appSlug)}/versions`
			)
			const { data: versionsData } = await axios.get(versionsUrl)
			const versions = Array.isArray(versionsData)
				? versionsData
				: (versionsData && Array.isArray(versionsData.results) ? versionsData.results : [])

			if (versions.length === 0) {
				applicationVersion.value = null
				return
			}

			// Fetch the Application record to read productionVersion UUID.
			// Uses OR's objects endpoint — the application-level slug lookup.
			let productionUuid = null
			try {
				const appUrl = generateUrl('/apps/openregister/api/objects/openbuilt/application')
				const { data: appData } = await axios.get(appUrl, { params: { slug: appSlug, _limit: 1 } })
				const apps = (appData && Array.isArray(appData.results))
					? appData.results
					: (Array.isArray(appData) ? appData : [])
				const app = apps.find((a) => a && a.slug === appSlug) || null
				if (app) {
					const pv = app.productionVersion
					productionUuid = typeof pv === 'string' ? pv : (pv && (pv.uuid || pv.id)) || null
				}
			} catch (appErr) {
				// Degraded: can't read productionVersion — fallback rule still works
				// but won't distinguish production from non-production.
			}

			applicationVersion.value = defaultEditableVersion(versions, productionUuid)
		} catch (e) {
			error.value = e instanceof Error ? e : new Error(String(e))
			applicationVersion.value = null
		} finally {
			loading.value = false
		}
	}

	// Kick off the appropriate fetch immediately.
	if (versionSlug && versionSlug !== '') {
		fetchBySlug()
	} else {
		fetchDefaultVersion()
	}

	return { applicationVersion, loading, error }
}
