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
			{{ t('openbuild', 'Required') }}
		</label>
		<input
			type="number"
			class="field-validation-builder__field field-validation-builder__field--narrow"
			:placeholder="t('openbuild', 'Min')"
			:aria-label="t('openbuild', 'Minimum')"
			:value="minDisplay"
			@input="onMinInput($event.target.value)" />
		<input
			type="number"
			class="field-validation-builder__field field-validation-builder__field--narrow"
			:placeholder="t('openbuild', 'Max')"
			:aria-label="t('openbuild', 'Maximum')"
			:value="maxDisplay"
			@input="onMaxInput($event.target.value)" />
		<input
			type="text"
			class="field-validation-builder__field"
			:placeholder="t('openbuild', 'Pattern (regex)')"
			:aria-label="t('openbuild', 'Pattern')"
			:aria-invalid="patternError"
			:value="patternDisplayValue"
			@input="onPatternInput($event.target.value)" />
		<input
			type="text"
			class="field-validation-builder__field"
			:placeholder="t('openbuild', 'Custom message (i18n key)')"
			:aria-label="t('openbuild', 'Message')"
			:value="messageDisplay"
			@input="onMessageInput($event.target.value)" />
		<span
			v-if="patternError"
			class="field-validation-builder__pattern-error"
			role="alert">
			{{ t('openbuild', 'This pattern is not a valid regular expression.') }}
		</span>
	</div>
</template>

<script>
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
	},

	emits: ['update:modelValue'],
	data() {
		return {
			// Live-typed pattern buffer — kept separate from `modelValue` so
			// an invalid, not-yet-committed pattern still displays (and marks
			// invalid) without ever being written (REQ-OBFEL-003).
			patternDraft: null,
		}
	},

	computed: {
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
			const required =
				overrides.required !== undefined
					? overrides.required
					: this.requiredDisplay
			const minRaw =
				overrides.min !== undefined ? overrides.min : this.minDisplay
			const maxRaw =
				overrides.max !== undefined ? overrides.max : this.maxDisplay
			const pattern =
				overrides.pattern !== undefined
					? overrides.pattern
					: this.patternDisplayValue
			const message =
				overrides.message !== undefined
					? overrides.message
					: this.messageDisplay
			const next = {}
			if (required) {
				next.required = true
			}
			if (
				minRaw !== ''
				&& minRaw !== null
				&& minRaw !== undefined
				&& !Number.isNaN(Number(minRaw))
			) {
				next.min = Number(minRaw)
			}
			if (
				maxRaw !== ''
				&& maxRaw !== null
				&& maxRaw !== undefined
				&& !Number.isNaN(Number(maxRaw))
			) {
				next.max = Number(maxRaw)
			}
			if (pattern) {
				next.pattern = pattern
			}
			if (message) {
				next.message = message
			}
			return next
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
		onPatternInput(value) {
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

<style scoped>
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
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.field-validation-builder__field--narrow {
	flex: 0 0 80px;
}

.field-validation-builder__pattern-error {
	flex-basis: 100%;
	font-size: 11px;
	color: var(--color-error);
}
</style>
