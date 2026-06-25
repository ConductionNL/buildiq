<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	ApplicationDetailHeader — the IDENTITY + CONTROLS header for the
	`VirtualAppDetail` page (registered as `headerComponent` in src/manifest.json,
	rendered above the action-menu line). It owns three rows:

	  1. Hero strip    — icon, name, description, type/status/role/semver badges
	  2. Version pills — chain-ordered (production starred), Promote affordance
	  3. Window toggle — 7d / 30d / 90d (drives the body KPI/activity widgets)

	The analytics that this component used to render (KPI grid, activity graph,
	structural Register/Schemas/Groups/Pages/Menu widgets, and the
	"version no longer accessible" banner) now live in
	[ApplicationDetailDashboard], mounted in CnDetailPage's `#before-body` slot so
	they render in the page BODY (below the action line) as a proper grid — this
	page is now grid-built. The two components coordinate without prop-drilling:
	version selection via the `?_version=` URL query (written by the pills here),
	and the insights window via the shared `useInsightsWindow` singleton (driven
	by the toggle here).

	@spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
-->
<template>
	<div class="ob-detail-header">
		<!-- 1. Hero strip -->
		<section class="ob-detail-header__hero">
			<img
				v-if="iconUrl"
				:src="iconUrl"
				class="ob-detail-header__icon"
				alt="">
			<div class="ob-detail-header__hero-text">
				<h1 class="ob-detail-header__name">
					{{ applicationName }}
				</h1>
				<p v-if="applicationDescription" class="ob-detail-header__description">
					{{ applicationDescription }}
				</p>
				<div class="ob-detail-header__hero-meta">
					<span
						class="ob-detail-header__badge ob-detail-header__badge--type"
						:class="`ob-detail-header__badge--type-${appTypeKey}`">{{ appTypeLabel }}</span>
					<span class="ob-detail-header__badge ob-detail-header__badge--status">{{ applicationStatus }}</span>
					<span v-if="callerRole" class="ob-detail-header__badge ob-detail-header__badge--role">{{ callerRole }}</span>
					<span v-if="productionSemver" class="ob-detail-header__badge ob-detail-header__badge--semver">
						v{{ productionSemver }}
					</span>
				</div>
				<p v-if="isHybrid" class="ob-detail-header__hybrid-note">
					{{ t('openbuild', 'This is a hybrid app — its name and id mirror the installed Nextcloud app and are read-only. You can still customize its pages, widgets, and menu.') }}
				</p>
				<!-- Hybrid apps ARE the live installed Nextcloud app — surface a
				     direct "Open app" link so it's obvious it's accessible. -->
				<p v-if="isHybrid && installedAppUrl" class="ob-detail-header__open-app">
					<a class="ob-detail-header__open-app-link" :href="installedAppUrl">
						<OpenInNew :size="16" class="ob-detail-header__open-app-icon" />
						{{ t('openbuild', 'Open {name}', { name: applicationName }) }}
					</a>
				</p>
			</div>
		</section>

		<!-- 2 + 3. Version pills and window toggle row -->
		<section class="ob-detail-header__controls">
			<div class="ob-detail-header__pills" role="tablist" :aria-label="t('openbuild', 'Version selection')">
				<div
					v-for="version in visibleVersions"
					:key="version.uuid"
					class="ob-detail-header__pill-group">
					<button
						:class="['ob-detail-header__pill', isActiveVersion(version) ? 'ob-detail-header__pill--active' : '']"
						role="tab"
						:aria-selected="isActiveVersion(version) ? 'true' : 'false'"
						type="button"
						@click="selectVersion(version)">
						<span v-if="isProductionVersion(version)" class="ob-detail-header__pill-star">*</span>
						{{ version.name || version.slug }}
					</button>
					<button
						v-if="hasPromoteTarget(version)"
						class="ob-detail-header__pill-promote"
						:aria-label="t('openbuild', 'Promote {name}', { name: version.name || version.slug })"
						type="button"
						@click.stop="onPromoteClick(version)">
						›
					</button>
				</div>
			</div>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

