<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - GeneratedNumberSettings — the "Automatic number" setting of a text field
  - (data-generated-number-field, REQ-OBSD-009). Controlled: `modelValue` is
  - the setting (or null when off) and `update:modelValue` carries the next
  - one. Shows the next number for today and refuses a format OpenRegister
  - would refuse.
  -->
<template>
	<div class="buildiq-generated-number">
		<NcCheckboxRadioSwitch
			:modelValue="enabled"
			type="switch"
			@update:modelValue="toggle">
			{{ t('buildiq', 'Automatic number') }}
		</NcCheckboxRadioSwitch>

		<template v-if="enabled">
			<NcTextField
				:modelValue="modelValue.sequence || ''"
				:label="t('buildiq', 'Counter name')"
				:placeholder="schemaSlug"
				:helperText="t('buildiq', 'Fields with the same counter name share one count.')"
				@update:modelValue="update('sequence', $event)" />
			<NcTextField
				:modelValue="modelValue.format || ''"
				:label="t('buildiq', 'Number format')"
				:error="!!formatError"
				:helperText="formatError || t('buildiq', 'Use {year}, {month} and a counter such as {seq:4}.')"
				@update:modelValue="update('format', $event)" />
			<NcCheckboxRadioSwitch
				:modelValue="modelValue.resetOn === 'year'"
				type="switch"
				@update:modelValue="update('resetOn', $event ? 'year' : 'never')">
				{{ t('buildiq', 'Restart the counter each year') }}
			</NcCheckboxRadioSwitch>
			<p v-if="!formatError" class="buildiq-generated-number__example" aria-live="polite">
				{{ t('buildiq', 'Next number: {example}', { example }, undefined, { escape: false }) }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { exampleNumber, formatProblem, newGeneratedSetting } from './generatedNumber.js'

export default {
	name: 'GeneratedNumberSettings',
	components: { NcCheckboxRadioSwitch, NcTextField },
	props: {
		modelValue: { type: Object, default: null },
		schemaSlug: { type: String, default: '' },
		/** The moment the example is rendered for; today unless a test pins it. */
		now: { type: Date, default: null },
	},

	emits: ['update:modelValue'],
	computed: {
		/**
		 * Whether the setting is on.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/data-generated-number-field/tasks.md#t01
		 */
		enabled() {
			return !!this.modelValue?.enabled
		},

		/**
		 * The next number for today and counter 1.
		 *
		 * @return {string}
		 * @spec openspec/changes/data-generated-number-field/tasks.md#t01
		 */
		example() {
			return exampleNumber(this.modelValue?.format, this.now || new Date(), 1)
		},

		/**
		 * Why the format is refused, or '' when it is accepted.
		 *
		 * @return {string}
		 * @spec openspec/changes/data-generated-number-field/tasks.md#t03
		 */
		formatError() {
			if (!this.enabled) {
				return ''
			}
			const problem = formatProblem(this.modelValue.format, this.modelValue.resetOn)
			if (problem === null) {
				return ''
			}
			if (problem.code === 'unknown-placeholder') {
				return this.t('buildiq', 'The format uses {placeholder}, which is not one of {seq}, {seq:n}, {year} or {month}.', { placeholder: problem.placeholder }, undefined, { escape: false })
			}
			if (problem.code === 'year-reset-without-year') {
				return this.t('buildiq', 'The counter restarts each year, so the format needs {year}, or next year repeats this year\'s numbers.')
			}
			return this.t('buildiq', 'The format needs a counter such as {seq:4}, or every record gets the same number.')
		},
	},

	methods: {
		/**
		 * Switch the setting on (with defaults) or off.
		 *
		 * @param {boolean} on Whether to switch it on.
		 * @return {void}
		 * @spec openspec/changes/data-generated-number-field/tasks.md#t01
		 */
		toggle(on) {
			if (!on) {
				this.$emit('update:modelValue', null)
				return
			}
			const previous = this.modelValue && !this.modelValue.enabled ? this.modelValue : null
			this.$emit('update:modelValue', previous ? { ...previous, enabled: true } : newGeneratedSetting(this.schemaSlug))
		},

		/**
		 * Change one part of the setting.
		 *
		 * @param {string} key The part.
		 * @param {string} value Its value.
		 * @return {void}
		 * @spec openspec/changes/data-generated-number-field/tasks.md#t01
		 */
		update(key, value) {
			this.$emit('update:modelValue', { ...this.modelValue, [key]: value })
		},
	},
}
</script>

<style scoped lang="scss">
.buildiq-generated-number {
	grid-column: 1 / -1;
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.buildiq-generated-number__example {
	grid-column: 1 / -1;
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
