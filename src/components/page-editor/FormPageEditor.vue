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
			{{ t('buildiq', 'Form page') }}
		</h3>

		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Submit') }}</legend>
			<div class="form-page-editor__submit-shape">
				<label class="form-page-editor__inline">
					<input
						type="radio"
						:checked="submitShape === 'endpoint'"
						value="endpoint"
						@change="setSubmitShape('endpoint')" />
					{{ t('buildiq', 'Save to a register') }}
				</label>
				<label class="form-page-editor__inline">
					<input
						type="radio"
						:checked="submitShape === 'handler'"
						value="handler"
						@change="setSubmitShape('handler')" />
					{{ t('buildiq', 'Custom submit handler') }}
				</label>
			</div>
			<input
				v-if="submitShape === 'handler'"
				type="text"
				class="form-page-editor__input"
				:value="config.submitHandler || ''"
				:placeholder="t('buildiq', 'customComponents registry key')"
				:aria-label="t('buildiq', 'customComponents registry key')"
				:aria-invalid="isInvalid('submitHandler')"
				@input="setSubmitHandler($event.target.value)" />
			<template v-else>
				<label class="form-page-editor__group-row">
					{{ t('buildiq', 'Register') }}
					<select
						class="form-page-editor__register"
						:value="targetRegister"
						@change="onTargetRegister($event.target.value)">
						<option value="">
							{{ t('buildiq', 'Select a register') }}
						</option>
						<option
							v-for="r in registers"
							:key="r.slug || r.id"
							:value="r.slug || String(r.id)">
							{{ r.label || r.title || r.slug }}
						</option>
					</select>
				</label>
				<label class="form-page-editor__group-row">
					{{ t('buildiq', 'Schema') }}
					<select
						class="form-page-editor__schema"
						:value="targetSchema"
						:disabled="!targetRegister"
						@change="onTargetSchema($event.target.value)">
						<option value="">
							{{ t('buildiq', 'Select a schema') }}
						</option>
						<option
							v-for="sc in schemas"
							:key="sc.slug || sc.id"
							:value="sc.slug || String(sc.id)">
							{{ sc.title || sc.slug }}
						</option>
					</select>
				</label>
				<label class="form-page-editor__group-row">
					{{ t('buildiq', 'Submit URL') }}
					<input
						type="text"
						class="form-page-editor__input"
						:value="config.submitEndpoint || ''"
						:placeholder="t('buildiq', '/api/objects/:slug/…')"
						:aria-invalid="isInvalid('submitEndpoint')"
						@input="setSubmitEndpoint($event.target.value)" />
				</label>
			</template>
			<InlineFieldMark
				:error="
					markFor(
						submitShape === 'endpoint'
							? 'submitEndpoint'
							: 'submitHandler',
					)
				" />
			<label class="form-page-editor__group-row">
				{{ t('buildiq', 'Method') }}
				<select
					:value="config.submitMethod || 'POST'"
					@change="update('submitMethod', $event.target.value)">
					<option value="POST">POST</option>
					<option value="PUT">PUT</option>
					<option value="PATCH">PATCH</option>
				</select>
			</label>
			<label class="form-page-editor__group-row">
				{{ t('buildiq', 'Mode') }}
				<select
					:value="config.mode || 'public'"
					@change="update('mode', $event.target.value)">
					<option value="public">public</option>
					<option value="create">create</option>
					<option value="edit">edit</option>
				</select>
			</label>
			<label class="form-page-editor__group-row">
				{{ t('buildiq', 'Submit label (optional)') }}
				<input
					type="text"
					:value="config.submitLabel || ''"
					:placeholder="t('buildiq', 'i18n key')"
					@input="update('submitLabel', $event.target.value)" />
			</label>
			<label class="form-page-editor__group-row">
				{{ t('buildiq', 'Success message (optional)') }}
				<input
					type="text"
					:value="config.successMessage || ''"
					:placeholder="t('buildiq', 'i18n key')"
					@input="update('successMessage', $event.target.value)" />
			</label>
		</fieldset>

		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Fields') }}</legend>
			<FormFieldBuilder
				:modelValue="config.fields || []"
				showLogic
				@update:modelValue="update('fields', $event)" />
			<InlineFieldMark :error="markFor('fields')" />
		</fieldset>

		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Steps') }}</legend>
			<FormStepsManager
				:steps="config.steps || []"
				:fields="config.fields || []"
				@update:steps="update('steps', $event)" />
			<InlineFieldMark :error="markFor('steps')" />
		</fieldset>

		<!-- REQ-EFP-002: External access — provisions OR schema authorization
		     (+ optional Portaliq portalPage) via ExternalFormAccessDialog. Only
		     offered for endpoint-shaped forms whose submitEndpoint resolves to
		     an OR `/api/objects/{register}/{schema}` target; Buildiq never
		     hosts the anonymous surface itself (design.md, thin-leaf rule). -->
		<fieldset class="form-page-editor__fieldset">
			<legend>{{ t('buildiq', 'External access') }}</legend>
			<template v-if="externalTarget">
				<p class="form-page-editor__external-status">
					{{
						externalFormEntry && externalFormEntry.status === 'enabled'
							? t(
									'buildiq',
									'Externally fillable ({register}/{schema})',
									externalTarget,
								)
							: t(
									'buildiq',
									'Not externally fillable yet ({register}/{schema})',
									externalTarget,
								)
					}}
				</p>
				<button
					type="button"
					class="form-page-editor__external-btn"
					@click="externalDialogOpen = true">
					{{ t('buildiq', 'Configure') }}
				</button>
				<ExternalFormAccessDialog
					v-model:open="externalDialogOpen"
					:register="externalTarget.register"
					:schema="externalTarget.schema"
					:pageId="pageId"
					:entry="externalFormEntry"
					@save="onExternalFormSave" />
			</template>
			<p v-else class="form-page-editor__hint">
				{{
					t(
						'buildiq',
						'External access requires a submitEndpoint shaped like /api/objects/{register}/{schema}.',
					)
				}}
			</p>
		</fieldset>
	</div>
