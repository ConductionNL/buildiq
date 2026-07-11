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
  - A `visibleWhen` carrying `endpoint` or `source` (the advanced modes) is
  - rendered as a read-only summary and NEVER rewritten — Raw-JSON-authored
  - advanced conditions round-trip byte-for-byte through this component.
  -->
<template>
	<div class="visible-when-builder">
		<p v-if="isAdvanced" class="visible-when-builder__advanced">
			{{ t('openbuild', 'Advanced condition — edit in Raw JSON') }}
		</p>
		<template v-else>
			<select
				class="visible-when-builder__select"
				:aria-label="t('openbuild', 'Condition field')"
				:value="currentField"
				@change="onFieldChange($event.target.value)">
				<option value="">
					{{ t('openbuild', '— no condition —') }}
				</option>
				<option v-for="key in fieldOptions" :key="key" :value="key">
					{{ key }}
				</option>
			</select>
			<select
				v-if="currentField"
				class="visible-when-builder__select visible-when-builder__select--narrow"
				:aria-label="t('openbuild', 'Condition operator')"
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
				:placeholder="t('openbuild', 'Value')"
				:aria-label="t('openbuild', 'Condition value')"
				:value="currentValueDisplay"
				@input="onValueInput($event.target.value)">
			<button
				v-if="currentField"
				type="button"
				class="visible-when-builder__clear"
				:title="t('openbuild', 'Clear condition')"
				@click="clear">
				{{ t('openbuild', 'Clear') }}
			</button>
		</template>
	</div>
</template>

<script>
/** The visibleWhen op allow-list (`$defs/visibleWhen.properties.op.enum`). */
const OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte']

/**
 * Coerce a typed value string to boolean/number where it unambiguously
 * looks like one, else leave it as the literal string (Decision 3 /
 * REQ-OBFEL-002).
 *
 * @param {string} raw - the raw typed value.
 * @return {boolean|number|string}
 */
function coerceValue(raw) {
	if (raw === 'true') {
		return true
	}
	if (raw === 'false') {
		return false
	}
	if (raw !== '' && raw.trim() !== '' && !Number.isNaN(Number(raw))) {
		return Number(raw)
	}
	return raw
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
			return !!(this.modelValue && (this.modelValue.endpoint || this.modelValue.source))
		},
		/**
		 * The currently-picked sibling field key, or '' (no condition).
		 *
		 * @return {string}
		 */
		currentField() {
			if (this.isAdvanced || !this.modelValue || typeof this.modelValue.field !== 'string') {
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
			return (this.modelValue && this.modelValue.op !== undefined) ? this.modelValue.op : 'eq'
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

<style scoped>
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
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.visible-when-builder__select--narrow {
	flex: 0 0 90px;
}

.visible-when-builder__clear {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
