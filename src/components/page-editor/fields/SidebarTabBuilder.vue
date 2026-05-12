<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SidebarTabBuilder — authors the `sidebarTab` $def.
  - Used by DetailPageEditor (sidebarProps.tabs) and IndexPageEditor (sidebar tabs).
  - REQ-OBPD-005.
  -->
<template>
	<div class="sidebar-tab-builder">
		<div v-for="(tab, index) in localTabs" :key="index" class="sidebar-tab-builder__row">
			<input
				:value="tab.id || ''"
				type="text"
				class="sidebar-tab-builder__field"
				:placeholder="t('openbuilt', 'Tab id')"
				@input="updateField(index, 'id', $event.target.value)">
			<input
				:value="tab.label || ''"
				type="text"
				class="sidebar-tab-builder__field"
				:placeholder="t('openbuilt', 'Label (i18n key)')"
				@input="updateField(index, 'label', $event.target.value)">
			<input
				:value="tab.icon || ''"
				type="text"
				class="sidebar-tab-builder__field sidebar-tab-builder__field--narrow"
				:placeholder="t('openbuilt', 'Icon')"
				@input="updateField(index, 'icon', $event.target.value)">
			<input
				:value="tab.component || ''"
				type="text"
				class="sidebar-tab-builder__field"
				:placeholder="t('openbuilt', 'Component (registry key)')"
				@input="updateField(index, 'component', $event.target.value)">
			<button
				type="button"
				class="sidebar-tab-builder__remove"
				:title="t('openbuilt', 'Remove tab')"
				@click="removeTab(index)">
				✕
			</button>
		</div>
		<button type="button" class="sidebar-tab-builder__add" @click="addTab">
			+ {{ t('openbuilt', 'Add tab') }}
		</button>
		<p class="sidebar-tab-builder__hint">
			{{ t('openbuilt', 'Each tab declares either a list of widgets OR a component (mutually exclusive).') }}
		</p>
	</div>
</template>

<script>
export default {
	name: 'SidebarTabBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:modelValue'],
	computed: {
		localTabs() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		updateField(index, key, value) {
			const next = this.localTabs.slice()
			const current = next[index] || {}
			if (value === '') {
				const { [key]: _omit, ...rest } = current
				next[index] = rest
			} else {
				next[index] = { ...current, [key]: value }
			}
			this.$emit('update:modelValue', next)
		},
		addTab() {
			const next = this.localTabs.slice()
			next.push({ id: '', label: '' })
			this.$emit('update:modelValue', next)
		},
		removeTab(index) {
			const next = this.localTabs.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped>
.sidebar-tab-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.sidebar-tab-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
}
.sidebar-tab-builder__field {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.sidebar-tab-builder__field--narrow {
	flex: 0 0 100px;
}
.sidebar-tab-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.sidebar-tab-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.sidebar-tab-builder__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
