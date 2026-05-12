<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SidebarSectionBuilder — authors the `sidebarSection` $def.
  - Used by IndexPageEditor (sidebar.columnGroups).
  -->
<template>
	<div class="sidebar-section-builder">
		<div v-for="(section, index) in localSections" :key="index" class="sidebar-section-builder__row">
			<input
				:value="section.id || ''"
				type="text"
				class="sidebar-section-builder__field"
				:placeholder="t('openbuilt', 'Section id')"
				@input="updateField(index, 'id', $event.target.value)">
			<input
				:value="section.label || ''"
				type="text"
				class="sidebar-section-builder__field"
				:placeholder="t('openbuilt', 'Label')"
				@input="updateField(index, 'label', $event.target.value)">
			<input
				:value="(section.columns || []).join(',')"
				type="text"
				class="sidebar-section-builder__field"
				:placeholder="t('openbuilt', 'Columns (comma-separated)')"
				@input="updateColumns(index, $event.target.value)">
			<button
				type="button"
				class="sidebar-section-builder__remove"
				:title="t('openbuilt', 'Remove section')"
				@click="removeSection(index)">
				✕
			</button>
		</div>
		<button type="button" class="sidebar-section-builder__add" @click="addSection">
			+ {{ t('openbuilt', 'Add section') }}
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
		localSections() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		updateField(index, key, value) {
			const next = this.localSections.slice()
			const current = next[index] || {}
			next[index] = { ...current, [key]: value }
			this.$emit('update:modelValue', next)
		},
		updateColumns(index, value) {
			const cols = value.split(',').map((s) => s.trim()).filter(Boolean)
			this.updateField(index, 'columns', cols)
		},
		addSection() {
			const next = this.localSections.slice()
			next.push({ id: '', label: '', columns: [] })
			this.$emit('update:modelValue', next)
		},
		removeSection(index) {
			const next = this.localSections.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped>
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
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.sidebar-section-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.sidebar-section-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
