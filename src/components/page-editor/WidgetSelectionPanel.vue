<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
	WidgetSelectionPanel — widget/page-section selection affordance for
	"save as block" (component-blocks task 2.2). Lists the selected page's
	uniform v2 `widgets[]` array (the same `widgetEntry` shape every page
	type shares — see app-manifest-v2.schema.json) with checkboxes; a
	single checked widget captures as a single-widget block, several
	checked widgets capture as a section block (identical fragment/remap
	machinery either way — design.md's Open Question, resolved: both in
	scope for v1).
-->
<template>
	<fieldset class="widget-selection-panel">
		<legend>{{ t('buildiq', 'Widgets on this page') }}</legend>

		<p v-if="!widgets.length" class="widget-selection-panel__empty">
			{{ t('buildiq', 'This page has no widgets yet.') }}
		</p>

		<ul v-else class="widget-selection-panel__list">
			<li
				v-for="widget in widgets"
				:key="widget.id"
				class="widget-selection-panel__row">
				<label>
					<input
						type="checkbox"
						:checked="isSelected(widget.id)"
						@change="toggle(widget.id)" />
					<span class="widget-selection-panel__label">
						{{ widget.widgetKey || widget.id }}
					</span>
					<span class="widget-selection-panel__slot">{{
						widget.slot
					}}</span>
				</label>
			</li>
		</ul>

		<button
			type="button"
			class="widget-selection-panel__save-btn"
			:disabled="selectedIds.length === 0"
			@click="openSaveDialog">
			{{
				selectedIds.length > 1
					? t('buildiq', 'Save selected section as block')
					: t('buildiq', 'Save selected widget as block')
			}}
		</button>

		<SaveBlockDialog
			:open="saveDialogOpen"
			:application="application"
			:fragment="captureFragment"
			:existingBlocks="existingBlocks"
			@update:open="saveDialogOpen = $event"
			@saved="onSaved" />
	</fieldset>
</template>

<script>
import SaveBlockDialog from '../../dialogs/SaveBlockDialog.vue'
import { buildSectionFragment } from '../../services/blockCapture.js'

export default {
	name: 'WidgetSelectionPanel',
	components: { SaveBlockDialog },
	props: {
		// The selected page's widgets[] array (uniform v2 widgetEntry shape).
		widgets: { type: Array, default: () => [] },
		// The source Application record, forwarded to SaveBlockDialog.
		application: { type: Object, default: null },
		// Blocks already visible to the caller, for slug-collision checking.
		existingBlocks: { type: Array, default: () => [] },
	},

	emits: ['saved'],
	data() {
		return {
			selectedIds: [],
			saveDialogOpen: false,
		}
	},

	computed: {
		/**
		 * The fragment `SaveBlockDialog` will capture: the single selected
		 * widgetEntry object, or a section wrapper when more than one is
		 * selected.
		 *
		 * @return {?object}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		captureFragment() {
			const selected = this.widgets.filter((w) =>
				this.selectedIds.includes(w.id),
			)
			if (selected.length === 0) {
				return null
			}
			if (selected.length === 1) {
				return selected[0]
			}
			return buildSectionFragment(
				`section-${selected.map((w) => w.id).join('-')}`.slice(0, 60),
				selected,
			)
		},
	},

	watch: {
		/**
		 * Drop any selection that no longer exists on the (possibly
		 * re-fetched) page.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		widgets() {
			const ids = new Set(this.widgets.map((w) => w.id))
			this.selectedIds = this.selectedIds.filter((id) => ids.has(id))
		},
	},

	methods: {
		/**
		 * Whether a widget id is currently checked.
		 *
		 * @param {string} id - the widget id.
		 * @return {boolean}
		 */
		isSelected(id) {
			return this.selectedIds.includes(id)
		},

		/**
		 * Toggle a widget's checked state.
		 *
		 * @param {string} id - the widget id.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		toggle(id) {
			this.selectedIds = this.isSelected(id)
				? this.selectedIds.filter((existing) => existing !== id)
				: [...this.selectedIds, id]
		},

		/**
		 * Open `SaveBlockDialog` for the current selection.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		openSaveDialog() {
			if (this.captureFragment) {
				this.saveDialogOpen = true
			}
		},

		/**
		 * Forward the saved event and reset selection.
		 *
		 * @param {object} payload - `{ slug }` of the saved block.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onSaved(payload) {
			this.selectedIds = []
			this.$emit('saved', payload)
		},
	},
}
</script>

<style scoped>
.widget-selection-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.widget-selection-panel__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.widget-selection-panel__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.widget-selection-panel__row label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
}

.widget-selection-panel__label {
	flex: 1;
}

.widget-selection-panel__slot {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.widget-selection-panel__save-btn {
	align-self: flex-start;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
	cursor: pointer;
}

.widget-selection-panel__save-btn[disabled] {
	cursor: not-allowed;
	opacity: 0.5;
}
</style>
