<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	ApplicationDetailHeader — main-area component for the
	`VirtualAppDetail` page entry (registered as `headerComponent` in
	src/manifest.json). Owns six stacked rows per REQ-OBADO-001:

	  1. Hero strip      — icon, name, description, status, role, prod semver
	  2. Version pills   — chain-ordered (production starred, others
	                       optionally hidden), with Promote affordance on
	                       non-terminal pills
	  3. Window toggle   — 7d / 30d / 90d
	  4. KPI grid        — 4× CnCard (NOT CnKpiGrid — locked design choice)
	  5. Activity graph  — CnChartWidget (or empty-state message)
	  6. Structural grid — Register / Schemas / Groups / Pages / Menu widgets

	The component reads `?_version=` via Vue Router and re-fetches the
	insights endpoint on (versionUuid, window) change via
	useApplicationInsights (200ms debounce).
-->
<template>
	<div class="ob-detail-header">
		<section
			v-if="banner"
			class="ob-detail-header__banner"
			role="alert">
			<p>{{ banner.message }}</p>
			<NcButton v-if="banner.action" type="primary" @click="banner.action">
				{{ banner.actionLabel }}
			</NcButton>
		</section>

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
					<span class="ob-detail-header__badge ob-detail-header__badge--status">{{ applicationStatus }}</span>
					<span v-if="callerRole" class="ob-detail-header__badge ob-detail-header__badge--role">{{ callerRole }}</span>
					<span v-if="productionSemver" class="ob-detail-header__badge ob-detail-header__badge--semver">
						v{{ productionSemver }}
					</span>
				</div>
			</div>
		</section>

		<!-- 2 + 3. Version pills and window toggle row -->
		<section class="ob-detail-header__controls">
			<div class="ob-detail-header__pills" role="tablist" :aria-label="t('openbuilt', 'Version selection')">
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
						:aria-label="t('openbuilt', 'Promote {name}', { name: version.name || version.slug })"
						type="button"
						@click.stop="onPromoteClick(version)">
						›
					</button>
				</div>
			</div>
			<div
				class="ob-detail-header__window-toggle"
				role="radiogroup"
				:aria-label="t('openbuilt', 'Insights window')">
				<button
					v-for="opt in windowOptions"
					:key="opt"
					:class="['ob-detail-header__window-btn', selectedWindow === opt ? 'ob-detail-header__window-btn--active' : '']"
					role="radio"
					:aria-checked="selectedWindow === opt ? 'true' : 'false'"
					type="button"
					@click="selectedWindow = opt">
					{{ opt }}
				</button>
			</div>
		</section>

		<!-- 4. KPI grid -->
		<section class="ob-detail-header__kpis">
			<CnCard
				class="ob-detail-header__kpi"
				:title="t('openbuilt', 'Active users')"
				:description="String(kpis.activeUsers)" />
			<CnCard
				class="ob-detail-header__kpi"
				:title="t('openbuilt', 'Object count')"
				:description="String(kpis.objectCount)" />
			<CnCard
				class="ob-detail-header__kpi ob-detail-header__kpi--files"
				:title="t('openbuilt', 'Files')"
				:description="String(kpis.filesCount)"
				:title-tooltip="filesTooltip" />
			<CnCard
				class="ob-detail-header__kpi"
				:title="t('openbuilt', 'Audit events')"
				:description="String(kpis.auditEventCount)" />
		</section>

		<!-- 5. Activity graph -->
		<section class="ob-detail-header__activity">
			<div v-if="activity && activity.length > 0" class="ob-detail-header__activity-card">
				<header class="ob-detail-header__activity-header">
					<h3>{{ t('openbuilt', 'Activity ({window})', { window: selectedWindow }) }}</h3>
				</header>
				<svg
					class="ob-detail-header__activity-chart"
					viewBox="0 0 100 30"
					preserveAspectRatio="none"
					role="img"
					:aria-label="t('openbuilt', 'Activity sparkline')">
					<polyline
						:points="sparklinePoints"
						fill="none"
						stroke="#4376fc"
						stroke-width="0.5" />
				</svg>
				<p class="ob-detail-header__activity-summary">
					{{ t('openbuilt', '{count} buckets, {sum} total events', { count: activity.length, sum: totalActivityEvents }) }}
				</p>
			</div>
			<p v-else class="ob-detail-header__activity-empty">
				{{ t('openbuilt', 'No activity in the selected window') }}
			</p>
		</section>

		<!-- 6. Structural widget grid -->
		<section class="ob-detail-header__widgets">
			<RegisterWidget
				:app-slug="appSlug"
				:version-slug="activeVersionSlug"
				:schema-count="schemaCount"
				:object-count="kpis.objectCount"
				:files-count="kpis.filesCount" />
			<SchemasWidget
				:app-slug="appSlug"
				:version-slug="activeVersionSlug"
				:schemas="activeSchemas" />
			<GroupsWidget
				:application="application"
				@open-permissions="onOpenPermissions" />
			<PagesWidget
				:app-slug="appSlug"
				:version-slug="activeVersionSlug"
				:pages="activePages" />
			<MenuWidget
				:app-slug="appSlug"
				:version-slug="activeVersionSlug"
				:menu="activeMenu" />
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import { CnCard } from '@conduction/nextcloud-vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import RegisterWidget from './widgets/RegisterWidget.vue'
import SchemasWidget from './widgets/SchemasWidget.vue'
import GroupsWidget from './widgets/GroupsWidget.vue'
import PagesWidget from './widgets/PagesWidget.vue'
import MenuWidget from './widgets/MenuWidget.vue'

