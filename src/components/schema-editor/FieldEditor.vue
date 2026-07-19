<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - FieldEditor — manages the schema's `properties` map as an ordered
  - list of rows (REQ-OBSD-003). Supports add, remove (with confirm
  - dialog), reorder, edit name, type, required, default, description,
  - and the type-specific validation set. Type picker is a fixed enum;
  - no free-text type entry. ADR-031 declarative-only.
  -
  - The schema's `properties` is an OBJECT (JSON Schema shape) but
  - Vue 2 reactivity needs an ordered array alongside — the editor
  - works on `staged.fields` (Array<{ name, type, required, default,
  - description, validation }>) and the parent reduces it back into
  - { properties, required } before Save.
  -->
<template>
	<section class="openbuild-field-editor">
		<header class="openbuild-field-editor__header">
			<h3>{{ t('openbuild', 'Fields') }}</h3>
			<NcButton @click="addField">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('openbuild', 'Add field') }}
			</NcButton>
		</header>

		<p v-if="fields.length === 0" class="openbuild-field-editor__empty">
			{{ t('openbuild', 'No fields yet. Add the first property to your schema.') }}
		</p>

		<ul v-else class="openbuild-field-editor__rows">
			<li
				v-for="(field, index) in fields"
				:key="field._key"
				class="openbuild-field-editor__row">
				<div class="openbuild-field-editor__handle">
					<NcButton
						type="tertiary"
						:aria-label="t('openbuild', 'Move up')"
						:disabled="index === 0"
						@click="moveUp(index)">
						<template #icon>
							<ChevronUpIcon :size="18" />
						</template>
					</NcButton>
					<NcButton
						type="tertiary"
						:aria-label="t('openbuild', 'Move down')"
						:disabled="index === fields.length - 1"
						@click="moveDown(index)">
						<template #icon>
							<ChevronDownIcon :size="18" />
						</template>
					</NcButton>
				</div>

				<div class="openbuild-field-editor__row-grid">
					<NcTextField
						:model-value="field.name"
						:label="t('openbuild', 'Name')"
						:error="!!nameError(field, index)"
						:helper-text="nameError(field, index)"
						@update:model-value="updateField(index, 'name', $event)" />

					<NcSelect
						:input-label="t('openbuild', 'Type')"
						:model-value="typeOption(field.type)"
						:options="typeOptions"
						:clearable="false"
						label="label"
						track-by="value"
						@update:model-value="updateField(index, 'type', $event ? $event.value : 'string')" />

					<NcCheckboxRadioSwitch
						:model-value="!!field.required"
						type="switch"
						@update:model-value="updateField(index, 'required', $event)">
						{{ t('openbuild', 'Required') }}
					</NcCheckboxRadioSwitch>

					<NcTextField
						:model-value="field.description || ''"
						:label="t('openbuild', 'Description')"
						@update:model-value="updateField(index, 'description', $event)" />
				</div>

				<div class="openbuild-field-editor__validation">
					<!-- string -->
					<template v-if="field.type === 'string'">
						<NcTextField
							:model-value="field.validation.format || ''"
							:label="t('openbuild', 'Format (optional)')"
							:placeholder="'email, uri, date, …'"
							@update:model-value="updateValidation(index, 'format', $event)" />
						<NcTextField
							:model-value="field.validation.pattern || ''"
							:label="t('openbuild', 'Pattern (regex, optional)')"
							@update:model-value="updateValidation(index, 'pattern', $event)" />
						<NcTextField
							:model-value="field.validation.minLength != null ? String(field.validation.minLength) : ''"
							:label="t('openbuild', 'Min length')"
							@update:model-value="updateValidation(index, 'minLength', toIntOrNull($event))" />
						<NcTextField
							:model-value="field.validation.maxLength != null ? String(field.validation.maxLength) : ''"
							:label="t('openbuild', 'Max length')"
							@update:model-value="updateValidation(index, 'maxLength', toIntOrNull($event))" />
					</template>

					<!-- number / integer -->
					<template v-else-if="field.type === 'number' || field.type === 'integer'">
						<NcTextField
							:model-value="field.validation.minimum != null ? String(field.validation.minimum) : ''"
							:label="t('openbuild', 'Minimum')"
							@update:model-value="updateValidation(index, 'minimum', toNumberOrNull($event))" />
						<NcTextField
							:model-value="field.validation.maximum != null ? String(field.validation.maximum) : ''"
							:label="t('openbuild', 'Maximum')"
							@update:model-value="updateValidation(index, 'maximum', toNumberOrNull($event))" />
						<NcTextField
							:model-value="field.validation.multipleOf != null ? String(field.validation.multipleOf) : ''"
							:label="t('openbuild', 'Multiple of')"
							@update:model-value="updateValidation(index, 'multipleOf', toNumberOrNull($event))" />
					</template>

					<!-- array -->
					<template v-else-if="field.type === 'array'">
						<NcSelect
							:input-label="t('openbuild', 'Items type')"
							:model-value="typeOption(field.validation.itemsType || 'string')"
							:options="itemsTypeOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@update:model-value="updateValidation(index, 'itemsType', $event ? $event.value : 'string')" />
						<NcTextField
							:model-value="field.validation.minItems != null ? String(field.validation.minItems) : ''"
							:label="t('openbuild', 'Min items')"
							@update:model-value="updateValidation(index, 'minItems', toIntOrNull($event))" />
						<NcTextField
							:model-value="field.validation.maxItems != null ? String(field.validation.maxItems) : ''"
							:label="t('openbuild', 'Max items')"
							@update:model-value="updateValidation(index, 'maxItems', toIntOrNull($event))" />
					</template>

					<!-- relation -->
					<template v-else-if="field.type === 'relation'">
						<NcSelect
							:input-label="t('openbuild', 'Target schema')"
							:model-value="schemaOption(field.validation.target)"
							:options="schemaOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@update:model-value="updateValidation(index, 'target', $event ? $event.value : '')" />
						<NcSelect
							:input-label="t('openbuild', 'Cardinality')"
							:model-value="cardinalityOption(field.validation.cardinality || 'one')"
							:options="cardinalityOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@update:model-value="updateValidation(index, 'cardinality', $event ? $event.value : 'one')" />
						<NcTextField
							:model-value="field.validation.inverseOf || ''"
							:label="t('openbuild', 'Inverse-of property (optional)')"
							@update:model-value="updateValidation(index, 'inverseOf', $event)" />
					</template>
				</div>

				<div class="openbuild-field-editor__actions">
					<NcButton type="error" @click="requestRemove(index)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('openbuild', 'Remove field') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<DeleteFieldDialog
			:open="removeDialogOpen"
			:field-name="pendingRemoveName"
			@confirm="confirmRemove"
			@cancel="cancelRemove"
			@update:open="removeDialogOpen = $event" />
	</section>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

