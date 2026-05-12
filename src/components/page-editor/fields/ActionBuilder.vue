<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ActionBuilder — authors the `action` $def. Used by IndexPageEditor.
  - Implements actions authoring for REQ-OBPD-004.
  -->
<template>
	<div class="action-builder">
		<div v-for="(act, index) in localActions" :key="index" class="action-builder__row">
			<input
				:value="act.id || ''"
				type="text"
				class="action-builder__field"
				:placeholder="t('openbuilt', 'Action id (e.g. edit)')"
				@input="updateField(index, 'id', $event.target.value)">
			<input
				:value="act.label || ''"
				type="text"
				class="action-builder__field"
				:placeholder="t('openbuilt', 'Label (i18n key)')"
				@input="updateField(index, 'label', $event.target.value)">
			<input
				:value="act.icon || ''"
				type="text"
				class="action-builder__field action-builder__field--narrow"
				:placeholder="t('openbuilt', 'Icon')"
				@input="updateField(index, 'icon', $event.target.value)">
			<select
				:value="act.target || ''"
				class="action-builder__field action-builder__field--narrow"
				@change="updateField(index, 'target', $event.target.value)">
				<option value="">
					{{ t('openbuilt', '— target —') }}
				</option>
				<option value="navigate">
					navigate
				</option>
				<option value="emit">
					emit
				</option>
				<option value="none">
					none
				</option>
			</select>
			<button
				type="button"
				class="action-builder__remove"
				:title="t('openbuilt', 'Remove action')"
				@click="removeAction(index)">
				✕
			</button>
		</div>
		<button type="button" class="action-builder__add" @click="addAction">
			+ {{ t('openbuilt', 'Add action') }}
		</button>
	</div>
</template>

<script>
export default {
	name: 'ActionBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:modelValue'],
	computed: {
		localActions() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		updateField(index, key, value) {
			const next = this.localActions.slice()
			const current = next[index] || {}
			if (value === '' && key !== 'id') {
				const { [key]: _omit, ...rest } = current
				next[index] = rest
			} else {
				next[index] = { ...current, [key]: value }
			}
			this.$emit('update:modelValue', next)
		},
		addAction() {
			const next = this.localActions.slice()
			next.push({ id: '', label: '' })
			this.$emit('update:modelValue', next)
		},
		removeAction(index) {
			const next = this.localActions.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped>
.action-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.action-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
}
.action-builder__field {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.action-builder__field--narrow {
	flex: 0 0 110px;
}
.action-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.action-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
