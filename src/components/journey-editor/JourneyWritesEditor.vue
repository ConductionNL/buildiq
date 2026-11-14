<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
  JourneyWritesEditor — the `writes[]` of one step (journey-designer, task 3):
  each write names its destination register and schema, maps answered fields
  onto destination properties and may fix a property to a value or to the id
  of a write made before it (`{ "$write": "<as>" }`). Whether a mapping fits
  its destination is not judged here: the designer asks the server, which
  hands it to the validator the submit path uses, and this editor shows the
  findings it got back.

  @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
-->
<template>
	<div class="journey-writes">
		<p v-if="localWrites.length === 0" class="journey-writes__empty">
			{{ t('buildiq', 'This step writes nothing.') }}
		</p>
		<fieldset
			v-for="(write, index) in localWrites"
			:key="index"
			class="journey-writes__write"
			:data-testid="`journey-write-${index}`">
			<legend>{{ write.as || t('buildiq', 'Write {n}', { n: index + 1 }) }}</legend>
			<div class="journey-writes__target">
				<input
					:value="write.as || ''"
					type="text"
					:aria-label="t('buildiq', 'Name of this write')"
					:placeholder="t('buildiq', 'Name of this write')"
					data-testid="journey-write-as"
					@input="update(index, { as: $event.target.value })">
				<input
					:value="write.register || ''"
					type="text"
					:aria-label="t('buildiq', 'Register')"
					:placeholder="t('buildiq', 'Register')"
					data-testid="journey-write-register"
					@input="update(index, { register: $event.target.value })">
				<input
					:value="write.schema || ''"
					type="text"
					:aria-label="t('buildiq', 'Schema')"
					:placeholder="t('buildiq', 'Schema')"
					data-testid="journey-write-schema"
					@input="update(index, { schema: $event.target.value })">
			</div>

			<div
				v-for="(row, r) in mappedFields(write)"
				:key="`f${r}`"
				class="journey-writes__row"
				data-testid="journey-write-field">
				<select
					:value="row.field || ''"
					:aria-label="t('buildiq', 'Answered field')"
					@change="updateField(index, r, { field: $event.target.value })">
					<option value="">
						{{ t('buildiq', '— field —') }}
					</option>
					<option v-for="field in fields" :key="field" :value="field">
						{{ field }}
					</option>
				</select>
				<input
					:value="row.property || ''"
					type="text"
					:aria-label="t('buildiq', 'Destination property')"
					:placeholder="t('buildiq', 'Destination property')"
					@input="updateField(index, r, { property: $event.target.value })">
				<button type="button" :aria-label="t('buildiq', 'Remove mapping')" @click="removeField(index, r)">
					×
				</button>
			</div>
			<button type="button" data-testid="journey-write-add-field" @click="addField(index)">
				{{ t('buildiq', 'Map a field') }}
			</button>

			<div
				v-for="(entry, f) in fixedEntries(write)"
				:key="`x${f}`"
				class="journey-writes__row"
				data-testid="journey-write-fixed">
				<input
					:value="entry.property"
					type="text"
					:aria-label="t('buildiq', 'Destination property')"
					:placeholder="t('buildiq', 'Destination property')"
					@change="renameFixed(index, entry.property, $event.target.value)">
				<select
					:value="entry.ref"
					:aria-label="t('buildiq', 'Id of an earlier write')"
					data-testid="journey-write-ref"
					@change="setFixed(index, entry.property, $event.target.value ? { $write: $event.target.value } : '')">
					<option value="">
						{{ t('buildiq', 'A fixed value') }}
					</option>
					<option v-for="as in writesBefore(index)" :key="as" :value="as">
						{{ t('buildiq', 'Id of {write}', { write: as }) }}
					</option>
				</select>
				<input
					v-if="entry.ref === ''"
					:value="entry.text"
					type="text"
					:aria-label="t('buildiq', 'Value')"
					:placeholder="t('buildiq', 'Value')"
					@input="setFixed(index, entry.property, $event.target.value)">
			</div>
			<button type="button" data-testid="journey-write-add-fixed" @click="addFixed(index)">
				{{ t('buildiq', 'Fix a property') }}
			</button>

			<ul v-if="findingsOf(write).length > 0" class="journey-writes__findings" role="alert">
				<li v-for="(finding, n) in findingsOf(write)" :key="n" data-testid="journey-write-finding">
					{{ finding.message || finding.code }}
				</li>
			</ul>
			<button type="button" data-testid="journey-write-remove" @click="remove(index)">
				{{ t('buildiq', 'Remove write') }}
			</button>
		</fieldset>
		<button type="button" data-testid="journey-write-add" @click="add">
			{{ t('buildiq', 'Add write') }}
		</button>
	</div>
</template>