import DeleteFieldDialog from '../../modals/DeleteFieldDialog.vue'

const FIELD_NAME_PATTERN = /^[a-zA-Z][a-zA-Z0-9_-]*$/

const SUPPORTED_TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object', 'relation']
const ITEMS_TYPES = ['string', 'number', 'integer', 'boolean', 'object']
const CARDINALITIES = ['one', 'many']

let keyCounter = 0
function nextKey() {
	keyCounter += 1
	return `field-${keyCounter}`
}

export default {
	name: 'FieldEditor',
	components: {
		ChevronDownIcon,
		ChevronUpIcon,
		DeleteFieldDialog,
		DeleteIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		PlusIcon,
	},
	props: {
		fields: { type: Array, default: () => [] },
		schemaSlugs: { type: Array, default: () => [] },
	},
	emits: ['update:fields'],
	data() {
		return {
			removeDialogOpen: false,
			pendingRemoveIndex: -1,
			pendingRemoveName: '',
		}
	},
	computed: {
		/**
		 * Build the field-type picker options.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		typeOptions() {
			return SUPPORTED_TYPES.map((value) => ({
				value,
				label: this.t('openbuild', value),
			}))
		},
		/**
		 * Build the array-items-type picker options.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		itemsTypeOptions() {
			return ITEMS_TYPES.map((value) => ({
				value,
				label: this.t('openbuild', value),
			}))
		},
		/**
		 * Build relation target-schema picker options.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		schemaOptions() {
			return this.schemaSlugs.map((slug) => ({ value: slug, label: slug }))
		},
		/**
		 * Build cardinality picker options (one/many).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		cardinalityOptions() {
			return CARDINALITIES.map((value) => ({
				value,
				label: value === 'one'
					? this.t('openbuild', 'One')
					: this.t('openbuild', 'Many'),
			}))
		},
	},
	methods: {
		/**
		 * Resolve the selected field-type option.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {string} type Field type.
		 * @return {object} Matching option.
		 */
		typeOption(type) {
			return this.typeOptions.find((o) => o.value === type) || this.typeOptions[0]
		},
		/**
		 * Resolve the selected target-schema option.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {string} value Schema slug.
		 * @return {object|null} Matching option.
		 */
		schemaOption(value) {
			return this.schemaOptions.find((o) => o.value === value) || null
		},
		/**
		 * Resolve the selected cardinality option.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {string} value Cardinality.
		 * @return {object} Matching option.
		 */
		cardinalityOption(value) {
			return this.cardinalityOptions.find((o) => o.value === value) || this.cardinalityOptions[0]
		},
		/**
		 * Validate a field name: presence, pattern, and uniqueness.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {object} field Field row.
		 * @param {number} index Row index.
		 * @return {string} Error message, or empty when valid.
		 */
		nameError(field, index) {
			if (!field.name) {
				return this.t('openbuild', 'Name is required.')
			}
			if (!FIELD_NAME_PATTERN.test(field.name)) {
				return this.t('openbuild', 'Name must start with a letter and use letters, digits, underscores, or hyphens only.')
			}
			const duplicate = this.fields.some((other, otherIndex) => otherIndex !== index && other.name === field.name)
			if (duplicate) {
				return this.t('openbuild', 'Name must be unique within the schema.')
			}
			return ''
		},
		/**
		 * Coerce an input to an integer or null.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {*} value Raw input.
		 * @return {number|null} Parsed integer or null.
		 */
		toIntOrNull(value) {
			if (value === '' || value == null) {
				return null
			}
			const parsed = parseInt(value, 10)
			return Number.isFinite(parsed) ? parsed : null
		},
		/**
		 * Coerce an input to a number or null.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {*} value Raw input.
		 * @return {number|null} Parsed number or null.
		 */
		toNumberOrNull(value) {
			if (value === '' || value == null) {
				return null
			}
			const parsed = Number(value)
			return Number.isFinite(parsed) ? parsed : null
		},
		/**
		 * Emit the updated fields array to the parent.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {Array} next Next fields array.
		 * @return {void}
		 */
		emitFields(next) {
			this.$emit('update:fields', next)
		},
		/**
		 * Append a new blank field row.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {void}
		 */
		addField() {
			const next = this.fields.slice()
			next.push({
				_key: nextKey(),
				name: '',
				type: 'string',
				required: false,
				default: null,
				description: '',
				validation: {},
			})
			this.emitFields(next)
		},
		/**
		 * Update a single field property; resets validation on type change.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {number} index Row index.
		 * @param {string} key Property key.
		 * @param {*} value New value.
		 * @return {void}
		 */
		updateField(index, key, value) {
			const next = this.fields.slice()
			const current = { ...next[index] }
			current[key] = value
			if (key === 'type') {
				// Reset validation when type changes — different types share no
				// validation slots (string format vs number multipleOf).
				current.validation = {}
			}
			next[index] = current
			this.emitFields(next)
		},
		/**
		 * Set or clear a single validation slot on a field.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {number} index Row index.
		 * @param {string} key Validation key.
		 * @param {*} value New value (empty clears the slot).
		 * @return {void}
		 */
		updateValidation(index, key, value) {
			const next = this.fields.slice()
			const current = { ...next[index] }
			const validation = { ...(current.validation || {}) }
			if (value === '' || value == null) {
				delete validation[key]
			} else {
				validation[key] = value
			}
			current.validation = validation
			next[index] = current
			this.emitFields(next)
		},
		/**
		 * Move a field up one position.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {number} index Row index.
		 * @return {void}
		 */
		moveUp(index) {
			if (index === 0) {
				return
			}
			const next = this.fields.slice()
			const [moved] = next.splice(index, 1)
			next.splice(index - 1, 0, moved)
			this.emitFields(next)
		},
		/**
		 * Move a field down one position.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {number} index Row index.
		 * @return {void}
		 */
		moveDown(index) {
			if (index === this.fields.length - 1) {
				return
			}
			const next = this.fields.slice()
			const [moved] = next.splice(index, 1)
			next.splice(index + 1, 0, moved)
			this.emitFields(next)
		},
		/**
		 * Open the remove-field confirmation dialog.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {number} index Row index.
		 * @return {void}
		 */
		requestRemove(index) {
			this.pendingRemoveIndex = index
			this.pendingRemoveName = this.fields[index]?.name || this.t('openbuild', '(unnamed)')
			this.removeDialogOpen = true
		},
		/**
		 * Confirm removal of the pending field.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {void}
		 */
		confirmRemove() {
			if (this.pendingRemoveIndex < 0) {
				this.removeDialogOpen = false
				return
			}
			const next = this.fields.slice()
			next.splice(this.pendingRemoveIndex, 1)
			this.emitFields(next)
			this.cancelRemove()
		},
		/**
		 * Cancel the pending field removal and reset state.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {void}
		 */
		cancelRemove() {
			this.removeDialogOpen = false
			this.pendingRemoveIndex = -1
			this.pendingRemoveName = ''
		},
	},
}

