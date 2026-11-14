<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RelatedCollectionBuilder — authors a detail page's `relatedCollections`:
  - lists of records of another schema that point at the open record.
  - A maker picks the register, the schema and the field on it that holds
  - this record's id; the builder writes `filter: { <field>: '@objectId' }`,
  - the token the detail page resolves against the open record (design D1).
  - Columns and limit are optional; a limit equal to the renderer's default
  - (10) is left out (design D2). REQ-BQRI-001.
  -->
<template>
	<div class="related-collection-builder">
		<div
			v-for="(entry, index) in localEntries"
			:key="index"
			class="related-collection-builder__row">
			<div class="related-collection-builder__fields">
				<label>
					{{ t('buildiq', 'Title') }}
					<input
						:value="entry.title || ''"
						type="text"
						@input="updateField(index, 'title', $event.target.value)" />
				</label>
				<RegisterSchemaPicker
					:entry="entry"
					:registers="registers"
					:schemas="schemasOf(entry.register)"
					@pick="onPick(index, $event)" />
				<FieldSelect
					:label="t('buildiq', 'Field that points at this record')"
					:value="linkFieldOf(entry)"
					:fields="fieldsOf(entry.register, entry.schema)"
					:emptyText="t('buildiq', '— select field —')"
					:disabled="!entry.schema"
					@change="updateLinkField(index, $event)" />
				<label>
					{{ t('buildiq', 'Columns (comma-separated; empty uses the schema list columns)') }}
					<input
						:value="(entry.columns || []).join(', ')"
						type="text"
						@change="updateColumns(index, $event.target.value)" />
				</label>
				<label>
					{{ t('buildiq', 'Rows to show (1 to 50)') }}
					<input
						:value="entry.limit || ''"
						type="number"
						min="1"
						max="50"
						:placeholder="String(defaultLimit)"
						@change="updateLimit(index, $event.target.value)" />
				</label>
			</div>
			<InlineFieldMark :error="rowError(entry)" />
			<button
				type="button"
				class="related-collection-builder__remove"
				:title="t('buildiq', 'Remove related list')"
				:aria-label="t('buildiq', 'Remove related list')"
				@click="removeEntry(index)">
				✕
			</button>
		</div>
		<button
			type="button"
			class="related-collection-builder__add"
			@click="addEntry">
			+ {{ t('buildiq', 'Add related list') }}
		</button>
	</div>
</template>

<script>
import { entryListMixin } from '../../../mixins/entryList.js'
import { FieldSelect, InlineFieldMark, RegisterSchemaPicker } from './entryBuilderParts.js'

/**
 * The filter token the detail page replaces with the open record's id.
 */
export const OBJECT_ID_TOKEN = '@objectId'

/**
 * The row limit the detail page applies when an entry names none.
 */
export const DEFAULT_LIMIT = 10

export default {
	name: 'RelatedCollectionBuilder',
	components: { FieldSelect, InlineFieldMark, RegisterSchemaPicker },
	mixins: [entryListMixin],
	props: {
		// The register a new entry starts on: the page's own register.
		defaultRegister: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * The renderer's default row limit, shown as the placeholder.
		 *
		 * @return {number}
		 */
		defaultLimit() {
			return DEFAULT_LIMIT
		},
	},

	methods: {
		/**
		 * The field the entry's filter maps to the open record's id.
		 *
		 * @param {object} entry - one related list.
		 * @return {string} the field, or '' when there is none.
		 * @spec openspec/changes/pages-detail-related-items/tasks.md#task-T01
		 */
		linkFieldOf(entry) {
			const filter = (entry && entry.filter) || {}
			return Object.keys(filter).find((key) => filter[key] === OBJECT_ID_TOKEN) || ''
		},

		/**
		 * The inline mark of a row without a register or schema (design D5).
		 *
		 * @param {object} entry - one related list.
		 * @return {{hasError: boolean, message: string}|null}
		 * @spec openspec/changes/pages-detail-related-items/design.md
		 */
		rowError(entry) {
			if (entry && entry.register && entry.schema) {
				return null
			}
			return {
				hasError: true,
				message: t('buildiq', 'Pick a register and a schema for this list.'),
			}
		},

		/**
		 * Pick the register; the schema and link field belong to it, so they go.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} register - register slug.
		 */
		updateRegister(index, register) {
			const { register: _r, schema: _s, filter: _f, columns: _c, ...rest } = this.localEntries[index] || {}
			this.replace(index, register === '' ? rest : { ...rest, register })
			this.loadSchemas(register)
		},

		/**
		 * Pick the schema; the link field and columns belong to it, so they go.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} schema - schema slug.
		 */
		updateSchema(index, schema) {
			const { schema: _s, filter: _f, columns: _c, ...rest } = this.localEntries[index] || {}
			this.replace(index, schema === '' ? rest : { ...rest, schema })
		},

		/**
		 * Map the chosen field to the open record's id; other filter keys stay.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} field - the field on the related schema; '' clears the link.
		 * @spec openspec/changes/pages-detail-related-items/tasks.md#task-T01
		 */
		updateLinkField(index, field) {
			const entry = this.localEntries[index] || {}
			const filter = {}
			Object.entries(entry.filter || {}).forEach(([key, value]) => {
				if (value !== OBJECT_ID_TOKEN) {
					filter[key] = value
				}
			})
			if (field !== '') {
				filter[field] = OBJECT_ID_TOKEN
			}
			const { filter: _f, ...rest } = entry
			this.replace(index, Object.keys(filter).length === 0 ? rest : { ...rest, filter })
		},

		/**
		 * Write the columns from a comma-separated list; empty removes them.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} value - the input's text.
		 */
		updateColumns(index, value) {
			const columns = value.split(',').map((c) => c.trim()).filter((c) => c !== '')
			const { columns: _c, ...rest } = this.localEntries[index] || {}
			this.replace(index, columns.length === 0 ? rest : { ...rest, columns })
		},

		/**
		 * Write the row limit, clamped to 1..50; empty or the default removes it.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} value - the input's text.
		 * @spec openspec/changes/pages-detail-related-items/design.md
		 */
		updateLimit(index, value) {
			const { limit: _l, ...rest } = this.localEntries[index] || {}
			const number = parseInt(value, 10)
			if (Number.isNaN(number)) {
				this.replace(index, rest)
				return
			}
			const limit = Math.min(50, Math.max(1, number))
			this.replace(index, limit === DEFAULT_LIMIT ? rest : { ...rest, limit })
		},

		/**
		 * Append a list on the page's own register.
		 */
		addEntry() {
			this.appendEntry(this.defaultRegister ? { title: '', register: this.defaultRegister } : { title: '' })
			this.loadSchemas(this.defaultRegister)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.related-collection-builder {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.related-collection-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: flex-start;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.related-collection-builder__fields {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 6px;
	flex: 1 1 auto;
}

.related-collection-builder__fields label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.related-collection-builder__fields input,
.related-collection-builder__fields select {
	@include c.control;
}

.related-collection-builder__remove {
	@include c.danger-button;
}

.related-collection-builder__add {
	@include c.add-button;
	align-self: flex-start;
}
</style>
