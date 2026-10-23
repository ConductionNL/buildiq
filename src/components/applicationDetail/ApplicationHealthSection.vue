<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
	Performance and errors of one app version (board BqAppWerking): the counts
	running copies of the app sent, for the window the page's toggle chose.
	Read from GET /api/applications/{app}/versions/{version}/health, which
	answers only to whoever may see the version's insights.
-->
<template>
	<section class="ob-health" data-testid="app-health">
		<header class="ob-health__header">
			<h3>{{ t('buildiq', 'Performance and errors') }}</h3>
			<p class="ob-health__hint">
				{{ t('buildiq', 'What the people using this version noticed: how fast its pages load and how often a request fails.') }}
			</p>
		</header>

		<p v-if="state === 'loading'" class="ob-health__hint" role="status">
			{{ t('buildiq', 'Loading…') }}
		</p>
		<p v-else-if="state === 'error'" class="ob-health__hint" role="alert">
			{{ t('buildiq', 'The performance figures could not be loaded.') }}
		</p>
		<p v-else-if="!health || !health.loads" class="ob-health__hint" data-testid="app-health-empty">
			{{ t('buildiq', 'No page loads were reported in this window yet.') }}
		</p>
		<template v-else>
			<dl class="ob-health__figures" data-testid="app-health-figures">
				<div>
					<dt>{{ t('buildiq', 'Average load time') }}</dt>
					<dd>{{ seconds(health.averageLoadMs) }}</dd>
				</div>
				<div>
					<dt>{{ t('buildiq', 'Loads of 3 s or more') }}</dt>
					<dd>{{ percent(health.slowLoadShare) }}</dd>
				</div>
				<div>
					<dt>{{ t('buildiq', 'Failed requests') }}</dt>
					<dd>
						{{ health.failures }}
						<span class="ob-health__hint">{{ t('buildiq', '{share} of {count} requests', { share: percent(health.failureShare), count: health.requests }) }}</span>
					</dd>
				</div>
				<div>
					<dt>{{ t('buildiq', 'Requests over 1 s') }}</dt>
					<dd>{{ percent(health.slowRequestShare) }}</dd>
				</div>
			</dl>

			<table class="ob-health__table" data-testid="app-health-pages">
				<caption>{{ t('buildiq', 'Slowest pages') }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('buildiq', 'Page') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Average') }}
						</th>
						<th scope="col">
							{{ t('buildiq', '3 s or more') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Loads') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Failed requests') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="page in health.slowestPages" :key="page.pageId">
						<th scope="row">
							{{ pageLabel(page.pageId) }}
						</th>
						<td>{{ seconds(page.averageLoadMs) }}</td>
						<td>{{ percent(page.slowLoadShare) }}</td>
						<td>{{ page.loads }}</td>
						<td>{{ page.failures }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ApplicationHealthSection',

	props: {
		/**
		 * The application UUID.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 */
		appUuid: { type: String, default: '' },
		/**
		 * The version UUID.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 */
		versionUuid: { type: String, default: '' },
		/**
		 * The window: 7d, 30d or 90d.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 */
		window: { type: String, default: '7d' },
		/**
		 * Page titles by page id, from the version's manifest.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 */
		pageTitles: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			state: 'idle',
			health: null,
		}
	},

	watch: {
		appUuid() {
			this.load()
		},

		versionUuid() {
			this.load()
		},

		window() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the health of the version for the window.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.appUuid || !this.versionUuid) {
				return
			}
			this.state = 'loading'
			try {
				const url = generateUrl(
					`/apps/buildiq/api/applications/${encodeURIComponent(this.appUuid)}/versions/${encodeURIComponent(this.versionUuid)}/health`,
				)
				const { data } = await axios.get(url, { params: { window: this.window } })
				this.health = data && typeof data === 'object' ? data : null
				this.state = 'done'
			} catch {
				this.health = null
				this.state = 'error'
			}
		},

		/**
		 * Milliseconds as seconds with one decimal, in the user's locale.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 * @param {number|null} ms The duration.
		 * @return {string} For example "2,4 s".
		 */
		seconds(ms) {
			if (ms === null || ms === undefined) {
				return this.t('buildiq', 'Not known')
			}
			return this.t('buildiq', '{seconds} s', {
				seconds: (ms / 1000).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
			})
		},

		/**
		 * A share as a percentage.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 * @param {number|null} share Between 0 and 1.
		 * @return {string} For example "4%".
		 */
		percent(share) {
			if (share === null || share === undefined) {
				return this.t('buildiq', 'Not known')
			}
			return (share).toLocaleString(undefined, { style: 'percent', maximumFractionDigits: 1 })
		},

		/**
		 * A page's title from the manifest, or its id.
		 *
		 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
		 * @param {string} pageId The page id.
		 * @return {string} The label.
		 */
		pageLabel(pageId) {
			return this.pageTitles[pageId] || pageId
		},
	},
}
</script>

<style scoped>
.ob-health {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);
}

.ob-health__header h3 {
	margin: 0;
}

.ob-health__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.ob-health__figures {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin: 0;
}

.ob-health__figures dt {
	color: var(--color-text-maxcontrast);
}

.ob-health__figures dd {
	margin: 0;
	font-size: 1.4rem;
	font-weight: 600;
}

.ob-health__table {
	width: 100%;
	border-collapse: collapse;
}

.ob-health__table caption {
	text-align: start;
	font-weight: 600;
	padding-bottom: var(--default-grid-baseline, 4px);
}

.ob-health__table th,
.ob-health__table td {
	text-align: start;
	padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 2) var(--default-grid-baseline, 4px) 0;
	border-bottom: 1px solid var(--color-border);
}

.ob-health__table thead th {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}
</style>
