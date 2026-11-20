<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
	Connections of one app built in buildiq (board BqAppWerking,
	built-app-connections-page REQ-BIQ-CONN-005): the integriq sources its
	manifest binds and the integriq connections registered for it, each with
	its health and when integriq last checked it. Read from
	GET /api/applications/{slug}/connections, which answers only to the app's
	owners and editors and to admins.
-->
<template>
	<section class="ob-connections" data-testid="app-connections">
		<header class="ob-connections__header">
			<h3>{{ t('buildiq', 'Connections') }}</h3>
			<p class="ob-connections__hint">
				{{ t('buildiq', 'The outside systems this app reads from, and whether they answered the last time they were checked.') }}
			</p>
		</header>
		<p v-if="state === 'loading'" class="ob-connections__hint" role="status">
			{{ t('buildiq', 'Loading…') }}
		</p>
		<p v-else-if="state === 'error'" class="ob-connections__hint" role="alert">
			{{ t('buildiq', 'The connections could not be loaded.') }}
		</p>
		<template v-else-if="state === 'ready'">
			<NcNoteCard v-if="healthNeedsIntegriq" type="info" data-testid="app-connections-no-integriq">
				{{ t('buildiq', 'Health checks need integriq. Install it to see whether these sources answer.') }}
			</NcNoteCard>
			<p v-if="rows.length === 0" class="ob-connections__hint" data-testid="app-connections-empty">
				{{ t('buildiq', 'This app reads from no outside system.') }}
			</p>
			<table v-else class="ob-connections__table" data-testid="app-connections-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('buildiq', 'Kind') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Name') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Status') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Last checked') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.kind + ':' + row.id" data-testid="app-connections-row">
						<td>{{ kindLabel(row.kind) }}</td>
						<td>
							<a v-if="linkOf(row)" :href="linkOf(row)" data-testid="app-connections-link">{{ row.name }}</a>
							<span v-else>{{ row.name }}</span>
						</td>
						<td>
							<span
								class="ob-connections__status"
								:class="'ob-connections__status--' + (row.status || 'unchecked')"
								data-testid="app-connections-status">
								{{ statusLabel(row.status) }}
							</span>
							<span v-if="row.statusMessage" class="ob-connections__hint" data-testid="app-connections-message">
								{{ row.statusMessage }}
							</span>
						</td>
						<td>{{ checkedLabel(row.checkedAt) }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { connectionLink, connectionStatusLabel, fetchAppConnections } from '../../services/appConnections.js'

export default {
	name: 'ApplicationConnectionsSection',
	components: { NcNoteCard },

	props: {
		appSlug: { type: String, default: '' },
	},

	data() {
		return {
			state: 'idle',
			rows: [],
			healthNeedsIntegriq: false,
		}
	},

	watch: {
		/**
		 * Reload for another app.
		 *
		 * @return {void}
		 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
		 */
		appSlug() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the app's connections.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
		 */
		async load() {
			if (!this.appSlug) {
				return
			}
			this.state = 'loading'
			try {
				const result = await fetchAppConnections(this.appSlug)
				this.rows = result.connections
				this.healthNeedsIntegriq = result.healthNeedsIntegriq
				this.state = 'ready'
			} catch {
				this.rows = []
				this.state = 'error'
			}
		},

		/**
		 * @param {?string} status - the stored status.
		 * @return {string}
		 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
		 */
		statusLabel(status) {
			return connectionStatusLabel(status)
		},

		/**
		 * @param {string} kind - `source` or `integration`.
		 * @return {string}
		 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
		 */
		kindLabel(kind) {
			return kind === 'source' ? t('buildiq', 'Source') : t('buildiq', 'Integration')
		},

		/**
		 * @param {object} row - the connection row.
		 * @return {string}
		 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
		 */
		linkOf(row) {
			return connectionLink(row, this.appSlug)
		},

		/**
		 * When integriq last checked a row, in the reader's locale, or a dash.
		 *
		 * @param {?string} checkedAt - the check time (ISO 8601).
		 * @return {string}
		 * @spec openspec/changes/built-app-connections-page/specs/app-connections/spec.md#requirement-req-biq-conn-005-every-app-built-in-buildiq-has-a-connections-page-with-health
		 */
		checkedLabel(checkedAt) {
			const date = checkedAt ? new Date(checkedAt) : null
			if (!date || Number.isNaN(date.getTime())) {
				return '—'
			}
			return date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.ob-connections {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 4);
}

.ob-connections__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.ob-connections__table {
	width: 100%;
	border-collapse: collapse;
}

.ob-connections__table th,
.ob-connections__table td {
	text-align: start;
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.ob-connections__status {
	font-weight: bold;
	margin-inline-end: calc(var(--default-grid-baseline) * 2);
}

.ob-connections__status--error,
.ob-connections__status--unavailable {
	color: var(--color-error-text);
}

.ob-connections__status--configured {
	color: var(--color-success-text);
}
</style>
