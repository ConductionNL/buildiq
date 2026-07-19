<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DocumentTemplateAttachmentDialog — standalone dialog (modal-isolation rule)
  - to attach a Docudesk template to a virtual-app schema (REQ-DDT-002).
  -
  - Template picker fed by Docudesk's `GET api/templates`; schema picker over
  - the app's own schemas; required action-label input; optional format picker
  - (pinned set, shared with the validator); optional filename-template input
  - with `{{property}}` hints; a "preview with sample data" affordance calling
  - `POST api/templates/{id}/preview`; and an optional "add document actions to
  - the detail page" toggle. Editing refreshes the template-name snapshot via
  - `GET api/templates/{id}` and warns when the template no longer exists.
  -
  - Emits the assembled `runtime.documents[]` entry on save.
  -->
<template>
	<NcDialog
		:open="open"
		:name="editing ? t('openbuild', 'Edit document attachment') : t('openbuild', 'Attach a Docudesk template')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-document-attach">
			<p v-if="!docudeskAvailable" class="ob-document-attach__warn">
				{{ t('openbuild', 'Docudesk is not installed or enabled on this instance. The template list cannot be loaded.') }}
			</p>
			<p v-if="templateMissing" class="ob-document-attach__warn" role="alert">
				{{ t('openbuild', 'The attached template no longer exists in Docudesk. Pick another template or detach.') }}
			</p>

			<NcSelect
				v-model="templateOption"
				:input-label="t('openbuild', 'Template')"
				:options="templateOptions"
				:loading="loadingTemplates"
				:disabled="!docudeskAvailable"
				label="label" />

			<NcSelect
				v-model="schemaOption"
				:input-label="t('openbuild', 'Schema')"
				:options="schemaOptions"
				label="label" />

			<NcTextField
				:model-value="label"
				:label="t('openbuild', 'Action label')"
				:placeholder="t('openbuild', 'e.g. Generate confirmation letter')"
				@update:model-value="label = $event" />

			<NcSelect
				v-model="formatOption"
				:input-label="t('openbuild', 'Output format (optional)')"
				:options="formatOptions"
				label="label" />

			<NcTextField
				:model-value="filenameTemplate"
				:label="t('openbuild', 'Filename template (optional)')"
				:placeholder="t('openbuild', 'e.g. bevestiging-{{dossiernummer}}.pdf')"
				@update:model-value="filenameTemplate = $event" />

			<label class="ob-document-attach__toggle">
				<input v-model="addActionsTab" type="checkbox">
				{{ t('openbuild', 'Add document actions to this schema\'s detail page') }}
			</label>

			<div class="ob-document-attach__preview">
				<NcButton
					type="secondary"
					:disabled="!canPreview || previewing"
					@click="onPreview">
					{{ previewing ? t('openbuild', 'Rendering preview…') : t('openbuild', 'Preview with sample data') }}
				</NcButton>
				<p v-if="previewError" class="ob-document-attach__error" role="alert">
					{{ t('openbuild', 'Preview failed. The template could not be rendered.') }}
				</p>
				<div v-if="previewContent" class="ob-document-attach__preview-body" v-html="previewContent" />
			</div>

			<p v-if="duplicateLabel" class="ob-document-attach__error" role="alert">
				{{ t('openbuild', 'An attachment with this label already exists on this schema. Choose a different label.') }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSave" @click="onSave">
				{{ t('openbuild', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { DOCUMENT_FORMATS } from '../services/manifestValidation/documentAttachments.js'

export default {
	name: 'DocumentTemplateAttachmentDialog',
	components: { NcDialog, NcButton, NcSelect, NcTextField },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		// The app's schemas as `[{ slug, title, properties }]`.
		schemas: {
			type: Array,
			default: () => ([]),
		},
		// Existing attachments (for the (schema,label) uniqueness check).
		attachments: {
			type: Array,
			default: () => ([]),
		},
		// Existing attachment when editing (null when adding).
		attachment: {
			type: Object,
			default: null,
		},
		// Soft capability flag for Docudesk.
		docudeskAvailable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['update:open', 'save'],
	data() {
		return {
			templates: [],
			loadingTemplates: false,
			templateOption: null,
			schemaOption: null,
			label: '',
			formatOption: null,
			filenameTemplate: '',
			addActionsTab: false,
			previewing: false,
			previewError: false,
			previewContent: '',
			templateMissing: false,
		}
	},
	computed: {
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		editing() {
			return !!this.attachment
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		templateOptions() {
			return this.templates.map((tpl) => ({
				label: tpl.name || tpl.title || tpl.id,
				uuid: tpl.id || tpl.uuid,
				name: tpl.name || tpl.title || '',
			}))
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		schemaOptions() {
			return this.schemas.map((s) => ({ label: s.title || s.slug, slug: s.slug }))
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-001 */
		formatOptions() {
			return [{ label: t('openbuild', 'Template default'), value: '' }]
				.concat(DOCUMENT_FORMATS.map((f) => ({ label: f.toUpperCase(), value: f })))
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		selectedSchemaSlug() {
			return this.schemaOption ? this.schemaOption.slug : ''
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		selectedTemplateId() {
			return this.templateOption ? this.templateOption.uuid : ''
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		canPreview() {
			return this.docudeskAvailable && !!this.selectedTemplateId
		},
		/**
		 * True when the (schema, label) pair already exists on another
		 * attachment (REQ-DDT-001 uniqueness, surfaced before save).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-001
		 */
		duplicateLabel() {
			const schema = this.selectedSchemaSlug
			const label = this.label.trim()
			if (!schema || !label) {
				return false
			}
			const editingId = this.attachment && this.attachment.id
			return this.attachments.some((a) =>
				a && a.schema === schema && a.label === label && a.id !== editingId)
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		canSave() {
			return !!(this.templateOption && this.schemaOption && this.label.trim() && !this.duplicateLabel)
		},
	},
	watch: {
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
				if (this.docudeskAvailable) {
					this.fetchTemplates()
					if (this.editing) {
						this.refreshTemplateSnapshot()
					}
				}
			}
		},
	},
	methods: {
		/**
		 * Seed the form from an existing attachment when editing.
		 *
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		hydrate() {
			this.previewContent = ''
			this.previewError = false
			this.templateMissing = false
			if (this.attachment) {
				this.templateOption = { label: this.attachment.templateName, uuid: this.attachment.templateId, name: this.attachment.templateName }
				this.schemaOption = { label: this.attachment.schema, slug: this.attachment.schema }
				this.label = this.attachment.label || ''
				this.formatOption = this.attachment.format
					? { label: this.attachment.format.toUpperCase(), value: this.attachment.format }
					: { label: t('openbuild', 'Template default'), value: '' }
				this.filenameTemplate = this.attachment.filenameTemplate || ''
				this.addActionsTab = false
			} else {
				this.templateOption = null
				this.schemaOption = null
				this.label = ''
				this.formatOption = { label: t('openbuild', 'Template default'), value: '' }
				this.filenameTemplate = ''
				this.addActionsTab = false
			}
		},
		/**
		 * Load templates from Docudesk's template index.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		async fetchTemplates() {
			this.loadingTemplates = true
			try {
				const url = generateUrl('/apps/docudesk/api/templates')
				const { data } = await axios.get(url)
				const list = (data && (data.results || data.templates || data)) || []
				this.templates = Array.isArray(list) ? list : []
			} catch {
				this.templates = []
			} finally {
				this.loadingTemplates = false
			}
		},
		/**
		 * On edit, refresh the template-name snapshot via the show endpoint and
		 * flag a missing template (404).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		async refreshTemplateSnapshot() {
			const id = this.attachment && this.attachment.templateId
			if (!id) {
				return
			}
			try {
				const url = generateUrl(`/apps/docudesk/api/templates/${id}`)
				const { data } = await axios.get(url)
				const name = (data && (data.name || data.title)) || ''
				if (name) {
					this.templateOption = { label: name, uuid: id, name }
				}
				this.templateMissing = false
			} catch (e) {
				const status = e && e.response && e.response.status
				if (status === 404) {
					this.templateMissing = true
				}
			}
		},
		/**
		 * Render a preview of the selected template without saving.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		async onPreview() {
			if (!this.canPreview) {
				return
			}
			this.previewing = true
			this.previewError = false
			this.previewContent = ''
			try {
				const url = generateUrl(`/apps/docudesk/api/templates/${this.selectedTemplateId}/preview`)
				const { data } = await axios.post(url, {})
				this.previewContent = (data && (data.html || data.content || data.preview)) || ''
			} catch {
				this.previewError = true
			} finally {
				this.previewing = false
			}
		},
		/**
		 * Assemble + emit the document-attachment entry.
		 *
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		onSave() {
			if (!this.canSave) {
				return
			}
			const id = (this.attachment && this.attachment.id)
				|| `doc-${this.schemaOption.slug}-${Date.now()}`
			const entry = {
				id,
				schema: this.schemaOption.slug,
				templateId: this.templateOption.uuid,
				templateName: this.templateOption.name || this.templateOption.label,
				label: this.label.trim(),
			}
			const format = this.formatOption && this.formatOption.value
			if (format) {
				entry.format = format
			}
			if (this.filenameTemplate.trim()) {
				entry.filenameTemplate = this.filenameTemplate.trim()
			}
			this.$emit('save', { entry, addActionsTab: this.addActionsTab })
			this.$emit('update:open', false)
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		onClose() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.ob-document-attach {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.ob-document-attach__warn {
	color: var(--color-warning-text, var(--color-warning));
}
.ob-document-attach__error {
	color: var(--color-error);
}
.ob-document-attach__toggle {
	display: flex;
	gap: 8px;
	align-items: center;
}
.ob-document-attach__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.ob-document-attach__preview-body {
	max-height: 240px;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}
</style>
