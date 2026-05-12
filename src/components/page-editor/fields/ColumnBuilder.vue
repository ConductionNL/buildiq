<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ColumnBuilder — authors the `column` $def, round-tripping both the
  - typed-object shape AND the legacy string shorthand. Surfaces
  - `@self.*` virtual columns when bound to a schema.
  - Used by IndexPageEditor and LogsPageEditor (REQ-OBPD-004).
  -->
<template>
	<div class="column-builder">
		<div v-for="(col, index) in localColumns" :key="index" class="column-builder__row">
			<select
				:value="rowKey(col)"
				class="column-builder__key"
				@change="onKeyChange(index, $event.target.value)">
				<option value="">
					{{ t('openbuilt', '— select column —') }}
				</option>
				<optgroup :label="t('openbuilt', 'Schema properties')">
					<option v-for="key in schemaPropertyKeys" :key="key" :value="key">
						{{ key }}
					</option>
				</optgroup>
				<optgroup :label="t('openbuilt', 'Metadata (@self.*)')">
					<option v-for="key in SELF_VIRTUAL_KEYS" :key="key" :value="key">
						{{ key }}
					</option>
				</optgroup>
			</select>
			<input
				:value="rowLabel(col)"
				type="text"
				class="column-builder__label"
				:placeholder="t('openbuilt', 'Label (i18n key)')"
				@input="onLabelInput(index, $event.target.value)">
			<button
				type="button"
				class="column-builder__remove"
				:title="t('openbuilt', 'Remove column')"
				@click="removeColumn(index)">
				✕
			</button>
		</div>
		<button type="button" class="column-builder__add" @click="addColumn">
			+ {{ t('openbuilt', 'Add column') }}
		</button>
	</div>
</template>

<script>
const SELF_VIRTUAL_KEYS = [
	'@self.uuid',
	'@self.created',
	'@self.updated',
	'@self.owner',
	'@self.organisation',
	'@self.locked',
]

export default {
	name: 'ColumnBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
		schemaProperties: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['update:modelValue'],
	data() {
		return {
			SELF_VIRTUAL_KEYS,
		}
	},
	computed: {
		localColumns() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
		schemaPropertyKeys() {
			return Object.keys(this.schemaProperties || {})
		},
	},
	methods: {
		rowKey(col) {
			if (typeof col === 'string') {
				return col
			}
			return (col && (col.key || col.property)) || ''
		},
		rowLabel(col) {
			if (typeof col === 'string') {
				return ''
			}
			return (col && col.label) || ''
		},
		onKeyChange(index, value) {
			const next = this.localColumns.slice()
			const existing = next[index]
			if (typeof existing === 'string' || !existing) {
				// Stay in string shorthand when no label exists.
				next[index] = value
			} else {
				next[index] = { ...existing, key: value }
			}
			this.$emit('update:modelValue', next)
		},
		onLabelInput(index, value) {
			const next = this.localColumns.slice()
			const existing = next[index]
			// Promote string shorthand to typed object once a label is added.
			const key = this.rowKey(existing)
			if (value) {
				next[index] = { key, label: value }
			} else if (typeof existing === 'object' && existing) {
				const { label, ...rest } = existing // eslint-disable-line no-unused-vars
				next[index] = Object.keys(rest).length === 1 && rest.key ? rest.key : rest
			}
			this.$emit('update:modelValue', next)
		},
		addColumn() {
			const next = this.localColumns.slice()
			next.push('')
			this.$emit('update:modelValue', next)
		},
		removeColumn(index) {
			const next = this.localColumns.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped>
.column-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.column-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
}
.column-builder__key,
.column-builder__label {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.column-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.column-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
