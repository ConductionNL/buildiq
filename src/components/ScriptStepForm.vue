<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - ScriptStepForm — the "Run a script" step of the automation composer
  - (logic-script-step D1, D5, REQ-BQSS-001): the JavaScript the code runner
  - runs over the flow's items, how long it may run, and a note saying what
  - it does. The code is edited as text; buildiq never runs it.
  -->
<template>
	<div class="script-step-form" data-testid="script-step-form">
		<p v-if="readOnly" class="script-step-form__locked" data-testid="script-step-locked">
			{{ t('buildiq', 'Only app owners can change a script step.') }}
		</p>
		<p class="script-step-form__hint">
			{{ t('buildiq', 'The script is the body of a function that receives the items and returns items. It runs only in OpenRegister\'s code runner, with no network access, and only app owners may change it.') }}
		</p>
		<CnJsonViewer
			:value="modelValue.code || ''"
			language="text"
			height="200px"
			:readOnly="readOnly"
			:aria-label="t('buildiq', 'Script')"
			data-testid="script-step-code"
			@update:value="emitKey('code', $event)" />
		<NcTextField
			:modelValue="String(modelValue.timeoutSeconds ?? '')"
			type="number"
			min="1"
			max="30"
			:disabled="readOnly"
			:label="t('buildiq', 'Time limit (seconds, 1 to 30)')"
			data-testid="script-step-timeout"
			@update:modelValue="emitKey('timeoutSeconds', timeoutValue($event))" />
		<NcTextField
			:modelValue="modelValue.note || ''"
			:disabled="readOnly"
			:label="t('buildiq', 'What the script does')"
			data-testid="script-step-note"
			@update:modelValue="emitKey('note', $event)" />
	</div>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import { NcTextField } from '@nextcloud/vue'

export default {
	name: 'ScriptStepForm',
	components: { CnJsonViewer, NcTextField },

	props: {
		modelValue: { type: Object, required: true },
		// REQ-BQSS-002: an editor reads the script but cannot change it.
		readOnly: { type: Boolean, default: false },
	},

	emits: ['update'],

	methods: {
		/**
		 * Hand one changed field to the composer.
		 *
		 * @param {string} key - the field.
		 * @param {unknown} value - its new value.
		 * @return {void}
		 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
		 */
		emitKey(key, value) {
			if (this.readOnly) {
				return
			}
			this.$emit('update', { key, value })
		},

		/**
		 * The typed time limit as a number, or '' while the field is empty.
		 *
		 * @param {string} text - what was typed.
		 * @return {number|string}
		 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
		 */
		timeoutValue(text) {
			const trimmed = String(text ?? '').trim()
			return trimmed === '' ? '' : Number(trimmed)
		},
	},
}
</script>

<style scoped>
.script-step-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.script-step-form__locked {
	font-weight: bold;
	margin: 0;
}

.script-step-form__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
