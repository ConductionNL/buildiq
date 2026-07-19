<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DocumentAttachmentsSection — the "Documents" section on the
  - application-detail/designer surface (REQ-DDT-002). Lists the app's
  - `runtime.documents[]` attachments with add / edit / detach actions, hosting
  - the standalone DocumentTemplateAttachmentDialog (modal-isolation rule).
  -
  - Pure controlled component: `manifest` prop in, `update:manifest` event out.
  - Multiple attachments per schema are allowed (unlike the Workflows section).
  - Detaching removes only the manifest entry — previously generated documents
  - are user downloads and are never touched.
  -->
<template>
	<section class="ob-documents-section">
		<header class="ob-documents-section__header">
			<h3 class="ob-documents-section__title">
				{{ t('openbuild', 'Documents') }}
			</h3>
			<NcButton
				type="secondary"
				:disabled="!docudeskAvailable"
				:title="docudeskAvailable ? '' : t('openbuild', 'Docudesk is not installed or enabled on this instance.')"
				@click="openAdd">
				{{ t('openbuild', 'Attach template') }}
			</NcButton>
		</header>

		<p v-if="!docudeskAvailable" class="ob-documents-section__hint">
			{{ t('openbuild', 'Docudesk is not available. Existing attachments stay viewable and removable, but you cannot add new ones.') }}
		</p>

		<p v-if="attachments.length === 0" class="ob-documents-section__empty">
			{{ t('openbuild', 'No Docudesk templates are attached yet. Attach one to let users generate a branded document from an object.') }}
		</p>
		<ul v-else class="ob-documents-section__list">
			<li v-for="doc in attachments" :key="doc.id" class="ob-documents-section__item">
				<div class="ob-documents-section__item-main">
					<strong>{{ doc.label }}</strong>
					<span class="ob-documents-section__item-meta">
						{{ t('openbuild', '{template} on schema {schema}', { template: doc.templateName, schema: doc.schema }) }}
					</span>
				</div>
				<div class="ob-documents-section__item-actions">
					<NcButton type="tertiary" @click="openEdit(doc)">
						{{ t('openbuild', 'Edit') }}
					</NcButton>
					<NcButton type="tertiary" @click="detach(doc)">
						{{ t('openbuild', 'Detach') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<DocumentTemplateAttachmentDialog
			v-model:open="dialogOpen"
			:schemas="schemas"
			:attachments="attachments"
			:attachment="editingAttachment"
			:docudesk-available="docudeskAvailable"
			@save="onDialogSave" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import DocumentTemplateAttachmentDialog from '../dialogs/DocumentTemplateAttachmentDialog.vue'

export default {
	name: 'DocumentAttachmentsSection',
	components: { NcButton, DocumentTemplateAttachmentDialog },
	props: {
		manifest: {
			type: Object,
			default: () => ({}),
		},
		// The app's schemas, passed through to the dialog's pickers.
		schemas: {
			type: Array,
			default: () => ([]),
		},
		docudeskAvailable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['update:manifest'],
	data() {
		return {
			dialogOpen: false,
			editingAttachment: null,
		}
	},
	computed: {
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		attachments() {
			return (this.manifest && this.manifest.runtime && this.manifest.runtime.documents) || []
		},
	},
	methods: {
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		openAdd() {
			if (!this.docudeskAvailable) {
				return
			}
			this.editingAttachment = null
			this.dialogOpen = true
		},
		/** @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002 */
		openEdit(doc) {
			this.editingAttachment = doc
			this.dialogOpen = true
		},
		/**
		 * Persist an added/edited attachment into `runtime.documents[]`.
		 *
		 * @param {object} payload - `{ entry, addActionsTab }` from the dialog.
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		onDialogSave(payload) {
			const entry = payload.entry
			const list = this.attachments.slice()
			const idx = list.findIndex((doc) => doc.id === entry.id)
			if (idx >= 0) {
				list.splice(idx, 1, entry)
			} else {
				list.push(entry)
			}
			let next = this.withDocuments(list)
			if (payload.addActionsTab) {
				next = this.injectActionsTab(next, entry)
			}
			this.$emit('update:manifest', next)
		},
		/**
		 * Detach an attachment (previously generated documents are unaffected).
		 *
		 * @param {object} doc - the attachment to remove.
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		detach(doc) {
			const ok = typeof window !== 'undefined' && window.confirm
				? window.confirm(t('openbuild', 'Detach this template? Previously generated documents are NOT deleted.'))
				: true
			if (!ok) {
				return
			}
			const list = this.attachments.filter((a) => a.id !== doc.id)
			this.$emit('update:manifest', this.withDocuments(list))
		},
		/**
		 * Return a manifest copy with the given documents list set (or the
		 * `runtime.documents` key removed when empty so zero-attachment
		 * manifests serialize byte-identically).
		 *
		 * @param {Array} list - the documents list.
		 * @return {object}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-001
		 */
		withDocuments(list) {
			const next = { ...this.manifest }
			const runtime = { ...(next.runtime || {}) }
			if (list.length === 0) {
				delete runtime.documents
			} else {
				runtime.documents = list
			}
			if (Object.keys(runtime).length === 0) {
				delete next.runtime
			} else {
				next.runtime = runtime
			}
			return next
		},
		/**
		 * Inject a `docudesk-document-actions` tab into the detail page that
		 * targets the attachment's schema, if such a page exists and lacks one.
		 *
		 * @param {object} manifest - the manifest.
		 * @param {object} entry - the attachment entry.
		 * @return {object}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
		 */
		injectActionsTab(manifest, entry) {
			const next = { ...manifest, pages: (manifest.pages || []).slice() }
			next.pages = next.pages.map((page) => {
				const cfg = page && page.config
				const isDetail = page && (page.type === 'detail') && cfg && cfg.schema === entry.schema
				if (!isDetail) {
					return page
				}
				const sidebarProps = { ...(cfg.sidebarProps || {}) }
				const tabs = (sidebarProps.tabs || []).slice()
				if (!tabs.some((t2) => t2.component === 'docudesk-document-actions')) {
					tabs.push({ id: 'docudesk-document-actions', label: 'Documents', component: 'docudesk-document-actions' })
				}
				sidebarProps.tabs = tabs
				return { ...page, config: { ...cfg, sidebarProps } }
			})
			return next
		},
	},
}
</script>

<style scoped>
.ob-documents-section__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}
.ob-documents-section__title {
	margin: 0;
}
.ob-documents-section__empty,
.ob-documents-section__hint {
	color: var(--color-text-maxcontrast);
}
.ob-documents-section__list {
	list-style: none;
	padding: 0;
	margin: 0;
}
.ob-documents-section__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}
.ob-documents-section__item-meta {
	color: var(--color-text-maxcontrast);
	margin-left: 8px;
}
</style>
