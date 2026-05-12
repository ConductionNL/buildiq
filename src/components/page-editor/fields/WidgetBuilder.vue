<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - WidgetBuilder — authors the `widgetDef` $def. Used by DashboardPageEditor.
  -->
<template>
	<div class="widget-builder">
		<div v-for="(widget, index) in localWidgets" :key="index" class="widget-builder__row">
			<input
				:value="widget.id || ''"
				type="text"
				class="widget-builder__field"
				:placeholder="t('openbuilt', 'Widget id')"
				@input="updateField(index, 'id', $event.target.value)">
			<input
				:value="widget.title || ''"
				type="text"
				class="widget-builder__field"
				:placeholder="t('openbuilt', 'Title')"
				@input="updateField(index, 'title', $event.target.value)">
			<input
				:value="widget.type || ''"
				type="text"
				class="widget-builder__field widget-builder__field--narrow"
				:placeholder="t('openbuilt', 'Type')"
				@input="updateField(index, 'type', $event.target.value)">
			<button
				type="button"
				class="widget-builder__remove"
				:title="t('openbuilt', 'Remove widget')"
				@click="removeWidget(index)">
				✕
			</button>
		</div>
		<button type="button" class="widget-builder__add" @click="addWidget">
			+ {{ t('openbuilt', 'Add widget') }}
		</button>
	</div>
</template>

<script>
export default {
	name: 'WidgetBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:modelValue'],
	computed: {
		localWidgets() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		updateField(index, key, value) {
			const next = this.localWidgets.slice()
			const current = next[index] || {}
			next[index] = { ...current, [key]: value }
			this.$emit('update:modelValue', next)
		},
		addWidget() {
			const next = this.localWidgets.slice()
			next.push({ id: '', title: '', type: 'custom' })
			this.$emit('update:modelValue', next)
		},
		removeWidget(index) {
			const next = this.localWidgets.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped>
.widget-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.widget-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
}
.widget-builder__field {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.widget-builder__field--narrow {
	flex: 0 0 130px;
}
.widget-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.widget-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
