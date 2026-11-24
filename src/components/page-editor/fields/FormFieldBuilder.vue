<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FormFieldBuilder — authors the `formField` $def with validation rules.
  - Used by FormPageEditor AND SettingsPageEditor's flat-field section bodies.
  - REQ-OBPD-006.
  -
  - `show-logic` (default false, opt-in) gates the manifest-form-logic
  - additions (REQ-OBFEL-002/003/004): a per-row expandable details area
  - with a Conditions (`VisibleWhenBuilder`) and Validation
  - (`FieldValidationBuilder`) section, replacing the collapsed row's flat
  - `required` checkbox / `pattern` input with a compact summary. Only
  - `FormPageEditor` passes `show-logic`; `SettingsSectionBuilder`'s
  - settings-page fields keep the original flat inline inputs unchanged —
  - visibleWhen/validation authoring is form-page-only by design (design.md
  - Non-Goals).
  -->
<template>
	<div class="form-field-builder">
		<!-- REQ-BQFK-005: the palette; drag a type onto the form, or Enter adds it at the end. -->
		<div
			v-if="showLogic"
			class="form-field-builder__palette"
			role="group"
			:aria-label="t('buildiq', 'Field types')">
			<button
				v-for="type in FIELD_TYPES"
				:key="'palette-' + type"
				type="button"
				draggable="true"
				class="form-field-builder__palette-item"
				:data-testid="'palette-' + type"
				:title="t('buildiq', 'Drag onto the form, or press Enter to add at the end')"
				@dragstart="startDrag({ kind: 'type', type }, $event)"
				@dragend="endDrag"
				@click="addOfType(type)">
				<span class="form-field-builder__palette-icon" aria-hidden="true">{{ typeIcon(type) }}</span>
				{{ typeLabel(type) }}
			</button>
		</div>
		<template
			v-for="(field, index) in localFields"
			:key="index">
		<div
			v-if="showLogic"
			class="form-field-builder__slot"
			:class="{ 'form-field-builder__slot--active': dragging !== null }"
			:data-testid="'drop-slot-' + index"
			@dragover.prevent
			@drop.prevent="dropAt(index)" />
		<div class="form-field-builder__row">
			<span
				v-if="showLogic"
				class="form-field-builder__handle"
				draggable="true"
				aria-hidden="true"
				:title="t('buildiq', 'Drag to reorder')"
				@dragstart="startDrag({ kind: 'row', index }, $event)"
				@dragend="endDrag">⠿</span>
			<input
				:value="field.key || ''"
				type="text"
				class="form-field-builder__field form-field-builder__key"
				:placeholder="t('buildiq', 'Key')"
				:aria-label="t('buildiq', 'Key')"
				@input="updateField(index, 'key', $event.target.value)" />
			<input
				:value="field.label || ''"
				type="text"
				class="form-field-builder__field"
				:placeholder="t('buildiq', 'Label')"
				:aria-label="t('buildiq', 'Label')"
				@input="updateField(index, 'label', $event.target.value)" />
			<select
				:value="field.type || 'string'"
				class="form-field-builder__field form-field-builder__field--narrow"
				@change="changeType(index, $event.target.value)">
				<option v-for="type in FIELD_TYPES" :key="type" :value="type">
					{{ type }}
				</option>
			</select>
			<span
				v-if="field.type === 'scan'"
				class="form-field-builder__formats"
				role="group"
				:aria-label="t('buildiq', 'Code formats')"
				data-testid="form-field-scan-formats">
				<label
					v-for="format in CODE_FORMAT_OPTIONS"
					:key="format.value"
					class="form-field-builder__inline">
					<input
						type="checkbox"
						:value="format.value"
						:checked="(field.formats || []).includes(format.value)"
						@change="toggleFormat(index, format.value, $event.target.checked)" />
					{{ format.value === 'qr' ? t('buildiq', 'QR code') : format.label }}
				</label>
			</span>

			<template v-if="!showLogic">
				<label class="form-field-builder__inline">
					<input
						type="checkbox"
						:checked="!!field.required"
						@change="
							updateField(index, 'required', $event.target.checked)
						" />
					{{ t('buildiq', 'Required') }}
				</label>
				<input
					:value="field.pattern || ''"
					type="text"
					class="form-field-builder__field form-field-builder__field--narrow"
					:placeholder="t('buildiq', 'Pattern')"
					:aria-label="t('buildiq', 'Pattern')"
					@input="updateField(index, 'pattern', $event.target.value)" />
			</template>
			<template v-else>
				<span class="form-field-builder__summary">{{
					summaryFor(field)
				}}</span>
				<button
					type="button"
					class="form-field-builder__disclosure"
					:aria-expanded="isExpanded(index)"
					@click="toggleExpanded(index)">
					{{ isExpanded(index) ? '▲' : '▼' }}
					{{ t('buildiq', 'Details') }}
				</button>
				<button
					type="button"
					class="form-field-builder__move"
					:disabled="index === 0"
					:aria-label="t('buildiq', 'Move field up')"
					:title="t('buildiq', 'Move field up')"
					@click="moveField(index, -1)">
					↑
				</button>
				<button
					type="button"
					class="form-field-builder__move"
					:disabled="index === localFields.length - 1"
					:aria-label="t('buildiq', 'Move field down')"
					:title="t('buildiq', 'Move field down')"
					@click="moveField(index, 1)">
					↓
				</button>
			</template>

			<button
				type="button"
				class="form-field-builder__remove"
				:title="t('buildiq', 'Remove field')"
				@click="removeField(index)">
				✕
			</button>
			<!-- Decision 179: each destination finding on its own field. -->
			<InlineFieldMark
				v-for="(finding, findingIndex) in findings[field.key] || []"
				:key="'finding-' + findingIndex"
				class="form-field-builder__finding"
				:error="{ hasError: true, message: finding.message }" />

			<!-- REQ-BQSA-001: the options of a signature, audio or location field. -->
			<div
				v-if="isDeviceField(field)"
				class="form-field-builder__device"
				:data-testid="'device-options-' + index">
				<template v-if="field.type === 'signature'">
					<label class="form-field-builder__inline">
						<input
							type="checkbox"
							data-testid="signature-allow-drawn"
							:checked="field.allowDrawn !== false"
							@change="updateOption(index, 'allowDrawn', $event.target.checked)" />
						{{ t('buildiq', 'Allow drawing') }}
					</label>
					<label class="form-field-builder__inline">
						<input
							type="checkbox"
							data-testid="signature-allow-typed"
							:checked="field.allowTyped !== false"
							@change="updateOption(index, 'allowTyped', $event.target.checked)" />
						{{ t('buildiq', 'Allow typing a name') }}
					</label>
				</template>
				<template v-else-if="field.type === 'audio'">
					<label class="form-field-builder__inline">
						{{ t('buildiq', 'Maximum length (seconds)') }}
						<input
							type="number"
							data-testid="audio-max-seconds"
							class="form-field-builder__field form-field-builder__field--narrow"
							:min="AUDIO_MIN_SECONDS"
							:max="AUDIO_MAX_SECONDS"
							:value="audioSeconds(field)"
							@input="updateOption(index, 'maxSeconds', Number.parseInt($event.target.value, 10))" />
					</label>
					<span class="form-field-builder__hint" data-testid="audio-size">{{ audioSizeHint(field) }}</span>
				</template>
				<template v-else-if="field.type === 'location'">
					<input
						:value="field.latField || ''"
						type="text"
						data-testid="location-lat-field"
						class="form-field-builder__field form-field-builder__field--narrow"
						:placeholder="t('buildiq', 'Latitude property')"
						:aria-label="t('buildiq', 'Latitude property')"
						@input="updateOption(index, 'latField', $event.target.value.trim() || null)" />
					<input
						:value="field.lngField || ''"
						type="text"
						data-testid="location-lng-field"
						class="form-field-builder__field form-field-builder__field--narrow"
						:placeholder="t('buildiq', 'Longitude property')"
						:aria-label="t('buildiq', 'Longitude property')"
						@input="updateOption(index, 'lngField', $event.target.value.trim() || null)" />
					<input
						:value="field.accuracyField || ''"
						type="text"
						data-testid="location-accuracy-field"
						class="form-field-builder__field form-field-builder__field--narrow"
						:placeholder="t('buildiq', 'Accuracy property (optional)')"
						:aria-label="t('buildiq', 'Accuracy property (optional)')"
						@input="updateOption(index, 'accuracyField', $event.target.value.trim() || null)" />
					<label class="form-field-builder__inline">
						<input
							type="checkbox"
							data-testid="location-allow-map-pick"
							:checked="field.allowMapPick !== false"
							@change="updateOption(index, 'allowMapPick', $event.target.checked ? null : false)" />
						{{ t('buildiq', 'Let the user place a pin on a map instead') }}
					</label>
				</template>
				<p
					v-if="Object.keys(missingTargets(field)).length"
					class="form-field-builder__hint"
					data-testid="device-missing-properties">
					{{
						t('buildiq', '{schema} has no property for this yet: {properties}.', {
							schema: targetSchemaEntry.title || targetSchemaEntry.slug || parentSchema,
							properties: Object.keys(missingTargets(field)).join(', '),
						})
					}}
					<button
						type="button"
						data-testid="device-add-properties"
						@click="offerProperties(field)">
						{{
							t('buildiq', 'Add the property to {schema}', {
								schema: targetSchemaEntry.title || targetSchemaEntry.slug || parentSchema,
							})
						}}
					</button>
				</p>
				<InlineFieldMark
					v-for="(problem, problemIndex) in deviceProblemMarks(field)"
					:key="'device-' + problemIndex"
					:error="{ hasError: true, message: problem }" />
			</div>

			<div
				v-if="showLogic && isExpanded(index)"
				class="form-field-builder__details">
				<div
					v-if="field.type === 'file'"
					class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'File')
					}}</span>
					<FileFieldSettings
						:field="field"
						@update="replaceField(index, $event)" />
				</div>
				<div
					v-if="field.type === 'product-list'"
					class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'Product list')
					}}</span>
					<ProductListFieldSettings
						:field="field"
						@update="replaceField(index, $event)" />
				</div>
				<div
					v-if="field.type === 'child-records'"
					class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'Table of records')
					}}</span>
					<ChildRecordsFieldSettings
						:field="field"
						:schemas="schemas"
						:parentSchema="parentSchema"
						@update="replaceField(index, $event)" />
				</div>
				<div class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'Conditions')
					}}</span>
					<VisibleWhenBuilder
						:modelValue="field.visibleWhen || null"
						:fieldOptions="siblingKeys(index)"
						@update:modelValue="updateVisibleWhen(index, $event)" />
					<InlineFieldMark :error="danglingConditionMark(field)" />
				</div>
				<div class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'Validation')
					}}</span>
					<FieldValidationBuilder
						:modelValue="field.validation || null"
						:legacyRequired="!!field.required"
						:legacyPattern="field.pattern || ''"
						:format="field.format || ''"
						:inheritedFormat="inheritedFormat(field)"
						@update:modelValue="updateValidation(index, $event)"
						@update:format="updateField(index, 'format', $event)" />
				</div>
				<div class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'Filled in when the form opens')
					}}</span>
					<FieldDefaultBuilder
						:modelValue="
							field.default === undefined ? null : field.default
						"
						@update:modelValue="updateLive(index, 'default', $event)" />
					<div class="form-field-builder__fx-default">
						<button
							type="button"
							class="form-field-builder__fx"
							:class="{ 'form-field-builder__fx--on': hasExpressionDefault(field) }"
							:aria-pressed="hasExpressionDefault(field) ? 'true' : 'false'"
							:title="hasExpressionDefault(field) ? t('buildiq', 'Stop computing the value') : t('buildiq', 'Compute the value with an expression')"
							@click="toggleExpressionDefault(index, field)">
							fx
						</button>
						<textarea
							v-if="hasExpressionDefault(field)"
							class="form-field-builder__expression"
							rows="2"
							spellcheck="false"
							:aria-label="t('buildiq', 'Default value expression')"
							:placeholder="t('buildiq', 'For example: user.displayName')"
							:value="field.expressionDefault"
							@input="updateLive(index, 'expressionDefault', $event.target.value)" />
					</div>
				</div>
				<div class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'Calculated')
					}}</span>
					<FieldCalculationBuilder
						:modelValue="field.calculate || null"
						:fieldOptions="siblingKeys(index)"
						@update:modelValue="
							updateLive(index, 'calculate', $event)
						" />
				</div>
				<div class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('buildiq', 'Fill in from another answer')
					}}</span>
					<FieldAssignBuilder
						:modelValue="Array.isArray(field.assign) ? field.assign : null"
						:fieldKey="field.key || ''"
						:fieldOptions="declaredKeys"
						@update:modelValue="updateLive(index, 'assign', $event)" />
				</div>
			</div>
		</div>
		</template>
		<div
			v-if="showLogic"
			class="form-field-builder__slot"
			:class="{ 'form-field-builder__slot--active': dragging !== null }"
			:data-testid="'drop-slot-' + localFields.length"
			@dragover.prevent
			@drop.prevent="dropAt(localFields.length)" />
		<button type="button" class="form-field-builder__add" @click="addField">
			+ {{ t('buildiq', 'Add field') }}
		</button>
		<!-- REQ-BQCV-024: add a property of the bound schema as a field. -->
		<select
			v-if="schemaPropertyChoices.length"
			class="form-field-builder__add-property"
			data-testid="form-field-add-property"
			value=""
			:aria-label="t('buildiq', 'Add a field from {schema}', { schema: targetSchemaName })"
			@change="onAddProperty($event)">
			<option value="">
				+ {{ t('buildiq', 'Add a field from {schema}', { schema: targetSchemaName }) }}
			</option>
			<option
				v-for="choice in schemaPropertyChoices"
				:key="choice.key"
				:value="choice.key">
				{{ choice.label }}
			</option>
		</select>
	</div>
