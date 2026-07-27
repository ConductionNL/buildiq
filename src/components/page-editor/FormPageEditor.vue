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

		<!-- REQ-EFP-002: External access — provisions OR schema authorization
		     (+ optional Portaliq portalPage) via ExternalFormAccessDialog. Only
		     offered for endpoint-shaped forms whose submitEndpoint resolves to
		     an OR `/api/objects/{register}/{schema}` target; OpenBuild never
		     hosts the anonymous surface itself (design.md, thin-leaf rule). -->
		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('openbuild', 'External access') }}</legend>
			<template v-if="externalTarget">
				<p class="form-page-editor__external-status">
					{{ externalFormEntry && externalFormEntry.status === 'enabled'
						? t('openbuild', 'Externally fillable ({register}/{schema})', externalTarget)
						: t('openbuild', 'Not externally fillable yet ({register}/{schema})', externalTarget) }}
				</p>
				<button type="button" class="form-page-editor__external-btn" @click="externalDialogOpen = true">
					{{ t('openbuild', 'Configure') }}
				</button>
				<ExternalFormAccessDialog
					:open.sync="externalDialogOpen"
					:register="externalTarget.register"
					:schema="externalTarget.schema"
					:page-id="pageId"
					:entry="externalFormEntry"
					@save="onExternalFormSave" />
			</template>
			<p v-else class="form-page-editor__hint">
				{{ t('openbuild', 'External access requires a submitEndpoint shaped like /api/objects/{register}/{schema}.') }}
			</p>
		</fieldset>
	</div>
</template>

<script>
import FormFieldBuilder from './fields/FormFieldBuilder.vue'
import FormStepsManager from './fields/FormStepsManager.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import ExternalFormAccessDialog from '../../dialogs/ExternalFormAccessDialog.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'FormPageEditor',
	components: { FormFieldBuilder, FormStepsManager, InlineFieldMark, ExternalFormAccessDialog },
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
		// The selected page's `id` (mergeManifestDelta's page key) — the
		// `runtime.externalForms[].pageId` this editor writes/reads
		// (REQ-EFP-001/002).
		pageId: {
			type: String,
			default: '',
		},
		// The manifest's full `runtime.externalForms[]` array — this editor
		// filters to the entry (if any) owned by `pageId`.
		runtimeExternalForms: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:config', 'update:runtimeExternalForms'],
	data() {
		return {
			externalDialogOpen: false,
		}
	},
	computed: {
		/**
		 * `{register, schema}` resolved from `config.submitEndpoint` when it
		 * matches OR's `/api/objects/{register}/{schema}` shape; null
		 * otherwise (handler-shaped forms, or an endpoint that isn't an OR
		 * objects target) — gates the External access section (REQ-EFP-002).
		 *
		 * @return {?{register: string, schema: string}}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		externalTarget() {
			if (this.submitShape !== 'endpoint') {
				return null
			}
			const endpoint = this.config.submitEndpoint || ''
			const match = /^\/(?:apps\/openregister\/)?api\/objects\/([^/]+)\/([^/]+)\/?$/.exec(endpoint)
			return match ? { register: match[1], schema: match[2] } : null
		},
		/**
		 * The existing `runtime.externalForms[]` entry for THIS page, if any.
		 *
		 * @return {?object}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-001
		 */
		externalFormEntry() {
			if (!this.pageId) {
				return null
			}
			return (this.runtimeExternalForms || []).find((e) => e && e.pageId === this.pageId) || null
		},
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
		 * Persist the provisioned/revoked entry from ExternalFormAccessDialog
		 * into `runtime.externalForms[]` (find-or-append by pageId, per
		 * design.md Decision 1). Emitted up to PageDesigner, which merges it
		 * onto the manifest — this editor never writes the manifest directly.
		 *
		 * @param {object} entry - the resolved `runtime.externalForms[]` entry.
		 * @return {void}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		onExternalFormSave(entry) {
			const list = (this.runtimeExternalForms || []).slice()
			const idx = list.findIndex((e) => e && e.pageId === this.pageId)
			if (idx >= 0) {
				list[idx] = entry
			} else {
				list.push(entry)
			}
			this.$emit('update:runtimeExternalForms', list)
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

.form-page-editor__hint,
.form-page-editor__external-status {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}

.form-page-editor__external-btn {
	align-self: flex-start;
	padding: 4px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}
</style>
