<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ConnectorListPage — the runtime page a connector-bound index page renders
  - as (registry `connector-list`). `services/connectorPages.js` rewrites an
  - index page with `config.dataSource.connector` into a custom page on this
  - component, in memory only.
  -
  - It shows the page title, a search box that filters the loaded rows on the
  - mapped fields, and `ConnectorDataView` for the rows with its loading, error
  - with Retry, stale and empty states (REQ-BQCB-002).
  -->
<template>
	<div class="connector-list-page" data-testid="connector-list-page">
		<h2 v-if="title" class="connector-list-page__title">
			{{ title }}
		</h2>
		<div v-if="hasFields" class="connector-list-page__search">
			<NcTextField
				:modelValue="search"
				:label="t('buildiq', 'Search')"
				data-testid="connector-list-search"
				@update:modelValue="onSearch" />
		</div>
		<ConnectorDataView
			:dataSource="dataSource"
			:appId="appId"
			:search="search" />
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import ConnectorDataView from './ConnectorDataView.vue'

export default {
	name: 'ConnectorListPage',
	components: { ConnectorDataView, NcTextField },
	props: {
		// The page title (lifted from the manifest page by CnPageRenderer).
		title: {
			type: String,
			default: '',
		},

		// The page's `dataSource` (carries `connector`).
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

	data() {
		return {
			search: '',
		}
	},

	computed: {
		/**
		 * Whether the binding maps any field the search can match.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/integrations-connector-bound-pages/specs/connector-bound-page-runtime/spec.md#requirement-the-list-page-shows-every-state-of-the-fetch-req-bqcb-002
		 */
		hasFields() {
			const connector = (this.dataSource && this.dataSource.connector) || {}
			return Object.keys(connector.fields || {}).length > 0
		},
	},

	methods: {
		/**
		 * Store the search term the rows are filtered on.
		 *
		 * @param {string} value - the search box's text.
		 * @spec openspec/changes/integrations-connector-bound-pages/specs/connector-bound-page-runtime/spec.md#requirement-the-list-page-shows-every-state-of-the-fetch-req-bqcb-002
		 */
		onSearch(value) {
			this.search = value === null || value === undefined ? '' : String(value)
		},
	},
}
</script>

<style scoped>
.connector-list-page {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.connector-list-page__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
	color: var(--color-main-text);
}

.connector-list-page__search {
	max-width: 400px;
}
</style>
