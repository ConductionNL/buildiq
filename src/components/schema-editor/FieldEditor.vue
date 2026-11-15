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
	<section class="buildiq-field-editor">
		<header class="buildiq-field-editor__header">
			<h3>{{ t('buildiq', 'Fields') }}</h3>
			<NcButton @click="addField">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('buildiq', 'Add field') }}
			</NcButton>
		</header>

		<p v-if="fields.length === 0" class="buildiq-field-editor__empty">
			{{
				t('buildiq', 'No fields yet. Add the first property to your schema.')
			}}
		</p>

		<ul v-else class="buildiq-field-editor__rows">
			<li
				v-for="(field, index) in fields"
				:key="field._key"
				class="buildiq-field-editor__row">
				<div class="buildiq-field-editor__handle">
					<NcButton
						variant="tertiary"
						:aria-label="t('buildiq', 'Move up')"
						:disabled="index === 0"
						@click="moveUp(index)">
						<template #icon>
							<ChevronUpIcon :size="18" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('buildiq', 'Move down')"
						:disabled="index === fields.length - 1"
						@click="moveDown(index)">
						<template #icon>
							<ChevronDownIcon :size="18" />
						</template>
					</NcButton>
				</div>

				<div class="buildiq-field-editor__row-grid">
					<NcTextField
						:modelValue="field.name"
						:label="t('buildiq', 'Name')"
						:error="!!nameError(field, index)"
						:helperText="nameError(field, index)"
						@update:modelValue="updateField(index, 'name', $event)" />

					<NcSelect
						:inputLabel="t('buildiq', 'Type')"
						:modelValue="typeOption(field.type)"
						:options="typeOptionsFor(field)"
						:clearable="false"
						label="label"
						trackBy="value"
						@update:modelValue="
							updateField(
								index,
								'type',
								$event ? $event.value : 'string',
							)
						" />

					<NcCheckboxRadioSwitch
						:modelValue="!!field.required"
						type="switch"
						@update:modelValue="updateField(index, 'required', $event)">
						{{ t('buildiq', 'Required') }}
					</NcCheckboxRadioSwitch>

					<NcTextField
						:modelValue="field.description || ''"
						:label="t('buildiq', 'Description')"
						@update:modelValue="
							updateField(index, 'description', $event)
						" />
				</div>

				<div class="buildiq-field-editor__validation">
					<!-- text, long text, and a string no named type fits -->
					<template v-if="hasTextSlots(field)">
						<NcTextField
							v-if="field.type === 'string'"
							:modelValue="field.validation.format || ''"
							:label="t('buildiq', 'Format (optional)')"
							placeholder="email, uri, date, …"
							@update:modelValue="
								updateValidation(index, 'format', $event)
							" />
						<NcTextField
							:modelValue="field.validation.pattern || ''"
							:label="t('buildiq', 'Pattern (regex, optional)')"
							@update:modelValue="
								updateValidation(index, 'pattern', $event)
							" />
						<NcTextField
							:modelValue="
								field.validation.minLength != null
									? String(field.validation.minLength)
									: ''
							"
							:label="t('buildiq', 'Min length')"
							@update:modelValue="
								updateValidation(
									index,
									'minLength',
									toIntOrNull($event),
								)
							" />
						<NcTextField
							:modelValue="
								field.validation.maxLength != null
									? String(field.validation.maxLength)
									: ''
							"
							:label="t('buildiq', 'Max length')"
							@update:modelValue="
								updateValidation(
									index,
									'maxLength',
									toIntOrNull($event),
								)
							" />
					</template>

					<!-- number / integer -->
					<template
						v-else-if="
							field.type === 'number' || field.type === 'integer'
						">
						<NcTextField
							:modelValue="
								field.validation.minimum != null
									? String(field.validation.minimum)
									: ''
							"
							:label="t('buildiq', 'Minimum')"
							@update:modelValue="
								updateValidation(
									index,
									'minimum',
									toNumberOrNull($event),
								)
							" />
						<NcTextField
							:modelValue="
								field.validation.maximum != null
									? String(field.validation.maximum)
									: ''
							"
							:label="t('buildiq', 'Maximum')"
							@update:modelValue="
								updateValidation(
									index,
									'maximum',
									toNumberOrNull($event),
								)
							" />
						<NcTextField
							:modelValue="
								field.validation.multipleOf != null
									? String(field.validation.multipleOf)
									: ''
							"
							:label="t('buildiq', 'Multiple of')"
							@update:modelValue="
								updateValidation(
									index,
									'multipleOf',
									toNumberOrNull($event),
								)
							" />
					</template>

					<!-- choice, several choices: inline options or a shared list (design D2) -->
					<div
						v-else-if="isChoice(field)"
						class="buildiq-field-editor__choice">
						<NcCheckboxRadioSwitch
							:modelValue="field.validation.conceptScheme === undefined ? 'inline' : 'shared'"
							value="inline"
							type="radio"
							:name="'choice-source-' + field._key"
							@update:modelValue="setChoiceSource(index, false)">
							{{ t('buildiq', 'Enter the options here') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:modelValue="field.validation.conceptScheme === undefined ? 'inline' : 'shared'"
							value="shared"
							type="radio"
							:name="'choice-source-' + field._key"
							@update:modelValue="setChoiceSource(index, true)">
							{{ t('buildiq', 'Use a shared list') }}
						</NcCheckboxRadioSwitch>

						<template v-if="field.validation.conceptScheme === undefined">
							<ol class="buildiq-field-editor__options">
								<li
									v-for="(option, position) in field.validation.options || []"
									:key="position"
									class="buildiq-field-editor__option">
									<NcTextField
										:modelValue="option.value"
										:label="t('buildiq', 'Value')"
										@update:modelValue="updateOption(index, position, 'value', $event)" />
									<NcTextField
										:modelValue="option.label"
										:label="t('buildiq', 'Label')"
										@update:modelValue="updateOption(index, position, 'label', $event)" />
									<NcButton
										variant="tertiary"
										:aria-label="t('buildiq', 'Move option up')"
										:disabled="position === 0"
										@click="moveOption(index, position, -1)">
										<template #icon>
											<ChevronUpIcon :size="20" />
										</template>
									</NcButton>
									<NcButton
										variant="tertiary"
										:aria-label="t('buildiq', 'Move option down')"
										:disabled="position === (field.validation.options || []).length - 1"
										@click="moveOption(index, position, 1)">
										<template #icon>
											<ChevronDownIcon :size="20" />
										</template>
									</NcButton>
									<NcButton
										variant="tertiary"
										:aria-label="t('buildiq', 'Remove option')"
										@click="removeOption(index, position)">
										<template #icon>
											<DeleteIcon :size="20" />
										</template>
									</NcButton>
								</li>
							</ol>
							<NcButton @click="addOption(index)">
								<template #icon>
									<PlusIcon :size="20" />
								</template>
								{{ t('buildiq', 'Add option') }}
							</NcButton>
						</template>

						<template v-else>
							<NcSelect
								:inputLabel="t('buildiq', 'Shared list')"
								:modelValue="schemeOption(field.validation.conceptScheme)"
								:options="schemes"
								:loading="schemesLoading"
								label="label"
								trackBy="value"
								@open="loadSchemes"
								@update:modelValue="pickScheme(index, $event)" />
							<p v-if="schemesError" class="buildiq-field-editor__note" role="alert">
								{{ schemesError }}
							</p>
							<ul
								v-if="(schemeOptions[field.validation.conceptScheme] || []).length"
								class="buildiq-field-editor__shared-options"
								:aria-label="t('buildiq', 'Options in this list')">
								<li
									v-for="shared in schemeOptions[field.validation.conceptScheme]"
									:key="shared.value">
									{{ shared.label }}
								</li>
							</ul>
							<p class="buildiq-field-editor__note">
								{{ t('buildiq', 'The options come from the shared list, so an option added to it later is offered without saving this schema again.') }}
							</p>
						</template>
					</div>

					<!-- user, several users (design D3) -->
					<p
						v-else-if="field.type === 'user' || field.type === 'users'"
						class="buildiq-field-editor__note">
						{{ t('buildiq', 'Stores the Nextcloud user id; the form shows a searchable list of users.') }}
					</p>

					<!-- array -->
					<template v-else-if="field.type === 'array'">
						<NcSelect
							:inputLabel="t('buildiq', 'Items type')"
							:modelValue="
								typeOption(field.validation.itemsType || 'string')
							"
							:options="itemsTypeOptions"
							:clearable="false"
							label="label"
							trackBy="value"
							@update:modelValue="
								updateValidation(
									index,
									'itemsType',
									$event ? $event.value : 'string',
								)
							" />
						<NcTextField
							:modelValue="
								field.validation.minItems != null
									? String(field.validation.minItems)
									: ''
							"
							:label="t('buildiq', 'Min items')"
							@update:modelValue="
								updateValidation(
									index,
									'minItems',
									toIntOrNull($event),
								)
							" />
						<NcTextField
							:modelValue="
								field.validation.maxItems != null
									? String(field.validation.maxItems)
									: ''
							"
							:label="t('buildiq', 'Max items')"
							@update:modelValue="
								updateValidation(
									index,
									'maxItems',
									toIntOrNull($event),
								)
							" />
					</template>

					<!-- relation -->
					<template v-else-if="field.type === 'relation'">
						<NcSelect
							:inputLabel="t('buildiq', 'Target schema')"
							:modelValue="schemaOption(field.validation.target)"
							:options="schemaOptions"
							:clearable="false"
							label="label"
							trackBy="value"
							@update:modelValue="
								updateValidation(
									index,
									'target',
									$event ? $event.value : '',
								)
							" />
						<NcSelect
							:inputLabel="t('buildiq', 'Cardinality')"
							:modelValue="
								cardinalityOption(
									field.validation.cardinality || 'one',
								)
							"
							:options="cardinalityOptions"
							:clearable="false"
							label="label"
							trackBy="value"
							@update:modelValue="
								updateValidation(
									index,
									'cardinality',
									$event ? $event.value : 'one',
								)
							" />
						<NcTextField
							:modelValue="field.validation.inverseOf || ''"
							:label="t('buildiq', 'Inverse-of property (optional)')"
							@update:modelValue="
								updateValidation(index, 'inverseOf', $event)
							" />
					</template>

					<!-- several choices, files or users: how many -->
					<template v-if="isMany(field)">
						<NcTextField
							:modelValue="field.validation.minItems != null ? String(field.validation.minItems) : ''"
							:label="t('buildiq', 'Min items')"
							@update:modelValue="updateValidation(index, 'minItems', toIntOrNull($event))" />
						<NcTextField
							:modelValue="field.validation.maxItems != null ? String(field.validation.maxItems) : ''"
							:label="t('buildiq', 'Max items')"
							@update:modelValue="updateValidation(index, 'maxItems', toIntOrNull($event))" />
					</template>
				</div>

				<div class="buildiq-field-editor__actions">
					<NcButton variant="error" @click="requestRemove(index)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('buildiq', 'Remove field') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<DeleteFieldDialog
			:open="removeDialogOpen"
			:fieldName="pendingRemoveName"
			@confirm="confirmRemove"
			@cancel="cancelRemove"
			@update:open="removeDialogOpen = $event" />
	</section>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteFieldDialog from '../../modals/DeleteFieldDialog.vue'
import { listConceptSchemes, listSchemeOptions } from '../../services/conceptSchemes.js'

const FIELD_NAME_PATTERN = /^[a-zA-Z][a-zA-Z0-9_-]*$/

/**
 * The named field types the picker offers, in picker order, with the label a
 * maker reads (data-field-types-and-choice-lists design D1). Each is stored as
 * one JSON Schema shape; `fieldTypeOf()` reads a stored property back to it.
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-the-designer-offers-named-field-types-req-bqft-001
 */
export const NAMED_TYPES = [
	{ value: 'text', label: 'Text' },
	{ value: 'longtext', label: 'Long text' },
	{ value: 'number', label: 'Number' },
	{ value: 'integer', label: 'Whole number' },
	{ value: 'boolean', label: 'Yes or no' },
	{ value: 'date', label: 'Date' },
	{ value: 'datetime', label: 'Date and time' },
	{ value: 'choice', label: 'Choice' },
	{ value: 'choices', label: 'Several choices' },
	{ value: 'file', label: 'File' },
	{ value: 'files', label: 'Several files' },
	{ value: 'user', label: 'User' },
	{ value: 'users', label: 'Several users' },
	{ value: 'relation', label: 'Relation' },
	{ value: 'array', label: 'List' },
	{ value: 'object', label: 'Group' },
]

/** The JSON types a stored property may fall back to when no named type fits. */
const JSON_TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object']

/** The user reference key nextcloud-vue renders as a user picker (design D3). */
const USER_REFERENCE = 'nextcloud-user'
const ITEMS_TYPES = ['string', 'number', 'integer', 'boolean', 'object']
const CARDINALITIES = ['one', 'many']

let keyCounter = 0
/**
 *
 */
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
			schemes: [],
			schemesLoaded: false,
			schemesLoading: false,
			schemesError: '',
			schemeOptions: {},
		}
	},

	computed: {
		/**
		 * Build the field-type picker options.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		typeOptions() {
			return NAMED_TYPES.map((type) => ({
				value: type.value,
				label: this.t('buildiq', type.label),
			}))
		},

		/**
		 * Build the array-items-type picker options.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		itemsTypeOptions() {
			return ITEMS_TYPES.map((value) => ({
				value,
				label: this.t('buildiq', value),
			}))
		},

		/**
		 * Build relation target-schema picker options.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		schemaOptions() {
			return this.schemaSlugs.map((slug) => ({ value: slug, label: slug }))
		},

		/**
		 * Build cardinality picker options (one/many).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {Array} Option objects.
		 */
		cardinalityOptions() {
			return CARDINALITIES.map((value) => ({
				value,
				label:
					value === 'one'
						? this.t('buildiq', 'One')
						: this.t('buildiq', 'Many'),
			}))
		},
	},

	mounted() {
		this.loadSharedLists()
	},

	methods: {
		/**
		 * Resolve the selected field-type option.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {string} type Field type.
		 * @return {object} Matching option.
		 */
		typeOption(type) {
			const named = this.typeOptions.find((o) => o.value === type)
			if (named) {
				return named
			}
			// A stored property no named type fits opens as its JSON type (design D1).
			return JSON_TYPES.includes(type)
				? { value: type, label: type }
				: this.typeOptions[0]
		},

		/**
		 * The type picker's options for one row: the named types, plus the
		 * row's own JSON type when no named type fits it.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-the-designer-offers-named-field-types-req-bqft-001
		 * @param {object} field Field row.
		 * @return {Array} Option objects.
		 */
		typeOptionsFor(field) {
			const own = this.typeOption(field.type)
			return this.typeOptions.some((o) => o.value === own.value)
				? this.typeOptions
				: [...this.typeOptions, own]
		},

		/**
		 * Whether a row holds choices.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {object} field Field row.
		 * @return {boolean} True for a choice or several choices.
		 */
		isChoice(field) {
			return field.type === 'choice' || field.type === 'choices'
		},

		/**
		 * Whether a row takes the text slots (pattern and lengths).
		 *
		 * @param {object} field Field row.
		 * @return {boolean} True for text-like rows.
		 */
		hasTextSlots(field) {
			return ['string', 'text', 'longtext'].includes(field.type)
		},

		/**
		 * Whether a row holds several values (and so takes item counts).
		 *
		 * @param {object} field Field row.
		 * @return {boolean} True for the list-like rows.
		 */
		isMany(field) {
			return ['choices', 'files', 'users'].includes(field.type)
		},

		/**
		 * Switch a choice row between inline options and a shared list. The
		 * two are never kept together (design D2).
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {number} index Row index.
		 * @param {boolean} shared True for a shared list.
		 * @return {void}
		 */
		setChoiceSource(index, shared) {
			this.patchValidation(index, (validation) => {
				if (shared) {
					delete validation.options
					validation.conceptScheme = validation.conceptScheme || ''
				} else {
					delete validation.conceptScheme
					validation.options = validation.options || []
				}
			})
			if (shared) {
				this.loadSchemes()
			}
		},

		/**
		 * Add an empty option row to an inline choice.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {number} index Row index.
		 * @return {void}
		 */
		addOption(index) {
			this.patchValidation(index, (validation) => {
				validation.options = [...(validation.options || []), { value: '', label: '' }]
			})
		},

		/**
		 * Change one option's value or label.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {number} index Row index.
		 * @param {number} position Option position.
		 * @param {string} key `value` or `label`.
		 * @param {string} value The new text.
		 * @return {void}
		 */
		updateOption(index, position, key, value) {
			this.patchValidation(index, (validation) => {
				const options = (validation.options || []).map((option) => ({ ...option }))
				if (options[position]) {
					options[position][key] = value
				}
				validation.options = options
			})
		},

		/**
		 * Move an option one place up (-1) or down (+1).
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {number} index Row index.
		 * @param {number} position Option position.
		 * @param {number} delta -1 or +1.
		 * @return {void}
		 */
		moveOption(index, position, delta) {
			this.patchValidation(index, (validation) => {
				const options = (validation.options || []).slice()
				const target = position + delta
				if (target < 0 || target >= options.length) {
					return
				}
				const [moved] = options.splice(position, 1)
				options.splice(target, 0, moved)
				validation.options = options
			})
		},

		/**
		 * Remove one option.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {number} index Row index.
		 * @param {number} position Option position.
		 * @return {void}
		 */
		removeOption(index, position) {
			this.patchValidation(index, (validation) => {
				validation.options = (validation.options || []).filter((_, i) => i !== position)
			})
		},

		/**
		 * Point a choice row at a shared list and show its options.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {number} index Row index.
		 * @param {object|null} option The picked scheme option.
		 * @return {void}
		 */
		pickScheme(index, option) {
			this.patchValidation(index, (validation) => {
				delete validation.options
				validation.conceptScheme = option ? option.value : ''
			})
			if (option) {
				this.loadSchemeOptions(option)
			}
		},

		/**
		 * The picked scheme's option, for the picker.
		 *
		 * @param {string} slug The stored scheme slug.
		 * @return {object|null} The option.
		 */
		schemeOption(slug) {
			if (!slug) {
				return null
			}
			return this.schemes.find((o) => o.value === slug) || { value: slug, label: slug, uri: '' }
		},

		/**
		 * Load OpenRegister's concept schemes once.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @return {Promise<void>}
		 */
		async loadSchemes() {
			if (this.schemesLoaded || this.schemesLoading) {
				return
			}
			this.schemesLoading = true
			try {
				this.schemes = await listConceptSchemes()
				this.schemesError = ''
				this.schemesLoaded = true
			} catch {
				this.schemesError = this.t('buildiq', 'The shared lists could not be loaded.')
			} finally {
				this.schemesLoading = false
			}
		},

		/**
		 * Load the options of a shared list, read-only, so the maker sees what the field offers.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @param {object} option The scheme option.
		 * @return {Promise<void>}
		 */
		async loadSchemeOptions(option) {
			if (!option || !option.uri || this.schemeOptions[option.value]) {
				return
			}
			try {
				const options = await listSchemeOptions(option.uri)
				this.schemeOptions = { ...this.schemeOptions, [option.value]: options }
			} catch {
				this.schemeOptions = { ...this.schemeOptions, [option.value]: [] }
			}
		},

		/**
		 * When a row already uses a shared list, load the lists and its options.
		 *
		 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
		 * @return {Promise<void>}
		 */
		async loadSharedLists() {
			const slugs = this.fields
				.filter((field) => this.isChoice(field) && field.validation && field.validation.conceptScheme)
				.map((field) => field.validation.conceptScheme)
			if (slugs.length === 0) {
				return
			}
			await this.loadSchemes()
			for (const slug of slugs) {
				await this.loadSchemeOptions(this.schemeOption(slug))
			}
		},

		/**
		 * Change a row's validation through a mutator and emit the next fields.
		 *
		 * @param {number} index Row index.
		 * @param {Function} mutate Changes the copied validation in place.
		 * @return {void}
		 */
		patchValidation(index, mutate) {
			const next = this.fields.slice()
			const current = { ...next[index] }
			const validation = { ...(current.validation || {}) }
			mutate(validation)
			current.validation = validation
			next[index] = current
			this.emitFields(next)
		},

		/**
		 * Resolve the selected target-schema option.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {string} value Schema slug.
		 * @return {object|null} Matching option.
		 */
		schemaOption(value) {
			return this.schemaOptions.find((o) => o.value === value) || null
		},

		/**
		 * Resolve the selected cardinality option.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {string} value Cardinality.
		 * @return {object} Matching option.
		 */
		cardinalityOption(value) {
			return (
				this.cardinalityOptions.find((o) => o.value === value)
				|| this.cardinalityOptions[0]
			)
		},

		/**
		 * Validate a field name: presence, pattern, and uniqueness.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {object} field Field row.
		 * @param {number} index Row index.
		 * @return {string} Error message, or empty when valid.
		 */
		nameError(field, index) {
			if (!field.name) {
				return this.t('buildiq', 'Name is required.')
			}
			if (!FIELD_NAME_PATTERN.test(field.name)) {
				return this.t(
					'buildiq',
					'Name must start with a letter and use letters, digits, underscores, or hyphens only.',
				)
			}
			const duplicate = this.fields.some(
				(other, otherIndex) =>
					otherIndex !== index && other.name === field.name,
			)
			if (duplicate) {
				return this.t('buildiq', 'Name must be unique within the schema.')
			}
			return ''
		},

		/**
		 * Coerce an input to an integer or null.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {Array} next Next fields array.
		 * @return {void}
		 */
		emitFields(next) {
			this.$emit('update:fields', next)
		},

		/**
		 * Append a new blank field row.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @return {void}
		 */
		addField() {
			const next = this.fields.slice()
			next.push({
				_key: nextKey(),
				name: '',
				type: 'text',
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
		 * @param {number} index Row index.
		 * @return {void}
		 */
		requestRemove(index) {
			this.pendingRemoveIndex = index
			this.pendingRemoveName =
				this.fields[index]?.name || this.t('buildiq', '(unnamed)')
			this.removeDialogOpen = true
		},

		/**
		 * Confirm removal of the pending field.
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-schema-designer-ui/tasks.md#task-2
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
	const required = schema && Array.isArray(schema.required) ? schema.required : []
	const order =
		schema && Array.isArray(schema['x-property-order'])
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
			fields.push(
				fieldFromProperty(name, properties[name], required.includes(name)),
			)
		}
	}
	return fields
}

/**
 * The validation keys each field type copies between a property and a row.
 * A `truthy` key is copied when its value is truthy, a `present` key whenever
 * it is not null or undefined.
 */
const STRING_KEYS = { truthy: ['format', 'pattern'], present: ['minLength', 'maxLength'] }
const TEXT_KEYS = { truthy: ['pattern'], present: ['minLength', 'maxLength'] }
const ARRAY_KEYS = { truthy: [], present: ['minItems', 'maxItems'] }
const VALIDATION_KEYS = {
	string: STRING_KEYS,
	text: TEXT_KEYS,
	longtext: TEXT_KEYS,
	choice: STRING_KEYS,
	number: { truthy: [], present: ['minimum', 'maximum', 'multipleOf'] },
	integer: { truthy: [], present: ['minimum', 'maximum', 'multipleOf'] },
	array: ARRAY_KEYS,
	choices: ARRAY_KEYS,
	files: ARRAY_KEYS,
	users: ARRAY_KEYS,
}

/** The relation keys a relation row reads from `x-openregister-relation`. */
const RELATION_KEYS = { truthy: ['target', 'cardinality', 'inverseOf'], present: [] }

/** No validation keys, for a field type that has none. */
const NO_KEYS = { truthy: [], present: [] }

/** The JSON Schema `type` each field type is saved as; any other type saves as a string. */
const PROPERTY_TYPES = {
	string: 'string',
	text: 'string',
	longtext: 'string',
	date: 'string',
	datetime: 'string',
	choice: 'string',
	user: 'string',
	number: 'number',
	integer: 'integer',
	boolean: 'boolean',
	file: 'file',
	array: 'array',
	choices: 'array',
	files: 'array',
	users: 'array',
	object: 'object',
	relation: 'string',
}

/** The `format` each formatted named type is stored with (design D1). */
const TYPE_FORMATS = { longtext: 'textarea', date: 'date', datetime: 'date-time' }

/** The keys a choice owns, on the property (choice) or its items (several choices). */
const CHOICE_KEYS = ['enum', 'x-enum-labels', 'conceptScheme']

/** The keys a user field owns, on the property (user) or its items (several users). */
const USER_KEYS = ['referenceType', 'format']

/** The item keys a list-like named type owns. */
const ITEM_KEYS = ['type', 'format', 'referenceType', 'enum', 'x-enum-labels', 'conceptScheme']

/**
 *
 * @param name
 * @param prop
 * @param isRequired
 */
function fieldFromProperty(name, prop, isRequired) {
	prop = prop || {}
	const type = fieldTypeOf(prop)
	return {
		_key: nextKey(),
		name,
		type,
		required: isRequired,
		default: prop.default != null ? prop.default : null,
		description: prop.description || '',
		validation: validationFromProperty(prop, type),
		// REQ-BQFT-004: the property as it was loaded, so a save writes back
		// every key this editor does not own (enum, title, x- keys, ...).
		_raw: JSON.parse(JSON.stringify(prop)),
	}
}

/**
 * The field type of a loaded property: `relation` when it carries an
 * `x-openregister-relation` block, else its JSON Schema type (string when absent).
 *
 * @param {object} prop The loaded property.
 * @return {string} The field type.
 */
function fieldTypeOf(prop) {
	if (prop['x-openregister-relation']) {
		return 'relation'
	}
	if (prop.type === 'array') {
		return listTypeOf(prop.items)
	}
	return singleTypeOf(prop)
}

/**
 * The named type of a single value (design D1): a file, a user, a choice, a
 * formatted text, plain text; else its JSON type.
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-the-designer-offers-named-field-types-req-bqft-001
 * @param {object} prop A property or an items schema.
 * @return {string} The named type, or the JSON type when none fits.
 */
function singleTypeOf(prop) {
	const type = prop.type || 'string'
	if (type === 'file') {
		return 'file'
	}
	if (type !== 'string') {
		return type
	}
	if (isUserProperty(prop)) {
		return 'user'
	}
	if (Array.isArray(prop.enum) || prop.conceptScheme) {
		return 'choice'
	}
	if (!prop.format) {
		return 'text'
	}
	const formatted = Object.keys(TYPE_FORMATS).find((named) => TYPE_FORMATS[named] === prop.format)
	return formatted || 'string'
}

/**
 * The named type of a list, from its items: several files, users or
 * choices; else a plain list.
 *
 * @param {object} items The items schema.
 * @return {string} The named type.
 */
function listTypeOf(items) {
	const itemType = items && typeof items === 'object' ? singleTypeOf(items) : 'string'
	const several = { file: 'files', user: 'users', choice: 'choices' }
	return several[itemType] || 'array'
}

/**
 * Whether a property holds a Nextcloud user id (design D3).
 *
 * @param {object} prop A property or an items schema.
 * @return {boolean} True for a user reference.
 */
function isUserProperty(prop) {
	return prop.referenceType === USER_REFERENCE || prop.format === 'user'
}

/**
 * The validation values of a row, read from a loaded property: an array's
 * item type, then the type's own keys (a relation reads them from its
 * `x-openregister-relation` block).
 *
 * @param {object} prop The loaded property.
 * @param {string} type The row's field type.
 * @return {object} The validation values.
 */
function validationFromProperty(prop, type) {
	const validation = {}
	if (type === 'array' && prop.items?.type) {
		validation.itemsType = prop.items.type
	}
	if (type === 'choice') {
		Object.assign(validation, choiceFromProperty(prop))
	}
	if (type === 'choices') {
		Object.assign(validation, choiceFromProperty(prop.items || {}))
	}
	if (type === 'relation') {
		copyKeys(validation, prop['x-openregister-relation'] || {}, RELATION_KEYS)
		return validation
	}
	copyKeys(validation, prop, keysFor(type))
	return validation
}

/**
 * The choice settings of a property: its shared list, or its inline options
 * as ordered value and label rows.
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
 * @param {object} prop A property or an items schema.
 * @return {object} `{ conceptScheme }` or `{ options }`.
 */
function choiceFromProperty(prop) {
	if (prop.conceptScheme) {
		return { conceptScheme: String(prop.conceptScheme) }
	}
	const labels = prop['x-enum-labels'] && typeof prop['x-enum-labels'] === 'object' ? prop['x-enum-labels'] : {}
	return {
		options: (Array.isArray(prop.enum) ? prop.enum : []).map((value) => ({
			value: String(value),
			label: labels[value] != null ? String(labels[value]) : '',
		})),
	}
}

/**
 * The validation keys a field type owns. A type without an own entry, also
 * one named like an Object prototype member (`constructor`), owns none.
 *
 * @param {string} type The field type.
 * @return {{truthy: Array<string>, present: Array<string>}} The keys.
 */
function keysFor(type) {
	return Object.hasOwn(VALIDATION_KEYS, type) ? VALIDATION_KEYS[type] : NO_KEYS
}

/**
 * Copy the keys a field type owns from one object onto another, in order:
 * the `truthy` keys first, then the `present` keys.
 *
 * @param {object} target The object to write.
 * @param {object} source The object to read.
 * @param {{truthy: Array<string>, present: Array<string>}} keys The keys.
 * @return {void}
 */
function copyKeys(target, source, keys) {
	for (const key of keys.truthy) {
		if (source[key]) target[key] = source[key]
	}
	for (const key of keys.present) {
		if (source[key] != null) target[key] = source[key]
	}
}

/**
 * The property keys this editor reads into a field row and writes back.
 * Every other key of a loaded property is carried through a save untouched.
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 */
const EDITOR_OWNED_KEYS = new Set([
	'type',
	'description',
	'default',
	'format',
	'pattern',
	'minLength',
	'maxLength',
	'minimum',
	'maximum',
	'multipleOf',
	'items',
	'minItems',
	'maxItems',
	'x-openregister-relation',
])

/**
 * The keys a save rewrites: the editor's own, plus the choice or user keys
 * when the row is, or was loaded as, a choice or user. So a changed type
 * clears the previous type's own slots, and an `enum` on, say, a number set
 * by an import still passes through untouched (REQ-BQFT-004).
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 * @param {string} type The row's type.
 * @param {string} loadedType The named type the property was loaded as.
 * @return {Set<string>} The keys.
 */
function ownedKeys(type, loadedType) {
	const keys = new Set(EDITOR_OWNED_KEYS)
	for (const named of [type, loadedType]) {
		if (named === 'choice') {
			CHOICE_KEYS.forEach((key) => keys.add(key))
		}
		if (named === 'user') {
			USER_KEYS.forEach((key) => keys.add(key))
		}
	}
	return keys
}

/**
 * Copy an object without the given keys.
 *
 * @param {object} source The object to copy (anything else yields `{}`).
 * @param {Array<string>|Set<string>} keys The keys to leave out.
 * @return {object} The copy.
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 */
function withoutKeys(source, keys) {
	const skip = keys instanceof Set ? keys : new Set(keys)
	const copy = {}
	if (!source || typeof source !== 'object' || Array.isArray(source)) {
		return copy
	}
	for (const key of Object.keys(source)) {
		if (!skip.has(key)) {
			copy[key] = source[key]
		}
	}
	return copy
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

/**
 * Build one JSON Schema property from an editor row. Starts from the property
 * as it was loaded (`_raw`) minus the keys this editor owns, so a save keeps
 * `enum`, `title`, `x-` keys and anything else set outside the designer, while
 * the editor's own keys (and a cleared one) still win (REQ-BQFT-004).
 *
 * @param {object} field Editor field row.
 * @return {object} The JSON Schema property.
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 */
function propertyFromField(field) {
	const raw = loadedProperty(field)
	const prop = baseProperty(field, raw)
	const v = field.validation || {}
	prop.type = propertyTypeOf(field.type)
	if (field.type === 'array') {
		// A list loaded as several choices, users or files drops their item slots.
		const itemSlots = raw.type === 'array' && listTypeOf(raw.items) !== 'array' ? ITEM_KEYS : ['type']
		prop.items = {
			...withoutKeys(raw.items, itemSlots),
			type: v.itemsType || 'string',
		}
	}
	if (field.type === 'relation') {
		prop['x-openregister-relation'] = relationFromField(raw, v)
	}
	copyKeys(prop, v, keysFor(field.type))
	writeNamedShape(prop, field.type, v, raw)
	return prop
}

/**
 * Write the shape a named type is stored as (design D1) onto a property.
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-the-designer-offers-named-field-types-req-bqft-001
 * @param {object} prop The property being saved.
 * @param {string} type The row's named type.
 * @param {object} v The row's validation values.
 * @param {object} raw The property as it was loaded.
 * @return {void}
 */
function writeNamedShape(prop, type, v, raw) {
	if (Object.hasOwn(TYPE_FORMATS, type)) {
		prop.format = TYPE_FORMATS[type]
	}
	if (type === 'choice') {
		writeChoice(prop, v)
	}
	if (type === 'user') {
		prop.referenceType = USER_REFERENCE
	}
	const several = { choices: 'string', users: 'string', files: 'file' }
	if (!Object.hasOwn(several, type)) {
		return
	}
	const items = { ...withoutKeys(raw.items, ITEM_KEYS), type: several[type] }
	if (type === 'choices') {
		writeChoice(items, v)
	}
	if (type === 'users') {
		items.referenceType = USER_REFERENCE
	}
	prop.items = items
}

/**
 * Write a choice's options: a shared list as `conceptScheme` and no `enum`,
 * or inline options as `enum` with `x-enum-labels` for the labelled ones.
 * Never both (design D2).
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
 * @param {object} target The property or items schema being saved.
 * @param {object} v The row's validation values.
 * @return {void}
 */
function writeChoice(target, v) {
	if (v.conceptScheme) {
		target.conceptScheme = v.conceptScheme
		return
	}
	const options = (v.options || []).filter((option) => option && String(option.value) !== '')
	target.enum = options.map((option) => String(option.value))
	const labelled = options.filter((option) => option.label)
	if (labelled.length > 0) {
		target['x-enum-labels'] = Object.fromEntries(labelled.map((option) => [String(option.value), option.label]))
	}
}

/**
 * The property a row was loaded from, or {} for a row made in the editor.
 *
 * @param {object} field Editor field row.
 * @return {object} The loaded property.
 */
function loadedProperty(field) {
	return field._raw && typeof field._raw === 'object' ? field._raw : {}
}

/**
 * The JSON Schema `type` a field type is saved as.
 *
 * @param {string} type The field type.
 * @return {string} The saved type.
 */
function propertyTypeOf(type) {
	return Object.hasOwn(PROPERTY_TYPES, type) ? PROPERTY_TYPES[type] : 'string'
}

/**
 * The loaded property minus the keys this editor owns, with the row's
 * description and default (when set) on top.
 *
 * @param {object} field Editor field row.
 * @param {object} raw The property as it was loaded.
 * @return {object} The start of the saved property.
 */
function baseProperty(field, raw) {
	const prop = withoutKeys(raw, ownedKeys(field.type, fieldTypeOf(raw)))
	if (field.description) {
		prop.description = field.description
	}
	if (field.default != null && field.default !== '') {
		prop.default = field.default
	}
	return prop
}

/**
 * The `x-openregister-relation` block of a relation row: the loaded block
 * minus the keys the row owns, then the row's target, cardinality and
 * (when set) inverse.
 *
 * @param {object} raw The property as it was loaded.
 * @param {object} v The row's validation values.
 * @return {object} The relation block.
 */
function relationFromField(raw, v) {
	return {
		...withoutKeys(raw['x-openregister-relation'], [
			'target',
			'cardinality',
			'inverseOf',
		]),
		target: v.target || '',
		cardinality: v.cardinality || 'one',
		...(v.inverseOf ? { inverseOf: v.inverseOf } : {}),
	}
}
</script>

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

.buildiq-field-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.buildiq-field-editor__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.buildiq-field-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.buildiq-field-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.buildiq-field-editor__rows {
	@include c.plain-list(8px);
}

.buildiq-field-editor__row {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.buildiq-field-editor__handle {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.buildiq-field-editor__row-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.buildiq-field-editor__validation {
	grid-column: 2;
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.buildiq-field-editor__choice {
	grid-column: 1 / -1;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.buildiq-field-editor__options,
.buildiq-field-editor__shared-options {
	margin: 0;
	padding-inline-start: 20px;
}

.buildiq-field-editor__option {
	display: grid;
	grid-template-columns: 1fr 1fr auto auto auto;
	gap: 8px;
	align-items: end;
}

.buildiq-field-editor__note {
	grid-column: 1 / -1;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.buildiq-field-editor__actions {
	grid-column: 2;
	display: flex;
	justify-content: flex-end;
}
</style>
