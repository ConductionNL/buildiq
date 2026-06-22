<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	DashboardIndex — the OpenBuild dashboard, modelled on the DocuDesk dashboard
	pattern: a single self-contained CnDashboardPage (KPI cards row + content
	panels) mounted via a `type: "custom"` manifest page. Rendering one
	CnDashboardPage from a custom view (rather than nesting CnDashboardPage as a
	widget body of a `type: "dashboard"` page) avoids the dashboard-in-dashboard
	antipattern (hydra gate-15).

	Row 1: three KPI tiles (Apps, Hybrid apps, Published versions) — each clickable
	       through to the Apps index.
	Row 2: full-width Recent apps table.
	Header: a "Create app" primary action opens the creation wizard.

	@spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
-->
<template>
	<CnDashboardPage
		:title="t('openbuild', 'Dashboard')"
		:widgets="widgetDefs"
		:layout="dashboardLayout"
		:loading="loading">
		<!-- Create app — primary header action (opens the creation wizard). -->
		<template #header-actions>
			<NcButton type="primary" @click="showWizard = true">
				{{ t('openbuild', 'Create app') }}
			</NcButton>
		</template>

		<!-- KPI: Apps (total) — clickable → Apps index -->
		<template #widget-apps>
			<div class="ob-kpi-link"
				role="button"
				tabindex="0"
				@click="goTo('VirtualApps')"
				@keyup.enter="goTo('VirtualApps')">
				<CnStatsBlock
					horizontal
					:icon="iconApps"
					:title="t('openbuild', 'Apps')"
					:count="counts.apps"
					:count-label="t('openbuild', 'apps')"
					variant="primary"
					show-zero-count />
			</div>
		</template>

		<!-- KPI: Hybrid apps — clickable → Apps index -->
		<template #widget-hybrid>
			<div class="ob-kpi-link"
				role="button"
				tabindex="0"
				@click="goTo('VirtualApps')"
				@keyup.enter="goTo('VirtualApps')">
				<CnStatsBlock
					horizontal
					:icon="iconHybrid"
					:title="t('openbuild', 'Hybrid apps')"
					:count="counts.hybrid"
					:count-label="t('openbuild', 'hybrid')"
					variant="default"
					show-zero-count />
			</div>
		</template>

		<!-- KPI: Published versions — clickable → Apps index -->
		<template #widget-versions>
			<div class="ob-kpi-link"
				role="button"
				tabindex="0"
				@click="goTo('VirtualApps')"
				@keyup.enter="goTo('VirtualApps')">
				<CnStatsBlock
					horizontal
					:icon="iconVersions"
					:title="t('openbuild', 'Published versions')"
					:count="counts.versions"
					:count-label="t('openbuild', 'versions')"
					variant="success"
					show-zero-count />
			</div>
		</template>

		<!-- Recent apps table — edge-to-edge inside the widget card (the wrapper's
		     padding is bled away by .ob-recent-apps) with a per-row Edit action
		     that opens the app detail page. The whole row is clickable too. -->
		<template #widget-recent-apps>
			<NcEmptyContent
				v-if="!loading && recentApps.length === 0"
				:name="t('openbuild', 'No apps yet')"
				:description="t('openbuild', 'Create your first app to get started.')" />
			<div v-else class="ob-recent-apps">
				<CnDataTable
					:rows="recentApps"
					:columns="recentColumns"
					:loading="false"
					:selectable="false"
					@row-click="goToApp">
					<template #actions-header>
						{{ t('openbuild', 'Edit') }}
					</template>
					<template #row-actions="{ row }">
						<NcButton
							type="tertiary"
							:aria-label="t('openbuild', 'Open {name}', { name: row.name || row.slug })"
							@click="goToApp(row)">
							<template #icon>
								<PencilOutline :size="20" />
							</template>
						</NcButton>
					</template>
				</CnDataTable>
			</div>
		</template>

		<CreateApplicationWizard :show.sync="showWizard" @created="onAppCreated" />
	</CnDashboardPage>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock, CnDataTable } from '@conduction/nextcloud-vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import PuzzleOutline from 'vue-material-design-icons/PuzzleOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import CreateApplicationWizard from '../dialogs/CreateApplicationWizard.vue'

export default {
	name: 'DashboardIndex',

	components: {
		CnDashboardPage,
		CnStatsBlock,
		CnDataTable,
		PencilOutline,
		CreateApplicationWizard,
		NcButton,
		NcEmptyContent,
	},

	/**
	 * Expose the (raw) MDI icon components for the KPI widgets.
	 *
	 * @return {object}
	 */
	setup() {
		return {
			iconApps: ShapeOutline,
			iconHybrid: PuzzleOutline,
			iconVersions: History,
		}
	},

	data() {
		return {
			loading: true,
			showWizard: false,
			counts: { apps: 0, hybrid: 0, versions: 0 },
			recentApps: [],
			dashboardLayout: [
				{ id: 1, widgetId: 'apps', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
				{ id: 2, widgetId: 'hybrid', gridX: 4, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
				{ id: 3, widgetId: 'versions', gridX: 8, gridY: 0, gridWidth: 4, gridHeight: 2, showTitle: false },
				{ id: 4, widgetId: 'recent-apps', gridX: 0, gridY: 2, gridWidth: 12, gridHeight: 5 },
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
				{ id: 'versions', title: t('openbuild', 'Published versions') },
				{ id: 'recent-apps', title: t('openbuild', 'Recent apps') },
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
		 * Fetch the KPI counts + the recent-apps list from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		async loadDashboard() {
			this.loading = true
			try {
				const [apps, hybrid, versions, recent] = await Promise.all([
					this.fetchTotal('application'),
					this.fetchTotal('application', { appType: 'hybrid' }),
					this.fetchTotal('applicationVersion'),
					this.fetchObjects('application', 8),
				])
				this.counts = { apps, hybrid, versions }
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
		 * After the wizard creates an app, navigate to it (or refresh the list).
		 *
		 * @param {string} applicationUuid The new application UUID (may be empty for hybrid).
		 *
		 * @return {void}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		onAppCreated(applicationUuid) {
			this.showWizard = false
			if (this.$router && applicationUuid) {
				this.$router.push({ name: 'VirtualAppDetail', params: { objectId: applicationUuid } }).catch(() => {})
				return
			}
			this.loadDashboard()
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

		/**
		 * Open a Recent-apps row's detail page (row click or the Edit action).
		 *
		 * @param {object} row The application row (carries the OR `@self` envelope).
		 * @return {void}
		 */
		goToApp(row) {
			const uuid = (row && ((row['@self'] && row['@self'].id) || row.uuid || row.id)) || ''
			if (this.$router && uuid) {
				this.$router.push({ name: 'VirtualAppDetail', params: { objectId: uuid } }).catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
/* KPI tiles are clickable shortcuts to the Apps index. */
.ob-kpi-link {
	cursor: pointer;
	height: 100%;
	border-radius: var(--border-radius-large, 8px);
}

.ob-kpi-link:hover {
	background: var(--color-background-hover, rgba(127, 127, 127, 0.08));
}

.ob-kpi-link:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

/* Recent apps: bleed past the CnWidgetWrapper's 16px content padding so the
   table sits edge-to-edge with the widget card, and flatten the inner table
   container's border/radius so there is no card-in-card frame. */
.ob-recent-apps {
	margin: -16px;
}

.ob-recent-apps :deep(.cn-table-container) {
	border: none;
	border-radius: 0;
	box-shadow: none;
	background: transparent;
}
</style>
