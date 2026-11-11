// SPDX-License-Identifier: EUPL-1.2
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { fetchApplicationRecord } from '../composables/useApplicationRecord.js'

/**
 * The version list out of an answer of the versions endpoint, each with a `uuid`.
 *
 * @param {*} data The response body: a list, or an object holding `results`.
 * @return {Array<object>} The versions.
 */
function versionsFrom(data) {
	let list = []
	if (Array.isArray(data)) {
		list = data
	} else if (data && Array.isArray(data.results)) {
		list = data.results
	}
	return list.map((v) => ({
		...v,
		uuid: v.uuid || v.id || (v['@self'] && v['@self'].id) || null,
	}))
}

/**
 * The uuid of a version given as a uuid or as an embedded record.
 *
 * @param {?(string|object)} version The version reference.
 * @return {string|null} The uuid, or null.
 */
function versionUuidOf(version) {
	if (!version) return null
	if (typeof version === 'string') return version
	return version.uuid || version.id || null
}

/**
 * The Application id a component loads: its `objectId`, else the route's.
 *
 * @param {object} vm The component.
 * @return {string} The id, or ''.
 */
function requestedApplicationId(vm) {
	return vm.objectId || routeObjectId(vm.$route) || ''
}

/**
 * The `objectId` route param, if any.
 *
 * @param {?object} route The current route.
 * @return {string|undefined}
 */
function routeObjectId(route) {
	return route && route.params && route.params.objectId
}

/**
 * Take the next request number for a component's record refresh.
 *
 * @param {object} vm The component.
 * @return {number} The number of the request now in flight.
 */
function nextApplicationRequest(vm) {
	const seq = (vm._appReqSeq || 0) + 1
	vm._appReqSeq = seq
	return seq
}

/**
 * A rejection as an Error.
 *
 * @param {unknown} e The rejection.
 * @return {Error}
 */
function asError(e) {
	return e instanceof Error ? e : new Error(String(e))
}

/**
 * Loading, ordering and picking the versions of the application a detail
 * component shows. A component runs its own follow-up in `onVersionsLoaded()`.
 *
 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
 */
export const applicationVersionsMixin = {
	computed: {
		/**
		 * App slug from the resolved Application record.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		appSlug() {
			return (this.application && this.application.slug) || ''
		},

		/**
		 * Production version UUID resolved from the Application record.
		 *
		 * @return {string|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		productionVersionUuid() {
			return versionUuidOf(this.application && this.application.productionVersion)
		},

		/**
		 * Versions ordered along the promotesTo chain (most-upstream first).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		orderedVersions() {
			const all = Array.isArray(this.versions) ? this.versions.slice() : []
			if (all.length === 0) return []
			const byUuid = new Map()
			all.forEach((v) => byUuid.set(v.uuid, v))
			const roots = all.filter(
				(v) => !all.some((u) => u.promotesTo === v.uuid),
			)
			const ordered = []
			const visited = new Set()
			const walk = (v) => {
				if (!v || visited.has(v.uuid)) return
				visited.add(v.uuid)
				ordered.push(v)
				if (v.promotesTo) {
					walk(byUuid.get(v.promotesTo))
				}
			}
			roots.forEach((r) => walk(r))
			all.forEach((v) => walk(v))
			return ordered
		},

		/**
		 * Currently active version (selected, or production, or first).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		activeVersion() {
			if (!this.selectedVersionUuid) {
				return this.productionVersion || this.orderedVersions[0] || null
			}
			return (
				this.orderedVersions.find((v) => v.uuid === this.selectedVersionUuid)
				|| null
			)
		},

		/**
		 * The production version row (for the chain/star resolution).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		productionVersion() {
			if (!this.productionVersionUuid) return null
			return (
				this.orderedVersions.find(
					(v) => v.uuid === this.productionVersionUuid,
				) || null
			)
		},
	},

	watch: {
		/**
		 * Re-bind to a freshly resolved record and reload its versions.
		 *
		 * @param {object} next The new Application record.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		object(next) {
			if (next) {
				this.application = next
				this.loadVersions()
			}
		},

		'$route.query._version': function (newSlug) {
			if (!newSlug) {
				if (this.productionVersionUuid)
					this.selectedVersionUuid = this.productionVersionUuid
				return
			}
			const match = this.orderedVersions.find((v) => v.slug === newSlug)
			if (match) this.selectedVersionUuid = match.uuid
		},
	},

	/**
	 * Fetch the Application + versions on mount.
	 *
	 * @return {void}
	 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
	 */
	mounted() {
		if (!this.application) {
			this.refreshApplication()
		} else {
			this.loadVersions()
		}
	},

	methods: {
		/**
		 * Re-load the Application record by `objectId`.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		async refreshApplication() {
			const uuid = requestedApplicationId(this)
			if (!uuid) return

			// Staleness token: if the uuid changes while a request is in flight
			// (route change), the older response must not overwrite the newer
			// record — latest-request-wins, not last-response-wins.
			const seq = nextApplicationRequest(this)

			try {
				// Shared with ApplicationDetailDashboard so the two components —
				// and this component's own three triggers — collapse onto ONE
				// request instead of ten (#49).
				const record = await fetchApplicationRecord(uuid)
				if (seq !== this._appReqSeq) return // superseded — drop it
				this.application = record
				this.loadVersions()
			} catch (e) {
				if (seq !== this._appReqSeq) return
				this.error = asError(e)
			}
		},

		/**
		 * The version the route's `_version` query names.
		 *
		 * @return {object|undefined} The version.
		 */
		versionFromRoute() {
			const slug = this.$route?.query?._version
			return slug
				? this.orderedVersions.find((v) => v.slug === slug)
				: undefined
		},

		/**
		 * Select the route's version, else production, else the first; else keep the selection.
		 *
		 * @return {void}
		 */
		pickVersion() {
			const match = this.versionFromRoute()
			if (match) {
				this.selectedVersionUuid = match.uuid
			} else if (this.productionVersionUuid) {
				this.selectedVersionUuid = this.productionVersionUuid
			} else if (this.orderedVersions[0]) {
				this.selectedVersionUuid = this.orderedVersions[0].uuid
			}
		},

		/**
		 * Runs after the versions loaded and one was picked.
		 *
		 * @return {void}
		 */
		onVersionsLoaded() {},

		/**
		 * Load the application's versions and pick one.
		 *
		 * @return {Promise<void>}
		 */
		async loadVersions() {
			if (!this.appSlug) return
			try {
				const url = generateUrl(
					`/apps/buildiq/api/applications/${encodeURIComponent(this.appSlug)}/versions`,
				)
				const { data } = await axios.get(url)
				this.versions = versionsFrom(data)
				this.pickVersion()
				this.onVersionsLoaded()
			} catch (e) {
				this.error = e instanceof Error ? e : new Error(String(e))
			}
		},
	},
}
