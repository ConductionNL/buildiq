// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useApplicationVersion — resolves an ApplicationVersion record for use by
// the four builder views (spec `buildiq-version-routing` REQ-OBVR-005).
//
// Signature:
//   useApplicationVersion(appSlug: string, versionSlug: string | undefined)
//   → { applicationVersion: Ref<object|null>, loading: Ref<boolean>, error: Ref<Error|null> }
//
// When versionSlug is a non-empty string:
//   GET /apps/buildiq/api/applications/{appSlug}/versions/{versionSlug}
//   Resolves the named ApplicationVersion record (spec C REQ-OBV-107).
//
// When versionSlug is undefined or empty:
//   GET /apps/buildiq/api/applications/{appSlug}/versions (list)
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

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'

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
 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-4
 */
export function defaultEditableVersion(versions, productionUuid) {
	if (!Array.isArray(versions) || versions.length === 0) {
		return null
	}

	// Find all versions that no other version promotes-to (upstream-most nodes).
	const upstreamMost = versions.filter(
		(v) => !versions.some((u) => u.promotesTo === v.uuid),
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
 * The version records of a list response: a bare array or `{ results }`.
 *
 * @param {unknown} data The response body.
 * @return {Array<object>} The versions, or an empty list.
 */
function versionListOf(data) {
	if (Array.isArray(data)) {
		return data
	}
	return data && Array.isArray(data.results) ? data.results : []
}

/**
 * The records of an OpenRegister objects response: `{ results }` or a bare
 * array.
 *
 * @param {unknown} data The response body.
 * @return {Array<object>} The records, or an empty list.
 */
function recordsOf(data) {
	if (data && Array.isArray(data.results)) {
		return data.results
	}
	return Array.isArray(data) ? data : []
}

/**
 * The production version UUID an Application record points at: a UUID
 * string, or an object carrying `uuid` or `id`.
 *
 * @param {object} app The Application record.
 * @return {string|null} The UUID, or null when it has none.
 */
function productionUuidOf(app) {
	const pv = app.productionVersion
	if (typeof pv === 'string') {
		return pv
	}
	return (pv && (pv.uuid || pv.id)) || null
}

/**
 * Read the production version UUID from the app's Application record, via
 * OpenRegister's objects endpoint (the application-level slug lookup).
 *
 * Degrades to null when the record cannot be read: the fallback rule still
 * works then, but does not distinguish production from non-production.
 *
 * @param {string} appSlug The virtual-app slug.
 * @return {Promise<string|null>} The UUID, or null.
 */
async function fetchProductionUuid(appSlug) {
	try {
		const appUrl = generateUrl(
			'/apps/openregister/api/objects/buildiq/built-app',
		)
		const { data: appData } = await axios.get(appUrl, {
			params: { slug: appSlug, _limit: 1 },
		})
		const app = recordsOf(appData).find((a) => a && a.slug === appSlug) || null
		return app ? productionUuidOf(app) : null
	} catch {
		return null
	}
}

/**
 * Composable: resolve an ApplicationVersion record for the given app + version slug.
 *
 * @param {string}           appSlug     The virtual-app slug (e.g. `hello-world`).
 * @param {string|undefined} versionSlug The version slug (e.g. `staging`), or undefined
 *                                       to trigger the most-upstream-non-production fallback.
 * @return {{ applicationVersion: import('vue').Ref<object|null>, loading: import('vue').Ref<boolean>, error: import('vue').Ref<Error|null> }}
 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-4
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
				`/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}/versions/${encodeURIComponent(versionSlug)}`,
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
				`/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}/versions`,
			)
			const { data: versionsData } = await axios.get(versionsUrl)
			const versions = versionListOf(versionsData)

			if (versions.length === 0) {
				applicationVersion.value = null
				return
			}

			applicationVersion.value = defaultEditableVersion(
				versions,
				await fetchProductionUuid(appSlug),
			)
		} catch (e) {
			error.value = e instanceof Error ? e : new Error(String(e))
			applicationVersion.value = null
		} finally {
			loading.value = false
		}
	}

	// Kick off the appropriate fetch immediately, and KEEP the promise. Callers
	// that must read `applicationVersion` (rather than merely render it
	// reactively) have to know when it is settled: `PageDesignerHost.load()`
	// seeds the editor FROM this value exactly once, so consuming it while the
	// fetch is still in flight silently yields an empty manifest.
	//
	// `fetchDefaultVersion()` makes TWO sequential round trips (/versions, then
	// the Application record for productionVersion), so it reliably settles LATER
	// than a single-request caller — the race is not theoretical, it is the
	// common case (#174).
	const ready =
		versionSlug && versionSlug !== '' ? fetchBySlug() : fetchDefaultVersion()

	return { applicationVersion, loading, error, ready }
}
