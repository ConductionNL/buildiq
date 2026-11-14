<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RelationLinkBuilder — authors a detail page's `relationLinks`: buttons
  - with which an app user links an existing record of another schema to the
  - open record. Each entry names the label, the register and schema of the
  - records to pick from, the field on the OPEN record that stores the link
  - (`fkField`), optionally the field shown in the picker (`labelField`) and
  - whether a new record may be created from the picker (`allowCreate`)
  - (design D3). A row without `fkField` gets an inline mark (design D5).
  - REQ-BQRI-002.
  -->
<template>
	<div class="relation-link-builder">
		<div
			v-for="(entry, index) in localEntries"
			:key="index"
			class="relation-link-builder__row">
			<div class="relation-link-builder__fields">
				<label>
					{{ t('buildiq', 'Button label') }}
					<input
						:value="entry.label || ''"
						type="text"
						@input="updateField(index, 'label', $event.target.value)" />
				</label>
				<RegisterSchemaPicker
					:entry="entry"
					:registers="registers"
					:schemas="schemasOf(entry.register)"
					@pick="onPick(index, $event)" />
				<FieldSelect
					:label="t('buildiq', 'Field on this record that stores the link')"
					:value="entry.fkField || ''"
					:fields="fieldsOf(hostRegister, hostSchema)"
					:emptyText="t('buildiq', '— select field —')"
					:invalid="!entry.fkField"
					@change="updateField(index, 'fkField', $event)" />
				<FieldSelect
					:label="t('buildiq', 'Field shown in the picker')"
					:value="entry.labelField || ''"
					:fields="fieldsOf(entry.register, entry.schema)"
					:emptyText="t('buildiq', '— default —')"
					:disabled="!entry.schema"
					@change="updateField(index, 'labelField', $event)" />
				<label class="relation-link-builder__inline">
					<input
						type="checkbox"
						:checked="entry.allowCreate === true"
						@change="updateAllowCreate(index, $event.target.checked)" />
					{{ t('buildiq', 'Allow creating a new record from the picker') }}
				</label>
			</div>
			<InlineFieldMark :error="rowError(entry)" />
			<button
				type="button"
				class="relation-link-builder__remove"
				:title="t('buildiq', 'Remove link button')"
				:aria-label="t('buildiq', 'Remove link button')"
				@click="removeEntry(index)">
				✕
			</button>
		</div>
		<button
			type="button"
			class="relation-link-builder__add"
			@click="addEntry">
			+ {{ t('buildiq', 'Add link button') }}
		</button>
	</div>
</template>

<script>
import { entryListMixin } from '../../../mixins/entryList.js'
import { FieldSelect, InlineFieldMark, RegisterSchemaPicker } from './entryBuilderParts.js'

export default {
	name: 'RelationLinkBuilder',
	components: { FieldSelect, InlineFieldMark, RegisterSchemaPicker },
	mixins: [entryListMixin],
	props: {
		// The page's own register: the open record lives here, and a new entry starts on it.
		hostRegister: {
			type: String,
			default: '',
		},

		// The page's own schema: `fkField` is one of its fields.
		hostSchema: {
			type: String,
			default: '',
		},
	},

	watch: {
		hostRegister: {
			immediate: true,
			handler(register) {
				this.loadSchemas(register)
			},
		},
	},

	methods: {
		/**
		 * The inline mark of a row: register and schema first, then `fkField` (design D5).
		 *
		 * @param {object} entry - one link button.
		 * @return {{hasError: boolean, message: string}|null}
		 * @spec openspec/changes/pages-detail-related-items/tasks.md#task-T02
		 */
		rowError(entry) {
			if (!entry || !entry.register || !entry.schema) {
				return {
					hasError: true,
					message: t('buildiq', 'Pick a register and a schema for this button.'),
				}
			}
			if (!entry.fkField) {
				return {
					hasError: true,
					message: t('buildiq', 'Pick the field on this record that stores the link.'),
				}
			}
			return null
		},

		/**
		 * Pick the register; the schema and its label field go with the old one.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} register - register slug.
		 */
		updateRegister(index, register) {
			const { register: _r, schema: _s, labelField: _l, ...rest } = this.localEntries[index] || {}
			this.replace(index, register === '' ? rest : { ...rest, register })
			this.loadSchemas(register)
		},

		/**
		 * Pick the schema; the label field belongs to it, so it goes.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} schema - schema slug.
		 */
		updateSchema(index, schema) {
			const { schema: _s, labelField: _l, ...rest } = this.localEntries[index] || {}
			this.replace(index, schema === '' ? rest : { ...rest, schema })
		},

		/**
		 * Write `allowCreate`; off removes the key, the renderer's default.
		 *
		 * @param {number} index - position of the entry.
		 * @param {boolean} allow - the checkbox state.
		 */
		updateAllowCreate(index, allow) {
			const { allowCreate: _a, ...rest } = this.localEntries[index] || {}
			this.replace(index, allow ? { ...rest, allowCreate: true } : rest)
		},

		/**
		 * Append a button on the page's own register.
		 */
		addEntry() {
			this.appendEntry(this.hostRegister ? { label: '', register: this.hostRegister } : { label: '' })
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.relation-link-builder {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.relation-link-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: flex-start;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.relation-link-builder__fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 6px;
	flex: 1 1 auto;
}

.relation-link-builder__fields label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.relation-link-builder__fields .relation-link-builder__inline {
	flex-direction: row;
	align-items: center;
	gap: 6px;
}

.relation-link-builder__fields input[type='text'],
.relation-link-builder__fields select {
	@include c.control;
}

.relation-link-builder__remove {
	@include c.danger-button;
}

.relation-link-builder__add {
	@include c.add-button;
	align-self: flex-start;
}
</style>