/**
 * Convert a JSON Schema `properties` map + `required` array into the
 * ordered editor model used by this component. The reverse helper
 * `fieldsToSchema` reduces editor state back into JSON Schema.
 *
 * Exported for use by SchemaDesigner.vue.
 *
 * @param {object} schema A JSON Schema fragment with `properties` + `required`.
 * @return {Array} Editor field rows.
 */
export function schemaToFields(schema) {
	const properties = (schema && schema.properties) || {}
	const required = (schema && Array.isArray(schema.required)) ? schema.required : []
	const order = (schema && Array.isArray(schema['x-property-order']))
		? schema['x-property-order']
		: Object.keys(properties)
	const fields = []
	for (const name of order) {
		if (!(name in properties)) {
			continue
		}
		const prop = properties[name] || {}
		fields.push(fieldFromProperty(name, prop, required.includes(name)))
	}
	// Append any properties that weren't in the explicit order.
	for (const name of Object.keys(properties)) {
		if (!order.includes(name)) {
			fields.push(fieldFromProperty(name, properties[name], required.includes(name)))
		}
	}
	return fields
}

function fieldFromProperty(name, prop, isRequired) {
	const type = prop['x-openregister-relation']
		? 'relation'
		: (prop.type || 'string')
	const validation = {}
	if (type === 'string') {
		if (prop.format) validation.format = prop.format
		if (prop.pattern) validation.pattern = prop.pattern
		if (prop.minLength != null) validation.minLength = prop.minLength
		if (prop.maxLength != null) validation.maxLength = prop.maxLength
	} else if (type === 'number' || type === 'integer') {
		if (prop.minimum != null) validation.minimum = prop.minimum
		if (prop.maximum != null) validation.maximum = prop.maximum
		if (prop.multipleOf != null) validation.multipleOf = prop.multipleOf
	} else if (type === 'array') {
		if (prop.items && prop.items.type) validation.itemsType = prop.items.type
		if (prop.minItems != null) validation.minItems = prop.minItems
		if (prop.maxItems != null) validation.maxItems = prop.maxItems
	} else if (type === 'relation') {
		const rel = prop['x-openregister-relation'] || {}
		if (rel.target) validation.target = rel.target
		if (rel.cardinality) validation.cardinality = rel.cardinality
		if (rel.inverseOf) validation.inverseOf = rel.inverseOf
	}
	return {
		_key: nextKey(),
		name,
		type,
		required: isRequired,
		default: prop.default != null ? prop.default : null,
		description: prop.description || '',
		validation,
	}
}

