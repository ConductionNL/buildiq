<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SidebarSectionBuilder — authors the `sidebarSection` $def.
  - Used by IndexPageEditor (sidebar.columnGroups).
  -->
<template>
	<div class="sidebar-section-builder">
		<div
			v-for="(section, index) in localSections"
			:key="index"
			class="sidebar-section-builder__row">
			<input
				:value="section.id || ''"
				type="text"
				class="sidebar-section-builder__field"
				:placeholder="t('buildiq', 'Section id')"
				:aria-label="t('buildiq', 'Section id')"
				@input="updateField(index, 'id', $event.target.value)" />
			<input
				:value="section.label || ''"
				type="text"
				class="sidebar-section-builder__field"
				:placeholder="t('buildiq', 'Label')"
				:aria-label="t('buildiq', 'Label')"
				@input="updateField(index, 'label', $event.target.value)" />
			<input
				:value="(section.columns || []).join(',')"
				type="text"
				class="sidebar-section-builder__field"
				:placeholder="t('buildiq', 'Columns (comma-separated)')"
				:aria-label="t('buildiq', 'Columns (comma-separated)')"
				@input="updateColumns(index, $event.target.value)" />
			<button
				type="button"
				class="sidebar-section-builder__remove"
				:title="t('buildiq', 'Remove section')"
				@click="removeSection(index)">
				✕
			</button>
		</div>
		<button
			type="button"
			class="sidebar-section-builder__add"
			@click="addSection">
			+ {{ t('buildiq', 'Add section') }}
		</button>
	</div>
</template>

<script>
export default {
	name: 'SidebarSectionBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],
	computed: {
		/**
		 * Observed behaviour of `localSections` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		localSections() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	methods: {
		/**
		 * Observed behaviour of `updateField` (retrofit annotation).
		 *
		 * @param {number} index - position of the section in the `sidebar.columnGroups` array.
		 * @param {'id'|'label'|'columns'} key - the sidebarSection property to write.
		 * @param {string|string[]} value - the input's new text for `id`/`label`, or
		 *   the already-split column-key list forwarded by `updateColumns`. Written
		 *   verbatim, so emptied inputs keep the key with an empty value.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateField(index, key, value) {
			const next = this.localSections.slice()
			const current = next[index] || {}
			next[index] = { ...current, [key]: value }
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `updateColumns` (retrofit annotation).
		 *
		 * @param {number} index - position of the section whose `columns` list is replaced.
		 * @param {string} value - the raw comma-separated column-key text typed by the
		 *   author; split on commas, trimmed, and blank entries dropped before storing.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateColumns(index, value) {
			const cols = value
				.split(',')
				.map((s) => s.trim())
				.filter(Boolean)
			this.updateField(index, 'columns', cols)
		},

		/**
		 * Observed behaviour of `addSection` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addSection() {
			const next = this.localSections.slice()
			next.push({ id: '', label: '', columns: [] })
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `removeSection` (retrofit annotation).
		 *
		 * @param {number} index - position of the section to drop from the `columnGroups` array.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeSection(index) {
			const next = this.localSections.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.sidebar-section-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sidebar-section-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
}

.sidebar-section-builder__field {
	flex: 1 1 auto;
	@include c.control;
}

.sidebar-section-builder__remove {
	@include c.danger-button;
}

.sidebar-section-builder__add {
	align-self: flex-start;
	@include c.add-button;
}
</style>
