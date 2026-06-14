<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - WorkflowAttachmentsSection — the "Workflows" section on the
  - application-detail/designer surface (REQ-PWA-002). Lists the app's
  - `runtime.workflows[]` attachments with add / edit / detach actions, hosting
  - the standalone WorkflowAttachmentDialog (modal-isolation rule).
  -
  - Pure controlled component: `manifest` prop in, `update:manifest` event out.
  - Detaching warns that existing linked cases are unaffected (links on objects
  - remain; no case is deleted).
  -->
<template>
	<section class="ob-workflows-section">
		<header class="ob-workflows-section__header">
			<h3 class="ob-workflows-section__title">
				{{ t('openbuild', 'Workflows') }}
			</h3>
			<NcButton type="secondary" @click="openAdd">
				{{ t('openbuild', 'Attach case type') }}
			</NcButton>
		</header>

		<p v-if="attachments.length === 0" class="ob-workflows-section__empty">
			{{ t('openbuild', 'No Procest case types are attached yet. Attach one to start a case when an object is created.') }}
		</p>
		<ul v-else class="ob-workflows-section__list">
			<li v-for="wf in attachments" :key="wf.id" class="ob-workflows-section__item">
				<div class="ob-workflows-section__item-main">
					<strong>{{ wf.caseTypeName }}</strong>
					<span class="ob-workflows-section__item-meta">
						{{ t('openbuild', 'on schema {schema} → {property}', { schema: wf.schema, property: wf.linkProperty }) }}
					</span>
				</div>
				<div class="ob-workflows-section__item-actions">
					<NcButton type="tertiary" @click="openEdit(wf)">
						{{ t('openbuild', 'Edit') }}
					</NcButton>
					<NcButton type="tertiary" @click="detach(wf)">
						{{ t('openbuild', 'Detach') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<WorkflowAttachmentDialog
			:open.sync="dialogOpen"
			:schemas="schemas"
			:attached-schemas="attachedSchemas"
			:attachment="editingAttachment"
			:procest-available="procestAvailable"
			@save="onDialogSave"
			@create-link-property="$emit('create-link-property', $event)" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import WorkflowAttachmentDialog from '../dialogs/WorkflowAttachmentDialog.vue'

export default {
	name: 'WorkflowAttachmentsSection',
	components: { NcButton, WorkflowAttachmentDialog },
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
		procestAvailable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['update:manifest', 'create-link-property'],
	data() {
		return {
			dialogOpen: false,
			editingAttachment: null,
		}
	},
	computed: {
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		attachments() {
			return (this.manifest && this.manifest.runtime && this.manifest.runtime.workflows) || []
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		attachedSchemas() {
			return this.attachments.map((wf) => wf.schema)
		},
	},
	methods: {
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		openAdd() {
			this.editingAttachment = null
			this.dialogOpen = true
		},
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002 */
		openEdit(wf) {
			this.editingAttachment = wf
			this.dialogOpen = true
		},
		/**
		 * Persist an added/edited attachment into `runtime.workflows[]`.
		 *
		 * @param {object} payload - `{ entry, addStatusTab }` from the dialog.
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		onDialogSave(payload) {
			const entry = payload.entry
			const list = this.attachments.slice()
			const idx = list.findIndex((wf) => wf.id === entry.id)
			if (idx >= 0) {
				list.splice(idx, 1, entry)
			} else {
				list.push(entry)
			}
			let next = this.withWorkflows(list)
			if (payload.addStatusTab) {
				next = this.injectStatusTab(next, entry)
			}
			this.$emit('update:manifest', next)
		},
		/**
		 * Detach an attachment (existing linked cases are unaffected).
		 *
		 * @param {object} wf - the attachment to remove.
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		detach(wf) {
			const ok = typeof window !== 'undefined' && window.confirm
				? window.confirm(t('openbuild', 'Detach this case type? Existing linked cases are NOT deleted and object links are kept.'))
				: true
			if (!ok) {
				return
			}
			const list = this.attachments.filter((a) => a.id !== wf.id)
			this.$emit('update:manifest', this.withWorkflows(list))
		},
		/**
		 * Return a manifest copy with the given workflows list set (or the
		 * `runtime.workflows` key removed when empty so zero-attachment
		 * manifests serialize byte-identically).
		 *
		 * @param {Array} list - the workflows list.
		 * @return {object}
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-001
		 */
		withWorkflows(list) {
			const next = { ...this.manifest }
			const runtime = { ...(next.runtime || {}) }
			if (list.length === 0) {
				delete runtime.workflows
			} else {
				runtime.workflows = list
			}
			if (Object.keys(runtime).length === 0) {
				delete next.runtime
			} else {
				next.runtime = runtime
			}
			return next
		},
		/**
		 * Inject a `procest-case-status` tab into the detail page that targets
		 * the attachment's schema, if such a page exists and lacks one.
		 *
		 * @param {object} manifest - the manifest.
		 * @param {object} entry - the attachment entry.
		 * @return {object}
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		injectStatusTab(manifest, entry) {
			const next = { ...manifest, pages: (manifest.pages || []).slice() }
			next.pages = next.pages.map((page) => {
				const cfg = page && page.config
				const isDetail = page && (page.type === 'detail') && cfg && cfg.schema === entry.schema
				if (!isDetail) {
					return page
				}
				const sidebarProps = { ...(cfg.sidebarProps || {}) }
				const tabs = (sidebarProps.tabs || []).slice()
				if (!tabs.some((t2) => t2.component === 'procest-case-status')) {
					tabs.push({ id: 'procest-case-status', label: 'Case', component: 'procest-case-status' })
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
.ob-workflows-section__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}
.ob-workflows-section__title {
	margin: 0;
}
.ob-workflows-section__empty {
	color: var(--color-text-maxcontrast);
}
.ob-workflows-section__list {
	list-style: none;
	padding: 0;
	margin: 0;
}
.ob-workflows-section__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}
.ob-workflows-section__item-meta {
	color: var(--color-text-maxcontrast);
	margin-left: 8px;
}
</style>
