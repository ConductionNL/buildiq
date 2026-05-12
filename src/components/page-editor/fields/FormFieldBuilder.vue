<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FormFieldBuilder — authors the `formField` $def with validation rules.
  - Used by FormPageEditor AND SettingsPageEditor's flat-field section bodies.
  - REQ-OBPD-006.
  -->
<template>
	<div class="form-field-builder">
		<div v-for="(field, index) in localFields" :key="index" class="form-field-builder__row">
			<input
				:value="field.key || ''"
				type="text"
				class="form-field-builder__field"
				:placeholder="t('openbuilt', 'Key')"
				@input="updateField(index, 'key', $event.target.value)">
			<input
				:value="field.label || ''"
				type="text"
				class="form-field-builder__field"
				:placeholder="t('openbuilt', 'Label')"
				@input="updateField(index, 'label', $event.target.value)">
			<select
				:value="field.type || 'string'"
				class="form-field-builder__field form-field-builder__field--narrow"
				@change="updateField(index, 'type', $event.target.value)">
				<option v-for="t in FIELD_TYPES" :key="t" :value="t">
					{{ t }}
				</option>
			</select>
			<label class="form-field-builder__inline">
				<input
					type="checkbox"
					:checked="!!field.required"
					@change="updateField(index, 'required', $event.target.checked)">
				{{ t('openbuilt', 'Required') }}
			</label>
			<input
				:value="field.pattern || ''"
				type="text"
				class="form-field-builder__field form-field-builder__field--narrow"
				:placeholder="t('openbuilt', 'Pattern')"
				@input="updateField(index, 'pattern', $event.target.value)">
			<button
				type="button"
				class="form-field-builder__remove"
				:title="t('openbuilt', 'Remove field')"
				@click="removeField(index)">
				✕
			</button>
		</div>
		<button type="button" class="form-field-builder__add" @click="addField">
			+ {{ t('openbuilt', 'Add field') }}
		</button>
	</div>
</template>

<script>
const FIELD_TYPES = ['string', 'number', 'boolean', 'select', 'textarea', 'date']

export default {
	name: 'FormFieldBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:modelValue'],
	data() {
		return { FIELD_TYPES }
	},
	computed: {
		localFields() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		updateField(index, key, value) {
			const next = this.localFields.slice()
			const current = next[index] || {}
			if ((value === '' || value === false) && key !== 'key' && key !== 'label' && key !== 'type') {
				const { [key]: _omit, ...rest } = current
				next[index] = rest
			} else {
				next[index] = { ...current, [key]: value }
			}
			this.$emit('update:modelValue', next)
		},
		addField() {
			const next = this.localFields.slice()
			next.push({ key: '', label: '', type: 'string' })
			this.$emit('update:modelValue', next)
		},
		removeField(index) {
			const next = this.localFields.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped>
.form-field-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.form-field-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
}
.form-field-builder__field {
	flex: 1 1 120px;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.form-field-builder__field--narrow {
	flex: 0 0 110px;
}
.form-field-builder__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}
.form-field-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.form-field-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