<script>
export default {
	name: 'JourneyWritesEditor',

	props: {
		/** The step's writes. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The field keys asked by this step and every step before it. */
		fields: {
			type: Array,
			default: () => [],
		},

		/** The `as` names of the writes of earlier steps. */
		earlierWrites: {
			type: Array,
			default: () => [],
		},

		/** The server's findings on this step: `[{ write, property, code, message }]`. */
		findings: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The writes, or none.
		 *
		 * @return {Array<object>}
		 */
		localWrites() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	methods: {
		/**
		 * A write's field mappings, or none.
		 *
		 * @param {object} write - the write.
		 * @return {Array<object>}
		 */
		mappedFields(write) {
			const fields = write && write.mapping && write.mapping.fields
			return Array.isArray(fields) ? fields : []
		},

		/**
		 * A write's fixed properties as rows: `{ property, ref, text }`.
		 *
		 * @param {object} write - the write.
		 * @return {Array<object>}
		 */
		fixedEntries(write) {
			const fixed = (write && write.mapping && write.mapping.fixed) || {}
			return Object.entries(fixed).map(([property, value]) => {
				const ref = value && typeof value === 'object' && typeof value.$write === 'string' ? value.$write : ''
				return { property, ref, text: ref === '' ? String(value ?? '') : '' }
			})
		},

		/**
		 * The writes a fixed property of the write at `index` may reference:
		 * those of earlier steps and the ones before it in this step.
		 *
		 * @param {number} index - the write's position.
		 * @return {Array<string>}
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
		 */
		writesBefore(index) {
			const here = this.localWrites.slice(0, index).map((w) => String(w.as || '')).filter((as) => as !== '')
			return [...this.earlierWrites, ...here]
		},

		/**
		 * The server's findings on one write.
		 *
		 * @param {object} write - the write.
		 * @return {Array<object>}
		 */
		findingsOf(write) {
			const as = String((write && write.as) || '')
			return this.findings.filter((finding) => String(finding.write || '') === as)
		},

		/**
		 * Emit the writes with one write changed.
		 *
		 * @param {number} index - the write's position.
		 * @param {object} patch - the changed keys.
		 */
		update(index, patch) {
			const writes = [...this.localWrites]
			writes[index] = { ...writes[index], ...patch }
			this.$emit('update:modelValue', writes)
		},

		/**
		 * Emit the writes with one write's mapping changed.
		 *
		 * @param {number} index - the write's position.
		 * @param {object} patch - the changed mapping keys.
		 */
		updateMapping(index, patch) {
			const mapping = { fields: [], fixed: {}, ...(this.localWrites[index].mapping || {}), ...patch }
			this.update(index, { mapping })
		},

		/**
		 * Change one field mapping.
		 *
		 * @param {number} index - the write's position.
		 * @param {number} row - the mapping's position.
		 * @param {object} patch - `field` or `property`.
		 */
		updateField(index, row, patch) {
			const fields = [...this.mappedFields(this.localWrites[index])]
			fields[row] = { ...fields[row], ...patch }
			this.updateMapping(index, { fields })
		},

		/**
		 * Add an empty field mapping.
		 *
		 * @param {number} index - the write's position.
		 */
		addField(index) {
			this.updateMapping(index, { fields: [...this.mappedFields(this.localWrites[index]), { field: '', property: '' }] })
		},

		/**
		 * Remove a field mapping.
		 *
		 * @param {number} index - the write's position.
		 * @param {number} row - the mapping's position.
		 */
		removeField(index, row) {
			this.updateMapping(index, { fields: this.mappedFields(this.localWrites[index]).filter((_, i) => i !== row) })
		},

		/**
		 * Set a fixed property to a value or a `{ $write }` reference.
		 *
		 * @param {number} index - the write's position.
		 * @param {string} property - the destination property.
		 * @param {*} value - the value.
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
		 */
		setFixed(index, property, value) {
			const fixed = { ...((this.localWrites[index].mapping || {}).fixed || {}), [property]: value }
			this.updateMapping(index, { fixed })
		},

		/**
		 * Rename a fixed property, keeping its value and its place.
		 *
		 * @param {number} index - the write's position.
		 * @param {string} from - the old property name.
		 * @param {string} to - the new property name.
		 */
		renameFixed(index, from, to) {
			const old = (this.localWrites[index].mapping || {}).fixed || {}
			const fixed = {}
			for (const [key, value] of Object.entries(old)) {
				fixed[key === from ? to : key] = value
			}
			this.updateMapping(index, { fixed })
		},

		/**
		 * Add a fixed property with an empty value.
		 *
		 * @param {number} index - the write's position.
		 */
		addFixed(index) {
			const fixed = (this.localWrites[index].mapping || {}).fixed || {}
			let n = Object.keys(fixed).length + 1
			while (`property${n}` in fixed) {
				n++
			}
			this.setFixed(index, `property${n}`, '')
		},

		/**
		 * Emit the writes without one.
		 *
		 * @param {number} index - the write's position.
		 */
		remove(index) {
			this.$emit('update:modelValue', this.localWrites.filter((_, i) => i !== index))
		},

		/**
		 * Emit the writes with an empty write at the end.
		 *
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
		 */
		add() {
			this.$emit('update:modelValue', [...this.localWrites, { as: '', register: '', schema: '', mapping: { fields: [], fixed: {} } }])
		},
	},
}
</script>

<style scoped>
.journey-writes__write {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px;
	margin-bottom: 8px;
}

.journey-writes__target,
.journey-writes__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 8px;
}

.journey-writes__findings {
	color: var(--color-error-text);
}

.journey-writes__empty {
	color: var(--color-text-maxcontrast);
}
</style>
