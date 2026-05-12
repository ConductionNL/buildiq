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
	<section class="openbuilt-field-editor">
		<header class="openbuilt-field-editor__header">
			<h3>{{ t('openbuilt', 'Fields') }}</h3>
			<NcButton @click="addField">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('openbuilt', 'Add field') }}
			</NcButton>
		</header>

		<p v-if="fields.length === 0" class="openbuilt-field-editor__empty">
			{{ t('openbuilt', 'No fields yet. Add the first property to your schema.') }}
		</p>

		<ul v-else class="openbuilt-field-editor__rows">
			<li
				v-for="(field, index) in fields"
				:key="field._key"
				class="openbuilt-field-editor__row">
				<div class="openbuilt-field-editor__handle">
					<NcButton
						type="tertiary"
						:aria-label="t('openbuilt', 'Move up')"
						:disabled="index === 0"
						@click="moveUp(index)">
						<template #icon>
							<ChevronUpIcon :size="18" />
						</template>
					</NcButton>
					<NcButton
						type="tertiary"
						:aria-label="t('openbuilt', 'Move down')"
						:disabled="index === fields.length - 1"
						@click="moveDown(index)">
						<template #icon>
							<ChevronDownIcon :size="18" />
						</template>
					</NcButton>
				</div>

				<div class="openbuilt-field-editor__row-grid">
					<NcTextField
						:value="field.name"
						:label="t('openbuilt', 'Name')"
						:error="!!nameError(field, index)"
						:helper-text="nameError(field, index)"
						@update:value="updateField(index, 'name', $event)" />

					<NcSelect
						:input-label="t('openbuilt', 'Type')"
						:value="typeOption(field.type)"
						:options="typeOptions"
						:clearable="false"
						label="label"
						track-by="value"
						@input="updateField(index, 'type', $event ? $event.value : 'string')" />

					<NcCheckboxRadioSwitch
						:checked="!!field.required"
						type="switch"
						@update:checked="updateField(index, 'required', $event)">
						{{ t('openbuilt', 'Required') }}
					</NcCheckboxRadioSwitch>

					<NcTextField
						:value="field.description || ''"
						:label="t('openbuilt', 'Description')"
						@update:value="updateField(index, 'description', $event)" />
				</div>

				<div class="openbuilt-field-editor__validation">
					<!-- string -->
					<template v-if="field.type === 'string'">
						<NcTextField
							:value="field.validation.format || ''"
							:label="t('openbuilt', 'Format (optional)')"
							:placeholder="'email, uri, date, …'"
							@update:value="updateValidation(index, 'format', $event)" />
						<NcTextField
							:value="field.validation.pattern || ''"
							:label="t('openbuilt', 'Pattern (regex, optional)')"
							@update:value="updateValidation(index, 'pattern', $event)" />
						<NcTextField
							:value="field.validation.minLength != null ? String(field.validation.minLength) : ''"
							:label="t('openbuilt', 'Min length')"
							@update:value="updateValidation(index, 'minLength', toIntOrNull($event))" />
						<NcTextField
							:value="field.validation.maxLength != null ? String(field.validation.maxLength) : ''"
							:label="t('openbuilt', 'Max length')"
							@update:value="updateValidation(index, 'maxLength', toIntOrNull($event))" />
					</template>

					<!-- number / integer -->
					<template v-else-if="field.type === 'number' || field.type === 'integer'">
						<NcTextField
							:value="field.validation.minimum != null ? String(field.validation.minimum) : ''"
							:label="t('openbuilt', 'Minimum')"
							@update:value="updateValidation(index, 'minimum', toNumberOrNull($event))" />
						<NcTextField
							:value="field.validation.maximum != null ? String(field.validation.maximum) : ''"
							:label="t('openbuilt', 'Maximum')"
							@update:value="updateValidation(index, 'maximum', toNumberOrNull($event))" />
						<NcTextField
							:value="field.validation.multipleOf != null ? String(field.validation.multipleOf) : ''"
							:label="t('openbuilt', 'Multiple of')"
							@update:value="updateValidation(index, 'multipleOf', toNumberOrNull($event))" />
					</template>

					<!-- array -->
					<template v-else-if="field.type === 'array'">
						<NcSelect
							:input-label="t('openbuilt', 'Items type')"
							:value="typeOption(field.validation.itemsType || 'string')"
							:options="itemsTypeOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@input="updateValidation(index, 'itemsType', $event ? $event.value : 'string')" />
						<NcTextField
							:value="field.validation.minItems != null ? String(field.validation.minItems) : ''"
							:label="t('openbuilt', 'Min items')"
							@update:value="updateValidation(index, 'minItems', toIntOrNull($event))" />
						<NcTextField
							:value="field.validation.maxItems != null ? String(field.validation.maxItems) : ''"
							:label="t('openbuilt', 'Max items')"
							@update:value="updateValidation(index, 'maxItems', toIntOrNull($event))" />
					</template>

					<!-- relation -->
					<template v-else-if="field.type === 'relation'">
						<NcSelect
							:input-label="t('openbuilt', 'Target schema')"
							:value="schemaOption(field.validation.target)"
							:options="schemaOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@input="updateValidation(index, 'target', $event ? $event.value : '')" />
						<NcSelect
							:input-label="t('openbuilt', 'Cardinality')"
							:value="cardinalityOption(field.validation.cardinality || 'one')"
							:options="cardinalityOptions"
							:clearable="false"
							label="label"
							track-by="value"
							@input="updateValidation(index, 'cardinality', $event ? $event.value : 'one')" />
						<NcTextField
							:value="field.validation.inverseOf || ''"
							:label="t('openbuilt', 'Inverse-of property (optional)')"
							@update:value="updateValidation(index, 'inverseOf', $event)" />
					</template>
				</div>

				<div class="openbuilt-field-editor__actions">
					<NcButton type="error" @click="requestRemove(index)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('openbuilt', 'Remove field') }}
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
		typeOptions() {
			return SUPPORTED_TYPES.map((value) => ({
				value,
				label: this.t('openbuilt', value),
			}))
		},
		itemsTypeOptions() {
			return ITEMS_TYPES.map((value) => ({
				value,
				label: this.t('openbuilt', value),
			}))
		},
		schemaOptions() {
			return this.schemaSlugs.map((slug) => ({ value: slug, label: slug }))
		},
		cardinalityOptions() {
			return CARDINALITIES.map((value) => ({
				value,
				label: value === 'one'
					? this.t('openbuilt', 'One')
					: this.t('openbuilt', 'Many'),
			}))
		},
	},
	methods: {
		typeOption(type) {
			return this.typeOptions.find((o) => o.value === type) || this.typeOptions[0]
		},
		schemaOption(value) {
			return this.schemaOptions.find((o) => o.value === value) || null
		},
		cardinalityOption(value) {
			return this.cardinalityOptions.find((o) => o.value === value) || this.cardinalityOptions[0]
		},
		nameError(field, index) {
			if (!field.name) {
				return this.t('openbuilt', 'Name is required.')
			}
			if (!FIELD_NAME_PATTERN.test(field.name)) {
				return this.t('openbuilt', 'Name must start with a letter and use letters, digits, underscores, or hyphens only.')
			}
			const duplicate = this.fields.some((other, otherIndex) => otherIndex !== index && other.name === field.name)
			if (duplicate) {
				return this.t('openbuilt', 'Name must be unique within the schema.')
			}
			return ''
		},
		toIntOrNull(value) {
			if (value === '' || value == null) {
				return null
			}
			const parsed = parseInt(value, 10)
			return Number.isFinite(parsed) ? parsed : null
		},
		toNumberOrNull(value) {
			if (value === '' || value == null) {
				return null
			}
			const parsed = Number(value)
			return Number.isFinite(parsed) ? parsed : null
		},
		emitFields(next) {
			this.$emit('update:fields', next)
		},
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
		moveUp(index) {
			if (index === 0) {
				return
			}
			const next = this.fields.slice()
			const [moved] = next.splice(index, 1)
			next.splice(index - 1, 0, moved)
			this.emitFields(next)
		},
		moveDown(index) {
			if (index === this.fields.length - 1) {
				return
			}
			const next = this.fields.slice()
			const [moved] = next.splice(index, 1)
			next.splice(index + 1, 0, moved)
			this.emitFields(next)
		},
		requestRemove(index) {
			this.pendingRemoveIndex = index
			this.pendingRemoveName = this.fields[index]?.name || this.t('openbuilt', '(unnamed)')
			this.removeDialogOpen = true
		},
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
.openbuilt-field-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-field-editor__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.openbuilt-field-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuilt-field-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.openbuilt-field-editor__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-field-editor__row {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.openbuilt-field-editor__handle {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.openbuilt-field-editor__row-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.openbuilt-field-editor__validation {
	grid-column: 2;
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.openbuilt-field-editor__actions {
	grid-column: 2;
	display: flex;
	justify-content: flex-end;
}
</style>
