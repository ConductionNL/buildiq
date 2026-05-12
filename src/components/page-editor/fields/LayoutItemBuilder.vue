<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - LayoutItemBuilder — authors the `layoutItem` $def. Used by DashboardPageEditor.
  -->
<template>
	<div class="layout-item-builder">
		<div v-for="(item, index) in localItems" :key="index" class="layout-item-builder__row">
			<input
				:value="item.widgetId || ''"
				type="text"
				class="layout-item-builder__field"
				:placeholder="t('openbuilt', 'widget id')"
				@input="updateField(index, 'widgetId', $event.target.value)">
			<label class="layout-item-builder__pair">
				X
				<input
					:value="item.gridX"
					type="number"
					min="0"
					class="layout-item-builder__num"
					@input="updateNum(index, 'gridX', $event.target.value)">
			</label>
			<label class="layout-item-builder__pair">
				Y
				<input
					:value="item.gridY"
					type="number"
					min="0"
					class="layout-item-builder__num"
					@input="updateNum(index, 'gridY', $event.target.value)">
			</label>
			<label class="layout-item-builder__pair">
				W
				<input
					:value="item.gridWidth"
					type="number"
					min="1"
					class="layout-item-builder__num"
					@input="updateNum(index, 'gridWidth', $event.target.value)">
			</label>
			<label class="layout-item-builder__pair">
				H
				<input
					:value="item.gridHeight"
					type="number"
					min="1"
					class="layout-item-builder__num"
					@input="updateNum(index, 'gridHeight', $event.target.value)">
			</label>
			<button
				type="button"
				class="layout-item-builder__remove"
				:title="t('openbuilt', 'Remove layout item')"
				@click="removeItem(index)">
				✕
			</button>
		</div>
		<button type="button" class="layout-item-builder__add" @click="addItem">
			+ {{ t('openbuilt', 'Add layout item') }}
		</button>
	</div>
</template>

<script>
export default {
	name: 'LayoutItemBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:modelValue'],
	computed: {
		localItems() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		updateField(index, key, value) {
			const next = this.localItems.slice()
			next[index] = { ...next[index], [key]: value }
			this.$emit('update:modelValue', next)
		},
		updateNum(index, key, value) {
			const num = parseInt(value, 10)
			this.updateField(index, key, Number.isNaN(num) ? 0 : num)
		},
		addItem() {
			const next = this.localItems.slice()
			next.push({ id: next.length + 1, widgetId: '', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2 })
			this.$emit('update:modelValue', next)
		},
		removeItem(index) {
			const next = this.localItems.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped>
.layout-item-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.layout-item-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
}
.layout-item-builder__field {
	flex: 1 1 160px;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.layout-item-builder__pair {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}
.layout-item-builder__num {
	width: 64px;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.layout-item-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.layout-item-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
