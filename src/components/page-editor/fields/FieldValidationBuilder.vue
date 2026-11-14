<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FieldValidationBuilder — authors a field's structured `validation`
  - object (`{ required?, min?, max?, pattern?, message? }`, the leaf's
  - `$defs/fieldValidation`, manifest-form-logic REQ-OBFEL-003). Controlled
  - component: `:model-value` is `field.validation`; `:legacy-required` /
  - `:legacy-pattern` are the field's legacy FLAT `required` / `pattern`
  - keys, used ONLY to prefill the section's display when no structured
  - `validation` object exists yet. Emits `update:modelValue` with the
  - merged object, or `null` when every rule is cleared (so the caller
  - drops the `validation` key entirely).
  -
  - Migrating the legacy flat keys off the field object (Decision 4 — "on
  - first write... the flat keys are removed from that field only") is the
  - HOST's (`FormFieldBuilder.vue`) responsibility, since it touches
  - sibling field keys this component does not own; this component only
  - ever writes its own `validation` subtree.
  -
  - `pattern` is compiled with `new RegExp()` on every keystroke: a
  - non-compiling pattern is marked invalid inline and is NEVER emitted
  - (the last known-good pattern, or none, stays written).
  -->
<template>
	<div class="field-validation-builder">
		<label class="field-validation-builder__inline">
			<input
				type="checkbox"
				:checked="requiredDisplay"
				@change="onRequiredChange($event.target.checked)" />
			{{ t('buildiq', 'Required') }}
		</label>
		<input
			type="number"
			class="field-validation-builder__field field-validation-builder__field--narrow"
			:placeholder="t('buildiq', 'Min')"
			:aria-label="t('buildiq', 'Minimum')"
			:value="minDisplay"
			@input="onMinInput($event.target.value)" />
		<input
			type="number"
			class="field-validation-builder__field field-validation-builder__field--narrow"
			:placeholder="t('buildiq', 'Max')"
			:aria-label="t('buildiq', 'Maximum')"
			:value="maxDisplay"
			@input="onMaxInput($event.target.value)" />
		<!-- Form-dutch-field-formats D1/D2: a Dutch format checks the field the
		     same way here and on the portal; with one set, no pattern. -->
		<label class="field-validation-builder__format">
			<span>{{ t('buildiq', 'Format') }}</span>
			<select
				class="field-validation-builder__field"
				data-testid="field-validation-format"
				:value="formatDisplay"
				:disabled="!!inheritedFormat"
				:aria-describedby="formatHint ? formatHintId : undefined"
				@change="onFormatChange($event.target.value)">
				<option value="">
					{{ t('buildiq', 'None') }}
				</option>
				<option
					v-for="option in formatOptions"
					:key="option.id"
					:value="option.id">
					{{ option.label }}
				</option>
			</select>
		</label>
		<span
			v-if="formatHint"
			:id="formatHintId"
			class="field-validation-builder__hint"
			data-testid="field-validation-format-hint">
			{{ formatHint }}
		</span>
		<input
			type="text"
			class="field-validation-builder__field"
			data-testid="field-validation-pattern"
			:placeholder="formatDisplay ? t('buildiq', 'The format already checks this field.') : t('buildiq', 'Pattern (regex)')"
			:aria-label="t('buildiq', 'Pattern')"
			:aria-invalid="patternError"
			:disabled="!!formatDisplay"
			:value="formatDisplay ? '' : patternDisplayValue"
			@input="onPatternInput($event.target.value)" />
		<input
			type="text"
			class="field-validation-builder__field"
			:placeholder="t('buildiq', 'Custom message (i18n key)')"
			:aria-label="t('buildiq', 'Message')"
			:value="messageDisplay"
			@input="onMessageInput($event.target.value)" />
		<span
			v-if="patternError"
			class="field-validation-builder__pattern-error"
			role="alert">
			{{ t('buildiq', 'This pattern is not a valid regular expression.') }}
		</span>
	</div>
</template>

<script>
import { dutchFormatOptions, isDutchFormat } from '../../../services/dutchFormats.js'

let hintCounter = 0

/**
 * Copy an object without its `undefined` entries, keeping key order.
 *
 * @param {object} source - Any plain object.
 * @return {object}
 */
function definedEntries(source) {
	return Object.fromEntries(
		Object.entries(source).filter(([, value]) => value !== undefined),
	)
}

/**
 * Read a min/max input as a number, or `undefined` when it is blank or not
 * numeric.
 *
 * @param {string|number|null|undefined} raw - The raw input value.
 * @return {number|undefined}
 */
function toBound(raw) {
	if (raw === '' || raw === null || raw === undefined) {
		return undefined
	}
	const value = Number(raw)
	return Number.isNaN(value) ? undefined : value
}

export default {
	name: 'FieldValidationBuilder',
	props: {
		modelValue: {
			type: Object,
			default: null,
		},

		legacyRequired: {
			type: Boolean,
			default: false,
		},

		legacyPattern: {
			type: String,
			default: '',
		},

		// The field's own Dutch `format` (form-dutch-field-formats D1), '' for none.
		format: {
			type: String,
			default: '',
		},

		// The Dutch format of the schema property the field is bound to (D2):
		// shown, and not changeable here.
		inheritedFormat: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue', 'update:format'],
	data() {
		hintCounter += 1
		return {
			formatHintId: `field-validation-format-hint-${hintCounter}`,
			// Live-typed pattern buffer — kept separate from `modelValue` so
			// an invalid, not-yet-committed pattern still displays (and marks
			// invalid) without ever being written (REQ-OBFEL-003).
			patternDraft: null,
		}
	},

	computed: {
		/**
		 * The Dutch formats, labelled.
		 *
		 * @return {Array<{id: string, label: string, hint: string}>}
		 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
		 */
		formatOptions() {
			return dutchFormatOptions()
		},

		/**
		 * The format in force: the schema's, else the field's own Dutch format.
		 *
		 * @return {string}
		 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
		 */
		formatDisplay() {
			if (isDutchFormat(this.inheritedFormat)) {
				return this.inheritedFormat
			}
			return isDutchFormat(this.format) ? this.format : ''
		},

		/**
		 * The line under the select: where an inherited format comes from, or the format's hint.
		 *
		 * @return {string}
		 */
		formatHint() {
			if (isDutchFormat(this.inheritedFormat)) {
				return this.t('buildiq', 'Comes from the schema')
			}
			const option = this.formatOptions.find((entry) => entry.id === this.formatDisplay)
			return option ? option.hint : ''
		},

		/**
		 * `required`, prefilled from the legacy flat key when no structured
		 * `validation` object exists yet.
		 *
		 * @return {boolean}
		 */
		requiredDisplay() {
			return this.modelValue
				? !!this.modelValue.required
				: !!this.legacyRequired
		},

		/** @return {number|string} */
		minDisplay() {
			return this.modelValue && this.modelValue.min !== undefined
				? this.modelValue.min
				: ''
		},

		/** @return {number|string} */
		maxDisplay() {
			return this.modelValue && this.modelValue.max !== undefined
				? this.modelValue.max
				: ''
		},

		/** @return {string} */
		messageDisplay() {
			return this.modelValue && this.modelValue.message !== undefined
				? this.modelValue.message
				: ''
		},

		/**
		 * `pattern`, prefilled from the legacy flat key when no structured
		 * `validation.pattern` exists yet, preferring the live typing draft.
		 *
		 * @return {string}
		 */
		patternDisplayValue() {
			if (this.patternDraft !== null) {
				return this.patternDraft
			}
			if (this.modelValue && this.modelValue.pattern !== undefined) {
				return this.modelValue.pattern
			}
			return this.legacyPattern || ''
		},

		/**
		 * The validation fields as currently shown in the inputs.
		 *
		 * @return {object}
		 */
		displayedValidation() {
			return {
				required: this.requiredDisplay,
				min: this.minDisplay,
				max: this.maxDisplay,
				pattern: this.patternDisplayValue,
				message: this.messageDisplay,
			}
		},

		/**
		 * Whether the currently displayed pattern fails to compile.
		 *
		 * @return {boolean}
		 */
		patternError() {
			const pattern = this.patternDisplayValue
			if (!pattern) {
				return false
			}
			try {
				new RegExp(pattern)
				return false
			} catch {
				return true
			}
		},
	},

	watch: {
		modelValue() {
			// Resync the pattern draft with the (possibly externally, e.g.
			// Raw-JSON-edited) prop once it changes.
			this.patternDraft = null
		},
	},

	methods: {
		/**
		 * Merge one overridden field into the current display values and
		 * build the next `validation` object, dropping default/empty
		 * entries (mirrors the house "empty drops the key" convention).
		 *
		 * @param {object} overrides - `{ required?, min?, max?, pattern?, message? }`.
		 * @return {object}
		 */
		buildValidation(overrides) {
			const values = {
				...this.displayedValidation,
				...definedEntries(overrides),
			}
			return definedEntries({
				required: values.required ? true : undefined,
				min: toBound(values.min),
				max: toBound(values.max),
				pattern: values.pattern || undefined,
				message: values.message || undefined,
			})
		},

		/**
		 * Emit the merged validation object (or `null` when every rule is
		 * cleared).
		 *
		 * @param {object} overrides - see `buildValidation`.
		 * @return {void}
		 */
		commit(overrides) {
			const next = this.buildValidation(overrides)
			this.$emit('update:modelValue', Object.keys(next).length ? next : null)
		},

		onRequiredChange(checked) {
			this.commit({ required: checked })
		},

		onMinInput(value) {
			this.commit({ min: value })
		},

		onMaxInput(value) {
			this.commit({ max: value })
		},

		onMessageInput(value) {
			this.commit({ message: value })
		},

		/**
		 * Live-compile the typed pattern; only commit (and thus write) it
		 * when it compiles. An invalid pattern stays visible (and marked)
		 * via `patternDraft` without ever reaching `modelValue`.
		 *
		 * @param {string} value - the typed pattern.
		 * @return {void}
		 */
		/**
		 * Set or clear the field's Dutch format. Setting one drops a pattern,
		 * because a field is checked by one or the other (REQ-BQDF-001).
		 *
		 * @param {string} value The format, or '' for none.
		 * @return {void}
		 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
		 */
		onFormatChange(value) {
			if (this.inheritedFormat) {
				return
			}
			const format = isDutchFormat(value) ? value : ''
			this.$emit('update:format', format)
			if (format !== '' && this.patternDisplayValue !== '') {
				this.patternDraft = null
				this.commit({ pattern: '' })
			}
		},

		onPatternInput(value) {
			if (this.formatDisplay) {
				return
			}
			this.patternDraft = value
			if (value !== '') {
				try {
					new RegExp(value)
				} catch {
					return
				}
			}
			this.commit({ pattern: value })
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.field-validation-builder {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.field-validation-builder__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.field-validation-builder__field {
	flex: 1 1 140px;
	@include c.control;
}

.field-validation-builder__field--narrow {
	flex: 0 0 80px;
}

.field-validation-builder__format {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	flex: 1 1 180px;
}

.field-validation-builder__hint {
	flex-basis: 100%;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.field-validation-builder__pattern-error {
	flex-basis: 100%;
	font-size: 11px;
	color: var(--color-error);
}
</style>
