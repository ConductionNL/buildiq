<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - CodeComponentEditor — edits one code component, `{html, css, js, inputs}`
  - (pages-custom-code D1, D5): an HTML editor, plain-text CSS and JavaScript
  - editors, and a checklist of what the page hands in.
  -
  - It only shapes the value. The code runs in the sandbox frame, never in the
  - page designer, and the server decides who may save it (REQ-BQCC-005).
  -->
<template>
	<div class="code-component-editor">
		<div
v-for="part in parts"
			:key="part.key"
			class="code-component-editor__part">
			<span class="code-component-editor__label">{{ part.label }}</span>
			<CnJsonViewer
				:value="valueOf(part.key)"
				:language="part.language"
				height="180px"
				:aria-label="part.label"
				@update:value="setPart(part.key, $event)" />
		</div>

		<fieldset class="code-component-editor__inputs">
			<legend>{{ t('buildiq', 'What the page hands in') }}</legend>
			<label
v-for="input in inputOptions"
				:key="input.key"
				class="code-component-editor__input">
				<input
type="checkbox"
					:checked="hasInput(input.key)"
					@change="toggleInput(input.key, $event.target.checked)">
				{{ input.label }}
			</label>
		</fieldset>
	</div>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { CODE_INPUTS } from '../../../services/manifestValidation/pageCode.js'

export default {
	name: 'CodeComponentEditor',
	components: { CnJsonViewer },
	props: {
		/** The code block, `{html?, css?, js?, inputs?}`. */
		modelValue: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The three text parts, in the order a maker writes them.
		 *
		 * @return {Array<{key: string, label: string, language: string}>}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t04
		 */
		parts() {
			return [
				{ key: 'html', label: t('buildiq', 'HTML'), language: 'html' },
				{ key: 'css', label: t('buildiq', 'CSS'), language: 'text' },
				{ key: 'js', label: t('buildiq', 'JavaScript'), language: 'text' },
			]
		},

		/**
		 * The inputs a code component may ask for, labelled.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t04
		 */
		inputOptions() {
			const labels = {
				object: t('buildiq', 'The current record (detail pages)'),
				rows: t('buildiq', 'The rows of the page’s register and schema, with its filters'),
				route: t('buildiq', 'The route parameters'),
				user: t('buildiq', 'The signed-in user’s id and name'),
			}
			return CODE_INPUTS.map((key) => ({ key, label: labels[key] }))
		},
	},

	methods: {
		/**
		 * The text of one part, empty when unset.
		 *
		 * @param {string} key - `html`, `css` or `js`.
		 * @return {string}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t04
		 */
		valueOf(key) {
			const value = this.modelValue ? this.modelValue[key] : undefined
			return typeof value === 'string' ? value : ''
		},

		/**
		 * Write one part. An emptied part is removed, not stored as `''`.
		 *
		 * @param {string} key - `html`, `css` or `js`.
		 * @param {string} value - the new text.
		 * @spec openspec/changes/pages-custom-code/tasks.md#t04
		 */
		setPart(key, value) {
			const next = { ...(this.modelValue || {}) }
			if (value === '' || value === null || value === undefined) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Whether the component asks for an input.
		 *
		 * @param {string} key - one of CODE_INPUTS.
		 * @return {boolean}
		 * @spec openspec/changes/pages-custom-code/tasks.md#t04
		 */
		hasInput(key) {
			const inputs = this.modelValue && Array.isArray(this.modelValue.inputs) ? this.modelValue.inputs : []
			return inputs.includes(key)
		},

		/**
		 * Tick or untick an input. The list keeps the order of CODE_INPUTS and
		 * is removed when nothing is ticked.
		 *
		 * @param {string} key - one of CODE_INPUTS.
		 * @param {boolean} on - whether it is now ticked.
		 * @spec openspec/changes/pages-custom-code/tasks.md#t04
		 */
		toggleInput(key, on) {
			const current = this.modelValue && Array.isArray(this.modelValue.inputs) ? this.modelValue.inputs : []
			const wanted = new Set(current.filter((input) => input !== key))
			if (on) {
				wanted.add(key)
			}
			const next = { ...(this.modelValue || {}) }
			const inputs = CODE_INPUTS.filter((input) => wanted.has(input))
			if (inputs.length === 0) {
				delete next.inputs
			} else {
				next.inputs = inputs
			}
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.code-component-editor {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.code-component-editor__part {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.code-component-editor__label {
	font-size: 13px;
	font-weight: 600;
}

.code-component-editor__inputs {
	@include c.fieldset;
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
}

.code-component-editor__input {
	display: flex;
	align-items: center;
	gap: 6px;
}
</style>
