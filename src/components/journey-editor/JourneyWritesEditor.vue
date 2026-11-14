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

			<div class="journey-writes__repeat">
				<label>
					<input
						:checked="write.bundle === true"
						type="checkbox"
						data-testid="journey-write-bundle"
						@change="setBundle(index, $event.target.checked)">
					{{ t('buildiq', 'The parts hang under this write') }}
				</label>
				<select
					:value="write.forEach || ''"
					:aria-label="t('buildiq', 'Repeat this write')"
					data-testid="journey-write-foreach"
					@change="setForEach(index, $event.target.value)">
					<option value="">
						{{ t('buildiq', 'Write once') }}
					</option>
					<option v-for="list in listFields" :key="list.key" :value="list.key">
						{{ t('buildiq', 'Once per item of {list}', { list: list.key }) }}
					</option>
				</select>
			</div>

			<div v-if="write.forEach" class="journey-writes__targets" data-testid="journey-write-targets">
				<input
					:value="write.targetBy || ''"
					type="text"
					:aria-label="t('buildiq', 'Item field that picks the target')"
					:placeholder="t('buildiq', 'Item field that picks the target')"
					data-testid="journey-write-target-by"
					@input="update(index, { targetBy: $event.target.value })">
				<div
					v-for="target in targetRows(write)"
					:key="`t${target.value}`"
					class="journey-writes__row"
					data-testid="journey-write-target">
					<input
						:value="target.value"
						type="text"
						:aria-label="t('buildiq', 'Product')"
						:placeholder="t('buildiq', 'Product')"
						@change="renameTarget(index, target.value, $event.target.value)">
					<input
						v-for="part in targetParts"
						:key="part.key"
						:value="target[part.key]"
						type="text"
						:aria-label="part.label"
						:placeholder="part.label"
						:data-testid="`journey-write-target-${part.key}`"
						@input="setTarget(index, target.value, { [part.key]: $event.target.value })">
					<button type="button" :aria-label="t('buildiq', 'Remove target')" @click="removeTarget(index, target.value)">
						×
					</button>
					<p
						v-if="flows !== null && creationFlowsOf(target).length === 0"
						class="journey-writes__warning"
						data-testid="journey-write-no-process">
						{{ t('buildiq', 'This product starts no process.') }}
					</p>
					<p v-else-if="flows !== null" class="journey-writes__flows" data-testid="journey-write-flows">
						{{ t('buildiq', 'Starts: {flows}', { flows: creationFlowsOf(target).map(flowName).join(', ') }) }}
					</p>
				</div>
				<button type="button" data-testid="journey-write-add-target" @click="addTarget(index)">
					{{ t('buildiq', 'Add a target') }}
				</button>
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
					<option v-for="field in itemFieldsOf(write)" :key="field" :value="field">
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
import { creationFlowsFor } from '../../services/creationFlows.js'

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

		/** The list answers asked so far, with their item fields: `[{ key, columns }]`. */
		listFields: {
			type: Array,
			default: () => [],
		},

		/** OpenRegister's flows, or null while they are not read: no process warning then. */
		flows: {
			type: Array,
			default: null,
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

		/**
		 * The parts of one target, besides its product value.
		 *
		 * @return {Array<{key: string, label: string}>}
		 */
		targetParts() {
			return [
				{ key: 'register', label: t('buildiq', 'Register') },
				{ key: 'schema', label: t('buildiq', 'Schema') },
				{ key: 'typeValue', label: t('buildiq', 'Type value') },
			]
		},
	},

	methods: {
		/**
		 * The item fields a repeating write's mapping may read: `item.<key>`.
		 *
		 * @param {object} write - the write.
		 * @return {Array<string>}
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-a-write-can-repeat-per-list-item-req-bqmp-001
		 */
		itemFieldsOf(write) {
			const list = this.listFields.find((l) => l.key === (write && write.forEach))
			return list ? list.columns.map((column) => `item.${column}`) : []
		},

		/**
		 * Repeat a write over a list answer, or write it once again: a write
		 * written once has no targets.
		 *
		 * @param {number} index - the write's position.
		 * @param {string} key - the list answer, or '' to write once.
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-a-write-can-repeat-per-list-item-req-bqmp-001
		 */
		setForEach(index, key) {
			if (key === '') {
				const { forEach, targetBy, targets, ...once } = this.localWrites[index]
				const writes = [...this.localWrites]
				writes[index] = once
				this.$emit('update:modelValue', writes)
				return
			}
			this.update(index, { forEach: key, bundle: false })
		},

		/**
		 * Mark the write the parts hang under; there is one, so marking it
		 * unmarks any other.
		 *
		 * @param {number} index - the write's position.
		 * @param {boolean} on - whether it is the bundle.
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		setBundle(index, on) {
			this.$emit('update:modelValue', this.localWrites.map((write, i) => {
				if (i === index) {
					return { ...write, bundle: on }
				}
				return write.bundle === true && on ? { ...write, bundle: false } : write
			}))
		},

		/**
		 * A repeating write's targets as rows: `{ value, register, schema, typeValue }`.
		 *
		 * @param {object} write - the write.
		 * @return {Array<object>}
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-each-item-goes-to-its-products-target-req-bqmp-002
		 */
		targetRows(write) {
			const targets = write && write.targets && typeof write.targets === 'object' ? write.targets : {}
			return Object.entries(targets).map(([value, target]) => ({
				value,
				register: String((target && target.register) || ''),
				schema: String((target && target.schema) || ''),
				typeValue: String((target && target.typeValue) || ''),
			}))
		},

		/**
		 * Emit the write with its targets replaced.
		 *
		 * @param {number} index - the write's position.
		 * @param {object} targets - the targets by product value.
		 */
		setTargets(index, targets) {
			this.update(index, { targets })
		},

		/**
		 * Change one target.
		 *
		 * @param {number} index - the write's position.
		 * @param {string} value - the product value.
		 * @param {object} patch - `register`, `schema` or `typeValue`.
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-each-item-goes-to-its-products-target-req-bqmp-002
		 */
		setTarget(index, value, patch) {
			const targets = { ...(this.localWrites[index].targets || {}) }
			targets[value] = { ...(targets[value] || {}), ...patch }
			this.setTargets(index, targets)
		},

		/**
		 * Rename a target's product value, keeping its place.
		 *
		 * @param {number} index - the write's position.
		 * @param {string} from - the old value.
		 * @param {string} to - the new value.
		 */
		renameTarget(index, from, to) {
			const targets = {}
			for (const [value, target] of Object.entries(this.localWrites[index].targets || {})) {
				targets[value === from ? to : value] = target
			}
			this.setTargets(index, targets)
		},

		/**
		 * Add an empty target.
		 *
		 * @param {number} index - the write's position.
		 */
		addTarget(index) {
			const targets = this.localWrites[index].targets || {}
			let n = Object.keys(targets).length + 1
			while (`product${n}` in targets) {
				n++
			}
			this.setTargets(index, { ...targets, [`product${n}`]: { register: '', schema: '', typeValue: '' } })
		},

		/**
		 * Remove a target.
		 *
		 * @param {number} index - the write's position.
		 * @param {string} value - the product value.
		 */
		removeTarget(index, value) {
			const { [value]: removed, ...targets } = this.localWrites[index].targets || {}
			this.setTargets(index, targets)
		},

		/**
		 * The flows that start when a record is created in the target.
		 *
		 * @param {object} target - the target row.
		 * @return {Array<object>}
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-designer-says-whether-a-part-starts-a-process-req-bqmp-004
		 */
		creationFlowsOf(target) {
			return creationFlowsFor(this.flows, target)
		},

		/**
		 * A flow's name for the list.
		 *
		 * @param {object} flow - the flow.
		 * @return {string}
		 */
		flowName(flow) {
			return String(flow.name || flow.title || flow.id || '')
		},

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

.journey-writes__repeat,
.journey-writes__targets {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 8px;
}

.journey-writes__warning {
	color: var(--color-warning-text);
	width: 100%;
}

.journey-writes__flows {
	color: var(--color-text-maxcontrast);
	width: 100%;
}

.journey-writes__empty {
	color: var(--color-text-maxcontrast);
}
</style>
