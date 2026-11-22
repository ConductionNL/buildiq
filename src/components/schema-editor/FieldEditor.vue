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
				:ref="`row-${field.name}`"
				class="buildiq-field-editor__row"
				:class="{ 'buildiq-field-editor__row--refused': !!sourceRefusals[field.name] }">
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

					<!-- REQ-BQCV-006: a facetable property is a filter on every list page of this schema. -->
					<NcCheckboxRadioSwitch
						:modelValue="field.facetable === true"
						type="switch"
						data-testid="schema-field-facetable"
						@update:modelValue="updateField(index, 'facetable', $event)">
						{{ t('buildiq', 'Users can filter on this field') }}
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
						<!-- Form-dutch-field-formats T03: the JSON Schema formats, then the
						     Dutch ones the form designer checks too; any other name stays
						     possible through "Other…". -->
						<label
							v-if="field.type === 'string'"
							class="buildiq-field-editor__format">
							<span>{{ t('buildiq', 'Format (optional)') }}</span>
							<select
								data-testid="schema-field-format"
								:value="formatChoice(field.validation.format)"
								@change="onFormatChoice(index, $event.target.value)">
								<option value="">
									{{ t('buildiq', 'None') }}
								</option>
								<optgroup :label="t('buildiq', 'JSON Schema formats')">
									<option
										v-for="name in jsonSchemaFormats"
										:key="name"
										:value="name">
										{{ name }}
									</option>
								</optgroup>
								<optgroup :label="t('buildiq', 'Dutch formats')">
									<option
										v-for="option in dutchFormatOptions"
										:key="option.id"
										:value="option.id">
										{{ option.label }}
									</option>
								</optgroup>
								<option value="__other">
									{{ t('buildiq', 'Other…') }}
								</option>
							</select>
						</label>
						<NcTextField
							v-if="field.type === 'string' && (otherFormatRows.includes(index) || formatChoice(field.validation.format) === '__other')"
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
						<GeneratedNumberSettings
							v-if="field.type !== 'longtext'"
							:modelValue="field.generated || null"
							:schemaSlug="schemaSlug"
							@update:modelValue="updateField(index, 'generated', $event)" />
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

				<!-- REQ-BQRF-001/002: the value source, beside the type (design D1). -->
				<fieldset
					v-if="takesSource(field)"
					class="buildiq-field-editor__source"
					:data-testid="`field-source-${field.name}`">
					<legend class="buildiq-field-editor__source-heading">
						{{ t('buildiq', 'Value source') }}
					</legend>
					<p class="buildiq-field-editor__source-lead">
						{{ t('buildiq', 'Only for type string or object') }}
					</p>

					<template v-if="propertySources.available">
						<div class="buildiq-field-editor__source-choice">
							<label>
								<input
									type="radio"
									:name="`source-${field._key}`"
									value="typed"
									:checked="!field.source"
									@change="setSourceKind(index, 'typed')">
								{{ t('buildiq', 'Typed in') }}
							</label>
							<label>
								<input
									type="radio"
									:name="`source-${field._key}`"
									value="registry"
									:checked="!!field.source"
									@change="setSourceKind(index, 'registry')">
								{{ t('buildiq', 'From a registry') }}
							</label>
						</div>

						<template v-if="field.source">
							<NcSelect
								:inputLabel="t('buildiq', 'Registry')"
								:modelValue="providerOption(field.source.provider)"
								:options="providerOptions"
								:clearable="false"
								label="label"
								trackBy="value"
								@update:modelValue="
									updateSource(
										index,
										'provider',
										$event ? $event.value : '',
									)
								" />
							<div class="buildiq-field-editor__source-choice">
								<label>
									<input
										type="radio"
										:name="`source-mode-${field._key}`"
										value="live"
										:checked="field.source.mode !== 'default'"
										@change="updateSource(index, 'mode', 'live')">
									{{ t('buildiq', 'Look it up every time') }}
								</label>
								<label>
									<input
										type="radio"
										:name="`source-mode-${field._key}`"
										value="default"
										:checked="field.source.mode === 'default'"
										@change="updateSource(index, 'mode', 'default')">
									{{ t('buildiq', 'Use it as a starting value') }}
								</label>
							</div>
						</template>
					</template>

					<template v-else-if="propertySources.loaded">
						<p class="buildiq-field-editor__source-note">
							{{ t('buildiq', 'Registry lookups need the integriq app.') }}
						</p>
					</template>

					<p
						v-if="sourceSummaryOf(field)"
						class="buildiq-field-editor__source-summary"
						:data-testid="`field-source-summary-${field.name}`">
						{{ t('buildiq', 'Format') }}: {{ sourceSummaryOf(field) }}
					</p>

					<p
						v-for="(message, mIndex) in sourceRefusals[field.name] || []"
						:key="`refusal-${mIndex}`"
						class="buildiq-field-editor__source-refusal"
						role="alert">
						{{ message }}
					</p>
				</fieldset>

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
import GeneratedNumberSettings from './GeneratedNumberSettings.vue'
import { relationPickerMixin } from '../../mixins/relationPicker.js'
import { listConceptSchemes, listSchemeOptions } from '../../services/conceptSchemes.js'
import { dutchFormatOptions, isDutchFormat } from '../../services/dutchFormats.js'
import {
	PROPERTY_SOURCE_KEY,
	PROPERTY_SOURCE_TYPES,
	propertySourceOf,
	sourceFromProperty,
	sourceSummary,
} from '../../services/propertySources.js'
import {
	formatProblem,
	GENERATED_KEY,
	generatedFromProperty,
	generatedKeyword,
} from './generatedNumber.js'