</template>

<script>
import ChildRecordsFieldSettings from './ChildRecordsFieldSettings.vue'
import FieldAssignBuilder from './FieldAssignBuilder.vue'
import FieldCalculationBuilder from './FieldCalculationBuilder.vue'
import FieldDefaultBuilder from './FieldDefaultBuilder.vue'
import FieldValidationBuilder from './FieldValidationBuilder.vue'
import FileFieldSettings from './FileFieldSettings.vue'
import InlineFieldMark from './InlineFieldMark.vue'
import ProductListFieldSettings from './ProductListFieldSettings.vue'
import VisibleWhenBuilder from './VisibleWhenBuilder.vue'
import {
	AUDIO_MAX_SECONDS,
	AUDIO_MIN_SECONDS,
	audioClipBytes,
	audioSecondsOf,
	DEVICE_FIELD_TYPES,
	deviceFieldProblems,
	inlineCapOf,
	longestAudioSeconds,
	missingDeviceTargets,
} from '../../../services/deviceFields.js'
import { isDutchFormat } from '../../../services/dutchFormats.js'
import {
	FIELD_TYPES as BASE_FIELD_TYPES,
	fieldTypeIcon,
	fieldTypeLabel,
	insertAt,
	moveToSlot,
	productListField,
} from './fieldTypes.js'

