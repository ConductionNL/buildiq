<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - VisibleWhenBuilder — authors a field's `visibleWhen` in the LOCAL shape
  - of the shared `$defs/visibleWhen` predicate (`{ field, op?, value }`,
  - manifest-form-logic REQ-OBFEL-002). Controlled component: `:model-value`
  - is the field's current `visibleWhen` (or null/undefined), `:field-options`
  - is the sibling `config.fields[].key` list with the edited field already
  - excluded by the caller. Emits `update:modelValue` with the next
  - `visibleWhen` object, or `null` to delete the key.
  -
  - "No condition" is the default state. Op defaults to `eq` and is OMITTED
  - from the written object when left at the default. The value input
  - coerces `"true"` / `"false"` / numeric literals to boolean / number on
  - write so ordering ops (`gt`/`gte`/`lt`/`lte`) behave against numbers.
  -
  - The "fx" toggle (pages-custom-code REQ-BQCC-002) switches to an
  - expression: `{ expression }`, JavaScript over `values`, `user` and `route`
  - that the sandbox evaluates. Switching fx off again clears the condition.
  - Only an app owner may save it with "Allow code on pages" on; the server
  - refuses anyone else.
  -
  - A `visibleWhen` carrying `endpoint` or `source` (the advanced modes) is
  - rendered as a read-only summary and NEVER rewritten — Raw-JSON-authored
  - advanced conditions round-trip byte-for-byte through this component.
  -->
<template>
	<div class="visible-when-builder">
		<p v-if="isAdvanced" class="visible-when-builder__advanced">
			{{ t('buildiq', 'Advanced condition — edit in Raw JSON') }}
		</p>
		<template v-else-if="isExpression">
			<textarea
				class="visible-when-builder__expression"
				rows="2"
				spellcheck="false"
				:aria-label="t('buildiq', 'Visibility expression')"
				:placeholder="t('buildiq', 'For example: values.wantsContact === true')"
				:value="modelValue.expression"
				@input="onExpressionInput($event.target.value)" />
			<button
				type="button"
				class="visible-when-builder__fx visible-when-builder__fx--on"
				aria-pressed="true"
				:title="t('buildiq', 'Stop using an expression')"
				@click="clear">
				fx
			</button>
		</template>
		<template v-else>
			<select
				class="visible-when-builder__select"
				:aria-label="t('buildiq', 'Condition field')"
				:value="currentField"
				@change="onFieldChange($event.target.value)">
				<option value="">
					{{ t('buildiq', '— no condition —') }}
				</option>
				<option v-for="key in fieldOptions" :key="key" :value="key">
					{{ key }}
				</option>
			</select>
			<select
				v-if="currentField"
				class="visible-when-builder__select visible-when-builder__select--narrow"
				:aria-label="t('buildiq', 'Condition operator')"
				:value="currentOp"
				@change="onOpChange($event.target.value)">
				<option v-for="op in OPS" :key="op" :value="op">
					{{ op }}
				</option>
			</select>
			<input
				v-if="currentField"
				type="text"
				class="visible-when-builder__value"
				:placeholder="t('buildiq', 'Value')"
				:aria-label="t('buildiq', 'Condition value')"
				:value="currentValueDisplay"
				@input="onValueInput($event.target.value)" />
			<button
				v-if="currentField"
				type="button"
				class="visible-when-builder__clear"
				:title="t('buildiq', 'Clear condition')"
				@click="clear">
				{{ t('buildiq', 'Clear') }}
			</button>
			<button
				type="button"
				class="visible-when-builder__fx"
				aria-pressed="false"
				:title="t('buildiq', 'Write an expression instead')"
				@click="startExpression">
				fx
			</button>
		</template>
	</div>
</template>

<script>
/** The visibleWhen op allow-list (`$defs/visibleWhen.properties.op.enum`). */
const OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte']

/** The typed values that read as booleans. */
const BOOLEAN_LITERALS = new Map([
	['true', true],
	['false', false],
])

/**
 * Coerce a typed value string to boolean/number where it unambiguously
 * looks like one, else leave it as the literal string (Decision 3 /
 * REQ-OBFEL-002).
 *
 * @param {string} raw - the raw typed value.
 * @return {boolean|number|string}
 */
function coerceValue(raw) {
	if (BOOLEAN_LITERALS.has(raw)) {
		return BOOLEAN_LITERALS.get(raw)
	}
	return isNumeric(raw) ? Number(raw) : raw
}