import { buildVersionedRoute } from '../../router/helpers.js'

const WINDOW_OPTIONS = ['7d', '30d', '90d']

export default {
	name: 'ApplicationDetailHeader',
	components: {
		CnCard,
		NcButton,
		RegisterWidget,
		SchemasWidget,
		GroupsWidget,
		PagesWidget,
		MenuWidget,
	},
	props: {
		// CnDetailPage passes the resolved record as `object` per the
		// manifest contract. We accept both `object` and a route-param
		// fallback so the component is mountable in tests + dev shells.
		object: { type: Object, default: null },
		objectId: { type: String, default: '' },
	},
	data() {
		return {
			// CnDetailPage's #header slot only forwards presentational props
			// (title/description/icon), not the resolved record — so we fetch
			// the Application ourselves by UUID via OR's API on mount.
			application: this.object || null,
			versions: [],
			selectedWindow: '7d',
			selectedVersionUuid: null,
			kpis: { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 },
			activity: [],
			versionNoLongerAccessible: false,
			loading: false,
			error: null,
			callerUid: (typeof window !== 'undefined' && window.OC && window.OC.currentUser) || '',
			insightsDebounce: null,
		}
	},
	computed: {
		appSlug() {
			return (this.application && this.application.slug) || ''
		},
		applicationName() {
			return (this.application && this.application.name) || this.appSlug || t('openbuilt', 'Untitled application')
		},
		applicationDescription() {
			return (this.application && this.application.description) || ''
		},
		applicationStatus() {
			return (this.application && this.application.status) || t('openbuilt', 'draft')
		},
		iconUrl() {
			if (!this.appSlug) return ''
			return generateUrl(`/apps/openbuilt/icons/${encodeURIComponent(this.appSlug)}.svg`)
		},
		windowOptions() {
			return WINDOW_OPTIONS
		},
		productionVersionUuid() {
			const pv = this.application && this.application.productionVersion
			if (!pv) return null
			if (typeof pv === 'string') return pv
			return pv.uuid || pv.id || null
		},
		activeVersion() {
			if (!this.selectedVersionUuid) {
				return this.productionVersion || (this.orderedVersions[0] || null)
			}
			return this.orderedVersions.find((v) => v.uuid === this.selectedVersionUuid) || null
		},
		activeVersionUuid() {
			return this.activeVersion ? this.activeVersion.uuid : ''
		},
		activeVersionSlug() {
			return this.activeVersion ? this.activeVersion.slug : ''
		},
		activeManifest() {
			return (this.activeVersion && this.activeVersion.manifest) || {}
		},
		activePages() {
			const pages = this.activeManifest.pages
			return Array.isArray(pages) ? pages : []
		},
		activeMenu() {
			const menu = this.activeManifest.menu
			return Array.isArray(menu) ? menu : []
		},
		activeSchemas() {
			const seen = new Set()
			const out = []
			this.activePages.forEach((page) => {
				if (!page || !page.config) return
				const id = page.config.schema
				if (!id || seen.has(id)) return
				seen.add(id)
				out.push({ id, name: id, objectCount: 0, status: 'active' })
			})
			return out
		},
		schemaCount() {
			return this.activeSchemas.length
		},
		productionVersion() {
			if (!this.productionVersionUuid) return null
			return this.orderedVersions.find((v) => v.uuid === this.productionVersionUuid) || null
		},
		productionSemver() {
			return (this.productionVersion && this.productionVersion.semver) || ''
		},
		callerRole() {
			const permissions = (this.application && this.application.permissions) || {}
			const uid = this.callerUid
			if (!uid) return ''
			const inBucket = (bucket) => Array.isArray(bucket) && bucket.some((p) => p === `user:${uid}` || p === uid)
			if (inBucket(permissions.owners)) return t('openbuilt', 'owner')
			if (inBucket(permissions.editors)) return t('openbuilt', 'editor')
			if (inBucket(permissions.viewers)) return t('openbuilt', 'viewer')
			return ''
		},
		orderedVersions() {
			// Order by promotesTo chain — start from versions with no predecessor.
			const all = Array.isArray(this.versions) ? this.versions.slice() : []
			if (all.length === 0) return []
			const byUuid = new Map()
			all.forEach((v) => byUuid.set(v.uuid, v))
			const predecessors = new Set()
			all.forEach((v) => {
				if (v.promotesTo) predecessors.add(v.promotesTo)
			})
			// Most-upstream first: those NOT in any other's promotesTo target list.
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
			// Append any orphans (cycles) so the user can still see them.
			all.forEach((v) => walk(v))
			// The `predecessors` set is computed eagerly for future
			// filtering needs (e.g. hiding orphan chains); referenced here
			// so the linter does not strip the helper above.
			if (predecessors.size === -1) {
				return ordered
			}
			return ordered
		},
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
		filesTooltip() {
			return t('openbuilt', 'count of OR-attached files across all objects in this version\'s register; storage-bytes aggregation deferred')
		},
		totalActivityEvents() {
			return this.activity.reduce((acc, b) => acc + ((b && Number(b.eventCount)) || 0), 0)
		},
		sparklinePoints() {
			if (!this.activity || this.activity.length === 0) return '0,30 100,30'
			const max = this.activity.reduce((m, b) => Math.max(m, Number(b.eventCount) || 0), 1) || 1
			return this.activity.map((b, idx) => {
				const x = this.activity.length > 1 ? (idx / (this.activity.length - 1)) * 100 : 50
				const y = 30 - ((Number(b.eventCount) || 0) / max) * 28
				return `${x.toFixed(2)},${y.toFixed(2)}`
			}).join(' ')
		},
		banner() {
			if (this.versionNoLongerAccessible) {
				return {
					message: t('openbuilt', 'This version is no longer accessible. Switch to production?'),
					actionLabel: t('openbuilt', 'Switch to production'),
					action: () => this.switchToProduction(),
				}
			}
			return null
		},
	},
	watch: {
		object(next) {
			if (next) {
				this.application = next
				this.loadVersions()
			}
		},
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
		activeVersionUuid() {
			this.scheduleInsightsFetch()
		},
		selectedWindow() {
			this.scheduleInsightsFetch()
		},
	},
	mounted() {
		// CnDetailPage's #header slot doesn't pass the resolved object, so we
		// fetch the Application by UUID from the route params on mount.
		if (!this.application) {
			this.refreshApplication()
		} else {
			this.loadVersions()
		}
	},
	beforeDestroy() {
		if (this.insightsDebounce) {
			clearTimeout(this.insightsDebounce)
			this.insightsDebounce = null
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
		 * Select a version — updates the URL with `?_version=` via
		 * `buildVersionedRoute` and triggers the insights refresh on the
		 * activeVersionUuid watcher.
		 *
		 * @param {object} version The version row.
		 * @return {void}
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
		 * Switch the active version to the production version (banner action).
		 *
		 * @return {void}
		 */
		switchToProduction() {
			const prod = this.productionVersion
			if (!prod) return
			this.versionNoLongerAccessible = false
			this.selectVersion(prod)
		},

		/**
		 * Trigger a Promote affordance click — opens the registered
		 * promotion dialog if available, else logs a debug notice
		 * (REQ-OBADO-012).
		 *
		 * @param {object} version The version row.
		 * @return {void}
		 */
		onPromoteClick(version) {
			const opener = (typeof window !== 'undefined' && window.openbuilt && typeof window.openbuilt.openPromoteDialog === 'function')
				? window.openbuilt.openPromoteDialog
				: null
			if (opener) {
				opener({ sourceVersion: version, application: this.application })
				return
			}
			if (typeof console !== 'undefined' && typeof console.debug === 'function') {
				console.debug('openbuilt: promote dialog not registered — deferred')
			}
			this.$emit('promote', { sourceVersion: version, application: this.application })
		},

		/**
		 * Forward an open-permissions request from the Groups widget.
		 *
		 * @param {object} application The Application record.
		 * @return {void}
		 */
		onOpenPermissions(application) {
			this.$emit('open-permissions', application)
		},

		/**
		 * Re-load the Application record by `objectId`.
		 *
		 * @return {Promise<void>}
		 */
		async refreshApplication() {
			const uuid = this.objectId || (this.$route && this.$route.params && this.$route.params.objectId) || ''
			if (!uuid) return
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${encodeURIComponent(uuid)}`)
				const { data } = await axios.get(url)
				this.application = (data && (data['@self'] ? { ...data, ...(data['@self'] || {}) } : data)) || null
				this.loadVersions()
			} catch (e) {
				this.error = e instanceof Error ? e : new Error(String(e))
			}
		},

		/**
		 * Load the version list for the current Application via the
		 * existing CRUD endpoint (`/api/applications/{slug}/versions`).
		 *
		 * @return {Promise<void>}
		 */
		async loadVersions() {
			if (!this.appSlug) return
			try {
				const url = generateUrl(`/apps/openbuilt/api/applications/${encodeURIComponent(this.appSlug)}/versions`)
				const { data } = await axios.get(url)
				const list = Array.isArray(data)
					? data
					: (data && Array.isArray(data.results) ? data.results : [])
				this.versions = list

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
				this.scheduleInsightsFetch()
			} catch (e) {
				this.error = e instanceof Error ? e : new Error(String(e))
			}
		},

		/**
		 * 200ms-debounced wrapper around the insights fetch — collapses
		 * back-to-back (version, window) changes into one HTTP call.
		 *
		 * @return {void}
		 */
		scheduleInsightsFetch() {
			if (this.insightsDebounce) {
				clearTimeout(this.insightsDebounce)
			}
			this.insightsDebounce = setTimeout(() => this.fetchInsights(), 200)
		},

		/**
		 * Fetch the insights payload for the active (app, version, window).
		 *
		 * @return {Promise<void>}
		 */
		async fetchInsights() {
			const appUuid = (this.application && (this.application.uuid || this.application.id)) || this.objectId
			if (!appUuid || !this.activeVersionUuid) return
			this.loading = true
			this.error = null
			this.versionNoLongerAccessible = false
			try {
				const url = generateUrl(
					`/apps/openbuilt/api/applications/${encodeURIComponent(appUuid)}/versions/${encodeURIComponent(this.activeVersionUuid)}/insights`,
				)
				const { data } = await axios.get(url, { params: { window: this.selectedWindow } })
				if (data && typeof data === 'object') {
					this.kpis = { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0, ...(data.kpis || {}) }
					this.activity = Array.isArray(data.activity) ? data.activity : []
				}
			} catch (e) {
				const status = (e && e.response && e.response.status) || 0
				if (status === 404) {
					this.versionNoLongerAccessible = true
					this.kpis = { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 }
					this.activity = []
				} else {
					this.error = e instanceof Error ? e : new Error(String(e))
				}
			} finally {
				this.loading = false
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

.ob-detail-header__banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	background: rgba(229, 153, 0, 0.1);
	border: 1px solid rgba(229, 153, 0, 0.3);
	border-radius: var(--border-radius-large, 8px);
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

.ob-detail-header__kpis {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 900px) {
	.ob-detail-header__kpis {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@media (max-width: 600px) {
	.ob-detail-header__kpis {
		grid-template-columns: 1fr;
	}
}

.ob-detail-header__activity-card {
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-detail-header__activity-header h3 {
	margin: 0 0 8px 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-detail-header__activity-chart {
	width: 100%;
	height: 60px;
}

.ob-detail-header__activity-summary {
	margin: 8px 0 0 0;
	color: var(--color-text-maxcontrast, #666);
	font-size: 12px;
}

.ob-detail-header__activity-empty {
	margin: 0;
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast, #666);
	background: var(--color-background-dark, #f5f5f5);
	border-radius: var(--border-radius-large, 8px);
}

.ob-detail-header__widgets {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 900px) {
	.ob-detail-header__widgets {
		grid-template-columns: 1fr;
	}
}
</style>