/**
 * The field types this builder offers: the shared ones and the device fields
 * (forms-signature-audio-and-location, REQ-BQSA-001).
 */
const FIELD_TYPES = [...BASE_FIELD_TYPES, ...DEVICE_FIELD_TYPES]

/**
 * The field type a schema property is filled in with: a number, a yes or no,
 * a date, or text.
 *
 * @param {?object} property - the JSON-schema property.
 * @return {string} the field type.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-picks-and-orders-the-fields-of-a-form-page-req-bqcv-024
 */
function fieldTypeForProperty(property) {
	const type = property && property.type
	if (type === 'integer' || type === 'number') {
		return 'number'
	}
	if (type === 'boolean') {
		return 'boolean'
	}
	if (property && (property.format === 'date' || property.format === 'date-time')) {
		return 'date'
	}
	return 'string'
}


/**
 * The maker-facing sentence of each device field problem (REQ-BQSA-002, REQ-BQSA-004).
 */
const DEVICE_PROBLEM_TEXT = {
	'device-field-on-public-form': () => t('buildiq', 'A public form cannot use the microphone or the position: public forms belong to the portal.'),
	'signature-without-mode': () => t('buildiq', 'Allow drawing, typing or both.'),
	'audio-length-out-of-range': () => t('buildiq', 'Choose a length from {min} to {max} seconds.', { min: AUDIO_MIN_SECONDS, max: AUDIO_MAX_SECONDS }),
	'location-without-fields': () => t('buildiq', 'Name the latitude and the longitude property.'),
	'location-same-fields': () => t('buildiq', 'The latitude and the longitude need two different properties.'),
}

