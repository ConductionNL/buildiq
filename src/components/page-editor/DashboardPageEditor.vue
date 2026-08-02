<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DashboardPageEditor — widgets list + layout grid editor. Reuses
  - WidgetBuilder.vue + LayoutItemBuilder.vue.
  -->
<template>
	<div class="dashboard-page-editor">
		<h3 class="dashboard-page-editor__title">
			{{ t('openbuild', 'Dashboard page') }}
		</h3>

		<DataSourceOriginToggle
			:data-source="config.dataSource || {}"
			@update:data-source="onDataSourceUpdate" />

		<fieldset class="dashboard-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Widgets') }}</legend>
			<WidgetBuilder
				:model-value="config.widgets || []"
				@update:modelValue="update('widgets', $event)" />
			<InlineFieldMark :error="markFor('widgets')" />
		</fieldset>

		<fieldset class="dashboard-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Layout') }}</legend>
			<LayoutItemBuilder
				:model-value="config.layout || []"
				@update:modelValue="update('layout', $event)" />
			<InlineFieldMark :error="markFor('layout')" />
		</fieldset>
	</div>
</template>

<script>
import WidgetBuilder from './fields/WidgetBuilder.vue'
import LayoutItemBuilder from './fields/LayoutItemBuilder.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import DataSourceOriginToggle from './DataSourceOriginToggle.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'DashboardPageEditor',
	components: { WidgetBuilder, LayoutItemBuilder, InlineFieldMark, DataSourceOriginToggle },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'dashboard',
		},
		appSlug: {
			type: String,
			default: '',
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['widgets', 'layout']
		},
	},
	methods: {
		/**
		 * Write one key on the page's `config` block. Only the named key is
		 * touched, so config keys this editor does not surface round-trip
		 * losslessly.
		 *
		 * @param {string} key - the config key being written: `widgets` or `layout`.
		 * @param {Array<object>} value - the rebuilt list from WidgetBuilder / LayoutItemBuilder. Any falsy value or an empty array deletes the key rather than storing `[]`.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (!value || (Array.isArray(value) && value.length === 0)) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * Persist a `dataSource` change from the origin toggle onto the
		 * dashboard config (REQ-OCAS-002).
		 *
		 * @param {object} dataSource - the updated dataSource object.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.2
		 */
		onDataSourceUpdate(dataSource) {
			const next = { ...this.config }
			if (!dataSource || Object.keys(dataSource).length === 0) {
				delete next.dataSource
			} else {
				next.dataSource = dataSource
			}
			this.$emit('update:config', next)
		},
	},
}
</script>

<style scoped>
.dashboard-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.dashboard-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.dashboard-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.dashboard-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}
</style>
