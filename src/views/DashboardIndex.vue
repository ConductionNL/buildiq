<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	DashboardIndex — the OpenBuild dashboard, modelled on the DocuDesk dashboard
	pattern: a single self-contained CnDashboardPage (KPI cards row + content
	panels) mounted via a `type: "custom"` manifest page. Rendering one
	CnDashboardPage from a custom view (rather than nesting CnDashboardPage as a
	widget body of a `type: "dashboard"` page) avoids the dashboard-in-dashboard
	antipattern (hydra gate-15).

	Row 1: four KPI tiles (Apps, Hybrid apps, Templates, Published versions).
	Row 2: two content panels — Recent apps (table) + Quick start (actions).

	@spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
-->
<template>
	<CnDashboardPage
		:title="t('openbuild', 'Dashboard')"
		:widgets="widgetDefs"
		:layout="dashboardLayout"
		:loading="loading">
		<!-- KPI: Apps (total) -->
		<template #widget-apps>
			<CnStatsBlock
				:title="t('openbuild', 'Apps')"
				:count="counts.apps"
				:count-label="t('openbuild', 'apps')"
				variant="primary"
				show-zero-count />
		</template>

		<!-- KPI: Hybrid apps -->
		<template #widget-hybrid>
			<CnStatsBlock
				:title="t('openbuild', 'Hybrid apps')"
				:count="counts.hybrid"
				:count-label="t('openbuild', 'hybrid')"
				variant="default"
				show-zero-count />
		</template>

		<!-- KPI: Templates -->
		<template #widget-templates>
			<CnStatsBlock
				:title="t('openbuild', 'Templates')"
				:count="counts.templates"
				:count-label="t('openbuild', 'templates')"
				variant="default"
				show-zero-count />
		</template>

		<!-- KPI: Published versions -->
		<template #widget-versions>
			<CnStatsBlock
				:title="t('openbuild', 'Published versions')"
				:count="counts.versions"
				:count-label="t('openbuild', 'versions')"
				variant="success"
				show-zero-count />
		</template>

		<!-- Recent apps table -->
		<template #widget-recent-apps>
			<NcEmptyContent
				v-if="!loading && recentApps.length === 0"
				:name="t('openbuild', 'No apps yet')"
				:description="t('openbuild', 'Create your first app to get started.')" />
			<CnTableWidget
				v-else
				:rows="recentApps"
				:columns="recentColumns" />
		</template>

		<!-- Quick start panel -->
		<template #widget-quick-start>
			<div class="ob-dash-quickstart">
				<p class="ob-dash-quickstart__lead">
					{{ t('openbuild', 'Build a new app, or customize an installed one.') }}
				</p>
				<div class="ob-dash-quickstart__actions">
					<NcButton type="primary" @click="goTo('VirtualApps')">
						{{ t('openbuild', 'Go to Apps') }}
					</NcButton>
					<NcButton @click="goTo('Templates')">
						{{ t('openbuild', 'Browse templates') }}
					</NcButton>
					<NcButton @click="goTo('Schemas')">
						{{ t('openbuild', 'Design schemas') }}
					</NcButton>
				</div>
			</div>
		</template>
	</CnDashboardPage>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock, CnTableWidget } from '@conduction/nextcloud-vue'

export default {
	name: 'DashboardIndex',

	components: {
		CnDashboardPage,
		CnStatsBlock,
		CnTableWidget,
		NcButton,
		NcEmptyContent,
	},

	data() {
		return {
			loading: true,
			counts: { apps: 0, hybrid: 0, templates: 0, versions: 0 },
			recentApps: [],
			dashboardLayout: [
				{ id: 1, widgetId: 'apps', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 2, widgetId: 'hybrid', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 3, widgetId: 'templates', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 4, widgetId: 'versions', gridX: 9, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
				{ id: 5, widgetId: 'recent-apps', gridX: 0, gridY: 2, gridWidth: 8, gridHeight: 5 },
				{ id: 6, widgetId: 'quick-start', gridX: 8, gridY: 2, gridWidth: 4, gridHeight: 5 },
			],
		}
	},

	computed: {
		/**
		 * Widget definitions (id + title) for CnDashboardPage.
		 *
		 * @return {Array<{id: string, title: string}>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		widgetDefs() {
			return [
				{ id: 'apps', title: t('openbuild', 'Apps') },
				{ id: 'hybrid', title: t('openbuild', 'Hybrid apps') },
				{ id: 'templates', title: t('openbuild', 'Templates') },
				{ id: 'versions', title: t('openbuild', 'Published versions') },
				{ id: 'recent-apps', title: t('openbuild', 'Recent apps') },
				{ id: 'quick-start', title: t('openbuild', 'Quick start') },
			]
		},

		/**
		 * Column definitions for the Recent apps table.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		recentColumns() {
			return [
				{ key: 'name', label: t('openbuild', 'Name') },
				{ key: 'typeLabel', label: t('openbuild', 'Type') },
				{ key: 'slug', label: t('openbuild', 'Slug') },
			]
		},
	},

	/**
	 * Load KPI counts + recent apps on mount.
	 *
	 * @return {void}
	 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
	 */
	async created() {
		await this.loadDashboard()
	},

	methods: {
		/**
		 * Fetch the four KPI counts + the recent-apps list from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		async loadDashboard() {
			this.loading = true
			try {
				const [apps, hybrid, templates, versions, recent] = await Promise.all([
					this.fetchTotal('application'),
					this.fetchTotal('application', { appType: 'hybrid' }),
					this.fetchTotal('application-template'),
					this.fetchTotal('applicationVersion'),
					this.fetchObjects('application', 8),
				])
				this.counts = { apps, hybrid, templates, versions }
				this.recentApps = recent.map((a) => ({
					...a,
					typeLabel: a.appType === 'hybrid' ? t('openbuild', 'Hybrid') : t('openbuild', 'Virtual'),
				}))
			} catch (e) {
				// Dashboard is best-effort — leave zeros / empty list on failure.
				console.error('OpenBuild dashboard load failed', e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the total count of objects in a schema (optionally filtered).
		 *
		 * @param {string} schema The OpenRegister schema slug.
		 * @param {object} [filter] Optional shorthand filter map (e.g. { appType: 'hybrid' }).
		 *
		 * @return {Promise<number>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		async fetchTotal(schema, filter = {}) {
			const url = generateUrl('/apps/openregister/api/objects/openbuild/{schema}', { schema })
			const { data } = await axios.get(url, { params: { _limit: 1, ...filter } })
			return Number(data && data.total ? data.total : 0)
		},

		/**
		 * Fetch the most recent objects of a schema.
		 *
		 * @param {string} schema The OpenRegister schema slug.
		 * @param {number} limit Max rows.
		 *
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		async fetchObjects(schema, limit) {
			const url = generateUrl('/apps/openregister/api/objects/openbuild/{schema}', { schema })
			const { data } = await axios.get(url, { params: { _limit: limit, _order: { '@self.updated': 'DESC' } } })
			return (data && Array.isArray(data.results)) ? data.results : []
		},

		/**
		 * Navigate to a named in-app route.
		 *
		 * @param {string} name The route name.
		 *
		 * @return {void}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		goTo(name) {
			if (this.$router) {
				this.$router.push({ name }).catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
.ob-dash-quickstart {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 4px 2px;
}

.ob-dash-quickstart__lead {
	margin: 0;
	color: var(--color-text-maxcontrast, #888);
}

.ob-dash-quickstart__actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: stretch;
}
</style>
