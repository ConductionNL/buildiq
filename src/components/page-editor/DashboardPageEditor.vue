<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DashboardPageEditor — widgets list + layout grid editor. Reuses
  - WidgetBuilder.vue + LayoutItemBuilder.vue.
  -->
<template>
	<div class="dashboard-page-editor">
		<h3 class="dashboard-page-editor__title">
			{{ t('buildiq', 'Dashboard page') }}
		</h3>

		<DataSourceOriginToggle
			:data-source="config.dataSource || {}"
			@update:dataSource="onDataSourceUpdate" />

		<fieldset class="dashboard-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Widgets') }}</legend>
			<WidgetBuilder
				:modelValue="config.widgets || []"
				@update:modelValue="update('widgets', $event)"
				@add:connectorWidget="placeConnectorWidget" />
			<button
				type="button"
				class="dashboard-page-editor__add-chart"
				@click="addChart">
				+ {{ t('buildiq', 'Add chart') }}
			</button>
			<InlineFieldMark :error="markFor('widgets')" />
		</fieldset>

		<fieldset class="dashboard-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Layout') }}</legend>
			<LayoutItemBuilder
				:modelValue="config.layout || []"
				@update:modelValue="update('layout', $event)" />
			<InlineFieldMark :error="markFor('layout')" />
		</fieldset>
	</div>
</template>

<script>
import DataSourceOriginToggle from './DataSourceOriginToggle.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import LayoutItemBuilder from './fields/LayoutItemBuilder.vue'
import WidgetBuilder from './fields/WidgetBuilder.vue'
import { pageEditorBaseMixin } from '../../mixins/pageEditorBase.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'
import { layoutWithItemFor, newChartWidget, nextChartId } from './fields/chartWidget.js'

export default {
	name: 'DashboardPageEditor',
	components: {
		WidgetBuilder,
		LayoutItemBuilder,
		InlineFieldMark,
		DataSourceOriginToggle,
	},

	mixins: [pageEditorBaseMixin, pageEditorValidationMixin],
	props: {
		pageType: {
			type: String,
			default: 'dashboard',
		},
	},

	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
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
		 * Add a connector widget to the dashboard: its definition to `widgets`
		 * and a full-width cell below the existing layout to `layout`, in one
		 * update so neither write overwrites the other (REQ-BQCB-003).
		 *
		 * @param {object} widget - the `connector-data` widget definition.
		 * @spec openspec/changes/integrations-connector-bound-pages/specs/connector-bound-page-runtime/spec.md#requirement-a-maker-can-place-a-connector-widget-on-a-dashboard-req-bqcb-003
		 */
		placeConnectorWidget(widget) {
			const widgets = Array.isArray(this.config.widgets)
				? this.config.widgets
				: []
			const layout = Array.isArray(this.config.layout)
				? this.config.layout
				: []
			const bottom = layout.reduce(
				(max, item) =>
					Math.max(
						max,
						(Number(item && item.gridY) || 0)
							+ (Number(item && item.gridHeight) || 0),
					),
				0,
			)
			this.$emit('update:config', {
				...this.config,
				widgets: [...widgets, widget],
				layout: [
					...layout,
					{
						id: widget.id,
						widgetId: widget.id,
						gridX: 0,
						gridY: bottom,
						gridWidth: 12,
						gridHeight: 4,
					},
				],
			})
		},

		/**
		 * Add a chart widget with a unique id and, in the same change, a
		 * layout item for it at the first free row, unless one already
		 * references that id: a widget without a layout item is not drawn.
		 *
		 * @spec openspec/changes/pages-chart-widget-authoring/specs/page-chart-authoring/spec.md#requirement-a-new-chart-is-placed-on-the-page-req-bqcw-003
		 */
		addChart() {
			const widgets = Array.isArray(this.config.widgets) ? this.config.widgets : []
			const id = nextChartId(widgets)
			this.$emit('update:config', {
				...this.config,
				widgets: [...widgets, newChartWidget(id)],
				layout: layoutWithItemFor(this.config.layout, id),
			})
		},

		/**
		 * Persist a `dataSource` change from the origin toggle onto the
		 * dashboard config (REQ-OCAS-002).
		 *
		 * @param {object} dataSource - the updated dataSource object.
		 * @spec openspec/changes/archive/2026-06-14-openconnector-api-sources/tasks.md#task-3.2
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

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

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

.dashboard-page-editor__add-chart {
	align-self: flex-start;
	margin-top: 6px;
	@include c.add-button;
}

.dashboard-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}
</style>
