<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	ApplicationDetailDashboard — the grid-built body of the `VirtualAppDetail`
	page. Mounted in CnDetailPage's `#before-body` slot (registered via the
	manifest `slots["before-body"]`) so it renders in the page BODY, below the
	header + action-menu line and above the auto Data / Related sections — rather
	than crammed into the header above the action line (the old layout).

	Owns three stacked rows (the analytics that used to live in
	ApplicationDetailHeader rows 4–6):

	  1. Banner          — "version no longer accessible" (insights 404)
	  2. KPI grid        — 4× CnCard (Active users / Object count / Files / Audit)
	  3. Activity graph  — sparkline (or empty-state message)
	  4. Structural grid — Register / Schemas / Groups / Pages / Menu widgets

	Version selection is read from `?_version=` (written by the header's version
	pills); the insights window is read from the shared `useInsightsWindow`
	singleton (driven by the header's 7d/30d/90d toggle). Insights re-fetch on
	(versionUuid, window) change with a 200ms debounce.

	@spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
-->
<template>
	<div class="ob-detail-dashboard">
		<!-- 1. Banner -->
		<section
			v-if="banner"
			class="ob-detail-dashboard__banner"
			role="alert">
			<p>{{ banner.message }}</p>
			<NcButton v-if="banner.action" type="primary" @click="banner.action">
				{{ banner.actionLabel }}
			</NcButton>
		</section>

		<!-- 2. KPI grid -->
		<section class="ob-detail-dashboard__kpis">
			<CnCard
				class="ob-detail-dashboard__kpi"
				:title="t('openbuild', 'Active users')"
				:description="String(kpis.activeUsers)" />
			<CnCard
				class="ob-detail-dashboard__kpi"
				:title="t('openbuild', 'Object count')"
				:description="String(kpis.objectCount)" />
			<CnCard
				class="ob-detail-dashboard__kpi ob-detail-dashboard__kpi--files"
				:title="t('openbuild', 'Files')"
				:description="String(kpis.filesCount)"
				:title-tooltip="filesTooltip" />
			<CnCard
				class="ob-detail-dashboard__kpi"
				:title="t('openbuild', 'Audit events')"
				:description="String(kpis.auditEventCount)" />
		</section>

		<!-- 3. Activity graph -->
		<section class="ob-detail-dashboard__activity">
			<div v-if="activity && activity.length > 0" class="ob-detail-dashboard__activity-card">
				<header class="ob-detail-dashboard__activity-header">
					<h3>{{ t('openbuild', 'Activity ({window})', { window: selectedWindow }) }}</h3>
				</header>
				<svg
					class="ob-detail-dashboard__activity-chart"
					viewBox="0 0 100 30"
					preserveAspectRatio="none"
					role="img"
					:aria-label="t('openbuild', 'Activity sparkline')">
					<polyline
						:points="sparklinePoints"
						fill="none"
						stroke="#4376fc"
						stroke-width="0.5" />
				</svg>
				<p class="ob-detail-dashboard__activity-summary">
					{{ t('openbuild', '{count} buckets, {sum} total events', { count: activity.length, sum: totalActivityEvents }) }}
				</p>
			</div>
			<p v-else class="ob-detail-dashboard__activity-empty">
				{{ t('openbuild', 'No activity in the selected window') }}
			</p>
		</section>

		<!-- 4. Structural widget grid -->
		<section class="ob-detail-dashboard__widgets">
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
import { useInsightsWindow } from '../../composables/useInsightsWindow.js'

export default {
	name: 'ApplicationDetailDashboard',
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
		// CnDetailPage's #before-body slot forwards the resolved record as
		// `object` plus the route-resolved `objectId`.
		object: { type: Object, default: null },
		objectId: { type: String, default: '' },
	},
	/**
	 * Expose the shared insights-window ref (driven by the header toggle) so the
	 * KPI/activity widgets re-fetch when the user changes 7d/30d/90d.
	 *
	 * @return {{ selectedWindow: import('vue').Ref<string> }}
	 */
	setup() {
		const { selectedWindow } = useInsightsWindow()
		return { selectedWindow }
	},
	data() {
		return {
			application: this.object || null,
			versions: [],
			selectedVersionUuid: null,
			kpis: { activeUsers: 0, objectCount: 0, filesCount: 0, auditEventCount: 0 },
			activity: [],
			versionNoLongerAccessible: false,
			loading: false,
			error: null,
			insightsDebounce: null,
		}
	},
	computed: {
		/**
		 * App slug from the resolved Application record.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		appSlug() {
			return (this.application && this.application.slug) || ''
		},
		/**
		 * Files KPI tooltip.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		filesTooltip() {
			return t('openbuild', 'count of OR-attached files across all objects in this version\'s register; storage-bytes aggregation deferred')
		},
		/**
		 * Production version UUID resolved from the Application record.
		 *
		 * @return {string|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		productionVersionUuid() {
			const pv = this.application && this.application.productionVersion
			if (!pv) return null
			if (typeof pv === 'string') return pv
			return pv.uuid || pv.id || null
		},
		/**
		 * Versions ordered along the promotesTo chain (most-upstream first).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
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
		 * Currently active version (selected, or production, or first).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
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
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		activeVersionUuid() {
			return this.activeVersion ? this.activeVersion.uuid : ''
		},
		/**
		 * Active version slug.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		activeVersionSlug() {
			return this.activeVersion ? this.activeVersion.slug : ''
		},
		/**
		 * Manifest of the active version.
		 *
		 * @return {object}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		activeManifest() {
			return (this.activeVersion && this.activeVersion.manifest) || {}
		},
		/**
		 * Pages declared in the active version's manifest.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		activePages() {
			const pages = this.activeManifest.pages
			return Array.isArray(pages) ? pages : []
		},
		/**
		 * Menu items declared in the active version's manifest.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		activeMenu() {
			const menu = this.activeManifest.menu
			return Array.isArray(menu) ? menu : []
		},
		/**
		 * Distinct schemas referenced by the active version's pages.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
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
		/**
		 * Count of distinct schemas in the active version.
		 *
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		schemaCount() {
			return this.activeSchemas.length
		},
		/**
		 * The production version row (for the chain/star resolution).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		productionVersion() {
			if (!this.productionVersionUuid) return null
			return this.orderedVersions.find((v) => v.uuid === this.productionVersionUuid) || null
		},
		/**
		 * Total activity events across all buckets.
		 *
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		totalActivityEvents() {
			return this.activity.reduce((acc, b) => acc + ((b && Number(b.eventCount)) || 0), 0)
		},
		/**
		 * SVG polyline points for the activity sparkline.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		sparklinePoints() {
			if (!this.activity || this.activity.length === 0) return '0,30 100,30'
			const max = this.activity.reduce((m, b) => Math.max(m, Number(b.eventCount) || 0), 1) || 1
			return this.activity.map((b, idx) => {
				const x = this.activity.length > 1 ? (idx / (this.activity.length - 1)) * 100 : 50
				const y = 30 - ((Number(b.eventCount) || 0) / max) * 28
				return `${x.toFixed(2)},${y.toFixed(2)}`
			}).join(' ')
		},
		/**
		 * The "version no longer accessible" banner descriptor, or null.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		banner() {
			if (this.versionNoLongerAccessible) {
				return {
					message: t('openbuild', 'This version is no longer accessible. Switch to production?'),
					actionLabel: t('openbuild', 'Switch to production'),
					action: () => this.switchToProduction(),
				}
			}
			return null
		},
	},
	watch: {
		/**
		 * Re-bind to a freshly resolved record and reload its versions.
		 *
		 * @param {object} next The new Application record.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		object(next) {
			if (next) {
				this.application = next
				this.loadVersions()
			}
		},
		'$route.query._version'(newSlug) {
			if (!newSlug) {
				if (this.productionVersionUuid) this.selectedVersionUuid = this.productionVersionUuid
				return
			}
			const match = this.orderedVersions.find((v) => v.slug === newSlug)
			if (match) this.selectedVersionUuid = match.uuid
		},
		/**
		 * Re-fetch insights when the active version changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		activeVersionUuid() {
			this.scheduleInsightsFetch()
		},
		/**
		 * Re-fetch insights when the shared window selection changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		selectedWindow() {
			this.scheduleInsightsFetch()
		},
	},
	/**
	 * Load the version list (and seed the active version) on mount.
	 *
	 * @return {void}
	 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
	 */
	mounted() {
		if (!this.application) {
			this.refreshApplication()
		} else {
			this.loadVersions()
		}
	},
	/**
	 * Clear the pending insights debounce timer on teardown.
	 *
	 * @return {void}
	 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
	 */
	beforeDestroy() {
		if (this.insightsDebounce) {
			clearTimeout(this.insightsDebounce)
			this.insightsDebounce = null
		}
	},
	methods: {
		/**
		 * Forward an open-permissions request from the Groups widget.
		 *
		 * @param {object} application The Application record.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		onOpenPermissions(application) {
			this.$emit('open-permissions', application)
		},

		/**
		 * Switch the active version to the production version (banner action).
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		switchToProduction() {
			const prod = this.productionVersion
			if (!prod || !prod.slug) return
			this.versionNoLongerAccessible = false
			this.selectedVersionUuid = prod.uuid
			const route = buildVersionedRoute(
				this.$route ? this.$route.name : 'VirtualAppDetail',
				this.$route ? this.$route.params : {},
				prod.slug,
			)
			if (this.$router) {
				this.$router.replace(route).catch(() => { /* ignore duplicate nav */ })
			}
		},

		/**
		 * Re-load the Application record by `objectId` (fallback when the slot
		 * did not pass a resolved `object`).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		async refreshApplication() {
			const uuid = this.objectId || (this.$route && this.$route.params && this.$route.params.objectId) || ''
			if (!uuid) return
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuild/application/${encodeURIComponent(uuid)}`)
				const { data } = await axios.get(url)
				this.application = data ? { ...data, '@self': data['@self'] || {} } : null
				this.loadVersions()
			} catch (e) {
				this.error = e instanceof Error ? e : new Error(String(e))
			}
		},

		/**
		 * Load the version list for the current Application.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
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
				this.scheduleInsightsFetch()
			} catch (e) {
				this.error = e instanceof Error ? e : new Error(String(e))
			}
		},

		/**
		 * 200ms-debounced wrapper around the insights fetch.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
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
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		async fetchInsights() {
			const appUuid = (this.application && (this.application.uuid || this.application.id)) || this.objectId
			if (!appUuid || !this.activeVersionUuid) return
			this.loading = true
			this.error = null
			this.versionNoLongerAccessible = false
			try {
				const url = generateUrl(
					`/apps/openbuild/api/applications/${encodeURIComponent(appUuid)}/versions/${encodeURIComponent(this.activeVersionUuid)}/insights`,
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
.ob-detail-dashboard {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.ob-detail-dashboard__banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	background: rgba(229, 153, 0, 0.1);
	border: 1px solid rgba(229, 153, 0, 0.3);
	border-radius: var(--border-radius-large, 8px);
}

.ob-detail-dashboard__kpis {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 900px) {
	.ob-detail-dashboard__kpis {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@media (max-width: 600px) {
	.ob-detail-dashboard__kpis {
		grid-template-columns: 1fr;
	}
}

.ob-detail-dashboard__activity-card {
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-detail-dashboard__activity-header h3 {
	margin: 0 0 8px 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-detail-dashboard__activity-chart {
	width: 100%;
	height: 60px;
}

.ob-detail-dashboard__activity-summary {
	margin: 8px 0 0 0;
	color: var(--color-text-maxcontrast, #666);
	font-size: 12px;
}

.ob-detail-dashboard__activity-empty {
	margin: 0;
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast, #666);
	background: var(--color-background-dark, #f5f5f5);
	border-radius: var(--border-radius-large, 8px);
}

.ob-detail-dashboard__widgets {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 900px) {
	.ob-detail-dashboard__widgets {
		grid-template-columns: 1fr;
	}
}
</style>
