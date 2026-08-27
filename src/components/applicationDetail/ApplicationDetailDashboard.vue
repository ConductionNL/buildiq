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
		<section v-if="banner" class="ob-detail-dashboard__banner" role="alert">
			<p>{{ banner.message }}</p>
			<NcButton v-if="banner.action" variant="primary" @click="banner.action">
				{{ banner.actionLabel }}
			</NcButton>
		</section>

		<!-- 2. KPI grid — the time-range control lives in the strip header (no
		     separate floating pill row above the cards). Each card is clickable
		     and deep-links into OpenRegister. -->
		<section class="ob-detail-dashboard__kpis-section">
			<div class="ob-detail-dashboard__kpis-toolbar">
				<div
					class="ob-detail-dashboard__range"
					role="group"
					:aria-label="t('buildiq', 'Time range')">
					<NcButton
						v-for="opt in windowOptions"
						:key="opt"
						:variant="selectedWindow === opt ? 'primary' : 'tertiary'"
						:aria-pressed="selectedWindow === opt"
						@click="selectedWindow = opt">
						{{ opt }}
					</NcButton>
				</div>
			</div>

			<div class="ob-detail-dashboard__kpis">
				<!-- KPI widgets. While insights are loading we show CnStatsBlock's
				     built-in spinner (loading + !showZeroCount) instead of a stale 0;
				     once loaded, showZeroCount renders a real 0 where applicable.
				     The wrapper makes the whole card a clickable OpenRegister link. -->
				<div
					class="ob-detail-dashboard__kpi-link"
					:class="{
						'ob-detail-dashboard__kpi-link--clickable': !!registerSlug,
					}"
					role="button"
					tabindex="0"
					:title="t('buildiq', 'Open in OpenRegister')"
					@click="openInRegister('audit')"
					@keyup.enter="openInRegister('audit')">
					<CnStatsBlock
						class="ob-detail-dashboard__kpi"
						horizontal
						:icon="iconUsers"
						:title="t('buildiq', 'Active users')"
						:count="kpis.activeUsers"
						:countLabel="t('buildiq', 'users')"
						variant="primary"
						:loading="!loaded"
						:loadingLabel="t('buildiq', 'Loading…')"
						:showZeroCount="loaded" />
				</div>
				<div
					class="ob-detail-dashboard__kpi-link"
					:class="{
						'ob-detail-dashboard__kpi-link--clickable': !!registerSlug,
					}"
					role="button"
					tabindex="0"
					:title="t('buildiq', 'Open in OpenRegister')"
					@click="openInRegister('objects')"
					@keyup.enter="openInRegister('objects')">
					<CnStatsBlock
						class="ob-detail-dashboard__kpi"
						horizontal
						:icon="iconObjects"
						:title="t('buildiq', 'Object count')"
						:count="kpis.objectCount"
						:countLabel="t('buildiq', 'objects')"
						variant="primary"
						:loading="!loaded"
						:loadingLabel="t('buildiq', 'Loading…')"
						:showZeroCount="loaded" />
				</div>
				<!-- Storage: the KPI value is the SUM of attached-file sizes (bytes)
				     from the audit trail, NOT a file count — so we label it Storage and
				     format it human-readable. Two variants: the loaded one uses the
				     #value slot (which would otherwise bypass the spinner), the loading
				     one keeps CnStatsBlock's built-in spinner. -->
				<div
					class="ob-detail-dashboard__kpi-link"
					:class="{
						'ob-detail-dashboard__kpi-link--clickable': !!registerSlug,
					}"
					role="button"
					tabindex="0"
					:title="t('buildiq', 'Open in OpenRegister')"
					@click="openInRegister('files')"
					@keyup.enter="openInRegister('files')">
					<CnStatsBlock
						v-if="loaded"
						class="ob-detail-dashboard__kpi"
						horizontal
						:icon="iconStorage"
						:title="t('buildiq', 'Storage')"
						:count="kpis.filesCount"
						countLabel=""
						variant="success"
						showZeroCount>
						<template #value="{ count }">
							{{ formatBytes(count) }}
						</template>
					</CnStatsBlock>
					<CnStatsBlock
						v-else
						class="ob-detail-dashboard__kpi"
						horizontal
						:icon="iconStorage"
						:title="t('buildiq', 'Storage')"
						:count="0"
						variant="success"
						loading
						:loadingLabel="t('buildiq', 'Loading…')" />
				</div>
				<div
					class="ob-detail-dashboard__kpi-link"
					:class="{
						'ob-detail-dashboard__kpi-link--clickable': !!registerSlug,
					}"
					role="button"
					tabindex="0"
					:title="t('buildiq', 'Open in OpenRegister')"
					@click="openInRegister('audit')"
					@keyup.enter="openInRegister('audit')">
					<CnStatsBlock
						class="ob-detail-dashboard__kpi"
						horizontal
						:icon="iconAudit"
						:title="t('buildiq', 'Audit events')"
						:count="kpis.auditEventCount"
						:countLabel="t('buildiq', 'events')"
						variant="warning"
						:loading="!loaded"
						:loadingLabel="t('buildiq', 'Loading…')"
						:showZeroCount="loaded" />
				</div>
			</div>
		</section>

		<!-- 3. Activity graph -->
		<section class="ob-detail-dashboard__activity">
			<div
				v-if="activity && activity.length > 0"
				class="ob-detail-dashboard__activity-card">
				<header class="ob-detail-dashboard__activity-header">
					<h3>
						{{
							t('buildiq', 'Activity ({window})', {
								window: selectedWindow,
							})
						}}
					</h3>
				</header>
				<svg
					class="ob-detail-dashboard__activity-chart"
					viewBox="0 0 100 30"
					preserveAspectRatio="none"
					role="img"
					:aria-label="t('buildiq', 'Activity sparkline')">
					<polyline
						:points="sparklinePoints"
						fill="none"
						stroke="#4376fc"
						stroke-width="0.5" />
				</svg>
				<p class="ob-detail-dashboard__activity-summary">
					{{
						t('buildiq', '{count} buckets, {sum} total events', {
							count: activity.length,
							sum: totalActivityEvents,
						})
					}}
				</p>
			</div>
			<p v-else class="ob-detail-dashboard__activity-empty">
				{{ t('buildiq', 'No activity in the selected window') }}
			</p>
		</section>

		<!-- 4. Customization-layer widget grid. The manifest-derived Schemas /
		     Pages / Menu widgets are replaced by the Manifest widget (delta
		     layers + version state) and the Register widget (counts + deep-link
		     to OpenRegister) — the schema/page/menu detail always reflects the
		     latest manifest and is reachable from the manifest layers
		     (layered-versioned-app-deltas). -->
		<section class="ob-detail-dashboard__widgets">
			<ManifestWidget
				:appSlug="appSlug"
				:isHybrid="isHybrid"
				:allowUserOverrides="allowUserOverrides"
				:adminLabel="adminVersionLabel"
				:adminStatus="adminVersionStatus"
				@openDetail="openManifestDetail"
				@editOverride="showUserDeltaModal = true"
				@changed="onUserDeltaChanged" />
			<RegisterWidget
				:appSlug="appSlug"
				:versionSlug="activeVersionSlug"
				:registerSlugOverride="registerSlug"
				:isHybrid="isHybrid"
				:canImport="canImport"
				@importData="onImportData" />
			<GroupsWidget
				:application="application"
				@openPermissions="onOpenPermissions" />
		</section>

		<!-- 5. Structure tables — the four parts of an app, each listed with
		     its rows so the whole app is editable from this page rather than
		     only from inside the running app. PagesWidget / MenuWidget /
		     SchemasWidget were implemented for REQ-OBADO-009 and mounted
		     nowhere; FlowsWidget completes the set. -->
		<section class="ob-detail-dashboard__structure">
			<PagesWidget
				:appSlug="appSlug"
				:versionSlug="activeVersionSlug"
				:pages="activePages" />
			<MenuWidget
				:appSlug="appSlug"
				:versionSlug="activeVersionSlug"
				:menu="activeMenu" />
			<SchemasWidget
				:appSlug="appSlug"
				:versionSlug="activeVersionSlug"
				:schemas="activeSchemas"
				@addSchema="onAddSchema" />
			<FlowsWidget :flows="activeFlows" />
		</section>

		<UserDeltaEditModal
			v-model:open="showUserDeltaModal"
			:appSlug="appSlug"
			:delta="userDeltaContent"
			@saved="onUserDeltaChanged" />

		<ImportDataWizard
			v-if="showImportWizard"
			:registerId="registerSlug"
			:schemas="importSchemas"
			@imported="onImported"
			@close="showImportWizard = false" />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Harddisk from 'vue-material-design-icons/Harddisk.vue'
