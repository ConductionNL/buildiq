<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - GridPageEditor — the editor of a "Grid" page: a `custom` page whose
  - `component` is the runtime `record-grid` page. It authors the page's
  - `config` (handed to the grid as props): the register and schema, the
  - columns (ColumnBuilder), and which of those columns an app user may edit
  - in place (`config.editable`, a list of property keys).
  -
  - A metadata column (`@self.*`) or a property the schema declares
  - `readOnly` can never be edited, so its toggle is disabled.
  -->
<template>
	<div class="grid-page-editor">
		<h3 class="grid-page-editor__title">
			{{ t('buildiq', 'Grid page') }}
		</h3>
		<p class="grid-page-editor__hint">
			{{
				t(
					'buildiq',
					'App users see one row per record and edit the columns you mark editable in place.',
				)
			}}
		</p>
		<div class="grid-page-editor__group">
			<RegisterSchemaFields
				:config="config"
				:registers="registers"
				:schemas="schemas"
				@register="update('register', $event)"
				@schema="update('schema', $event)" />
		</div>

		<fieldset class="grid-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Columns') }}</legend>
			<ColumnBuilder
				:modelValue="config.columns || []"
				:schemaProperties="schemaProperties"
				@update:modelValue="onColumnsUpdate" />
			<InlineFieldMark :error="markFor('columns')" />
		</fieldset>

		<fieldset class="grid-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Editable columns') }}</legend>
			<p v-if="!columnKeys.length" class="grid-page-editor__hint">
				{{ t('buildiq', 'Add columns first.') }}
			</p>
			<label
				v-for="key in columnKeys"
				:key="key"
				class="grid-page-editor__inline">
				<input
					type="checkbox"
					:checked="isEditable(key)"
					:disabled="!canBeEditable(key)"
					@change="onEditableToggle(key, $event.target.checked)" />
				{{ key }}
				<span v-if="!canBeEditable(key)" class="grid-page-editor__note">
					{{ t('buildiq', '(read-only)') }}
				</span>
			</label>
		</fieldset>
	</div>
</template>

<script>
import ColumnBuilder from './fields/ColumnBuilder.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import RegisterSchemaFields from './fields/RegisterSchemaFields.vue'
import { useEditorRegisterPicker } from '../../composables/useEditorRegisterPicker.js'
import {
	pageEditorBaseMixin,
	pageEditorSchemaBoundMixin,
} from '../../mixins/pageEditorBase.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'
import { columnKey } from '../../utils/gridColumns.js'

export default {
	name: 'GridPageEditor',
	components: { ColumnBuilder, InlineFieldMark, RegisterSchemaFields },
	mixins: [
		pageEditorBaseMixin,
		pageEditorValidationMixin,
		pageEditorSchemaBoundMixin,
	],

	props: {
		pageType: {
			type: String,
			default: 'custom',
		},
	},

	setup: useEditorRegisterPicker,

	computed: {
		/**
		 * Config keys the validation marks are looked up for.
		 *
		 * @return {string[]}
		 */
		validatedConfigKeys() {
			return ['register', 'schema', 'columns', 'editable']
		},

		/**
		 * The property keys of the authored columns, without blanks and
		 * duplicates.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t04
		 */
		columnKeys() {
			const keys = (this.config.columns || []).map(columnKey).filter(Boolean)
			return Array.from(new Set(keys))
		},
	},

	methods: {
		/**
		 * Write one config key. Choosing another register also drops the
		 * schema, the columns and the editable list, which belonged to the old
		 * register's schema; choosing another schema drops the columns and the
		 * editable list.
		 *
		 * @param {string} key - `register`, `schema`, `columns` or `editable`.
		 * @param {string|Array} value - the new value. `''` and `null` delete the key; an empty array is kept, since the grid page reads both lists.
		 * @return {void}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t04
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			if (key === 'register') {
				delete next.schema
			}
			if (key === 'register' || key === 'schema') {
				next.columns = []
				next.editable = []
			}
			this.$emit('update:config', next)
		},

		/**
		 * Store the rebuilt columns, and drop editable keys whose column is
		 * gone so the grid never offers a column it does not show.
		 *
		 * @param {Array<string|object>} columns - from ColumnBuilder.
		 * @return {void}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t04
		 */
		onColumnsUpdate(columns) {
			const keys = new Set(columns.map(columnKey))
			const editable = (this.config.editable || []).filter((key) =>
				keys.has(key),
			)
			this.$emit('update:config', { ...this.config, columns, editable })
		},

		/**
		 * Whether a column can be made editable: a schema property that the
		 * schema does not declare read-only.
		 *
		 * @param {string} key - the column's property key.
		 * @return {boolean}
		 */
		canBeEditable(key) {
			if (key.startsWith('@self.')) {
				return false
			}
			const property = (this.schemaProperties || {})[key]
			return !(property && property.readOnly === true)
		},

		/**
		 * Whether the maker marked a column editable.
		 *
		 * @param {string} key - the column's property key.
		 * @return {boolean}
		 */
		isEditable(key) {
			return (this.config.editable || []).includes(key)
		},

		/**
		 * Mark a column editable or not.
		 *
		 * @param {string} key - the column's property key.
		 * @param {boolean} checked - the toggle's new state.
		 * @return {void}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t04
		 */
		onEditableToggle(key, checked) {
			const current = (this.config.editable || []).filter((k) => k !== key)
			if (checked && this.canBeEditable(key)) {
				current.push(key)
			}
			this.$emit('update:config', { ...this.config, editable: current })
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

.grid-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.grid-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.grid-page-editor__hint,
.grid-page-editor__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.grid-page-editor__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.grid-page-editor__group :deep(label) {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.grid-page-editor__group :deep(select) {
	@include c.control;
}

.grid-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.grid-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.grid-page-editor__inline {
	display: flex;
	gap: 6px;
	align-items: center;
	font-size: 13px;
}
</style>
