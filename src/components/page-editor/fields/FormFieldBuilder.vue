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
		<div
			v-for="(field, index) in localFields"
			:key="index"
			class="form-field-builder__row">
			<input
				:value="field.key || ''"
				type="text"
				class="form-field-builder__field"
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
				@change="updateField(index, 'type', $event.target.value)">
				<option v-for="t in FIELD_TYPES" :key="t" :value="t">
					{{ t }}
				</option>
			</select>

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
				<InlineFieldMark
					v-for="(problem, problemIndex) in deviceProblemMarks(field)"
					:key="'device-' + problemIndex"
					:error="{ hasError: true, message: problem }" />
			</div>

			<div
				v-if="showLogic && isExpanded(index)"
				class="form-field-builder__details">
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
						@update:modelValue="updateValidation(index, $event)" />
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
			</div>
		</div>
		<button type="button" class="form-field-builder__add" @click="addField">
			+ {{ t('buildiq', 'Add field') }}
		</button>
	</div>
</template>

<script>
import FieldCalculationBuilder from './FieldCalculationBuilder.vue'
import FieldDefaultBuilder from './FieldDefaultBuilder.vue'
import FieldValidationBuilder from './FieldValidationBuilder.vue'
import InlineFieldMark from './InlineFieldMark.vue'
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
} from '../../../services/deviceFields.js'

const FIELD_TYPES = ['string', 'number', 'boolean', 'select', 'textarea', 'date', ...DEVICE_FIELD_TYPES]

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
]

export default {
	name: 'FormFieldBuilder',
	components: {
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

		// REQ-BQSA-004: the form's mode, so a device field a public form may
		// not use is marked while the maker edits. Empty outside form pages.
		formMode: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],
	data() {
		return {
			FIELD_TYPES,
			AUDIO_MIN_SECONDS,
			AUDIO_MAX_SECONDS,
			expandedIndices: [],
		}
	},

	computed: {
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
	},

	methods: {
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
		 * Write (or delete, on `null`) one field's `default` or `calculate`
		 * (REQ-BQLV-001, REQ-BQLV-002). Unknown sibling keys survive.
		 *
		 * @param {number} index - the field index.
		 * @param {'default'|'calculate'} key - the key to write.
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

.form-field-builder__add {
	align-self: flex-start;
	@include c.add-button;
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
</style>