import { buildVersionedRoute } from '../../router/helpers.js'

export default {
	name: 'ApplicationDetailHeader',
	components: { OpenInNew },
	props: {
		// CnDetailPage passes the resolved record as `object` per the
		// manifest contract. We accept both `object` and a route-param
		// fallback so the component is mountable in tests + dev shells.
		object: { type: Object, default: null },
		objectId: { type: String, default: '' },
	},
	/**
	 * Local component state. The insights time-range control now lives in the
	 * body dashboard (ApplicationDetailDashboard), not the header.
	 */
	data() {
		return {
			// CnDetailPage's #header slot only forwards presentational props
			// (title/description/icon), not the resolved record — so we fetch
			// the Application ourselves by UUID via OR's API on mount.
			application: this.object || null,
			versions: [],
			selectedVersionUuid: null,
			error: null,
			callerUid: (typeof window !== 'undefined' && window.OC && window.OC.currentUser) || '',
		}
	},
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
		 * Display name of the application.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		applicationName() {
			return (this.application && this.application.name) || this.appSlug || t('openbuild', 'Untitled application')
		},
		/**
		 * Application description.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		applicationDescription() {
			return (this.application && this.application.description) || ''
		},
		/**
		 * Application status label.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		applicationStatus() {
			return (this.application && this.application.status) || t('openbuild', 'draft')
		},
		/**
		 * The app's type discriminator (unify-apps-with-app-type). Absent reads
		 * as 'virtual' (legacy default), matching the schema.
		 *
		 * @return {string} 'virtual' | 'hybrid'
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		appTypeKey() {
			return (this.application && this.application.appType) === 'hybrid' ? 'hybrid' : 'virtual'
		},
		/**
		 * Human-readable label for the app-type badge.
		 *
		 * @return {string}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		appTypeLabel() {
			return this.appTypeKey === 'hybrid' ? t('openbuild', 'Hybrid') : t('openbuild', 'Virtual')
		},
		/**
		 * Whether this is a hybrid app whose identity metadata (name/slug) is
		 * read-only — it mirrors the installed Nextcloud app it customizes.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		isHybrid() {
			return this.appTypeKey === 'hybrid'
		},
		/**
		 * URL of the app's icon SVG.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		iconUrl() {
			if (!this.appSlug) return ''
			return generateUrl(`/apps/openbuild/icons/${encodeURIComponent(this.appSlug)}.svg`)
		},
		/**
		 * URL of the live installed Nextcloud app a hybrid app mirrors. A hybrid
		 * app's slug equals the installed app id, so it is always reachable at
		 * `/apps/{slug}`. Empty for virtual apps (not installed NC apps).
		 *
		 * @return {string}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		installedAppUrl() {
			if (this.isHybrid === false || !this.appSlug) return ''
			return generateUrl(`/apps/${encodeURIComponent(this.appSlug)}/`)
		},
		/**
		 * Production version UUID resolved from the Application record.
		 *
		 * @return {string|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		productionVersionUuid() {
			const pv = this.application && this.application.productionVersion
			if (!pv) return null
			if (typeof pv === 'string') return pv
			return pv.uuid || pv.id || null
		},
		/**
		 * Currently active version (selected, or production, or first).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		activeVersion() {
			if (!this.selectedVersionUuid) {
				return this.productionVersion || (this.orderedVersions[0] || null)
			}
			return this.orderedVersions.find((v) => v.uuid === this.selectedVersionUuid) || null
		},
		/**
		 * Active version UUID.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		activeVersionUuid() {
			return this.activeVersion ? this.activeVersion.uuid : ''
		},
		/**
		 * The production version row (for the chain/star resolution).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		productionVersion() {
			if (!this.productionVersionUuid) return null
			return this.orderedVersions.find((v) => v.uuid === this.productionVersionUuid) || null
		},
		/**
		 * Semver of the production version (badge in the hero).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		productionSemver() {
			return (this.productionVersion && this.productionVersion.semver) || ''
		},
		/**
		 * The caller's role on this application (owner / editor / viewer).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		callerRole() {
			const permissions = (this.application && this.application.permissions) || {}
			const uid = this.callerUid
			if (!uid) return ''
			const inBucket = (bucket) => Array.isArray(bucket) && bucket.some((p) => p === `user:${uid}` || p === uid)
			if (inBucket(permissions.owners)) return t('openbuild', 'owner')
			if (inBucket(permissions.editors)) return t('openbuild', 'editor')
			if (inBucket(permissions.viewers)) return t('openbuild', 'viewer')
			return ''
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
			const roots = all.filter((v) => !all.some((u) => u.promotesTo === v.uuid))
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
		 * Versions visible as pills — production is always shown; non-production
		 * versions are shown to editors/owners only.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		visibleVersions() {
			const uid = this.callerUid
			const permissions = (this.application && this.application.permissions) || {}
			const inEditorOrOwner = (bucket) => Array.isArray(bucket) && bucket.some((p) => p === `user:${uid}` || p === uid)
			const isEditorOrOwner = inEditorOrOwner(permissions.editors) || inEditorOrOwner(permissions.owners)
			return this.orderedVersions.filter((v) => {
				if (v.uuid === this.productionVersionUuid) return true
				return isEditorOrOwner
			})
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
		/**
		 * Re-load when the route's objectId changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		objectId() {
			this.refreshApplication()
		},
		'$route.query._version'(newSlug) {
			if (!newSlug) {
				if (this.productionVersionUuid) this.selectedVersionUuid = this.productionVersionUuid
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
		 * Check whether a given version is the currently selected one.
		 *
		 * @param {object} version The version row.
		 * @return {boolean}
		 */
		isActiveVersion(version) {
			return this.activeVersionUuid === version.uuid
		},

		/**
		 * Check whether the version is the production version (asterisked pill).
		 *
		 * @param {object} version The version row.
		 * @return {boolean}
		 */
		isProductionVersion(version) {
			return this.productionVersionUuid && version.uuid === this.productionVersionUuid
		},

		/**
		 * Check whether the version has a downstream `promotesTo` target —
		 * controls Promote button visibility (REQ-OBADO-012).
		 *
		 * @param {object} version The version row.
		 * @return {boolean}
		 */
		hasPromoteTarget(version) {
			return Boolean(version && version.promotesTo)
		},

		/**
		 * Select a version — updates the URL with `?_version=` so both this
		 * header and the body dashboard re-resolve the active version.
		 *
		 * @param {object} version The version row.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		selectVersion(version) {
			if (!version || !version.slug) return
			this.selectedVersionUuid = version.uuid
			const route = buildVersionedRoute(
				this.$route ? this.$route.name : 'VirtualAppDetail',
				this.$route ? this.$route.params : {},
				version.slug,
			)
			if (this.$router) {
				this.$router.replace(route).catch(() => { /* ignore duplicate nav */ })
			}
		},

		/**
		 * Trigger a Promote affordance click — opens the registered
		 * promotion dialog if available (REQ-OBADO-012).
		 *
		 * @param {object} version The version row.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		onPromoteClick(version) {
			const opener = (typeof window !== 'undefined' && window.openbuild && typeof window.openbuild.openPromoteDialog === 'function')
				? window.openbuild.openPromoteDialog
				: null
			if (opener) {
				opener({ sourceVersion: version, application: this.application })
				return
			}
			if (typeof console !== 'undefined' && typeof console.debug === 'function') {
				console.debug('openbuild: promote dialog not registered — deferred')
			}
			this.$emit('promote', { sourceVersion: version, application: this.application })
		},

		/**
		 * Re-load the Application record by `objectId`.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		async refreshApplication() {
			const uuid = this.objectId || (this.$route && this.$route.params && this.$route.params.objectId) || ''
			if (!uuid) return
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuild/application/${encodeURIComponent(uuid)}`)
				const { data } = await axios.get(url)
				// Keep user-visible fields from `data` and stash OR's internal
				// metadata block separately (see issue #73).
				this.application = data
					? { ...data, '@self': data['@self'] || {} }
					: null
				this.loadVersions()
			} catch (e) {
				this.error = e instanceof Error ? e : new Error(String(e))
			}
		},

		/**
		 * Load the version list for the current Application.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-1
		 */
		async loadVersions() {
			if (!this.appSlug) return
			try {
				const url = generateUrl(`/apps/openbuild/api/applications/${encodeURIComponent(this.appSlug)}/versions`)
				const { data } = await axios.get(url)
				const list = Array.isArray(data)
					? data
					: (data && Array.isArray(data.results) ? data.results : [])
				this.versions = list.map((v) => ({
					...v,
					uuid: v.uuid || v.id || (v['@self'] && v['@self'].id) || null,
				}))

				const versionSlugFromRoute = (this.$route && this.$route.query && this.$route.query._version) || ''
				const match = versionSlugFromRoute
					? this.orderedVersions.find((v) => v.slug === versionSlugFromRoute)
					: null
				if (match) {
					this.selectedVersionUuid = match.uuid
				} else if (this.productionVersionUuid) {
					this.selectedVersionUuid = this.productionVersionUuid
				} else if (this.orderedVersions[0]) {
					this.selectedVersionUuid = this.orderedVersions[0].uuid
				}
			} catch (e) {
				this.error = e instanceof Error ? e : new Error(String(e))
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-detail-header {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 24px;
}

.ob-detail-header__hero {
	display: flex;
	align-items: center;
	gap: 16px;
}

.ob-detail-header__icon {
	width: 64px;
	height: 64px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-dark, #eee);
}

.ob-detail-header__name {
	margin: 0;
	font-size: 24px;
	font-weight: 600;
}

.ob-detail-header__description {
	margin: 4px 0 0 0;
	color: var(--color-text-maxcontrast, #666);
}

.ob-detail-header__hero-meta {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.ob-detail-header__badge {
	font-size: 12px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-dark, #eee);
}

.ob-detail-header__badge--status {
	background: rgba(67, 118, 252, 0.15);
	color: #2e5ed9;
}

.ob-detail-header__badge--role {
	background: rgba(120, 120, 120, 0.15);
	color: #555;
}

.ob-detail-header__badge--semver {
	background: rgba(46, 184, 102, 0.15);
	color: #246b3d;
}

.ob-detail-header__badge--type-virtual {
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element, #0082c9);
}

.ob-detail-header__badge--type-hybrid {
	background: rgba(120, 120, 120, 0.18);
	color: #444;
}

.ob-detail-header__hybrid-note {
	margin: 8px 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #888);
}

.ob-detail-header__open-app {
	margin: 10px 0 0;
}

.ob-detail-header__open-app-link {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px;
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-primary-element, #4376fc);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
	font-size: 13px;
	text-decoration: none;
}

.ob-detail-header__open-app-link:hover {
	background: var(--color-primary-element-hover, #3568e6);
}

.ob-detail-header__open-app-icon {
	display: inline-flex;
}

.ob-detail-header__controls {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.ob-detail-header__pills {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}

.ob-detail-header__pill-group {
	display: inline-flex;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 999px;
	overflow: hidden;
}

.ob-detail-header__pill {
	padding: 6px 12px;
	background: transparent;
	border: 0;
	cursor: pointer;
	font-size: 13px;
	font-weight: 500;
}

.ob-detail-header__pill--active {
	background: var(--color-primary-element, #4376fc);
	color: var(--color-primary-element-text, #fff);
}

.ob-detail-header__pill-promote {
	padding: 6px 10px;
	background: transparent;
	border: 0;
	border-left: 1px solid var(--color-border, #ddd);
	cursor: pointer;
}

.ob-detail-header__pill-star {
	font-weight: 700;
	margin-right: 2px;
}

.ob-detail-header__window-toggle {
	display: inline-flex;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 999px;
	overflow: hidden;
}

.ob-detail-header__window-btn {
	padding: 6px 12px;
	background: transparent;
	border: 0;
	cursor: pointer;
	font-size: 13px;
}

.ob-detail-header__window-btn--active {
	background: var(--color-primary-element, #4376fc);
	color: var(--color-primary-element-text, #fff);
}
</style>