import History from 'vue-material-design-icons/History.vue'
import ImportDataWizard from '../../dialogs/ImportDataWizard.vue'
import UserDeltaEditModal from '../../modals/UserDeltaEditModal.vue'
import FlowsWidget from './widgets/FlowsWidget.vue'
import GroupsWidget from './widgets/GroupsWidget.vue'
import ManifestWidget from './widgets/ManifestWidget.vue'
import MenuWidget from './widgets/MenuWidget.vue'
import PagesWidget from './widgets/PagesWidget.vue'
import RegisterWidget from './widgets/RegisterWidget.vue'
import SchemasWidget from './widgets/SchemasWidget.vue'
import { fetchApplicationRecord } from '../../composables/useApplicationRecord.js'
import { useInsightsWindow } from '../../composables/useInsightsWindow.js'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { useRole } from '../../composables/useRole.js'
import { buildVersionedRoute } from '../../router/helpers.js'

export default {
	name: 'ApplicationDetailDashboard',
	components: {
		CnStatsBlock,
		NcButton,
		FlowsWidget,
		GroupsWidget,
		ManifestWidget,
		MenuWidget,
		PagesWidget,
		RegisterWidget,
		SchemasWidget,
		UserDeltaEditModal,
		ImportDataWizard,
	},

	props: {
		// CnDetailPage's #before-body slot forwards the resolved record as
		// `object` plus the route-resolved `objectId`.
		object: { type: Object, default: null },
		objectId: { type: String, default: '' },
	},

	// Declared so the emit is part of the component's contract rather than an
	// undeclared side channel. This one was already fired and never declared.
	emits: ['open-permissions'],

	/**
	 * Expose the shared insights-window ref (driven by the header toggle) so the
	 * KPI/activity widgets re-fetch when the user changes 7d/30d/90d — plus the
	 * (raw) MDI icon components for the KPI widgets.
	 *
	 * @return {object}
	 */
	setup() {
		const { selectedWindow, windowOptions } = useInsightsWindow()
		return {
			selectedWindow,
			windowOptions,
			iconUsers: AccountMultipleOutline,
			iconObjects: CubeOutline,
			iconStorage: Harddisk,
			iconAudit: History,
		}
	},

	data() {
		return {
			application: this.object || null,
			versions: [],
			selectedVersionUuid: null,
			kpis: {
				activeUsers: 0,
				objectCount: 0,
				filesCount: 0,
				auditEventCount: 0,
			},

			activity: [],
			versionNoLongerAccessible: false,
			loading: false,
			// Becomes true after the first insights fetch settles; gates the KPI
			// widgets' spinner so they show a loader (not a stale 0) while waiting.
			loaded: false,
			error: null,
			insightsDebounce: null,
			// Layered-delta UI state (layered-versioned-app-deltas).
			showUserDeltaModal: false,
			userDeltaContent: {},
			// Import-data wizard state (buildiq-data-import-wizard).
			showImportWizard: false,
			importSchemas: [],
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
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
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
		 * OpenRegister flows bound to this app.
		 *
		 * Flows are a field on the Application record (written by
		 * `ApplicationDetailActions.setFlows` via `obPatchApp({ flows })`), not
		 * part of the version manifest, so they are read off the record rather
		 * than off `activeManifest`.
		 *
		 * @return {Array<object>}
		 *
		 * @spec exclude reads an existing record field for a display-only table
		 */
		activeFlows() {
			const flows = this.application && this.application.flows
			return Array.isArray(flows) ? flows : []
		},

		/**
		 * The production version row (for the chain/star resolution).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		productionVersion() {
			if (!this.productionVersionUuid) return null
			return (
				this.orderedVersions.find(
					(v) => v.uuid === this.productionVersionUuid,
				) || null
			)
		},

		/**
		 * Total activity events across all buckets.
		 *
		 * @return {number}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		totalActivityEvents() {
			return this.activity.reduce(
				(acc, b) => acc + ((b && Number(b.eventCount)) || 0),
				0,
			)
		},

		/**
		 * SVG polyline points for the activity sparkline.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		sparklinePoints() {
			if (!this.activity || this.activity.length === 0) return '0,30 100,30'
			const max =
				this.activity.reduce(
					(m, b) => Math.max(m, Number(b.eventCount) || 0),
					1,
				) || 1
			return this.activity
				.map((b, idx) => {
					const x =
						this.activity.length > 1
							? (idx / (this.activity.length - 1)) * 100
							: 50
					const y = 30 - ((Number(b.eventCount) || 0) / max) * 28
					return `${x.toFixed(2)},${y.toFixed(2)}`
				})
				.join(' ')
		},

		/**
		 * The "version no longer accessible" banner descriptor, or null.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		banner() {
			// A hybrid app IS the live installed Nextcloud app — the
			// "version no longer accessible" banner is misleading there
			// (the app is always reachable at /apps/{slug}); suppress it.
			if (this.isHybrid) {
				return null
			}
			if (this.versionNoLongerAccessible) {
				return {
					message: t(
						'buildiq',
						'This version is no longer accessible. Switch to production?',
					),

					actionLabel: t('buildiq', 'Switch to production'),
					action: () => this.switchToProduction(),
				}
			}
			return null
		},

		/**
		 * Whether this is a hybrid app (mirrors an installed Nextcloud app).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		isHybrid() {
			return (this.application && this.application.appType) === 'hybrid'
		},

		/**
		 * Whether this app allows per-user manifest overrides
		 * (layered-versioned-app-deltas).
		 *
		 * @return {boolean}
		 */
		allowUserOverrides() {
			return !!(this.application && this.application.allowUserOverrides)
		},

		/**
		 * Human label for the admin delta's current version (the production
		 * version's name/semver, falling back to the active version).
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		adminVersionLabel() {
			const v = this.productionVersion || this.activeVersion
			if (!v) return t('buildiq', 'current')
			return v.semver || v.name || v.slug || t('buildiq', 'current')
		},

		/**
		 * Lifecycle status of the admin delta's current version.
		 *
		 * @return {string}
		 */
		adminVersionStatus() {
			const v = this.productionVersion || this.activeVersion
			return (v && v.status) || ''
		},

		/**
		 * Slug of the OpenRegister register the KPIs reflect. Hybrid apps use the
		 * installed fleet app's register (== appSlug); virtual apps use the
		 * per-version register `buildiq-{appSlug}-{versionSlug}`. Empty until the
		 * app + active version are known (KPI cards are then non-clickable).
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		registerSlug() {
			if (!this.appSlug) return ''
			if (this.isHybrid) return this.appSlug
			// Prefer the active version's REAL register — versions may share
			// production's register (manifest-only versioning), so the
			// `buildiq-{appSlug}-{versionSlug}` convention can name a register
			// that doesn't exist. Fall back to the convention when absent.
			const real = (this.activeVersion && this.activeVersion.register) || ''
			if (real) return real
			if (!this.activeVersionSlug) return ''
			return `openbuild-${this.appSlug}-${this.activeVersionSlug}`
		},

		/**
		 * Whether the caller holds a build/manage role (owner or editor) on this
		 * Application. Gates the "Import data" affordance (REQ: the import is
		 * authorised on both sides — the write is independently re-gated by
		 * OpenRegister's own register manage-permission).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.2
		 */
		canImport() {
			const role = useRole(this.application)
			return role === 'owner' || role === 'editor'
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

		'$route.query._version': function (newSlug) {
			if (!newSlug) {
				if (this.productionVersionUuid)
					this.selectedVersionUuid = this.productionVersionUuid
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
	beforeUnmount() {
		if (this.insightsDebounce) {
			clearTimeout(this.insightsDebounce)
			this.insightsDebounce = null
		}
	},

	methods: {
		/**
		 * Deep-link a KPI into OpenRegister — the system of record behind the
		 * numbers — at the app's register detail page. The optional `tab` hint
		 * (objects / files / audit) is passed as a query param; OpenRegister lands
		 * on the right tab when it honours it and on the register otherwise. A
		 * no-op until the register is resolved. OpenRegister is a sibling app, so
		 * this is a top-level navigation.
		 *
		 * @param {string} [tab] Optional tab hint: 'objects' | 'files' | 'audit'.
		 * @return {void}
		 */
		openInRegister(tab) {
			if (!this.registerSlug) return
			let url = generateUrl(
				`/apps/openregister/registers/${encodeURIComponent(this.registerSlug)}`,
			)
			if (typeof tab === 'string' && tab !== '') {
				url += `?tab=${encodeURIComponent(tab)}`
			}
			window.location.href = url
		},

		/**
		 * Format a byte count as a human-readable storage size (e.g. 517 KB).
		 *
		 * @param {number} bytes Raw byte count.
		 * @return {string} Localised size with a binary unit.
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		formatBytes(bytes) {
			const n = Number(bytes) || 0
			if (n < 1024) {
				return t('buildiq', '{n} B', { n })
			}
			const units = ['KB', 'MB', 'GB', 'TB', 'PB']
			let value = n / 1024
			let i = 0
			while (value >= 1024 && i < units.length - 1) {
				value /= 1024
				i++
			}
			// 1 decimal place, dropping a trailing .0.
			const rounded = Math.round(value * 10) / 10
			return `${rounded} ${units[i]}`
		},

		/**
		 * Forward an open-permissions request from the Groups widget.
		 *
		 * @param {object} application The Application record.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		/**
		 * Open the schema designer for this app.
		 *
		 * SchemasWidget emitted `add-schema` and logged that no create dialog
		 * was registered yet, deferring to the schema-designer spec. That
		 * designer has since shipped (`SchemaDesignerList` at
		 * `/builder/:slug/schemas`), so the emit now goes somewhere.
		 *
		 * @return {void}
		 *
		 * @spec exclude routes to an existing page, no new behaviour
		 */
		onAddSchema() {
			if (!this.appSlug) {
				return
			}

			this.$router
				.push({ name: 'SchemaDesignerList', params: { slug: this.appSlug } })
				.catch(() => {})
		},

		onOpenPermissions(application) {
			this.$emit('open-permissions', application)
		},

		/**
		 * Open the Import-data wizard for the active version's register. Fetches
		 * the register's schemas so the wizard can offer them as existing-schema
		 * targets — only schemas in THIS register (the active version's own
		 * per-version register) are fetched, so shared bound `dataRegisters` are
		 * never offered as import targets.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.2
		 */
		async onImportData() {
			if (!this.registerSlug) {
				return
			}
			try {
				const { fetchSchemas } = useRegisterPicker({ appSlug: this.appSlug })
				this.importSchemas = await fetchSchemas(this.registerSlug)
			} catch (e) {
				this.importSchemas = []
			}
			this.showImportWizard = true
		},

		/**
		 * Refresh the insights/KPIs after a successful import (or rollback) so
		 * the object counts reflect the freshly imported rows.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.2
		 */
		onImported() {
			if (typeof this.fetchInsights === 'function') {
				this.fetchInsights()
			}
		},

		/**
		 * Navigate to the routed Manifest detail page for this app
		 * (layered-versioned-app-deltas).
		 *
		 * @return {void}
		 */
		openManifestDetail() {
			const uuid =
				(this.application && (this.application.uuid || this.application.id))
				|| this.objectId
			if (this.$router && uuid) {
				this.$router
					.push({
						name: 'ApplicationManifestDetail',
						params: { objectId: uuid },
					})
					.catch(() => {})
			}
		},

		/**
		 * Re-load the caller's user-delta content after a create/edit/reset so
		 * the edit modal opens pre-seeded with the latest delta.
		 *
		 * @return {Promise<void>}
		 */
		async onUserDeltaChanged() {
			await this.loadUserDeltaContent()
		},

		/**
		 * Fetch the caller's own user-delta content (for seeding the edit modal).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async loadUserDeltaContent() {
			if (!this.appSlug || !this.allowUserOverrides) {
				this.userDeltaContent = {}
				return
			}
			try {
				const url = generateUrl(
					'/apps/buildiq/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				const { data } = await axios.get(url)
				this.userDeltaContent = (data && data.manifestDelta) || {}
			} catch (e) {
				this.userDeltaContent = {}
			}
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
				this.$router.replace(route).catch(() => {
					/* ignore duplicate nav */
				})
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
			const uuid =
				this.objectId
				|| (this.$route && this.$route.params && this.$route.params.objectId)
				|| ''
			if (!uuid) return

			// Latest-request-wins if the uuid changes mid-flight (route change).
			const seq = (this._appReqSeq || 0) + 1
			this._appReqSeq = seq

			try {
				// Shared with ApplicationDetailHeader — both components resolve
				// the same record, and each has several triggers, so without
				// coalescing one page load issued ten identical GETs (#49).
				const record = await fetchApplicationRecord(uuid)
				if (seq !== this._appReqSeq) return
				this.application = record
				this.loadVersions()
			} catch (e) {
				if (seq !== this._appReqSeq) return
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
				const url = generateUrl(
					`/apps/buildiq/api/applications/${encodeURIComponent(this.appSlug)}/versions`,
				)
				const { data } = await axios.get(url)
				const list = Array.isArray(data)
					? data
					: data && Array.isArray(data.results)
						? data.results
						: []
				this.versions = list.map((v) => ({
					...v,
					uuid: v.uuid || v.id || (v['@self'] && v['@self'].id) || null,
				}))

				const versionSlugFromRoute =
					(this.$route && this.$route.query && this.$route.query._version)
					|| ''
				const match = versionSlugFromRoute
					? this.orderedVersions.find(
							(v) => v.slug === versionSlugFromRoute,
						)
					: null
				if (match) {
					this.selectedVersionUuid = match.uuid
				} else if (this.productionVersionUuid) {
					this.selectedVersionUuid = this.productionVersionUuid
				} else if (this.orderedVersions[0]) {
					this.selectedVersionUuid = this.orderedVersions[0].uuid
				}
				this.scheduleInsightsFetch()
				this.loadUserDeltaContent()
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
			const appUuid =
				(this.application && (this.application.uuid || this.application.id))
				|| this.objectId
			if (!appUuid || !this.activeVersionUuid) return
			this.loading = true
			this.error = null
			this.versionNoLongerAccessible = false
			try {
				const url = generateUrl(
					`/apps/buildiq/api/applications/${encodeURIComponent(appUuid)}/versions/${encodeURIComponent(this.activeVersionUuid)}/insights`,
				)
				const { data } = await axios.get(url, {
					params: { window: this.selectedWindow },
				})
				if (data && typeof data === 'object') {
					this.kpis = {
						activeUsers: 0,
						objectCount: 0,
						filesCount: 0,
						auditEventCount: 0,
						...(data.kpis || {}),
					}
					this.activity = Array.isArray(data.activity) ? data.activity : []
				}
			} catch (e) {
				const status = (e && e.response && e.response.status) || 0
				if (status === 404) {
					this.versionNoLongerAccessible = true
					this.kpis = {
						activeUsers: 0,
						objectCount: 0,
						filesCount: 0,
						auditEventCount: 0,
					}
					this.activity = []
				} else {
					this.error = e instanceof Error ? e : new Error(String(e))
				}
			} finally {
				this.loading = false
				this.loaded = true
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

.ob-detail-dashboard__kpis-toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 8px;
}

.ob-detail-dashboard__range {
	display: inline-flex;
	gap: 2px;
	padding: 2px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-dark, #f0f0f0);
}

.ob-detail-dashboard__kpis {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 12px;
}

/* Each KPI card is a clickable deep-link into OpenRegister. */
.ob-detail-dashboard__kpi-link {
	border-radius: var(--border-radius-large, 8px);
}

.ob-detail-dashboard__kpi-link--clickable {
	cursor: pointer;
}

.ob-detail-dashboard__kpi-link--clickable:hover {
	background: var(--color-background-hover, rgba(127, 127, 127, 0.08));
}

.ob-detail-dashboard__kpi-link--clickable:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
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

.ob-detail-dashboard__structure {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
	margin-top: 12px;
}

@media (max-width: 900px) {
	.ob-detail-dashboard__widgets,
	.ob-detail-dashboard__structure {
		grid-template-columns: 1fr;
	}
}
</style>
