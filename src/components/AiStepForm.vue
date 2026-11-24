<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - AiStepForm — the "Ask AI" step of the automation composer
  - (ai-llm-steps-and-computed-fields D1, D3, REQ-BQLS-001): what the AI does,
  - which Hermiq agent runs it, which fields it reads and where the answer
  - goes, plus the summary of exactly which fields are sent to the model.
  -->
<template>
	<div class="ai-step-form" data-testid="ai-step-form">
		<NcSelect
			:modelValue="presetOption"
			:inputLabel="t('buildiq', 'What the AI does')"
			:options="presetOptions"
			:clearable="false"
			label="label"
			data-testid="ai-step-preset"
			@update:modelValue="emitKey('preset', $event ? $event.value : 'classify')" />

		<NcSelect
			v-if="agentOptions.length > 0"
			:modelValue="agentOption"
			:inputLabel="t('buildiq', 'Hermiq agent')"
			:options="agentOptions"
			:loading="agentsLoading"
			label="label"
			data-testid="ai-step-agent-select"
			@update:modelValue="emitKey('agent', $event ? $event.id : '')" />
		<NcTextField
			v-else
			:modelValue="modelValue.agent"
			:label="t('buildiq', 'Hermiq agent id')"
			data-testid="ai-step-agent-text"
			@update:modelValue="emitKey('agent', $event)" />
		<p class="ai-step-form__hint">
			{{ t('buildiq', 'Only agents without tools are listed: this step answers, it does not act.') }}
		</p>

		<NcSelect
			:modelValue="optionsFor(modelValue.sourceFields)"
			:inputLabel="t('buildiq', 'Fields the AI reads')"
			:options="fieldOptions"
			:multiple="true"
			label="label"
			data-testid="ai-step-sources"
			@update:modelValue="emitKey('sourceFields', valuesOf($event))" />

		<NcSelect
			v-if="modelValue.preset === 'extract'"
			:modelValue="optionsFor(modelValue.fields)"
			:inputLabel="t('buildiq', 'Fields the AI fills in')"
			:options="fieldOptions"
			:multiple="true"
			label="label"
			data-testid="ai-step-extract-fields"
			@update:modelValue="emitKey('fields', valuesOf($event))" />
		<NcSelect
			v-else
			:modelValue="optionsFor(modelValue.targetField ? [modelValue.targetField] : [])[0] || null"
			:inputLabel="t('buildiq', 'Write the answer to field')"
			:options="fieldOptions"
			label="label"
			data-testid="ai-step-target"
			@update:modelValue="emitKey('targetField', $event ? $event.value : '')" />

		<NcTextField
			v-if="modelValue.preset === 'classify'"
			:modelValue="(modelValue.labels || []).join(', ')"
			:label="t('buildiq', 'Labels, separated by commas')"
			:helperText="t('buildiq', 'The target field must be a choice list of exactly these labels.')"
			data-testid="ai-step-labels"
			@update:modelValue="emitKey('labels', splitLabels($event))" />
		<NcTextField
			v-if="modelValue.preset === 'translate'"
			:modelValue="modelValue.language"
			:label="t('buildiq', 'Translate into')"
			data-testid="ai-step-language"
			@update:modelValue="emitKey('language', $event)" />
		<NcTextArea
			v-if="modelValue.preset === 'prompt'"
			:modelValue="modelValue.prompt"
			:label="t('buildiq', 'Instruction')"
			:helperText="t('buildiq', 'You may name a field the AI reads between double curly braces. No other field is sent.')"
			data-testid="ai-step-prompt"
			@update:modelValue="emitKey('prompt', $event)" />

		<NcNoteCard
			v-if="summary"
			type="info"
			data-testid="ai-step-summary">
			{{ summary }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import { aiPresetOptions, aiStepSummary, namesOf } from '../services/aiSteps.js'

export default {
	name: 'AiStepForm',
	components: { NcNoteCard, NcSelect, NcTextArea, NcTextField },

	props: {
		modelValue: { type: Object, required: true },
		fieldOptions: { type: Array, default: () => [] },
		agentOptions: { type: Array, default: () => [] },
		agentsLoading: { type: Boolean, default: false },
	},

	emits: ['update'],

	computed: {
		/**
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		presetOptions() {
			return aiPresetOptions()
		},

		/**
		 * @return {?{value: string, label: string}}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		presetOption() {
			return this.presetOptions.find((o) => o.value === this.modelValue.preset) || this.presetOptions[0]
		},

		/**
		 * @return {?{id: string, label: string}}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		agentOption() {
			return this.agentOptions.find((o) => o.id === this.modelValue.agent) || null
		},

		/**
		 * The fields this step sends, shown before the maker saves (D3).
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		summary() {
			return aiStepSummary(this.modelValue)
		},
	},

	methods: {
		/**
		 * Report one changed key to the dialog.
		 *
		 * @param {string} key - the action key.
		 * @param {string|Array<string>} value - its new value.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		emitKey(key, value) {
			this.$emit('update', { key, value })
		},

		/**
		 * The field options of a list of names; a name without an option keeps itself as label.
		 *
		 * @param {Array<string>} names - the field names.
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		optionsFor(names) {
			return namesOf(names).map((name) => this.fieldOptions.find((o) => o.value === name) || { value: name, label: name })
		},

		/**
		 * The names of picked options.
		 *
		 * @param {Array<{value: string}>|null} options - the picked options.
		 * @return {Array<string>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		valuesOf(options) {
			return namesOf((options || []).map((option) => option && option.value))
		},

		/**
		 * The labels typed as a comma separated list.
		 *
		 * @param {string} text - the typed text.
		 * @return {Array<string>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
		 */
		splitLabels(text) {
			return namesOf(String(text || '').split(','))
		},
	},
}
</script>

<style scoped>
.ai-step-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.ai-step-form__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