/**
 * Where each old position lands after a list operation, given the new order
 * of old positions (null for an inserted item).
 *
 * @param {Array<number|null>} order - old index per new position.
 * @param {number[]} indices - old indices to remap.
 * @return {number[]}
 */
function remapIndices(order, indices) {
	return indices.map((i) => order.indexOf(i)).filter((i) => i !== -1)
}

/**
 * The code formats a `scan` field accepts (pages-qr-and-barcodes D2). Code 128
 * and EAN-13 are names, not words, so they are not translated.
 */
const CODE_FORMAT_OPTIONS = [
	{ value: 'qr', label: 'QR code' },
	{ value: 'code128', label: 'Code 128' },
	{ value: 'ean13', label: 'EAN-13' },
]

/**
 * Whether a `visibleWhen` condition points at a field of this form (not an
 * endpoint or a remote source), so its key can be checked for existence.
 *
 * @param {object|undefined} vw - the field's `visibleWhen`.
 * @return {boolean}
 */
function isLocalFieldCondition(vw) {
	return !!vw && !vw.endpoint && !vw.source && typeof vw.field === 'string'
}

/**
 * Whether a field is required, from its validation object when it has one
 * and from the legacy flat key otherwise.
 *
 * @param {object} field - the field entry.
 * @return {boolean}
 */
function hasRequiredMark(field) {
	const validation = field && field.validation
	return validation ? !!validation.required : !!(field && field.required)
}

/**
 * Whether a field carries a pattern: a validation pattern that is set at all,
 * or else a truthy legacy flat pattern.
 *
 * @param {object} field - the field entry.
 * @return {boolean}
 */
function hasPatternMark(field) {
	const validation = field && field.validation
	return validation && validation.pattern !== undefined
		? true
		: !!(field && field.pattern)
}

/**
 * The marks of a field's collapsed-row summary, in display order: when each
 * applies and its label.
 */
const SUMMARY_MARKS = [
	{ applies: hasRequiredMark, label: () => t('buildiq', 'required') },
	{ applies: hasPatternMark, label: () => t('buildiq', 'pattern') },
	{
		applies: (field) => !!(field && field.visibleWhen),
		label: () => t('buildiq', '1 condition'),
	},
	{
		applies: (field) => !!(field && field.default !== undefined),
		label: () => t('buildiq', 'prefilled'),
	},
	{
		applies: (field) => !!(field && field.calculate),
		label: () => t('buildiq', 'calculated'),
	},
	{
		applies: (field) => !!(field && Array.isArray(field.assign) && field.assign.length),
		label: () => t('buildiq', 'filled in from another answer'),
	},
]

