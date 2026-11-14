<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - WidgetBuilder — authors the `widgetDef` $def. Used by DashboardPageEditor.
  -->
<template>
	<div class="widget-builder">
		<div
			v-for="(widget, index) in localWidgets"
			:key="index"
			class="widget-builder__item">
			<div class="widget-builder__row">
				<input
					:value="widget.id || ''"
					type="text"
					class="widget-builder__field"
					:placeholder="t('buildiq', 'Widget id')"
					:aria-label="t('buildiq', 'Widget id')"
					@input="updateField(index, 'id', $event.target.value)" />
				<input
					:value="widget.title || ''"
					type="text"
					class="widget-builder__field"
					:placeholder="t('buildiq', 'Title')"
					:aria-label="t('buildiq', 'Title')"
					@input="updateField(index, 'title', $event.target.value)" />
				<input
					:value="widget.type || ''"
					type="text"
					class="widget-builder__field widget-builder__field--narrow"
					:placeholder="t('buildiq', 'Type')"
					:aria-label="t('buildiq', 'Type')"
					@input="updateField(index, 'type', $event.target.value)" />
				<button
					type="button"
					class="widget-builder__remove"
					:title="t('buildiq', 'Remove widget')"
					@click="removeWidget(index)">
					✕
				</button>
			</div>
			<!-- A chart is configured with the library's own chart form, the one
			     the dashboard's in-app editor uses (pages-chart-widget-authoring D1). -->
			<CnChartWidgetForm
				v-if="widget.type === 'chart'"
				class="widget-builder__chart"
				:editingWidget="chartFormInput(widget)"
				@update:content="updateChart(index, $event)" />
		</div>
		<button type="button" class="widget-builder__add" @click="addWidget">
			+ {{ t('buildiq', 'Add widget') }}
		</button>
	</div>
</template>

<script>
import { CnChartWidgetForm } from '@conduction/nextcloud-vue'
import { chartFormInput, widgetWithChartContent } from './chartWidget.js'

export default {
	name: 'WidgetBuilder',
	components: {
		CnChartWidgetForm,
	},

	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],
	computed: {
		/**
		 * Observed behaviour of `localWidgets` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		localWidgets() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	methods: {
		chartFormInput,

		/**
		 * Write the chart form's content onto the widget: the chart type as
		 * `props.chartKind`, the binding as `dataSource`, never a `content` key.
		 *
		 * @param {number} index - position of the chart widget in the `widgets` array.
		 * @param {{ chartKind: string, dataSource: object }} content - the form's `update:content` payload.
		 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-the-chart-is-saved-in-the-shape-the-manifest-schema-allows-req-bqcw-002
		 */
		updateChart(index, content) {
			const next = this.localWidgets.slice()
			next[index] = widgetWithChartContent(next[index], content)
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `updateField` (retrofit annotation).
		 *
		 * @param {number} index - position of the widget in the `widgets` array.
		 * @param {'id'|'title'|'type'} key - the widgetDef property the edited input is bound to.
		 * @param {string} value - the input's new text; written verbatim, so an
		 *   emptied input keeps the key with an empty string rather than dropping it.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateField(index, key, value) {
			const next = this.localWidgets.slice()
			const current = next[index] || {}
			next[index] = { ...current, [key]: value }
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `addWidget` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addWidget() {
			const next = this.localWidgets.slice()
			next.push({ id: '', title: '', type: 'custom' })
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `removeWidget` (retrofit annotation).
		 *
		 * @param {number} index - position of the widget to drop from the `widgets` array.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeWidget(index) {
			const next = this.localWidgets.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.widget-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.widget-builder__item {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.widget-builder__chart {
	padding: 8px;
	border-left: 2px solid var(--color-border);
}

.widget-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
}

.widget-builder__field {
	flex: 1 1 auto;
	@include c.control;
}

.widget-builder__field--narrow {
	flex: 0 0 130px;
}

.widget-builder__remove {
	@include c.danger-button;
}

.widget-builder__add {
	align-self: flex-start;
	@include c.add-button;
}
</style>
