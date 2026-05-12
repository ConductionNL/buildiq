<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DashboardPageEditor — widgets list + layout grid editor. Reuses
  - WidgetBuilder.vue + LayoutItemBuilder.vue.
  -->
<template>
	<div class="dashboard-page-editor">
		<h3 class="dashboard-page-editor__title">
			{{ t('openbuilt', 'Dashboard page') }}
		</h3>

		<fieldset class="dashboard-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Widgets') }}</legend>
			<WidgetBuilder
				:model-value="config.widgets || []"
				@update:modelValue="update('widgets', $event)" />
			<InlineFieldMark :error="markFor('widgets')" />
		</fieldset>

		<fieldset class="dashboard-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Layout') }}</legend>
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
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'DashboardPageEditor',
	components: { WidgetBuilder, LayoutItemBuilder, InlineFieldMark },
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
		validatedConfigKeys() {
			return ['widgets', 'layout']
		},
	},
	methods: {
		update(key, value) {
			const next = { ...this.config }
			if (!value || (Array.isArray(value) && value.length === 0)) {
				delete next[key]
			} else {
				next[key] = value
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