/**
 * Reduce editor field rows back into a JSON Schema `properties` map +
 * `required` array + `x-property-order` array (to preserve user order).
 *
 * @param {Array} fields Editor field rows.
 * @return {{ properties: object, required: Array<string>, order: Array<string> }}
 */
export function fieldsToSchema(fields) {
	const properties = {}
	const required = []
	const order = []
	for (const field of fields) {
		if (!field.name) {
			continue
		}
		order.push(field.name)
		const prop = propertyFromField(field)
		properties[field.name] = prop
		if (field.required) {
			required.push(field.name)
		}
	}
	return { properties, required, order }
}

function propertyFromField(field) {
	const prop = {}
	if (field.description) {
		prop.description = field.description
	}
	if (field.default != null && field.default !== '') {
		prop.default = field.default
	}
	const v = field.validation || {}
	switch (field.type) {
	case 'string':
		prop.type = 'string'
		if (v.format) prop.format = v.format
		if (v.pattern) prop.pattern = v.pattern
		if (v.minLength != null) prop.minLength = v.minLength
		if (v.maxLength != null) prop.maxLength = v.maxLength
		break
	case 'number':
	case 'integer':
		prop.type = field.type
		if (v.minimum != null) prop.minimum = v.minimum
		if (v.maximum != null) prop.maximum = v.maximum
		if (v.multipleOf != null) prop.multipleOf = v.multipleOf
		break
	case 'boolean':
		prop.type = 'boolean'
		break
	case 'array':
		prop.type = 'array'
		prop.items = { type: v.itemsType || 'string' }
		if (v.minItems != null) prop.minItems = v.minItems
		if (v.maxItems != null) prop.maxItems = v.maxItems
		break
	case 'object':
		prop.type = 'object'
		break
	case 'relation':
		prop.type = 'string'
		prop['x-openregister-relation'] = {
			target: v.target || '',
			cardinality: v.cardinality || 'one',
			...(v.inverseOf ? { inverseOf: v.inverseOf } : {}),
		}
		break
	default:
		prop.type = 'string'
		break
	}
	return prop
}
</script>

<style scoped>
.openbuild-field-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-field-editor__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.openbuild-field-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuild-field-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.openbuild-field-editor__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-field-editor__row {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.openbuild-field-editor__handle {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.openbuild-field-editor__row-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.openbuild-field-editor__validation {
	grid-column: 2;
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.openbuild-field-editor__actions {
	grid-column: 2;
	display: flex;
	justify-content: flex-end;
}
</style>