/**
 * Whether a typed value is a number (not blank, and not NaN).
 *
 * @param {string} raw - the raw typed value.
 * @return {boolean}
 */
function isNumeric(raw) {
	return raw !== '' && raw.trim() !== '' && !Number.isNaN(Number(raw))
}

export default {
	name: 'VisibleWhenBuilder',
	props: {
		modelValue: {
			type: Object,
			default: null,
		},

		fieldOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],
	data() {
		return { OPS }
	},

	computed: {
		/**
		 * Whether the current `visibleWhen` uses an advanced (`endpoint` /
		 * `source`) mode this builder never rewrites.
		 *
		 * @return {boolean}
		 */
		isAdvanced() {
			return !!(
				this.modelValue
				&& (this.modelValue.endpoint || this.modelValue.source)
			)
		},

		/**
		 * Whether the current `visibleWhen` is an fx expression (REQ-BQCC-002).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t05
		 */
		isExpression() {
			return !!(
				!this.isAdvanced
				&& this.modelValue
				&& typeof this.modelValue.expression === 'string'
			)
		},

		/**
		 * The currently-picked sibling field key, or '' (no condition).
		 *
		 * @return {string}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t05
		 */
		currentField() {
			if (
				this.isAdvanced
				|| this.isExpression
				|| !this.modelValue
				|| typeof this.modelValue.field !== 'string'
			) {
				return ''
			}
			return this.modelValue.field
		},

		/**
		 * The current op, defaulting to `eq` when absent.
		 *
		 * @return {string}
		 */
		currentOp() {
			return this.modelValue && this.modelValue.op !== undefined
				? this.modelValue.op
				: 'eq'
		},

		/**
		 * The current value, stringified for the text input.
		 *
		 * @return {string}
		 */
		currentValueDisplay() {
			if (!this.modelValue || this.modelValue.value === undefined) {
				return ''
			}
			return String(this.modelValue.value)
		},
	},

	methods: {
		/**
		 * Assemble and emit the next `visibleWhen` from the three inputs,
		 * or emit `null` when the field is cleared. `op` is omitted from
		 * the written object when it is the default `eq`.
		 *
		 * @param {string} field - the picked sibling field key ('' clears).
		 * @param {string} op - the picked op.
		 * @param {string} rawValue - the raw typed value (coerced on write).
		 * @return {void}
		 */
		emitCondition(field, op, rawValue) {
			if (!field) {
				this.$emit('update:modelValue', null)
				return
			}
			const next = { field, value: coerceValue(rawValue) }
			if (op && op !== 'eq') {
				next.op = op
			}
			this.$emit('update:modelValue', next)
		},

		onFieldChange(value) {
			this.emitCondition(value, this.currentOp, this.currentValueDisplay)
		},

		onOpChange(value) {
			this.emitCondition(this.currentField, value, this.currentValueDisplay)
		},

		onValueInput(value) {
			this.emitCondition(this.currentField, this.currentOp, value)
		},

		/**
		 * Switch to an fx expression, starting empty (REQ-BQCC-002).
		 *
		 * @return {void}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t05
		 */
		startExpression() {
			this.$emit('update:modelValue', { expression: '' })
		},

		/**
		 * Write the typed expression as `visibleWhen.expression`.
		 *
		 * @param {string} value - the typed expression.
		 * @return {void}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t05
		 */
		onExpressionInput(value) {
			this.$emit('update:modelValue', { expression: value })
		},

		/**
		 * Clear the condition entirely.
		 *
		 * @return {void}
		 */
		clear() {
			this.$emit('update:modelValue', null)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.visible-when-builder {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.visible-when-builder__advanced {
	margin: 0;
	font-size: 12px;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.visible-when-builder__select,
.visible-when-builder__value {
	flex: 1 1 120px;
	@include c.control;
}

.visible-when-builder__select--narrow {
	flex: 0 0 90px;
}

.visible-when-builder__clear,
.visible-when-builder__fx {
	@include c.ghost-button;
}

.visible-when-builder__fx {
	font-style: italic;
	font-weight: bold;
}

.visible-when-builder__fx--on {
	color: var(--color-primary-element);
}

.visible-when-builder__expression {
	flex: 1 1 100%;
	font-family: var(--font-face-monospace, monospace);
	@include c.control;
}
</style>
