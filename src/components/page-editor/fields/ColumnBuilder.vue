<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ColumnBuilder — authors the `column` $def, round-tripping both the
  - typed-object shape AND the legacy string shorthand. Surfaces
  - `@self.*` virtual columns when bound to a schema.
  - Used by IndexPageEditor and LogsPageEditor (REQ-OBPD-004).
  -
  - The per-row "fx" toggle (pages-custom-code REQ-BQCC-002) turns a column
  - into a computed one: `{ key, label?, expression }`, where `key` names the
  - column and `expression` is JavaScript over `row`, `user` and `route` that
  - the sandbox evaluates. Only an app owner may save it with "Allow code on
  - pages" on; the server refuses anyone else.
  -->
<template>
	<div class="column-builder">
		<div
			v-for="(col, index) in localColumns"
			:key="index"
			class="column-builder__row">
			<input
				v-if="isComputed(col)"
				:value="rowKey(col)"
				type="text"
				class="column-builder__key"
				:placeholder="t('buildiq', 'Column name')"
				:aria-label="t('buildiq', 'Column name')"
				@input="onKeyChange(index, $event.target.value)" />
			<select
				v-else
				:value="rowKey(col)"
				class="column-builder__key"
				@change="onKeyChange(index, $event.target.value)">
				<option value="">
					{{ t('buildiq', '— select column —') }}
				</option>
				<optgroup :label="t('buildiq', 'Schema properties')">
					<option
						v-for="key in schemaPropertyKeys"
						:key="key"
						:value="key">
						{{ key }}
					</option>
				</optgroup>
				<optgroup :label="t('buildiq', 'Metadata (@self.*)')">
					<option v-for="key in SELF_VIRTUAL_KEYS" :key="key" :value="key">
						{{ key }}
					</option>
				</optgroup>
			</select>
			<input
				:value="rowLabel(col)"
				type="text"
				class="column-builder__label"
				:placeholder="t('buildiq', 'Label (i18n key)')"
				:aria-label="t('buildiq', 'Label (i18n key)')"
				@input="onLabelInput(index, $event.target.value)" />
			<button
				type="button"
				class="column-builder__fx"
				:class="{ 'column-builder__fx--on': isComputed(col) }"
				:aria-pressed="isComputed(col) ? 'true' : 'false'"
				:title="isComputed(col) ? t('buildiq', 'Show a property instead') : t('buildiq', 'Compute this column with an expression')"
				@click="toggleExpression(index)">
				fx
			</button>
			<button
				type="button"
				class="column-builder__remove"
				:title="t('buildiq', 'Remove column')"
				@click="removeColumn(index)">
				✕
			</button>
			<textarea
				v-if="isComputed(col)"
				:value="col.expression"
				class="column-builder__expression"
				rows="2"
				spellcheck="false"
				:placeholder="t('buildiq', 'For example: row.aantal * row.prijs')"
				:aria-label="t('buildiq', 'Column expression')"
				@input="onExpressionInput(index, $event.target.value)" />
		</div>
		<button type="button" class="column-builder__add" @click="addColumn">
			+ {{ t('buildiq', 'Add column') }}
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

/**
 * A computed column without its expression: the typed column while it keeps
 * a label, else its key as the string shorthand.
 *
 * @param {{key?: string, label?: string, expression?: string}} column - the computed column.
 * @return {string|object} the key, or the column without `expression`.
 */
function withoutExpression(column) {
	const { expression, ...rest } = column
	return rest.label ? rest : (rest.key || '')
}

/**
 * A typed column without its label, collapsed back to the string shorthand
 * when only a (non-empty) key is left.
 *
 * @param {{key?: string, label?: string}} column - the typed column.
 * @return {string|object} the key, or the column without `label`.
 */
