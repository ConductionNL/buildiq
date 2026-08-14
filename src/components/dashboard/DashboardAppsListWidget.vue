<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - DashboardAppsListWidget — Dashboard slot widget that lists the most
  - recently updated virtual applications. Fetches directly from the
  - OpenRegister objects API and renders a compact table with icon, name,
  - status badge, version, last-updated timestamp, and a quick-open link.
  -
  - Registered in registry.js under "DashboardAppsListWidget" (kind: "page")
  - so CnDashboardPage resolves it via the manifest `slots.widget-recent-apps`
  - entry.
  -->
<template>
	<div class="ob-apps-list-widget" data-testid="ob-apps-list-widget">
		<NcLoadingIcon
			v-if="loading"
			:size="32"
			class="ob-apps-list-widget__loading" />

		<NcEmptyContent
			v-else-if="!loading && apps.length === 0"
			:name="t('openbuild', 'No virtual apps yet')"
			:description="
				t(
					'openbuild',
					'Create your first virtual application to get started.',
				)
			">
			<template #icon>
				<span
					class="icon-category-app-bundles"
					style="width: 48px; height: 48px; display: block" />
			</template>
			<template #action>
				<NcButton type="primary" @click="goToApps">
					{{ t('openbuild', 'Create an app') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<table v-else class="ob-apps-list-widget__table">
			<thead>
				<tr>
					<th scope="col" class="ob-apps-list-widget__col-name">
						{{ t('openbuild', 'App') }}
					</th>
					<th scope="col" class="ob-apps-list-widget__col-status">
						{{ t('openbuild', 'Status') }}
					</th>
					<th scope="col" class="ob-apps-list-widget__col-version">
						{{ t('openbuild', 'Version') }}
					</th>
					<th scope="col" class="ob-apps-list-widget__col-updated">
						{{ t('openbuild', 'Updated') }}
					</th>
					<!-- Row-actions column: no visible caption, but still a column
					     header, so it keeps `scope="col"` and an sr-only name. -->
					<th scope="col" class="ob-apps-list-widget__col-actions">
						<span class="hidden-visually">{{
							t('openbuild', 'Actions')
						}}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="app in apps"
					:key="appId(app)"
					class="ob-apps-list-widget__row"
					@click="openApp(app)">
					<td class="ob-apps-list-widget__col-name">
						<div class="ob-apps-list-widget__name-cell">
							<img
								class="ob-apps-list-widget__icon"
								:src="`/index.php/apps/openbuild/icons/${app.slug}-dark.svg`"
								:alt="app.name || app.slug"
								width="20"
								height="20"
								@error="onIconError" />
							<span class="ob-apps-list-widget__name">{{
								app.name
								|| app.slug
								|| t('openbuild', 'Untitled app')
							}}</span>
							<span class="ob-apps-list-widget__slug">{{
								app.slug
							}}</span>
						</div>
					</td>
					<td class="ob-apps-list-widget__col-status">
						<span
							class="ob-apps-list-widget__badge"
							:class="`ob-apps-list-widget__badge--${appStatus(app)}`">
							{{ appStatusLabel(app) }}
						</span>
					</td>
					<td class="ob-apps-list-widget__col-version">
						{{ appVersion(app) }}
					</td>
					<td class="ob-apps-list-widget__col-updated">
						{{ appUpdated(app) }}
					</td>
					<td class="ob-apps-list-widget__col-actions">
						<NcButton
							type="tertiary"
							:aria-label="
								t('openbuild', 'Open {name}', {
									name: app.name || app.slug,
								})
							"
							@click.stop="openApp(app)">
							<template #icon>
								<ArrowRight :size="16" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<div
			v-if="!loading && total > apps.length"
			class="ob-apps-list-widget__footer">
			<NcButton type="tertiary" @click="goToApps">
				{{ t('openbuild', 'View all {count} apps', { count: total }) }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl, imagePath } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'

const STATUS_LABELS = {
	draft: 'Draft',
	published: 'Published',
	archived: 'Archived',
}

export default {
	name: 'DashboardAppsListWidget',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ArrowRight,
	},

	props: {
		item: { type: Object, default: null },
		widget: { type: Object, default: null },
	},

	data() {
		return {
			loading: true,
			apps: [],
			total: 0,
		}
	},

	mounted() {
		this.fetchApps()
	},

	methods: {
		t,

		async fetchApps() {
			this.loading = true
			try {
				const url = generateUrl('/apps/openregister/api/graphql')
				const { data } = await axios.post(url, {
					query: `{
						application(first: 20) {
							edges { node { _uuid name slug description _updated productionVersion } }
							totalCount
						}
					}`,
				})
				const conn = data?.data?.application
				const edges = conn?.edges ?? []
				const nodes = edges.map((e) => e.node)
				// Sort by most recently updated, take first 8.
				nodes.sort((a, b) => {
					const at = a._updated ? new Date(a._updated).getTime() : 0
					const bt = b._updated ? new Date(b._updated).getTime() : 0
					return bt - at
				})
				this.apps = nodes.slice(0, 8)
				this.total = conn?.totalCount ?? nodes.length
			} catch {
				this.apps = []
				this.total = 0
			} finally {
				this.loading = false
			}
		},

		appId(app) {
			return (
				app._uuid
				|| (app['@self'] && app['@self'].id)
				|| app.uuid
				|| app.id
				|| app.slug
			)
		},

		appStatus(app) {
			const pv = app.productionVersion
			const status = (pv && pv.status) || app.status
			return ['draft', 'published', 'archived'].includes(status)
				? status
				: 'draft'
		},

		appStatusLabel(app) {
			return t('openbuild', STATUS_LABELS[this.appStatus(app)] || 'Draft')
		},

		appVersion(app) {
			return (
				(app.productionVersion && app.productionVersion.semver)
				|| app.version
				|| '—'
			)
		},

		appUpdated(app) {
			const raw =
				app._updated || (app['@self'] && app['@self'].updated) || app.updated
			if (!raw) return '—'
			try {
				return new Date(raw).toLocaleDateString(undefined, {
					day: 'numeric',
					month: 'short',
					year: 'numeric',
				})
			} catch {
				return raw
			}
		},

		onIconError(e) {
			// imagePath resolves to the app's real web root (e.g.
			// /apps/openbuild/img/app-dark.svg, or /apps-shared/… in dev). The
			// previous hardcoded '/apps/openbuild/img/app-dark.svg' 404s when the web
			// root differs, which re-fired this error handler and re-set the same
			// failing src in an infinite loop (spamming the request + draining
			// resources).
			const fallback = imagePath('openbuild', 'app-dark.svg')
			// Guard against re-entry: if the fallback itself fails to load, the error
			// event lands here again — bail once we're already showing the fallback
			// so we swap the src at most once. Compare the literal attribute (not the
			// resolved .src property, which is absolute) against the fallback path.
			if (e.target.getAttribute('src') === fallback) {
				return
			}
			e.target.src = fallback
		},

		openApp(app) {
			const id = this.appId(app)
			if (id && this.$router) {
				this.$router.push({
					name: 'VirtualAppDetail',
					params: { objectId: id },
				})
			}
		},

		goToApps() {
			if (this.$router) {
				this.$router.push({ name: 'VirtualApps' })
			}
		},
	},
}
</script>

<style scoped>
.ob-apps-list-widget {
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
}

.ob-apps-list-widget__loading {
	margin: auto;
}

.ob-apps-list-widget__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.ob-apps-list-widget__table thead th {
	padding: 6px 12px;
	text-align: left;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast, #888);
	border-bottom: 1px solid var(--color-border, #ddd);
	white-space: nowrap;
}

.ob-apps-list-widget__row {
	cursor: pointer;
	transition: background 0.1s;
}

.ob-apps-list-widget__row:hover {
	background: var(--color-background-hover, rgba(0, 0, 0, 0.04));
}

.ob-apps-list-widget__row td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border-dark, #f0f0f0);
	vertical-align: middle;
}

.ob-apps-list-widget__name-cell {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ob-apps-list-widget__icon {
	width: 20px;
	height: 20px;
	object-fit: contain;
	flex-shrink: 0;
	border-radius: var(--border-radius, 4px);
}

.ob-apps-list-widget__name {
	font-weight: 500;
}

.ob-apps-list-widget__slug {
	font-family: monospace;
	font-size: 11px;
	color: var(--color-text-maxcontrast, #888);
}

.ob-apps-list-widget__badge {
	display: inline-block;
	font-size: 11px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ob-apps-list-widget__badge--draft {
	background: var(--color-background-dark, #eee);
	color: var(--color-main-text, #222);
}

.ob-apps-list-widget__badge--published {
	background: var(--color-success-default-background, rgba(70, 186, 97, 0.2));
	color: var(--color-success-text, #2d8a3e);
}

.ob-apps-list-widget__badge--archived {
	background: var(--color-warning-default-background, rgba(201, 121, 0, 0.2));
	color: var(--color-warning-text, #8a5300);
}

.ob-apps-list-widget__col-name {
	width: 45%;
}

.ob-apps-list-widget__col-status {
	width: 15%;
}

.ob-apps-list-widget__col-version {
	width: 12%;
	font-family: monospace;
	font-size: 12px;
}

.ob-apps-list-widget__col-updated {
	width: 18%;
	color: var(--color-text-maxcontrast, #888);
	font-size: 12px;
}

.ob-apps-list-widget__col-actions {
	width: 10%;
	text-align: right;
}

.ob-apps-list-widget__footer {
	padding: 8px 12px;
	text-align: center;
	border-top: 1px solid var(--color-border, #ddd);
	margin-top: auto;
}

/*
 * WCAG 2.2 AA 2.3.3 Animation from Interactions. The row animates its
 * background on hover; honour an OS-level reduced-motion preference. Scoped to
 * this widget's own selector so it cannot reach into NC component internals.
 */
@media (prefers-reduced-motion: reduce) {
	.ob-apps-list-widget__row {
		transition: none;
	}
}
</style>
