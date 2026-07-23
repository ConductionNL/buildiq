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
				show-logic
				@update:modelValue="update('fields', $event)" />
			<InlineFieldMark :error="markFor('fields')" />
		</fieldset>

		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Steps') }}</legend>
			<FormStepsManager
				:steps="config.steps || []"
				:fields="config.fields || []"
				@update:steps="update('steps', $event)" />
			<InlineFieldMark :error="markFor('steps')" />
		</fieldset>

		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Public access') }}</legend>
			<label class="form-page-editor__inline">
				<input
					type="checkbox"
					:checked="publicConfig.enabled === true"
					@change="setPublicEnabled($event.target.checked)">
				{{ t('openbuild', 'Allow this page to be shared publicly (anonymous, no login)') }}
			</label>
			<p class="form-page-editor__hint">
				{{ t('openbuild', 'A page must be marked public here before a share link can be created for it (page designer toolbar or App settings).') }}
			</p>
			<template v-if="publicConfig.enabled === true">
				<label class="form-page-editor__group-row">
					{{ t('openbuild', 'Default link mode') }}
					<select
						:value="publicConfig.mode || 'submit'"
						@change="updatePublic('mode', $event.target.value)">
						<option value="submit">
							{{ t('openbuild', 'submit — anonymous create form') }}
						</option>
						<option value="edit">
							{{ t('openbuild', 'edit — per-record edit link') }}
						</option>
					</select>
				</label>
				<label class="form-page-editor__group-row">
					{{ t('openbuild', 'Allowed prefill fields (comma-separated)') }}
					<input
						type="text"
						:value="(publicConfig.allowedPrefillFields || []).join(', ')"
						:placeholder="t('openbuild', 'e.g. postcode, straat')"
						@change="setAllowedPrefillFields($event.target.value)">
				</label>
				<label class="form-page-editor__inline">
					<input
						type="checkbox"
						:checked="publicConfig.requireEmailVerification === true"
						@change="updatePublic('requireEmailVerification', $event.target.checked)">
					{{ t('openbuild', 'Flag submissions as unverified until the visitor confirms their email (accept-then-flag)') }}
				</label>
			</template>
		</fieldset>
	</div>
</template>

<script>
import FormFieldBuilder from './fields/FormFieldBuilder.vue'
import FormStepsManager from './fields/FormStepsManager.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'FormPageEditor',
	components: { FormFieldBuilder, FormStepsManager, InlineFieldMark },
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
		 * `steps` added by REQ-OBFEL-001 so `formLogic.js` / the canonical
		 * validator's `/pages/<n>/config/steps` errors mark inline.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 * @spec openspec/changes/form-editor-logic/specs/form-editor-logic/spec.md#req-obfel-001
		 */
		validatedConfigKeys() {
			return ['submitHandler', 'submitEndpoint', 'submitMethod', 'mode', 'submitLabel', 'successMessage', 'fields', 'initialValue', 'steps']
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
		/**
		 * The page config's `public` block (public-forms-runtime), normalised
		 * to a plain object so template bindings never dereference undefined.
		 *
		 * @return {object}
		 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-page-can-only-be-issued-a-token-when-its-config-declares-publicenabled
		 */
		publicConfig() {
			return (this.config.public && typeof this.config.public === 'object') ? this.config.public : {}
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
		/**
		 * Patch a single key on `config.public`, creating the block when absent.
		 *
		 * @param {string} key The `public` block key to set.
		 * @param {*} value The new value.
		 * @return {void}
		 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-page-can-only-be-issued-a-token-when-its-config-declares-publicenabled
		 */
		updatePublic(key, value) {
			const next = { ...this.config }
			next.public = { ...this.publicConfig, [key]: value }
			this.$emit('update:config', next)
		},
		/**
		 * Toggle `config.public.enabled`. Unlike other `public` keys, turning
		 * this OFF does not delete the rest of the block — a previously
		 * configured mode/prefill list is preserved so re-enabling restores it.
		 *
		 * @param {boolean} value Checkbox state.
		 * @return {void}
		 */
		setPublicEnabled(value) {
			this.updatePublic('enabled', value === true)
		},
		/**
		 * Parse the comma-separated prefill-fields input into a trimmed,
		 * non-empty string array.
		 *
		 * @param {string} value Raw comma-separated input value.
		 * @return {void}
		 */
		setAllowedPrefillFields(value) {
			const fields = String(value || '')
				.split(',')
				.map((f) => f.trim())
				.filter((f) => f !== '')
			this.updatePublic('allowedPrefillFields', fields)
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

.form-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