function withoutLabel(column) {
	const { label, ...rest } = column
	return Object.keys(rest).length === 1 && rest.key ? rest.key : rest
}

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
		/**
		 * Observed behaviour of `localColumns` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		localColumns() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/**
		 * Observed behaviour of `schemaPropertyKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		schemaPropertyKeys() {
			return Object.keys(this.schemaProperties || {})
		},
	},

	methods: {
		/**
		 * Observed behaviour of `rowKey` (retrofit annotation).
		 *
		 * @param {string|{key?: string, property?: string, label?: string}} col - one entry of
		 *   `config.columns`: either the legacy string shorthand (the property key itself) or
		 *   the typed column object, which may name its key as `key` or as the alias `property`.
		 * @return {string} the property key the row's `<select>` shows — a schema property
		 *   name or an `@self.*` virtual key; `''` when the entry names neither.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		rowKey(col) {
			if (typeof col === 'string') {
				return col
			}
			return (col && (col.key || col.property)) || ''
		},

		/**
		 * Observed behaviour of `rowLabel` (retrofit annotation).
		 *
		 * @param {string|{key?: string, label?: string}} col - one entry of `config.columns`;
		 *   the string shorthand carries no label by construction.
		 * @return {string} the i18n label key shown in the row's label input, `''` when
		 *   the column is unlabelled.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		rowLabel(col) {
			if (typeof col === 'string') {
				return ''
			}
			return (col && col.label) || ''
		},

		/**
		 * Observed behaviour of `onKeyChange` (retrofit annotation).
		 *
		 * @param {number} index - position of the column in the `columns` array.
		 * @param {string} value - the newly selected property key: a schema property
		 *   name, an `@self.*` virtual key, or `''` for the placeholder option. A
		 *   labelled column keeps its object shape; an unlabelled one stays a string.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
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

		/**
		 * Observed behaviour of `onLabelInput` (retrofit annotation).
		 *
		 * @param {number} index - position of the column in the `columns` array.
		 * @param {string} value - the new i18n label key. A non-empty label PROMOTES a
		 *   string-shorthand column to `{ key, label }`; clearing it DEMOTES a
		 *   key-only object back to the string shorthand.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		onLabelInput(index, value) {
			const next = this.localColumns.slice()
			const existing = next[index]
			// Promote string shorthand to typed object once a label is added.
			const key = this.rowKey(existing)
			if (value) {
				next[index] = this.isComputed(existing) ? { key, label: value, expression: existing.expression } : { key, label: value }
			} else if (typeof existing === 'object' && existing) {
				next[index] = withoutLabel(existing)
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Whether a column is computed by an fx expression (REQ-BQCC-002).
		 *
		 * @param {string|object} col - one entry of `config.columns`.
		 * @return {boolean}
		 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
		 */
		isComputed(col) {
			return !!col && typeof col === 'object' && typeof col.expression === 'string'
		},

		/**
		 * Switch a column between a property and an fx expression. On, it keeps
		 * its key and label and starts with an empty expression; off, it drops
		 * the expression and collapses to the string shorthand when only a key
		 * is left.
		 *
		 * @param {number} index - position of the column.
		 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
		 */
		toggleExpression(index) {
			const next = this.localColumns.slice()
			const existing = next[index]
			next[index] = this.isComputed(existing) ? withoutExpression(existing) : this.withExpression(existing)
			this.$emit('update:modelValue', next)
		},

		/**
		 * A column made computed: the typed column with an empty expression.
		 *
		 * @param {string|object} existing - the column entry.
		 * @return {object}
		 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
		 */
		withExpression(existing) {
			const column = typeof existing === 'object' && existing ? { ...existing } : { key: this.rowKey(existing) }
			return { ...column, expression: '' }
		},

		/**
		 * Write a computed column's expression.
		 *
		 * @param {number} index - position of the column.
		 * @param {string} value - the typed expression.
		 * @spec openspec/changes/archive/2026-11-24-pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
		 */
		onExpressionInput(index, value) {
			const next = this.localColumns.slice()
			next[index] = { ...next[index], expression: value }
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `addColumn` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addColumn() {
			const next = this.localColumns.slice()
			next.push('')
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `removeColumn` (retrofit annotation).
		 *
		 * @param {number} index - position of the column to drop from the `columns` array.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeColumn(index) {
			const next = this.localColumns.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.column-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.column-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.column-builder__key,
.column-builder__label {
	/* Shrink with a narrow editor, and wrap before running out of it. */
	flex: 1 1 120px;
	min-width: 0;
	@include c.control;
}

.column-builder__remove {
	@include c.danger-button;
}

.column-builder__fx {
	font-style: italic;
	font-weight: bold;
	@include c.ghost-button;
}

.column-builder__fx--on {
	color: var(--color-primary-element);
}

.column-builder__expression {
	flex: 1 1 100%;
	font-family: var(--font-face-monospace, monospace);
	@include c.control;
}

.column-builder__add {
	align-self: flex-start;
	@include c.add-button;
}
</style>
