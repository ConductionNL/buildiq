<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FieldAssignBuilder — "Fill in from another answer": authors a field's
  - `assign` rules, the shape nextcloud-vue's form renderer reads
  - (`utils/formAssign.js`): a list of `{ when, value }`, where `when` is a
  - local `visibleWhen` condition (`{ field, op, value }`, the shared
  - operator set) and `value` is a literal, `@answer.<field>` or one of the
  - default tokens. The first rule whose condition holds sets the field.
  -
  - A rule never reads the field it sets: picking the field itself as the
  - condition or as the answer is refused with a message and not written.
  -
  - Controlled: `:model-value` is the field's `assign` (or null), emits
  - `update:modelValue` with the next array, or null when no rule is left.
  -
  - @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-field-is-filled-in-from-another-answer-req-bqfk-004
  -->
<template>
	<div class="field-assign-builder">
		<div
			v-for="(rule, index) in rules"
			:key="index"
			class="field-assign-builder__rule"
			data-testid="assign-rule">
			<span class="field-assign-builder__word">{{ t('buildiq', 'When') }}</span>
			<select
				class="field-assign-builder__control"
				:aria-label="t('buildiq', 'Condition field')"
				data-testid="assign-when-field"
				:value="whenOf(rule).field || ''"
				@change="setWhen(index, 'field', $event.target.value)">
				<option value="">
					{{ t('buildiq', 'Pick a field') }}
				</option>
				<option v-for="key in fieldOptions" :key="key" :value="key">
					{{ key }}
				</option>
			</select>
			<select
				class="field-assign-builder__control field-assign-builder__control--narrow"
				:aria-label="t('buildiq', 'Condition operator')"
				data-testid="assign-when-op"
				:value="whenOf(rule).op || 'eq'"
				@change="setWhen(index, 'op', $event.target.value)">
				<option v-for="op in OPS" :key="op" :value="op">
					{{ op }}
				</option>
			</select>
			<input
				type="text"
				class="field-assign-builder__control field-assign-builder__control--narrow"
				:aria-label="t('buildiq', 'Condition value')"
				data-testid="assign-when-value"
				:value="display(whenOf(rule).value)"
				@input="setWhen(index, 'value', coerce($event.target.value))" />
			<span class="field-assign-builder__word">{{ t('buildiq', 'set') }}</span>
			<select
				class="field-assign-builder__control field-assign-builder__control--narrow"
				:aria-label="t('buildiq', 'Kind of value')"
				data-testid="assign-value-kind"
				:value="kindOf(rule.value)"
				@change="setKind(index, $event.target.value)">
				<option value="literal">
					{{ t('buildiq', 'A value') }}
				</option>
				<option value="answer">
					{{ t('buildiq', 'Another answer') }}
				</option>
				<option value="token">
					{{ t('buildiq', 'A token') }}
				</option>
			</select>
			<select
				v-if="kindOf(rule.value) === 'answer'"
				class="field-assign-builder__control"
				:aria-label="t('buildiq', 'Answer to copy')"
				data-testid="assign-value-answer"
				:value="answerOf(rule.value)"
				@change="setAnswer(index, $event.target.value)">
				<option value="">
					{{ t('buildiq', 'Pick a field') }}
				</option>
				<option v-for="key in fieldOptions" :key="key" :value="key">
					{{ key }}
				</option>
			</select>
			<select
				v-else-if="kindOf(rule.value) === 'token'"
				class="field-assign-builder__control"
				:aria-label="t('buildiq', 'Token')"
				data-testid="assign-value-token"
				:value="tokenOf(rule.value)"
				@change="setValue(index, $event.target.value)">
				<option v-for="token in TOKENS" :key="token" :value="token">
					{{ token }}
				</option>
			</select>
			<input
				v-else
				type="text"
				class="field-assign-builder__control"
				:aria-label="t('buildiq', 'Value to set')"
				data-testid="assign-value-literal"
				:value="display(rule.value)"
				@input="setValue(index, coerce($event.target.value))" />
			<button
				type="button"
				class="field-assign-builder__remove"
				:title="t('buildiq', 'Remove rule')"
				:aria-label="t('buildiq', 'Remove rule')"
				@click="removeRule(index)">
				✕
			</button>
			<p
				v-if="refused[index]"
				class="field-assign-builder__refused"
				role="alert"
				data-testid="assign-refused">
				{{ refused[index] }}
			</p>
		</div>
		<button
			type="button"
			class="field-assign-builder__add"
			data-testid="assign-add"
			@click="addRule">
			+ {{ t('buildiq', 'Add rule') }}
		</button>
	</div>
</template>

<script>
import { DEFAULT_TOKENS } from '../../../services/formLiveValues.js'
import { VISIBLE_WHEN_OPS } from '../../../services/manifestValidation/formLogic.js'

const ANSWER_PREFIX = '@answer.'

/**
 * A typed value as boolean or number when it plainly is one, else the text
 * (the same coercion the visibleWhen builder applies).
 *
 * @param {string} raw - the typed value.
 * @return {boolean|number|string}
 */
function coerceValue(raw) {
	if (raw === 'true' || raw === 'false') {
		return raw === 'true'
	}
	return raw.trim() !== '' && !Number.isNaN(Number(raw)) ? Number(raw) : raw
}

/**
 * Whether a value is one of the sentinel tokens (`@object.<field>` included).
 *
 * @param {unknown} value - the rule value.
 * @return {boolean}
 */
function isToken(value) {
	return typeof value === 'string' && !value.startsWith(ANSWER_PREFIX)
		&& (DEFAULT_TOKENS.includes(value) || value.startsWith('@object.'))
}

