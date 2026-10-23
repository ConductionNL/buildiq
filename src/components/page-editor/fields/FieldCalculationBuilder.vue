<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FieldCalculationBuilder: bind a form field to an output of a rule set
  - (REQ-BQLV-002). The field is shown read-only and recomputed when one of
  - the answers it reads changes. Stored as the field's
  - `calculate: {ruleSet, output, inputs[]}`; no formula language.
  -
  - @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
  -->
<template>
	<div class="field-calculation-builder">
		<label class="field-calculation-builder__label">
			{{ t('buildiq', 'Worked out by rule set') }}
			<select
				data-test="calc-rule-set"
				:value="binding.ruleSet || ''"
				@change="chooseRuleSet($event.target.value)">
				<option value="">
					{{ t('buildiq', 'Not calculated') }}
				</option>
				<option v-for="set in ruleSets" :key="set.slug" :value="set.slug">
					{{ set.name }}
				</option>
			</select>
		</label>
		<p v-if="inactive" class="field-calculation-builder__warn" role="alert">
			{{
				t(
					'buildiq',
					'This rule set is not active, so the field stays empty until it is.',
				)
			}}
		</p>
		<template v-if="binding.ruleSet">
			<label class="field-calculation-builder__label">
				{{ t('buildiq', 'Show the outcome') }}
				<select
					:value="binding.output || ''"
					@change="emitPatch({ output: $event.target.value })">
					<option
						v-for="output in outputs"
						:key="output.name"
						:value="output.name">
						{{ output.name }}
					</option>
				</select>
			</label>
			<fieldset class="field-calculation-builder__inputs">
				<legend>
					{{ t('buildiq', 'Work it out again when these answers change') }}
				</legend>
				<label
					v-for="key in fieldOptions"
					:key="key"
					class="field-calculation-builder__input">
					<input
						type="checkbox"
						:value="key"
						:checked="inputs.includes(key)"
						@change="toggleInput(key, $event.target.checked)" />
					{{ key }}
				</label>
			</fieldset>
		</template>
	</div>
</template>

<script>
import { listRuleSets, ruleSetColumns } from '../../../services/formLiveValues.js'

export default {
	name: 'FieldCalculationBuilder',
	props: {
		modelValue: {
			type: Object,
			default: null,
		},

		// The other fields of the form, which the calculation may read.
		fieldOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	data() {
		return { ruleSets: [], outputs: [] }
	},

	computed: {
		/**
		 * The stored binding, or an empty one.
		 *
		 * @return {object}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		binding() {
			return this.modelValue || {}
		},

		/**
		 * The answers the calculation reads.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		inputs() {
			return Array.isArray(this.binding.inputs) ? this.binding.inputs : []
		},

		/**
		 * Whether the bound rule set exists but is not active.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		inactive() {
			const set = this.ruleSets.find(
				(item) => item.slug === this.binding.ruleSet,
			)
			return !!set && set.status !== '' && set.status !== 'active'
		},
	},

	/**
	 * Load the rule sets, and the outputs of the bound one.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
	 */
	async mounted() {
		try {
			this.ruleSets = await listRuleSets()
		} catch {
			this.ruleSets = []
		}
		if (this.binding.ruleSet) {
			await this.loadOutputs(this.binding.ruleSet)
		}
	},

	methods: {
		/**
		 * Load the outputs of a rule set.
		 *
		 * @param {string} slug The rule set slug.
		 * @return {Promise<object>} The columns.
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		async loadOutputs(slug) {
			let columns = { inputs: [], outputs: [] }
			try {
				columns = await ruleSetColumns(slug)
			} catch {
				// An unreadable rule set offers no outputs; the field stays as stored.
			}
			this.outputs = columns.outputs
			return columns
		},

		/**
		 * Bind the field to a rule set, or unbind it.
		 *
		 * The first output is preselected, and the form fields named after the
		 * rule set's inputs are ticked as the answers it reads.
		 *
		 * @param {string} slug The rule set slug, or '' to unbind.
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		async chooseRuleSet(slug) {
			if (slug === '') {
				this.outputs = []
				this.$emit('update:modelValue', null)
				return
			}
			const columns = await this.loadOutputs(slug)
			const read = columns.inputs
				.map((input) => input.path)
				.filter((path) => this.fieldOptions.includes(path))
			this.$emit('update:modelValue', {
				ruleSet: slug,
				output: columns.outputs.length ? columns.outputs[0].name : '',
				inputs: read,
			})
		},

		/**
		 * Merge a change into the binding.
		 *
		 * @param {object} patch The changed keys.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		emitPatch(patch) {
			this.$emit('update:modelValue', {
				...this.binding,
				inputs: this.inputs,
				...patch,
			})
		},

		/**
		 * Tick or untick an answer the calculation reads.
		 *
		 * @param {string} key The form field key.
		 * @param {boolean} on Whether it is read.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		toggleInput(key, on) {
			const next = this.inputs.filter((item) => item !== key)
			if (on) {
				next.push(key)
			}
			this.emitPatch({ inputs: next })
		},
	},
}
</script>

<style scoped>
.field-calculation-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.field-calculation-builder__label {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.field-calculation-builder__inputs {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 4px 8px;
}

.field-calculation-builder__input {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.field-calculation-builder__warn {
	color: var(--color-warning-text, var(--color-main-text));
}
</style>