export default {
	name: 'FormFieldBuilder',
	components: {
		ChildRecordsFieldSettings,
		ProductListFieldSettings,
		FieldAssignBuilder,
		FileFieldSettings,
		VisibleWhenBuilder,
		FieldValidationBuilder,
		InlineFieldMark,
		FieldDefaultBuilder,
		FieldCalculationBuilder,
	},

	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},

		// Opt-in (REQ-OBFEL-002/003/004): mount the Conditions/Validation
		// details area. Only FormPageEditor sets this; SettingsSectionBuilder
		// keeps the original flat required/pattern inputs unchanged.
		showLogic: {
			type: Boolean,
			default: false,
		},

		// Decision 179/189: destination findings per field key, from the
		// last save. Each is marked under its field.
		findings: {
			type: Object,
			default: () => ({}),
		},

		// The schemas of the form's register (with their properties), for
		// the child-records settings (REQ-BQFK-003).
		schemas: {
			type: Array,
			default: () => [],
		},

		// The slug or id of the schema the form saves into.
		parentSchema: {
			type: String,
			default: '',
		},

		// REQ-BQSA-004: the form's mode, so a device field a public form may
		// not use is marked while the maker edits. Empty outside form pages.
		formMode: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue', 'addProperties'],
	data() {
		return {
			FIELD_TYPES,
			CODE_FORMAT_OPTIONS,
			AUDIO_MIN_SECONDS,
			AUDIO_MAX_SECONDS,
			expandedIndices: [],
			// What is being dragged: { kind: 'type', type } or { kind: 'row', index }.
			dragging: null,
			// The row whose key gets focus once rendered (keyboard add).
			pendingFocus: null,
		}
	},

	computed: {
		/**
		 * The schema the form saves into, from the register's list, or null
		 * when it is not known (no schema picked, or the list not loaded).
		 *
		 * @return {?object}
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
		 */
		targetSchemaEntry() {
			if (!this.parentSchema) {
				return null
			}
			return (this.schemas || []).find(
				(entry) => entry
					&& (String(entry.slug) === this.parentSchema || String(entry.id) === this.parentSchema),
			) || null
		},

		/**
		 * Observed behaviour of `localFields` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		localFields() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/**
		 * Every declared `key` across the field list (used for the
		 * dangling-condition-reference check, REQ-OBFEL-004).
		 *
		 * @return {string[]}
		 */
		declaredKeys() {
			return this.localFields
				.map((f) => f && f.key)
				.filter((k) => typeof k === 'string' && k !== '')
		},

		/**
		 * The name of the schema the form saves into, for the add control.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-picks-and-orders-the-fields-of-a-form-page-req-bqcv-024
		 */
		targetSchemaName() {
			const entry = this.targetSchemaEntry
			return (entry && (entry.title || entry.slug)) || this.parentSchema
		},

		/**
		 * The properties of the bound schema that are not on the form yet, in
		 * the schema's order, each with the label the field gets.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-picks-and-orders-the-fields-of-a-form-page-req-bqcv-024
		 */
		schemaPropertyChoices() {
			const properties = (this.targetSchemaEntry && this.targetSchemaEntry.properties) || {}
			const used = new Set(this.declaredKeys)
			return Object.keys(properties)
				.filter((key) => !used.has(key))
				.map((key) => ({ key, label: (properties[key] && properties[key].title) || key }))
		},
	},

	watch: {
		/**
		 * A keyboard-added field gets focus when the parent renders it.
		 *
		 * @return {void}
		 */
		localFields() {
			this.$nextTick(() => this.applyPendingFocus())
		},
	},

	methods: {
		/**
		 * Change a field's type. Choosing `scan` accepts every code format
		 * unless formats were chosen before; any other type drops `formats`.
		 *
		 * @param {number} index - the field's row.
		 * @param {string} type - the new type.
		 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-forms-can-fill-a-field-from-a-scan-req-bqqr-002
		 */
		changeType(index, type) {
			const next = this.localFields.slice()
			const { formats, ...rest } = next[index] || {}
			next[index] = { ...rest, type }
			if (type === 'scan') {
				next[index].formats = Array.isArray(formats) && formats.length > 0
					? formats
					: CODE_FORMAT_OPTIONS.map((format) => format.value)
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Accept or drop one code format on a `scan` field, keeping the
		 * formats in their listed order.
		 *
		 * @param {number} index - the field's row.
		 * @param {string} format - `qr`, `code128` or `ean13`.
		 * @param {boolean} checked - whether the format is now accepted.
		 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-forms-can-fill-a-field-from-a-scan-req-bqqr-002
		 */
		toggleFormat(index, format, checked) {
			const next = this.localFields.slice()
			const current = next[index] || {}
			const chosen = new Set(Array.isArray(current.formats) ? current.formats : [])
			if (checked) {
				chosen.add(format)
			} else {
				chosen.delete(format)
			}
			next[index] = {
				...current,
				formats: CODE_FORMAT_OPTIONS.map((option) => option.value).filter((value) => chosen.has(value)),
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `updateField` (retrofit annotation).
		 *
		 * @param {number} index - position of the field in the `fields` array.
		 * @param {'key'|'label'|'type'|'required'|'pattern'} key - the formField property
		 *   the bound control edits (`required`/`pattern` only exist in the flat,
		 *   `show-logic`-off layout).
		 * @param {string|boolean} value - the control's new value: input text, the
		 *   selected `type`, or the `required` checkbox state. An empty string or
		 *   `false` DELETES the key, except for the identity keys `key`, `label` and
		 *   `type`, which are always written.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		/**
		 * The Dutch format of the schema property a field is bound to, or ''
		 * (form-dutch-field-formats D2): the form shows it and never drops it.
		 *
		 * @param {object} field The form field.
		 * @return {string}
		 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
		 */
		inheritedFormat(field) {
			const schema = (this.schemas || []).find((entry) => entry && this.parentSchema
				&& (entry.slug === this.parentSchema || String(entry.id) === String(this.parentSchema)))
			const property = schema && schema.properties && field && field.key ? schema.properties[field.key] : null
			const format = property && typeof property === 'object' ? property.format : ''
			return isDutchFormat(format) ? format : ''
		},

		updateField(index, key, value) {
			const next = this.localFields.slice()
			const current = next[index] || {}
			if (
				(value === '' || value === false)
				&& key !== 'key'
				&& key !== 'label'
				&& key !== 'type'
			) {
				const { [key]: _omit, ...rest } = current
				next[index] = rest
			} else {
				next[index] = { ...current, [key]: value }
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Write (or delete, on `null`) one field's `visibleWhen` — the
		 * `VisibleWhenBuilder` output (REQ-OBFEL-002). Unknown sibling keys
		 * on the field survive via the shallow spread.
		 *
		 * @param {number} index - the field index.
		 * @param {?object} value - the next `visibleWhen`, or `null` to clear.
		 * @return {void}
		 */
		updateVisibleWhen(index, value) {
			const next = this.localFields.slice()
			const current = { ...next[index] }
			if (value === null) {
				delete current.visibleWhen
			} else {
				current.visibleWhen = value
			}
			next[index] = current
			this.$emit('update:modelValue', next)
		},

		/**
		 * Write (or delete, on `null`) one field's structured `validation`
		 * object (REQ-OBFEL-003). Per Decision 4, writing `validation` also
		 * migrates away THIS field's legacy flat `required` / `pattern`
		 * keys — sibling fields that are never edited keep theirs.
		 *
		 * @param {number} index - the field index.
		 * @param {?object} value - the next `validation`, or `null` to clear.
		 * @return {void}
		 */
		updateValidation(index, value) {
			const next = this.localFields.slice()
			const current = { ...next[index] }
			if (value === null) {
				delete current.validation
			} else {
				current.validation = value
			}
			delete current.required
			delete current.pattern
			next[index] = current
			this.$emit('update:modelValue', next)
		},

		/**
		 * Whether a field's default is an fx expression (pages-custom-code REQ-BQCC-002).
		 *
		 * @param {object} field - the field entry.
		 * @return {boolean}
		 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
		 */
		hasExpressionDefault(field) {
			return !!field && typeof field.expressionDefault === 'string'
		},

		/**
		 * Switch a field's default to an fx expression (`expressionDefault`,
		 * JavaScript over `values`, `user` and `route`), or back. Its `default`
		 * is left as it is.
		 *
		 * @param {number} index - the field index.
		 * @param {object} field - the field entry.
		 * @return {void}
		 * @spec openspec/changes/pages-custom-code/specs/page-custom-code/spec.md#requirement-an-owner-writes-expressions-for-columns-and-form-fields-req-bqcc-002
		 */
		toggleExpressionDefault(index, field) {
			this.updateLive(index, 'expressionDefault', this.hasExpressionDefault(field) ? null : '')
		},

		/**
		 * Write (or delete, on `null`) one field's `default` or `calculate`
		 * (REQ-BQLV-001, REQ-BQLV-002). Unknown sibling keys survive.
		 *
		 * @param {number} index - the field index.
		 * @param {'default'|'calculate'|'expressionDefault'} key - the key to write.
		 * @param {*} value - the next value, or `null` to clear.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		updateLive(index, key, value) {
			const next = this.localFields.slice()
			const current = { ...next[index] }
			if (value === null || value === undefined) {
				delete current[key]
			} else {
				current[key] = value
			}
			next[index] = current
			this.$emit('update:modelValue', next)
		},

		/**
		 * Replace a whole field entry (the file and child-records settings
		 * emit the next entry).
		 *
		 * @param {number} index - the field index.
		 * @param {object} field - the next entry.
		 * @return {void}
		 */
		replaceField(index, field) {
			const next = this.localFields.slice()
			next[index] = field
			this.$emit('update:modelValue', next)
		},

		/**
		 * The palette name of a field type.
		 *
		 * @param {string} type - the type.
		 * @return {string}
		 */
		typeLabel(type) {
			return fieldTypeLabel(type)
		},

		/**
		 * The palette icon of a field type.
		 *
		 * @param {string} type - the type.
		 * @return {string}
		 */
		typeIcon(type) {
			return fieldTypeIcon(type)
		},

		/**
		 * A new, empty field of a type.
		 *
		 * @param {string} type - the type.
		 * @return {object}
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		newField(type) {
			// A product list starts as the sub-objects widget with its product column.
			return type === 'product-list' ? productListField({ key: '', label: '' }) : { key: '', label: '', type }
		},

		/**
		 * Add a field of a type at the end and focus its key (the keyboard
		 * path: pick the type in the palette, press Enter).
		 *
		 * @param {string} type - the type.
		 * @return {void}
		 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-builds-a-form-by-dragging-field-types-from-a-palette-req-bqfk-005
		 */
		addOfType(type) {
			const next = this.localFields.slice()
			next.push(this.newField(type))
			this.$emit('update:modelValue', next)
			this.focusKey(next.length - 1)
		},

		/**
		 * Focus a row's key input once it is rendered.
		 *
		 * @param {number} index - the row index.
		 * @return {void}
		 */
		focusKey(index) {
			this.pendingFocus = index
			this.$nextTick(() => this.applyPendingFocus())
		},

		/**
		 * Focus the row waiting for focus, once the parent has rendered it.
		 *
		 * @return {void}
		 */
		applyPendingFocus() {
			if (this.pendingFocus === null) {
				return
			}
			const inputs = this.$el.querySelectorAll('.form-field-builder__key')
			if (inputs[this.pendingFocus]) {
				inputs[this.pendingFocus].focus()
				this.pendingFocus = null
			}
		},

		/**
		 * Start dragging a palette type or a row.
		 *
		 * @param {object} payload - what is dragged.
		 * @param {DragEvent} event - the drag event.
		 * @return {void}
		 */
		startDrag(payload, event) {
			this.dragging = payload
			if (event && event.dataTransfer) {
				event.dataTransfer.effectAllowed = payload.kind === 'row' ? 'move' : 'copy'
				event.dataTransfer.setData('text/plain', JSON.stringify(payload))
			}
		},

		/**
		 * End a drag without a drop.
		 *
		 * @return {void}
		 */
		endDrag() {
			this.dragging = null
		},

		/**
		 * Drop at a slot (0 = before the first field, length = after the
		 * last): a palette type inserts a new field there, a row moves there.
		 *
		 * @param {number} slot - the drop slot.
		 * @return {void}
		 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-builds-a-form-by-dragging-field-types-from-a-palette-req-bqfk-005
		 */
		dropAt(slot) {
			const dragged = this.dragging
			this.dragging = null
			if (!dragged) {
				return
			}
			const positions = this.localFields.map((_, i) => i)
			if (dragged.kind === 'type') {
				this.expandedIndices = remapIndices(insertAt(positions, slot, null), this.expandedIndices)
				this.$emit('update:modelValue', insertAt(this.localFields, slot, this.newField(dragged.type)))
				return
			}
			this.reorder(dragged.index, slot)
		},

		/**
		 * Move a row one place up (-1) or down (+1).
		 *
		 * @param {number} index - the row.
		 * @param {-1|1} step - the direction.
		 * @return {void}
		 */
		moveField(index, step) {
			this.reorder(index, step < 0 ? index - 1 : index + 2)
		},

		/**
		 * Move a row to a slot, keeping open details pinned to their fields.
		 *
		 * @param {number} from - the row.
		 * @param {number} slot - the drop slot.
		 * @return {void}
		 */
		reorder(from, slot) {
			const positions = this.localFields.map((_, i) => i)
			const order = moveToSlot(positions, from, slot)
			if (order.every((old, i) => old === i)) {
				return
			}
			this.expandedIndices = remapIndices(order, this.expandedIndices)
			this.$emit('update:modelValue', moveToSlot(this.localFields, from, slot))
		},

		/**
		 * Observed behaviour of `addField` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addField() {
			const next = this.localFields.slice()
			next.push({ key: '', label: '', type: 'string' })
			this.$emit('update:modelValue', next)
		},

		/**
		 * Add the picked schema property as a field at the end of the form,
		 * with the property's title as its label and a type that fits it,
		 * and set the picker back to its prompt.
		 *
		 * @param {Event} event - the change event of the add control.
		 * @return {void}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-picks-and-orders-the-fields-of-a-form-page-req-bqcv-024
		 */
		onAddProperty(event) {
			const key = event.target.value
			event.target.value = ''
			const choice = this.schemaPropertyChoices.find((entry) => entry.key === key)
			if (!choice) {
				return
			}
			const property = this.targetSchemaEntry.properties[key]
			this.$emit('update:modelValue', [
				...this.localFields,
				{ key, label: choice.label, type: fieldTypeForProperty(property) },
			])
		},

		/**
		 * Write one device field option as given (a `false` is kept, unlike
		 * updateField), or delete it on `null` / `NaN`.
		 *
		 * @param {number} index - the field index.
		 * @param {string} key - the option key.
		 * @param {boolean|number|string|null} value - the value, or null to delete.
		 * @return {void}
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-forms-offer-signature-audio-and-location-fields-req-bqsa-001
		 */
		updateOption(index, key, value) {
			const next = this.localFields.slice()
			const current = { ...next[index] }
			if (value === null || Number.isNaN(value)) {
				delete current[key]
			} else {
				current[key] = value
			}
			next[index] = current
			this.$emit('update:modelValue', next)
		},

		/**
		 * Whether a field uses a device: signature, audio or location.
		 *
		 * @param {object} field - the field entry.
		 * @return {boolean}
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-forms-offer-signature-audio-and-location-fields-req-bqsa-001
		 */
		isDeviceField(field) {
			return !!field && DEVICE_FIELD_TYPES.includes(field.type)
		},

		/**
		 * The audio length shown in the builder, the default when unset.
		 *
		 * @param {object} field - the audio field.
		 * @return {number} the stored length, as stored.
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-forms-offer-signature-audio-and-location-fields-req-bqsa-001
		 */
		audioSeconds(field) {
			return audioSecondsOf(field)
		},

		/**
		 * The size a clip of the chosen length takes, or the longest length
		 * that fits when it passes the inline file cap (REQ-BQSA-002).
		 *
		 * @param {object} field - the audio field.
		 * @return {string}
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
		 */
		audioSizeHint(field) {
			const seconds = audioSecondsOf(field)
			if (!Number.isInteger(seconds)) {
				return ''
			}
			const longest = longestAudioSeconds(inlineCapOf(field))
			if (seconds > longest) {
				return t('buildiq', 'Too long to store with the record: at most {seconds} seconds fit.', { seconds: longest })
			}
			const kilobytes = Math.ceil(audioClipBytes(seconds) / 1024)
			return t('buildiq', 'A clip of {seconds} seconds takes about {size} KB.', { seconds, size: kilobytes })
		},

		/**
		 * The properties a device field writes that its target schema lacks.
		 * Empty while the schema is unknown: nothing is offered then.
		 *
		 * @param {object} field - the field entry.
		 * @return {{[name: string]: {type: string}}}
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
		 */
		missingTargets(field) {
			const schema = this.targetSchemaEntry
			return schema ? missingDeviceTargets(field, schema.properties || {}) : {}
		},

		/**
		 * Ask the page editor to add a device field's missing properties to
		 * the target schema. The builder holds no network; the editor saves.
		 *
		 * @param {object} field - the field entry.
		 * @return {void}
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
		 */
		offerProperties(field) {
			const additions = this.missingTargets(field)
			if (!this.targetSchemaEntry || Object.keys(additions).length === 0) {
				return
			}
			this.$emit('addProperties', { schema: this.targetSchemaEntry, additions })
		},

		/**
		 * The maker-facing problems of a device field's options, in this form's mode.
		 * The too-long length is told by audioSizeHint instead.
		 *
		 * @param {object} field - the field entry.
		 * @return {Array<string>}
		 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004
		 */
		deviceProblemMarks(field) {
			return deviceFieldProblems(field, this.formMode)
				.filter((code) => Object.hasOwn(DEVICE_PROBLEM_TEXT, code))
				.map((code) => DEVICE_PROBLEM_TEXT[code]())
		},

		/**
		 * Remove a field row, keeping the expanded-details state pinned to the
		 * SAME fields it was pinned to before.
		 *
		 * `expandedIndices` holds positions, not identities, so a bare splice
		 * silently re-points every open panel one field to the left: delete row
		 * 0 while row 1 is open and the panel stays open showing what is now row
		 * 1 — a different field. The author goes on editing conditions and
		 * validation believing they belong to the row they opened, and a live
		 * dangling-reference warning on the shifted field vanishes because its
		 * panel is no longer the expanded one (REQ-OBFEL-004 warns *inside* the
		 * details area). Remap instead: drop the removed index, shift the rest.
		 *
		 * @param {number} index - position of the field to drop from the `fields` array.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeField(index) {
			const next = this.localFields.slice()
			next.splice(index, 1)
			this.expandedIndices = this.expandedIndices
				.filter((i) => i !== index)
				.map((i) => (i > index ? i - 1 : i))
			this.$emit('update:modelValue', next)
		},

		/**
		 * Sibling `key` values available to the Conditions field picker —
		 * every declared key EXCEPT the field currently being edited
		 * (REQ-OBFEL-002).
		 *
		 * @param {number} index - the field index being edited.
		 * @return {string[]}
		 */
		siblingKeys(index) {
			return this.localFields
				.map((f, i) => (i === index ? null : f && f.key))
				.filter((k) => typeof k === 'string' && k !== '')
		},

		/**
		 * The `{ hasError, message }` bag for a field's dangling LOCAL
		 * `visibleWhen.field` reference (REQ-OBFEL-004) — never mutates,
		 * purely a live, read-only warning (Decision 5).
		 *
		 * @param {object} field - the field entry.
		 * @return {{hasError: boolean, message: string}}
		 *
		 * @spec openspec/specs/form-editor-logic/spec.md
		 */
		danglingConditionMark(field) {
			const vw = field && field.visibleWhen
			if (!isLocalFieldCondition(vw)) {
				return { hasError: false, message: '' }
			}
			if (this.declaredKeys.includes(vw.field)) {
				return { hasError: false, message: '' }
			}
			return {
				hasError: true,
				message: t('buildiq', "Condition references removed field '{key}'", {
					key: vw.field,
				}),
			}
		},

		/**
		 * A compact collapsed-row summary of a field's logic, e.g.
		 * "required · pattern · 1 condition" (task 4.3).
		 *
		 * @param {object} field - the field entry.
		 * @return {string}
		 *
		 * @spec openspec/specs/form-editor-logic/spec.md
		 */
		summaryFor(field) {
			return SUMMARY_MARKS.filter((mark) => mark.applies(field))
				.map((mark) => mark.label())
				.join(' · ')
		},

		/**
		 * Whether a field row's details area is expanded.
		 *
		 * @param {number} index - the field index.
		 * @return {boolean}
		 */
		isExpanded(index) {
			return this.expandedIndices.includes(index)
		},

		/**
		 * Toggle a field row's details-area disclosure.
		 *
		 * @param {number} index - the field index.
		 * @return {void}
		 */
		toggleExpanded(index) {
			const at = this.expandedIndices.indexOf(index)
			if (at === -1) {
				this.expandedIndices.push(index)
			} else {
				this.expandedIndices.splice(at, 1)
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.form-field-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.form-field-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
}

.form-field-builder__field {
	flex: 1 1 120px;
	@include c.control;
}

.form-field-builder__field--narrow {
	flex: 0 0 110px;
}

.form-field-builder__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.form-field-builder__device {
	flex-basis: 100%;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	padding-inline-start: 8px;
}

.form-field-builder__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.form-field-builder__summary {
	flex: 1 1 auto;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.form-field-builder__disclosure {
	@include c.ghost-button;
}

.form-field-builder__remove {
	@include c.danger-button;
}

.form-field-builder__move {
	@include c.ghost-button;
}

.form-field-builder__palette {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.form-field-builder__palette-item {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	cursor: grab;
	@include c.ghost-button;
}

.form-field-builder__palette-icon {
	min-width: 1.2em;
	text-align: center;
}

.form-field-builder__handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	user-select: none;
}

.form-field-builder__slot {
	height: 4px;
	border-radius: var(--border-radius);
}

.form-field-builder__slot--active {
	height: 12px;
	border: 1px dashed var(--color-primary-element);
}

.form-field-builder__add {
	align-self: flex-start;
	@include c.add-button;
}

.form-field-builder__add-property {
	align-self: flex-start;
	@include c.control;
}

.form-field-builder__details {
	flex-basis: 100%;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	margin-top: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.form-field-builder__section {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.form-field-builder__section-label {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}
.form-field-builder__fx-default {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: flex-start;
	margin-top: 6px;
}

.form-field-builder__fx {
	font-style: italic;
	font-weight: bold;
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	cursor: pointer;
}

.form-field-builder__fx--on {
	color: var(--color-primary-element);
}

.form-field-builder__expression {
	flex: 1 1 100%;
	font-family: var(--font-face-monospace, monospace);
}
</style>