</template>

<script>
import ExternalFormAccessDialog from '../../dialogs/ExternalFormAccessDialog.vue'
import FormFieldBuilder from './fields/FormFieldBuilder.vue'
import FormStepsManager from './fields/FormStepsManager.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

// OpenRegister's object collection URL: POSTing the form's values here
// creates an object in that register and schema.
const OR_OBJECTS_ENDPOINT =
	/^\/(?:index\.php\/)?(?:apps\/openregister\/)?api\/objects\/([^/]+)\/([^/]+)\/?$/

export default {
	name: 'FormPageEditor',
	components: {
		FormFieldBuilder,
		FormStepsManager,
		InlineFieldMark,
		ExternalFormAccessDialog,
	},

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

		dataRegisters: {
			type: Array,
			default: () => [],
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

	/**
	 * Build the register/schema picker the "Save to a register" choice uses.
	 *
	 * @param {{appSlug: string, dataRegisters: Array<object>}} props - the resolved props.
	 * @return {{picker: object}} the picker, exposed as `this.picker`.
	 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
	 */
	setup(props) {
		const picker = useRegisterPicker({
			appSlug: props.appSlug,
			dataRegisters: props.dataRegisters,
		})
		return { picker }
	},

	data() {
		return {
			externalDialogOpen: false,
			// The submit choice the user clicked. Switching choices clears the
			// other key, so with both keys empty the config alone cannot say
			// which choice is active; this remembers it.
			chosenShape: null,
			// A register picked before its schema: no URL can be built yet.
			pendingRegister: '',
			registers: [],
			schemas: [],
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
			const match = OR_OBJECTS_ENDPOINT.exec(this.config.submitEndpoint || '')
			return match ? { register: match[1], schema: match[2] } : null
		},

		/**
		 * The register the form saves into: read from the submit URL, or the
		 * one picked before a schema was chosen.
		 *
		 * @return {string} register slug, or ''.
		 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
		 */
		targetRegister() {
			return this.externalTarget
				? this.externalTarget.register
				: this.pendingRegister
		},

		/**
		 * The schema the form saves into, read from the submit URL.
		 *
		 * @return {string} schema slug, or ''.
		 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
		 */
		targetSchema() {
			return this.externalTarget ? this.externalTarget.schema : ''
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
			return (
				(this.runtimeExternalForms || []).find(
					(e) => e && e.pageId === this.pageId,
				) || null
			)
		},

		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 * `steps` added by REQ-OBFEL-001 so `formLogic.js` / the canonical
		 * validator's `/pages/<n>/config/steps` errors mark inline.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 * @spec openspec/specs/form-editor-logic/spec.md#req-obfel-001
		 */
		validatedConfigKeys() {
			return [
				'submitHandler',
				'submitEndpoint',
				'submitMethod',
				'mode',
				'submitLabel',
				'successMessage',
				'fields',
				'initialValue',
				'steps',
			]
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
			// Neither key is set: a new form, or one whose submit choice was
			// just switched (switching clears the other key). Follow the choice
			// the user made; a new form saves to a register.
			return this.chosenShape || 'endpoint'
		},
	},

	watch: {
		/**
		 * The designer reuses this editor when another form page is selected;
		 * the remembered choices belong to the previous page.
		 *
		 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
		 */
		pageId() {
			this.chosenShape = null
			this.pendingRegister = ''
		},

		targetRegister: {
			immediate: true,
			/**
			 * Load the schema list of the register the form saves into.
			 *
			 * @param {string} register - the register slug, or ''.
			 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
			 */
			async handler(register) {
				this.schemas = register ? await this.picker.fetchSchemas(register) : []
			},
		},
	},

	/**
	 * Load the registers for the "Save to a register" picker.
	 *
	 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
	 */
	async mounted() {
		const list = await this.picker.fetchRegisters()
		this.registers = Array.isArray(list) ? list : []
	},

	methods: {
		/**
		 * Write one key on the page's `config` block. Only the named key is
		 * touched, so config keys this editor does not surface round-trip
		 * losslessly. The submit one-of is handled by `setSubmitHandler` /
		 * `setSubmitEndpoint`, never here.
		 *
		 * @param {string} key - the config key being written: `submitMethod`, `mode`, `submitLabel`, `successMessage`, `fields` or `steps`.
		 * @param {string|Array<object>} value - the new value: the enum choice from a `<select>`, the i18n key from a text input, or the rebuilt list from FormFieldBuilder / FormStepsManager. `''` and `null` delete the key.
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
		 * Switch between the two mutually exclusive submit targets by
		 * deleting the key of the branch being left, so the emitted config
		 * never carries both halves of the one-of.
		 *
		 * @param {'handler'|'endpoint'} shape - the radio's value: `handler` drops `submitEndpoint`, anything else drops `submitHandler`.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setSubmitShape(shape) {
			this.chosenShape = shape === 'handler' ? 'handler' : 'endpoint'
			const next = { ...this.config }
			if (shape === 'handler') {
				delete next.submitEndpoint
			} else {
				delete next.submitHandler
			}
			this.$emit('update:config', next)
		},

		/**
		 * Write `submitHandler` and clear `submitEndpoint` in the same emit,
		 * so typing a handler can never leave a stale endpoint behind.
		 *
		 * @param {string} value - the customComponents registry key of the submit handler; `''` deletes `submitHandler`, leaving the form with neither submit target.
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
		 * Pick the register the form saves into. The URL is written once a
		 * schema is picked too.
		 *
		 * @param {string} register - the register slug, or ''.
		 * @return {void}
		 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
		 */
		onTargetRegister(register) {
			this.pendingRegister = register
			if (this.config.submitEndpoint) {
				// The old URL points at another register; drop it until a schema
				// in the new register is picked.
				this.setSubmitEndpoint('')
			}
		},

		/**
		 * Pick the schema the form saves into, which writes the submit URL.
		 *
		 * @param {string} schema - the schema slug, or '' to clear the URL.
		 * @return {void}
		 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-form-page-sub-editor-with-exactly-one-of-submit-handling
		 */
		onTargetSchema(schema) {
			const register = this.targetRegister
			if (!register || !schema) {
				this.setSubmitEndpoint('')
				return
			}
			this.pendingRegister = register
			this.setSubmitEndpoint(
				`/apps/openregister/api/objects/${register}/${schema}`,
			)
		},

		/**
		 * Write `submitEndpoint` and clear `submitHandler` in the same emit.
		 * The value is also what `externalTarget` parses, so an OR
		 * `/api/objects/{register}/{schema}` URL is what unlocks the External
		 * access section.
		 *
		 * @param {string} value - the submit URL; `''` deletes `submitEndpoint`, leaving the form with neither submit target.
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
