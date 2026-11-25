<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - BuildiqFormPage: the form page of a running virtual app. It renders
  - nextcloud-vue's CnFormPage with every prop and listener passed through,
  - and hands it what buildiq works out while the form is filled in
  - (change forms-live-values-and-checks):
  -
  - - `calculate`: a field with `calculate: {ruleSet, output, inputs}` gets the
  -   output of its rule set, evaluated in preview mode. CnFormPage decides when
  -   (it waits for the answers the field reads to settle).
  - - `unmetConditions` / `blockSubmit`: the form's `eligibility` check is
  -   evaluated through the live values bridge as the answers change; while it
  -   does not pass, its explanation is listed above submit and, with
  -   `eligibility.blockSubmit`, holds the submit.
  -
  - - `customComponents` / `smartPaste`: buildiq's "Paste to fill" handler
  -   (`buildiq-smart-paste`, over hermiq's form fill) is registered for
  -   CnFormPage, and a smart-paste setting saved before the handler was named
  -   gets it (ai-smart-paste-into-forms REQ-BQSP-003).
  -
  - A form without calculated fields or an eligibility check renders exactly as
  - CnFormPage. Nothing computed here is trusted: the server evaluates again
  - when the record is saved.
  -->
<template>
	<CnFormPage
		v-bind="$attrs"
		:fields="fields"
		:calculate="calculator"
		:unmetConditions="unmetConditions"
		:blockSubmit="submitBlocked"
		:customComponents="formComponents"
		:smartPaste="smartPasteConfig"
		@input="onInput">
		<template v-for="(_, name) in $slots" #[name]="slotProps">
			<slot :name="name" v-bind="slotProps || {}" />
		</template>
	</CnFormPage>
</template>

<script>
import { CnFormPage } from '@conduction/nextcloud-vue'
import {
	createLiveValuesBridge,
	evaluatePreview,
} from '../../services/formLiveValues.js'
import { SMART_PASTE_HANDLER, smartPasteHandler } from '../../services/smartPaste.js'

/**
 * The field's calculation when it names a rule set and an output, else null.
 *
 * @param {?object} field A form field.
 * @return {?object} `{ruleSet, output, inputs}`.
 */
function calculationOf(field) {
	const calc = field && field.calculate
	if (!calc || !calc.ruleSet || !calc.output) {
		return null
	}
	return {
		ruleSet: calc.ruleSet,
		output: calc.output,
		inputs: Array.isArray(calc.inputs) ? calc.inputs : [],
	}
}

/**
 * The named answers only.
 *
 * @param {object} answers All answers.
 * @param {Array<string>} keys The keys to keep.
 * @return {object}
 */
function pickAnswers(answers, keys) {
	const out = {}
	keys.forEach((key) => {
		if (answers && Object.hasOwn(answers, key)) {
			out[key] = answers[key]
		}
	})
	return out
}

export default {
	name: 'BuildiqFormPage',

	components: { CnFormPage },

	inject: {
		cnCustomComponents: { default: null },
	},

	inheritAttrs: false,

	props: {
		/** The form's fields, passed on to CnFormPage. */
		fields: {
			type: Array,
			default: () => [],
		},

		/**
		 * The form's eligibility check: `{ruleSet, passWhen: {output, equals},
		 * explainWith, blockSubmit}`, or null.
		 */
		eligibility: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			answers: {},
			unmet: '',
			blocked: false,
			bridge: null,
		}
	},

	computed: {
		/**
		 * What CnFormPage resolves handlers from: what it would have resolved,
		 * plus buildiq's smart-paste handler.
		 *
		 * @return {object}
		 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
		 */
		formComponents() {
			const inherited = this.$attrs.customComponents || this.cnCustomComponents || {}
			return { ...inherited, [SMART_PASTE_HANDLER]: smartPasteHandler }
		},

		/**
		 * The form's smart-paste setting, naming buildiq's handler when it names none.
		 *
		 * @return {?object}
		 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
		 */
		smartPasteConfig() {
			const setting = this.$attrs.smartPaste
			if (!setting || typeof setting !== 'object') {
				return null
			}
			return setting.handler ? setting : { ...setting, handler: SMART_PASTE_HANDLER }
		},

		/**
		 * Whether any field is calculated by a rule set.
		 *
		 * @return {boolean}
		 */
		hasCalculations() {
			return this.fields.some((field) => calculationOf(field) !== null)
		},

		/**
		 * The calculate hook for CnFormPage, or null without calculated fields.
		 *
		 * @return {?((key: string, answers: object) => Promise<unknown>)}
		 */
		calculator() {
			return this.hasCalculations ? this.calculateField : null
		},

		/**
		 * The unmet eligibility condition as CnFormPage lists it.
		 *
		 * @return {Array<{message: string}>}
		 */
		unmetConditions() {
			return this.unmet === '' ? [] : [{ message: this.unmet }]
		},

		/**
		 * Whether submit waits: a blocking check that does not pass.
		 *
		 * @return {boolean}
		 */
		submitBlocked() {
			return this.blocked
		},

		/**
		 * The eligibility check when it names a rule set, else null.
		 *
		 * @return {?object}
		 */
		check() {
			return this.eligibility && this.eligibility.ruleSet ? this.eligibility : null
		},
	},

	watch: {
		check: {
			handler() {
				this.startBridge()
			},

			immediate: true,
		},
	},

	beforeUnmount() {
		this.stopBridge()
	},

	methods: {
		/**
		 * Work out one calculated field from the current answers.
		 *
		 * @param {string} key The field to calculate.
		 * @param {object} answers All answers of the form.
		 * @return {Promise<unknown>} The rule set output; rejects when it cannot be evaluated.
		 * @spec openspec/changes/archive/2026-11-24-forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
		 */
		async calculateField(key, answers) {
			const calc = calculationOf(this.fields.find((field) => field && field.key === key))
			if (calc === null) {
				throw new Error('Field ' + key + ' is not calculated')
			}
			const result = await evaluatePreview(calc.ruleSet, pickAnswers(answers, calc.inputs))
			return result ? result[calc.output] : undefined
		},

		/**
		 * Note a changed answer and let the eligibility check follow.
		 *
		 * @param {{key: string, value: unknown}} change The changed answer.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
		 */
		onInput(change) {
			if (!change || typeof change.key !== 'string') {
				return
			}
			this.answers = { ...this.answers, [change.key]: change.value }
			if (this.bridge !== null) {
				this.bridge.answersChanged(this.answers)
			}
		},

		/**
		 * (Re)start the live values bridge for the current eligibility check.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003
		 */
		startBridge() {
			this.stopBridge()
			this.unmet = ''
			this.blocked = false
			if (this.check === null) {
				return
			}
			this.bridge = createLiveValuesBridge({
				fields: [],
				eligibility: this.check,
				evaluate: evaluatePreview,
				onChange: ({ unmet, submitBlocked }) => {
					this.unmet = unmet
					this.blocked = submitBlocked
				},
			})
		},

		/**
		 * Cancel a pending evaluation.
		 *
		 * @return {void}
		 */
		stopBridge() {
			if (this.bridge !== null) {
				this.bridge.dispose()
			}
			this.bridge = null
		},
	},
}
</script>