export default {
	name: 'FieldAssignBuilder',

	props: {
		modelValue: {
			type: Array,
			default: null,
		},

		// The key of the field these rules set: never readable by its rules.
		fieldKey: {
			type: String,
			default: '',
		},

		// The form's field keys a rule may read.
		fieldOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			OPS: VISIBLE_WHEN_OPS,
			TOKENS: DEFAULT_TOKENS,
			// Refusal message per rule index, for a rule that would read itself.
			refused: {},
		}
	},

	computed: {
		/**
		 * The stored rules.
		 *
		 * @return {Array<object>}
		 */
		rules() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	methods: {
		/**
		 * The condition of a rule, or an empty one.
		 *
		 * @param {object} rule - the rule.
		 * @return {object}
		 */
		whenOf(rule) {
			return rule && rule.when && typeof rule.when === 'object' ? rule.when : {}
		},

		/**
		 * A stored value as input text.
		 *
		 * @param {unknown} value - the value.
		 * @return {string}
		 */
		display(value) {
			return value === undefined || value === null ? '' : String(value)
		},

		/**
		 * Coerce a typed value.
		 *
		 * @param {string} raw - the typed value.
		 * @return {boolean|number|string}
		 */
		coerce(raw) {
			return coerceValue(raw)
		},

		/**
		 * What kind of value a rule sets.
		 *
		 * @param {unknown} value - the rule value.
		 * @return {'literal'|'answer'|'token'}
		 */
		kindOf(value) {
			if (typeof value === 'string' && value.startsWith(ANSWER_PREFIX)) {
				return 'answer'
			}
			return isToken(value) ? 'token' : 'literal'
		},

		/**
		 * The field an `@answer.<field>` value copies.
		 *
		 * @param {unknown} value - the rule value.
		 * @return {string}
		 */
		answerOf(value) {
			return typeof value === 'string' && value.startsWith(ANSWER_PREFIX)
				? value.slice(ANSWER_PREFIX.length)
				: ''
		},

		/**
		 * The token picked for a token value (`@object.<x>` shows as `@object.`).
		 *
		 * @param {unknown} value - the rule value.
		 * @return {string}
		 */
		tokenOf(value) {
			return typeof value === 'string' && value.startsWith('@object.') ? '@object.' : this.display(value)
		},

		/**
		 * Whether a key is the field these rules set; records the refusal.
		 *
		 * @param {number} index - the rule index.
		 * @param {string} key - the field the rule would read.
		 * @return {boolean} true when refused.
		 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-field-is-filled-in-from-another-answer-req-bqfk-004
		 */
		refuseSelf(index, key) {
			if (this.fieldKey !== '' && key === this.fieldKey) {
				this.refused = { ...this.refused, [index]: t('buildiq', 'A field cannot fill itself. Pick another field.') }
				return true
			}
			const { [index]: _drop, ...rest } = this.refused
			this.refused = rest
			return false
		},

		/**
		 * Set one key of a rule's condition. Reading the field itself is refused.
		 *
		 * @param {number} index - the rule index.
		 * @param {'field'|'op'|'value'} key - the condition key.
		 * @param {unknown} value - the next value.
		 * @return {void}
		 */
		setWhen(index, key, value) {
			if (key === 'field' && this.refuseSelf(index, value)) {
				return
			}
			const rule = this.rules[index] || {}
			const when = { op: 'eq', ...this.whenOf(rule), [key]: value }
			if (key === 'field' && value === '') {
				delete when.field
			}
			this.emitRule(index, { ...rule, when })
		},

		/**
		 * Switch the kind of value a rule sets, starting from an empty one.
		 *
		 * @param {number} index - the rule index.
		 * @param {'literal'|'answer'|'token'} kind - the new kind.
		 * @return {void}
		 */
		setKind(index, kind) {
			const start = { literal: '', answer: ANSWER_PREFIX, token: DEFAULT_TOKENS[0] }
			this.setValue(index, start[kind] ?? '')
		},

		/**
		 * Copy another answer. Copying the field itself is refused.
		 *
		 * @param {number} index - the rule index.
		 * @param {string} key - the field to copy.
		 * @return {void}
		 */
		setAnswer(index, key) {
			if (this.refuseSelf(index, key)) {
				return
			}
			this.setValue(index, ANSWER_PREFIX + key)
		},

		/**
		 * Set the value a rule writes.
		 *
		 * @param {number} index - the rule index.
		 * @param {unknown} value - the value.
		 * @return {void}
		 */
		setValue(index, value) {
			const rule = this.rules[index] || {}
			this.emitRule(index, { ...rule, value })
		},

		/**
		 * Emit the rules with one replaced.
		 *
		 * @param {number} index - the rule index.
		 * @param {object} rule - the next rule.
		 * @return {void}
		 */
		emitRule(index, rule) {
			const next = this.rules.slice()
			next[index] = rule
			this.$emit('update:modelValue', next)
		},

		/**
		 * Add an empty rule.
		 *
		 * @return {void}
		 */
		addRule() {
			this.$emit('update:modelValue', [...this.rules, { when: { op: 'eq' }, value: '' }])
		},

		/**
		 * Remove a rule; null when none is left so the key is dropped.
		 *
		 * @param {number} index - the rule index.
		 * @return {void}
		 */
		removeRule(index) {
			const next = this.rules.filter((_, i) => i !== index)
			this.refused = {}
			this.$emit('update:modelValue', next.length ? next : null)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.field-assign-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.field-assign-builder__rule {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.field-assign-builder__word {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.field-assign-builder__control {
	flex: 1 1 120px;
	@include c.control;
}

.field-assign-builder__control--narrow {
	flex: 0 0 90px;
}

.field-assign-builder__remove {
	@include c.danger-button;
}

.field-assign-builder__add {
	align-self: flex-start;
	@include c.add-button;
}

.field-assign-builder__refused {
	flex-basis: 100%;
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}
</style>
