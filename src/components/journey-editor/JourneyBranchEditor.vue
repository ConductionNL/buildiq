<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
  JourneyBranchEditor — the `branch: [{ when, goto }]` rules of one step
  (journey-designer, task 2). `when` is a `visibleWhen` predicate
  `{ field, op, value }`; the operators offered are the canonical schema's
  enum (BRANCH_OPERATORS) and the fields are the ones asked by this step, so
  the editor cannot author a rule the renderer does not know or one that reads
  a field not asked yet.

  @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
-->
<template>
	<div class="journey-branch">
		<p v-if="localRules.length === 0" class="journey-branch__empty">
			{{ t('buildiq', 'No branch rules: the journey goes on to the next step.') }}
		</p>
		<div
			v-for="(rule, index) in localRules"
			:key="index"
			class="journey-branch__row"
			:data-testid="`journey-branch-${index}`">
			<select
				:value="whenOf(rule).field || ''"
				:aria-label="t('buildiq', 'Field')"
				data-testid="journey-branch-field"
				@change="updateWhen(index, { field: $event.target.value })">
				<option value="">
					{{ t('buildiq', '— field —') }}
				</option>
				<option v-for="field in fields" :key="field" :value="field">
					{{ field }}
				</option>
			</select>
			<select
				:value="whenOf(rule).op || 'eq'"
				:aria-label="t('buildiq', 'Operator')"
				data-testid="journey-branch-op"
				@change="updateWhen(index, { op: $event.target.value })">
				<option v-for="op in operators" :key="op" :value="op">
					{{ op }}
				</option>
			</select>
			<input
				v-if="takesValue(whenOf(rule).op)"
				:value="valueText(whenOf(rule).value)"
				type="text"
				:aria-label="t('buildiq', 'Value')"
				:placeholder="t('buildiq', 'Value')"
				data-testid="journey-branch-value"
				@input="updateWhen(index, { value: $event.target.value })">
			<select
				:value="rule.goto || ''"
				:aria-label="t('buildiq', 'Go to step')"
				data-testid="journey-branch-goto"
				@change="updateRule(index, { goto: $event.target.value })">
				<option value="">
					{{ t('buildiq', '— step —') }}
				</option>
				<option v-for="step in targets" :key="step.id" :value="step.id">
					{{ step.title || step.id }}
				</option>
			</select>
			<button
				type="button"
				:aria-label="t('buildiq', 'Remove rule')"
				data-testid="journey-branch-remove"
				@click="remove(index)">
				×
			</button>
		</div>
		<button type="button" data-testid="journey-branch-add" @click="add">
			{{ t('buildiq', 'Add branch rule') }}
		</button>
	</div>
</template>

<script>
import { BRANCH_OPERATORS } from '../../services/journeyValidation.js'

/** Operators that test a field without comparing it to a value. */
const VALUELESS = ['empty', 'notEmpty']

export default {
	name: 'JourneyBranchEditor',

	props: {
		/** The step's branch rules. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The field keys asked by this step and every step before it. */
		fields: {
			type: Array,
			default: () => [],
		},

		/** The steps a rule may go to: `[{ id, title }]`. */
		targets: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The rules, or none.
		 *
		 * @return {Array<object>}
		 */
		localRules() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/**
		 * The canonical `visibleWhen` operators, and only those.
		 *
		 * @return {ReadonlyArray<string>}
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
		 */
		operators() {
			return BRANCH_OPERATORS
		},
	},

	methods: {
		/**
		 * A rule's predicate, or an empty one.
		 *
		 * @param {object} rule - the rule.
		 * @return {object}
		 */
		whenOf(rule) {
			return rule && rule.when && typeof rule.when === 'object' ? rule.when : {}
		},

		/**
		 * Whether an operator compares against a value.
		 *
		 * @param {string} op - the operator.
		 * @return {boolean}
		 */
		takesValue(op) {
			return !VALUELESS.includes(op)
		},

		/**
		 * A value as the input shows it.
		 *
		 * @param {*} value - the stored value.
		 * @return {string}
		 */
		valueText(value) {
			if (value === undefined || value === null) {
				return ''
			}
			return Array.isArray(value) ? value.join(', ') : String(value)
		},

		/**
		 * Emit the rules with one rule changed.
		 *
		 * @param {number} index - the rule's position.
		 * @param {object} patch - the changed keys.
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
		 */
		updateRule(index, patch) {
			const rules = [...this.localRules]
			rules[index] = { ...rules[index], ...patch }
			this.$emit('update:modelValue', rules)
		},

		/**
		 * Emit the rules with one rule's predicate changed. A predicate holds
		 * only `field`, `op` and, for a comparing operator, `value`: nothing
		 * of the designer's own.
		 *
		 * @param {number} index - the rule's position.
		 * @param {object} patch - the changed predicate keys.
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
		 */
		updateWhen(index, patch) {
			const merged = { ...this.whenOf(this.localRules[index]), ...patch }
			const when = { field: merged.field || '', op: merged.op || 'eq' }
			if (this.takesValue(when.op) && merged.value !== undefined) {
				when.value = merged.value
			}
			this.updateRule(index, { when })
		},

		/**
		 * Emit the rules without one.
		 *
		 * @param {number} index - the rule's position.
		 */
		remove(index) {
			this.$emit('update:modelValue', this.localRules.filter((_, i) => i !== index))
		},

		/**
		 * Emit the rules with an empty rule at the end.
		 *
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
		 */
		add() {
			this.$emit('update:modelValue', [...this.localRules, { when: { field: '', op: 'eq', value: '' }, goto: '' }])
		},
	},
}
</script>

<style scoped>
.journey-branch__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	margin-bottom: 8px;
}

.journey-branch__empty {
	color: var(--color-text-maxcontrast);
}
</style>