// The JSON Schema string formats the schema field editor lists before the Dutch ones.
const JSON_SCHEMA_FORMATS = ['email', 'uri', 'date', 'date-time', 'time', 'uuid', 'hostname', 'ipv4', 'ipv6']

// The row types that take an automatic number (REQ-OBSD-009): single-line text,
// which the named types call `text` and a formatted text row `string`.
const GENERATED_TYPES = ['string', 'text']

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
		GeneratedNumberSettings,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		PlusIcon,
	},

	mixins: [relationPickerMixin],

	props: {
		fields: { type: Array, default: () => [] },
		/** The schema's own slug, the default counter name of an automatic number. */
		schemaSlug: { type: String, default: '' },
		/**
		 * The registry providers integriq publishes, fetched once per designer
		 * session: `{ loaded, available, results }` (design D2).
		 */
		propertySources: {
			type: Object,
			default: () => ({ loaded: false, available: false, results: [] }),
		},

		/** Refusals of the last save per field name, for a declaration OpenRegister refused. */
		sourceRefusals: { type: Object, default: () => ({}) },
	},

	emits: ['update:fields'],
	data() {
		return {
			// Form-dutch-field-formats T03: the rows where "Other…" was chosen.
			otherFormatRows: [],
			jsonSchemaFormats: JSON_SCHEMA_FORMATS,
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
		 * The Dutch formats, labelled (form-dutch-field-formats T03).
		 *
		 * @return {Array<{id: string, label: string, hint: string}>}
		 *
		 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
		 */
		dutchFormatOptions() {
			return dutchFormatOptions()
		},

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
		 * The registry picker options: the provider label, with the identifier
		 * it keys on as the secondary line.
		 *
		 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
		 * @return {Array} Option objects.
		 */
		providerOptions() {
			return (this.propertySources.results || []).map((provider) => ({
				value: provider.id,
				label: provider.label || provider.id,
				subname: provider.identifier
					? this.t('buildiq', 'keyed on {identifier}', {
							identifier: provider.identifier,
						})
					: '',
			}))
		},
	},

	watch: {
		/**
		 * REQ-BQRF-003: bring the first field a refused save names into view.
		 *
		 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-the-fields-table-shows-the-registry-source-and-a-refusal-lands-on-its-field-req-bqrf-003
		 * @param {object} refusals Messages per field name.
		 * @return {void}
		 */
		sourceRefusals(refusals) {
			const first = Object.keys(refusals || {})[0]
			if (!first) {
				return
			}
			this.$nextTick(() => {
				const found = this.$refs[`row-${first}`]
				const row = Array.isArray(found) ? found[0] : found
				if (row && typeof row.scrollIntoView === 'function') {
					row.scrollIntoView({ block: 'center' })
				}
			})
		},
	},

	mounted() {
		this.loadSharedLists()
	},

	methods: {
		/**
		 * Whether a field offers the value source section: string and object only.
		 *
		 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
		 * @param {object} field Field row.
		 * @return {boolean} True for string and object.
		 */
		takesSource(field) {
			return takesSourceType(field.type)
		},

		/**
		 * The selected registry option, shown as its id when integriq does not publish it.
		 *
		 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
		 * @param {string} id Provider id.
		 * @return {object|null} Matching option.
		 */
		providerOption(id) {
			if (!id) {
				return null
			}
			return (
				this.providerOptions.find((o) => o.value === id) || {
					value: id,
					label: id,
					subname: '',
				}
			)
		},

		/**
		 * The source text a field row shows: "{label}, live" or "{label}, starting value".
		 *
		 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-the-fields-table-shows-the-registry-source-and-a-refusal-lands-on-its-field-req-bqrf-003
		 * @param {object} field Field row.
		 * @return {string} The text, empty without a source.
		 */
		sourceSummaryOf(field) {
			return sourceSummary(field.source, this.propertySources.results)
		},

		/**
		 * Choose "Typed in" (removes the declaration) or "From a registry"
		 * (starts one, live, with an empty config).
		 *
		 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
		 * @param {number} index Row index.
		 * @param {string} kind `typed` or `registry`.
		 * @return {void}
		 */
		setSourceKind(index, kind) {
			const current = this.fields[index] || {}
			if (kind === 'typed') {
				this.updateField(index, 'source', null)
				return
			}
			if (current.source) {
				return
			}
			this.updateField(index, 'source', { provider: '', mode: 'live', config: {} })
		},

		/**
		 * Set the provider or the mode of a field's source, keeping its config.
		 *
		 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
		 * @param {number} index Row index.
		 * @param {string} key `provider` or `mode`.
		 * @param {string} value The new value.
		 * @return {void}
		 */
		updateSource(index, key, value) {
			const current = this.fields[index] || {}
			const source = {
				provider: '',
				mode: 'live',
				config: {},
				...(current.source || {}),
				[key]: value,
			}
			this.updateField(index, 'source', source)
		},

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
			if (value === '' || value === null || value === undefined) {
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
			if (value === '' || value === null || value === undefined) {
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
				// A registry source belongs to string and object fields only.
				if (!takesSourceType(value)) {
					current.source = null
				}
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
		/**
		 * The option a stored format selects: '' for none, the format when it
		 * is listed, '__other' for any other name.
		 *
		 * @param {string|undefined} format The stored format.
		 * @return {string}
		 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
		 */
		formatChoice(format) {
			if (!format) {
				return ''
			}
			return JSON_SCHEMA_FORMATS.includes(format) || isDutchFormat(format) ? format : '__other'
		},

		/**
		 * Store the chosen format on the property, or open the free field for "Other…".
		 *
		 * @param {number} index Row index.
		 * @param {string} choice The chosen option.
		 * @return {void}
		 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
		 */
		onFormatChoice(index, choice) {
			this.otherFormatRows = this.otherFormatRows.filter((row) => row !== index)
			if (choice === '__other') {
				this.otherFormatRows = [...this.otherFormatRows, index]
				return
			}
			this.updateValidation(index, 'format', choice)
		},

		/**
		 * Set one validation rule on a field row, or drop it when the value
		 * is empty, null or undefined.
		 *
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-schema-designer/spec.md#requirement-a-field-carries-required-length-range-and-pattern-rules-req-bqcv-021
		 * @param {number} index The row index.
		 * @param {string} key The validation key.
		 * @param {string|number|null|undefined} value The new value.
		 * @return {void}
		 */
		updateValidation(index, key, value) {
			const next = this.fields.slice()
			const current = { ...next[index] }
			const validation = { ...(current.validation || {}) }
			if (value === '' || value === null || value === undefined) {
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
 * The editor row for one loaded property.
 *
 * @param {string} name The property name.
 * @param {object} prop The loaded property.
 * @param {boolean} isRequired Whether the schema lists it as required.
 * @return {object} The row.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-shows-records-in-a-table-with-sorting-filtering-and-paging-req-bqcv-006
 */
function fieldFromProperty(name, prop, isRequired) {
	prop = prop || {}
	const type = fieldTypeOf(prop)
	return {
		_key: nextKey(),
		name,
		type,
		required: isRequired,
		default: prop.default !== null && prop.default !== undefined ? prop.default : null,
		description: prop.description || '',
		validation: validationFromProperty(prop, type),
		// REQ-OBSD-009: the automatic-number setting, only on a field that has one.
		...generatedRow(prop),
		// REQ-BQCV-006: the filter switch, only on a field that is a filter.
		...facetableRow(prop),
		// REQ-BQRF-001: the registry source, or null for a typed-in field. A
		// declaration that is not an object is left undefined, so a save
		// carries it through for OpenRegister to judge instead of dropping it.
		source: loadedSource(prop),
		// REQ-BQFT-004: the property as it was loaded, so a save writes back
		// every key this editor does not own (enum, title, x- keys, ...).
		_raw: JSON.parse(JSON.stringify(prop)),
	}
}

/**
 * The automatic-number part of a row: `{ generated }` when the property
 * carries `x-openregister-generated`, else nothing, so other rows keep
 * their shape.
 *
 * @param {object} prop The loaded property.
 * @return {object} The part.
 * @spec openspec/changes/data-generated-number-field/tasks.md#t02
 */
function generatedRow(prop) {
	const generated = generatedFromProperty(prop)
	return generated ? { generated } : {}
}

/**
 * The filter part of a row: `{ facetable: true }` when the property is a
 * facet, else nothing, so other rows keep their shape.
 *
 * @param {object} prop The loaded property.
 * @return {object} The part.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-shows-records-in-a-table-with-sorting-filtering-and-paging-req-bqcv-006
 */
function facetableRow(prop) {
	return prop.facetable === true ? { facetable: true } : {}
}

/**
 * The registry source of a loaded property: its declaration, null when it has
 * none, undefined when it has one that is not an object.
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
 * @param {object} prop The loaded property.
 * @return {object|null|undefined} The source.
 */
function loadedSource(prop) {
	const source = sourceFromProperty(prop)
	if (source) {
		return source
	}
	return prop[PROPERTY_SOURCE_KEY] === undefined ? null : undefined
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
			label: labels[value] !== null && labels[value] !== undefined ? String(labels[value]) : '',
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
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 */
function copyKeys(target, source, keys) {
	for (const key of keys.truthy) {
		if (source[key]) target[key] = source[key]
	}
	for (const key of keys.present) {
		if (source[key] !== null && source[key] !== undefined) target[key] = source[key]
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
	// REQ-BQCV-006: written from the row's filter switch; off removes it.
	'facetable',
	GENERATED_KEY,
	// REQ-BQRF-001: written from the row's source; "Typed in" removes it.
	PROPERTY_SOURCE_KEY,
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
		if (named === 'relation') {
			// REQ-BQCV-003: a relation writes its own `$ref`; another type drops it.
			keys.add('$ref')
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
 * @param {string} schemaSlug The schema's slug, the counter name of an automatic number that names none.
 * @return {{ properties: object, required: Array<string>, order: Array<string> }}
 */
export function fieldsToSchema(fields, schemaSlug = '') {
	const properties = {}
	const required = []
	const order = []
	for (const field of fields) {
		if (!field.name) {
			continue
		}
		order.push(field.name)
		const prop = propertyFromField(field, schemaSlug)
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
 * @param {string} schemaSlug The schema's slug.
 * @return {object} The JSON Schema property.
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-schema-designer/spec.md#requirement-a-maker-links-one-object-type-to-another-req-bqcv-003
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-shows-records-in-a-table-with-sorting-filtering-and-paging-req-bqcv-006
 */
function propertyFromField(field, schemaSlug = '') {
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
		writeRelation(prop, raw, v, field.name)
	}
	if (field.facetable === true) {
		prop.facetable = true
	}
	const generated = generatedFromField(field, raw, schemaSlug)
	if (generated) {
		prop[GENERATED_KEY] = generated
	}
	copyKeys(prop, v, keysFor(field.type))
	writeSource(prop, field, raw)
	writeNamedShape(prop, field.type, v, raw)
	return prop
}
/**
 * Whether a field type takes a registry source: one saved as a JSON string or
 * object (text, a formatted text, a choice, ...), a relation excepted.
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
 * @param {string} type The field type.
 * @return {boolean} True when the type takes a source.
 */
function takesSourceType(type) {
	return type !== 'relation' && PROPERTY_SOURCE_TYPES.includes(propertyTypeOf(type))
}

/**
 * Write a row's registry source onto its property (REQ-BQRF-001/002): the
 * declaration for a string or object field with a provider, config carried
 * through; an unreadable loaded declaration the row never touched, as it was.
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-an-existing-declaration-survives-a-session-without-integriq-req-bqrf-002
 * @param {object} prop The property being saved.
 * @param {object} field Editor field row.
 * @param {object} raw The property as it was loaded.
 * @return {void}
 */
function writeSource(prop, field, raw) {
	if (!takesSourceType(field.type)) {
		return
	}
	if (field.source === undefined) {
		if (raw[PROPERTY_SOURCE_KEY] !== undefined) {
			prop[PROPERTY_SOURCE_KEY] = raw[PROPERTY_SOURCE_KEY]
		}
		return
	}
	const declaration = propertySourceOf(field.source)
	if (declaration && declaration.provider) {
		prop[PROPERTY_SOURCE_KEY] = declaration
	}
}

/**
 * The `x-openregister-generated` keyword a row writes (REQ-OBSD-009): the
 * row's setting when it is on and its format is one OpenRegister accepts;
 * the keyword as it was loaded when the new format would be refused, so a
 * refused format is never saved; nothing when the setting is off or the
 * field is not single-line text.
 *
 * @param {object} field Editor field row.
 * @param {object} raw The property as it was loaded.
 * @param {string} schemaSlug The schema's slug, the counter name when the row names none.
 * @return {object|null} The keyword.
 * @spec openspec/changes/data-generated-number-field/tasks.md#t02
 */
function generatedFromField(field, raw, schemaSlug) {
	if (!GENERATED_TYPES.includes(field.type)) {
		return null
	}
	const keyword = generatedKeyword(field.generated, schemaSlug)
	if (keyword === null) {
		return null
	}
	if (formatProblem(keyword.format, keyword.resetOn) !== null) {
		return raw[GENERATED_KEY] || null
	}
	return keyword
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
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-save-keeps-the-keys-the-designer-does-not-edit-req-bqft-004
 */
function baseProperty(field, raw) {
	const prop = withoutKeys(raw, ownedKeys(field.type, fieldTypeOf(raw)))
	if (field.description) {
		prop.description = field.description
	}
	if (field.default !== null && field.default !== undefined && field.default !== '') {
		prop.default = field.default
	}
	return prop
}

/**
 * Write a relation row onto its property: the relation block, and the
 * `$ref` OpenRegister needs to accept it, on the property for a one relation
 * and on its items for a many relation, which is stored as a list of
 * references (`type: array` with string items) instead of a single one.
 *
 * @param {object} prop The property being saved.
 * @param {object} raw The property as it was loaded.
 * @param {object} v The row's validation values.
 * @param {string} name The field name, the label when the relation has none.
 * @return {void}
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-schema-designer/spec.md#requirement-a-maker-links-one-object-type-to-another-req-bqcv-003
 */
function writeRelation(prop, raw, v, name) {
	const relation = relationFromField(raw, v, name)
	const ref = relation.target ? { $ref: relation.target } : {}
	prop['x-openregister-relation'] = relation
	if (relation.cardinality !== 'many') {
		Object.assign(prop, ref)
		return
	}
	prop.type = 'array'
	prop.items = { ...withoutKeys(raw.items, ['type', '$ref']), type: 'string', ...ref }
}

/**
 * The `x-openregister-relation` block of a relation row: the loaded block
 * minus the keys the row owns, then the row's target, cardinality and
 * (when set) inverse. A block with neither a `label` nor a `type` gets the
 * property's title, else the field name, as its label: OpenRegister refuses
 * a relation that names neither.
 *
 * @param {object} raw The property as it was loaded.
 * @param {object} v The row's validation values.
 * @param {string} name The field name.
 * @return {object} The relation block.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-schema-designer/spec.md#requirement-a-maker-links-one-object-type-to-another-req-bqcv-003
 */
function relationFromField(raw, v, name) {
	const kept = withoutKeys(raw['x-openregister-relation'], [
		'target',
		'cardinality',
		'inverseOf',
	])
	const named = kept.label || kept.type ? {} : { label: raw.title || name }
	return {
		...kept,
		...named,
		target: v.target || '',
		cardinality: v.cardinality || 'one',
		...(v.inverseOf ? { inverseOf: v.inverseOf } : {}),
	}
}
</script>

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

.buildiq-field-editor__format {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

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

.buildiq-field-editor__source {
	grid-column: 2;
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.buildiq-field-editor__source-heading {
	font-weight: 600;
}

.buildiq-field-editor__source-lead,
.buildiq-field-editor__source-note,
.buildiq-field-editor__source-summary {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.buildiq-field-editor__source-choice {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.buildiq-field-editor__source-refusal {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}

.buildiq-field-editor__row--refused {
	border-color: var(--color-error);
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
