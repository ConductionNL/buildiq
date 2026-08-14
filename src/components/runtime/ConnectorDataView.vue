<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ConnectorDataView — runtime widget that renders an OpenConnector-bound
  - data source inside a built (virtual) app. Registered in the v2 registry so
  - a manifest page/widget with `dataSource.connector` can resolve to it
  - (widgetKey `connector-data` / referencable as a custom component).
  -
  - It delegates the fetch + projection + caching to `useConnectorDataSource`
  - and renders the projected rows as a table. Failure-tolerant: a missing
  - OpenConnector (404) surfaces a per-widget error state with a retry action,
  - never a blank page (REQ-OCAS-006, REQ-OCAS-005). Stale-on-error cache hits
  - render with a non-blocking "showing cached data" notice.
  -->
<template>
	<div class="connector-data-view">
		<div v-if="loading" class="connector-data-view__state">
			{{ t('openbuild', 'Loading…') }}
		</div>
		<div
			v-else-if="error"
			class="connector-data-view__state connector-data-view__state--error">
			<p>{{ t('openbuild', 'Could not load data from OpenConnector.') }}</p>
			<NcButton type="secondary" @click="retry">
				{{ t('openbuild', 'Retry') }}
			</NcButton>
		</div>
		<div v-else>
			<p v-if="isStale" class="connector-data-view__stale">
				{{ t('openbuild', 'Showing cached data — a refresh failed.') }}
			</p>
			<table v-if="rows.length" class="connector-data-view__table">
				<thead>
					<tr>
						<th v-for="col in columns" :key="col" scope="col">
							{{ col }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, idx) in rows" :key="idx">
						<td v-for="col in columns" :key="col">
							{{ row[col] === null ? '' : row[col] }}
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="connector-data-view__state">
				{{ t('openbuild', 'No results.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useConnectorDataSource } from '../../composables/useConnectorDataSource.js'

export default {
	name: 'ConnectorDataView',
	components: { NcButton },
	props: {
		// The manifest page/widget `dataSource` object (carries `connector`).
		dataSource: {
			type: Object,
			default: () => ({}),
		},

		// The virtual app id (cache namespacing).
		appId: {
			type: String,
			default: '',
		},
	},

	/**
	 * Bind the runtime resolver to the connector block.
	 *
	 * @param {object} props - component props.
	 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-4.3
	 */
	setup(props) {
		const binding = (props.dataSource && props.dataSource.connector) || {}
		const resolver = useConnectorDataSource({ appId: props.appId, binding })
		return { resolver }
	},

	computed: {
		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006 */
		loading() {
			return this.resolver.loading.value
		},

		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006 */
		error() {
			return this.resolver.error.value
		},

		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006 */
		isStale() {
			return this.resolver.isStale.value
		},

		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006 */
		rows() {
			return this.resolver.data.value || []
		},

		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006 */
		columns() {
			const connector = (this.dataSource && this.dataSource.connector) || {}
			return Object.keys(connector.fields || {})
		},
	},

	mounted() {
		this.resolver.load()
	},

	methods: {
		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006 */
		retry() {
			this.resolver.retry()
		},
	},
}
</script>

<style scoped>
.connector-data-view__state {
	padding: 12px;
	color: var(--color-text-maxcontrast);
}

.connector-data-view__state--error {
	color: var(--color-error);
}

.connector-data-view__stale {
	color: var(--color-warning-text, var(--color-warning));
	margin: 0 0 8px;
}

.connector-data-view__table {
	width: 100%;
	border-collapse: collapse;
}

.connector-data-view__table th,
.connector-data-view__table td {
	text-align: left;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
