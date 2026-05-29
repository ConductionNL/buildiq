<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FormPageEditor — field list (reusing FormFieldBuilder.vue), exactly-one-of
  - submitHandler / submitEndpoint, submitMethod enum picker, mode enum
  - picker, optional submitLabel / successMessage / initialValue.
  - Implements REQ-OBPD-006.
  -->
<template>
	<div class="form-page-editor">
		<h3 class="form-page-editor__title">
			{{ t('openbuild', 'Form page') }}
		</h3>

		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Submit') }}</legend>
			<div class="form-page-editor__submit-shape">
				<label class="form-page-editor__inline">
					<input
						type="radio"
						:checked="submitShape === 'handler'"
						value="handler"
						@change="setSubmitShape('handler')">
					{{ t('openbuild', 'submitHandler (registry key)') }}
				</label>
				<label class="form-page-editor__inline">
					<input
						type="radio"
						:checked="submitShape === 'endpoint'"
						value="endpoint"
						@change="setSubmitShape('endpoint')">
					{{ t('openbuild', 'submitEndpoint (URL)') }}
				</label>
			</div>
			<input
				v-if="submitShape === 'handler'"
				type="text"
				class="form-page-editor__input"
				:value="config.submitHandler || ''"
				:placeholder="t('openbuild', 'customComponents registry key')"
				:aria-invalid="isInvalid('submitHandler')"
				@input="setSubmitHandler($event.target.value)">
			<input
				v-else-if="submitShape === 'endpoint'"
				type="text"
				class="form-page-editor__input"
				:value="config.submitEndpoint || ''"
				:placeholder="t('openbuild', '/api/objects/:slug/...')"
				:aria-invalid="isInvalid('submitEndpoint')"
				@input="setSubmitEndpoint($event.target.value)">
			<InlineFieldMark :error="markFor(submitShape === 'endpoint' ? 'submitEndpoint' : 'submitHandler')" />
			<label class="form-page-editor__group-row">
				{{ t('openbuild', 'Method') }}
				<select
					:value="config.submitMethod || 'POST'"
					@change="update('submitMethod', $event.target.value)">
					<option value="POST">
						POST
					</option>
					<option value="PUT">
						PUT
					</option>
					<option value="PATCH">
						PATCH
					</option>
				</select>
			</label>
			<label class="form-page-editor__group-row">
				{{ t('openbuild', 'Mode') }}
				<select
					:value="config.mode || 'public'"
					@change="update('mode', $event.target.value)">
					<option value="public">
						public
					</option>
					<option value="create">
						create
					</option>
					<option value="edit">
						edit
					</option>
				</select>
			</label>
			<label class="form-page-editor__group-row">
				{{ t('openbuild', 'Submit label (optional)') }}
				<input
					type="text"
					:value="config.submitLabel || ''"
					:placeholder="t('openbuild', 'i18n key')"
					@input="update('submitLabel', $event.target.value)">
			</label>
			<label class="form-page-editor__group-row">
				{{ t('openbuild', 'Success message (optional)') }}
				<input
					type="text"
					:value="config.successMessage || ''"
					:placeholder="t('openbuild', 'i18n key')"
					@input="update('successMessage', $event.target.value)">
			</label>
		</fieldset>

		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Fields') }}</legend>
			<FormFieldBuilder
				:model-value="config.fields || []"
				@update:modelValue="update('fields', $event)" />
			<InlineFieldMark :error="markFor('fields')" />
		</fieldset>
	</div>
</template>

<script>
import FormFieldBuilder from './fields/FormFieldBuilder.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'FormPageEditor',
	components: { FormFieldBuilder, InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'form',
		},
		appSlug: {
			type: String,
			default: '',
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['submitHandler', 'submitEndpoint', 'submitMethod', 'mode', 'submitLabel', 'successMessage', 'fields', 'initialValue']
		},
		/**
		 * Observed behaviour of `submitShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		submitShape() {
			if (this.config.submitHandler) {
				return 'handler'
			}
			if (this.config.submitEndpoint) {
				return 'endpoint'
			}
			return 'handler'
		},
	},
	methods: {
		/**
		 * Observed behaviour of `update` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * Observed behaviour of `setSubmitShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setSubmitShape(shape) {
			const next = { ...this.config }
			if (shape === 'handler') {
				delete next.submitEndpoint
			} else {
				delete next.submitHandler
			}
			this.$emit('update:config', next)
		},
		/**
		 * Observed behaviour of `setSubmitHandler` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setSubmitHandler(value) {
			const next = { ...this.config }
			// Exactly-one-of: setting submitHandler clears submitEndpoint.
			delete next.submitEndpoint
			if (value === '') {
				delete next.submitHandler
			} else {
				next.submitHandler = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * Observed behaviour of `setSubmitEndpoint` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setSubmitEndpoint(value) {
			const next = { ...this.config }
			delete next.submitHandler
			if (value === '') {
				delete next.submitEndpoint
			} else {
				next.submitEndpoint = value
			}
			this.$emit('update:config', next)
		},
	},
}
</script>

<style scoped>
.form-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.form-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.form-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.form-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.form-page-editor__submit-shape {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.form-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
}

.form-page-editor__input,
.form-page-editor__group-row input,
.form-page-editor__group-row select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.form-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}
</style>
