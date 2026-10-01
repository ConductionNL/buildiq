<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - EligibilityCheckBuilder: a rule set a form checks while it is filled in
  - (REQ-BQLV-003). The person filling it in sees why they do not qualify,
  - and with "Wait for a pass" the submit button stays disabled until they
  - do. Stored as the form page's
  - `eligibility: {ruleSet, passWhen: {output, equals}, explainWith, blockSubmit}`.
  -
  - @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
  -->
<template>
	<div class="eligibility-check-builder">
		<label class="eligibility-check-builder__label">
			{{ t('buildiq', 'Check with rule set') }}
			<select
				data-test="elig-rule-set"
				:value="check.ruleSet || ''"
				@change="chooseRuleSet($event.target.value)">
				<option value="">
					{{ t('buildiq', 'No check') }}
				</option>
				<option v-for="set in ruleSets" :key="set.slug" :value="set.slug">
					{{ set.name }}
				</option>
			</select>
		</label>
		<template v-if="check.ruleSet">
			<div class="eligibility-check-builder__row">
				<label class="eligibility-check-builder__label">
					{{ t('buildiq', 'Passes when') }}
					<select
						data-test="elig-pass-output"
						:value="passWhen.output || ''"
						@change="
							emitPatch({
								passWhen: {
									...passWhen,
									output: $event.target.value,
								},
							})
						">
						<option
							v-for="output in outputs"
							:key="output.name"
							:value="output.name">
							{{ output.name }}
						</option>
					</select>
				</label>
				<label class="eligibility-check-builder__label">
					{{ t('buildiq', 'is') }}
					<input
						data-test="elig-pass-value"
						type="text"
						:value="passWhen.equals || ''"
						@input="
							emitPatch({
								passWhen: {
									...passWhen,
									equals: $event.target.value,
								},
							})
						" />
				</label>
			</div>
			<label class="eligibility-check-builder__label">
				{{ t('buildiq', 'Explain with') }}
				<select
					data-test="elig-explain"
					:value="check.explainWith || ''"
					@change="emitPatch({ explainWith: $event.target.value })">
					<option
						v-for="output in outputs"
						:key="output.name"
						:value="output.name">
						{{ output.name }}
					</option>
				</select>
			</label>
			<label class="eligibility-check-builder__inline">
				<input
					data-test="elig-block"
					type="checkbox"
					:checked="!!check.blockSubmit"
					@change="emitPatch({ blockSubmit: $event.target.checked })" />
				{{ t('buildiq', 'Wait for a pass before the form can be sent') }}
			</label>
		</template>
	</div>
</template>

<script>
import { listRuleSets, ruleSetColumns } from '../../../services/formLiveValues.js'

export default {
	name: 'EligibilityCheckBuilder',
	props: {
		modelValue: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return { ruleSets: [], outputs: [] }
	},

	computed: {
		/**
		 * The stored check, or an empty one.
		 *
		 * @return {object}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
		 */
		check() {
			return this.modelValue || {}
		},

		/**
		 * The passing condition.
		 *
		 * @return {object}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
		 */
		passWhen() {
			return this.check.passWhen || {}
		},
	},

	async mounted() {
		try {
			this.ruleSets = await listRuleSets()
		} catch {
			this.ruleSets = []
		}
		if (this.check.ruleSet) {
			await this.loadOutputs(this.check.ruleSet)
		}
	},

	methods: {
		/**
		 * Load the outputs of a rule set.
		 *
		 * @param {string} slug The rule set slug.
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
		 */
		async loadOutputs(slug) {
			try {
				this.outputs = (await ruleSetColumns(slug)).outputs
			} catch {
				this.outputs = []
			}
		},

		/**
		 * Choose the rule set, or remove the check.
		 *
		 * @param {string} slug The rule set slug, or '' for no check.
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
		 */
		async chooseRuleSet(slug) {
			if (slug === '') {
				this.outputs = []
				this.$emit('update:modelValue', null)
				return
			}
			await this.loadOutputs(slug)
			this.$emit('update:modelValue', {
				ruleSet: slug,
				passWhen: { output: '', equals: '' },
				explainWith: '',
				blockSubmit: false,
			})
		},

		/**
		 * Merge a change into the check.
		 *
		 * @param {object} patch The changed keys.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
		 */
		emitPatch(patch) {
			this.$emit('update:modelValue', { ...this.check, ...patch })
		},
	},
}
</script>

<style scoped>
.eligibility-check-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.eligibility-check-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.eligibility-check-builder__label {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.eligibility-check-builder__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}
</style>
