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
				<label>
					{{ t('buildiq', 'Register') }}
					<select
						:value="entry.register || ''"
						:aria-invalid="!entry.register"
						@change="updateRegister(index, $event.target.value)">
						<option value="">
							{{ t('buildiq', '— select register —') }}
						</option>
						<option
							v-for="r in registers"
							:key="r.slug || r.id"
							:value="r.slug">
							{{ r.title || r.slug }}
						</option>
					</select>
				</label>
				<label>
					{{ t('buildiq', 'Schema') }}
					<select
						:value="entry.schema || ''"
						:disabled="!entry.register"
						:aria-invalid="!entry.schema"
						@change="updateSchema(index, $event.target.value)">
						<option value="">
							{{ t('buildiq', '— select schema —') }}
						</option>
						<option
							v-for="s in schemasOf(entry.register)"
							:key="s.slug || s.id"
							:value="s.slug">
							{{ s.title || s.slug }}
						</option>
					</select>
				</label>
				<label>
					{{ t('buildiq', 'Field on this record that stores the link') }}
					<select
						:value="entry.fkField || ''"
						:aria-invalid="!entry.fkField"
						@change="updateField(index, 'fkField', $event.target.value)">
						<option value="">
							{{ t('buildiq', '— select field —') }}
						</option>
						<option
							v-for="field in fieldsOf(hostRegister, hostSchema)"
							:key="field"
							:value="field">
							{{ field }}
						</option>
					</select>
				</label>
				<label>
					{{ t('buildiq', 'Field shown in the picker') }}
					<select
						:value="entry.labelField || ''"
						:disabled="!entry.schema"
						@change="updateField(index, 'labelField', $event.target.value)">
						<option value="">
							{{ t('buildiq', '— default —') }}
						</option>
						<option
							v-for="field in fieldsOf(entry.register, entry.schema)"
							:key="field"
							:value="field">
							{{ field }}
						</option>
					</select>
				</label>
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
import InlineFieldMark from './InlineFieldMark.vue'
import { schemaListCacheMixin } from '../../../mixins/schemaListCache.js'

export default {
	name: 'RelationLinkBuilder',
	components: { InlineFieldMark },
	mixins: [schemaListCacheMixin],
	props: {
		// The `relationLinks` array.
		modelValue: {
			type: Array,
			default: () => [],
		},

		// The registers the editor offers, as loaded by its picker.
		registers: {
			type: Array,
			default: () => [],
		},

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

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The entries as an array, whatever the bound value is.
		 *
		 * @return {Array<object>}
		 */
		localEntries() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	watch: {
		localEntries: {
			immediate: true,
			handler(entries) {
				entries.forEach((entry) => this.loadSchemas(entry && entry.register))
			},
		},

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
		 * Emit the list with one entry replaced.
		 *
		 * @param {number} index - position of the entry.
		 * @param {object} entry - its new value.
		 */
		replace(index, entry) {
			const next = this.localEntries.slice()
			next[index] = entry
			this.$emit('update:modelValue', next)
		},

		/**
		 * Write a text key of an entry; an empty value deletes it.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} key - `label`, `fkField` or `labelField`.
		 * @param {string} value - the new value.
		 * @spec openspec/changes/pages-detail-related-items/tasks.md#task-T02
		 */
		updateField(index, key, value) {
			const { [key]: _omit, ...rest } = this.localEntries[index] || {}
			this.replace(index, value === '' ? rest : { ...rest, [key]: value })
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
			const next = this.localEntries.slice()
			next.push(this.hostRegister ? { label: '', register: this.hostRegister } : { label: '' })
			this.$emit('update:modelValue', next)
		},

		/**
		 * Drop one button.
		 *
		 * @param {number} index - position of the entry.
		 */
		removeEntry(index) {
			const next = this.localEntries.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
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
