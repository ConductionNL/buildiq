<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DocumentActions — detail-page surface (REQ-DDT-004) rendering one generate
  - button per document attachment declared for the current object's schema.
  - Registered as the runtime widget `docudesk-document-actions`, referencable
  - from a detail page's `sidebarProps.tabs` or as a detail action group.
  -
  - Each button drives `useDocudeskDocument.generate` (REQ-DDT-003) with its
  - own busy/error state. Renders NOTHING (no heading/placeholder) when the
  - schema has no attachments, and an unavailable state — issuing zero requests
  - to /apps/docudesk — when Docudesk is absent.
  -->
<template>
	<div v-if="schemaAttachments.length" class="ob-document-actions">
		<div v-if="!docudeskAvailable" class="ob-document-actions__unavailable">
			{{ t('openbuild', 'Docudesk is not available — document generation is disabled.') }}
		</div>
		<template v-else>
			<div
				v-for="att in schemaAttachments"
				:key="att.id"
				class="ob-document-actions__row">
				<NcButton
					type="secondary"
					:disabled="isBusy(att)"
					@click="onGenerate(att)">
					{{ isBusy(att) ? t('openbuild', 'Generating…') : att.label }}
				</NcButton>
				<span
					v-if="errorCode(att)"
					class="ob-document-actions__error"
					role="alert">
					{{ errorMessage(att) }}
				</span>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useDocudeskDocument } from '../../composables/useDocudeskDocument.js'

export default {
	name: 'DocumentActions',
	components: { NcButton },
	props: {
		// The current OR object being viewed.
		object: {
			type: Object,
			default: () => ({}),
		},
		// All document attachments for the app (manifest `runtime.documents[]`).
		// The widget filters to this object's schema itself.
		attachments: {
			type: Array,
			default: () => ([]),
		},
		// Soft capability flag for Docudesk (graceful absence, REQ-DDT-005).
		docudeskAvailable: {
			type: Boolean,
			default: true,
		},
	},
	/**
	 * Bind the Docudesk generate integration.
	 *
	 * @return {object}
	 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
	 */
	setup() {
		const docs = useDocudeskDocument()
		return { docs }
	},
	computed: {
		/**
		 * The object's schema slug from its `@self` envelope.
		 *
		 * @return {string}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		objectSchema() {
			const self = (this.object && this.object['@self']) || {}
			return self.schema || this.object.schema || ''
		},
		/**
		 * Attachments declared for this object's schema, in declared order.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		schemaAttachments() {
			const schema = this.objectSchema
			if (!schema) {
				return []
			}
			return (this.attachments || []).filter((a) => a && a.schema === schema)
		},
	},
	methods: {
		/**
		 * @param {object} att - the attachment.
		 * @return {boolean}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		isBusy(att) {
			return this.docs.busyFor(att, this.object)
		},
		/**
		 * @param {object} att - the attachment.
		 * @return {?string}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		errorCode(att) {
			return this.docs.errorFor(att, this.object)
		},
		/**
		 * @param {object} att - the attachment.
		 * @return {string}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		errorMessage(att) {
			return this.errorCode(att) === 'no-access'
				? t('openbuild', 'You do not have access to generate this document.')
				: t('openbuild', 'Generating the document failed. The object is unchanged — you can try again.')
		},
		/**
		 * Trigger generation for an attachment (guarded against absent app).
		 *
		 * @param {object} att - the attachment.
		 * @return {Promise<void>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-003
		 */
		async onGenerate(att) {
			if (!this.docudeskAvailable) {
				return
			}
			await this.docs.generate(att, this.object)
		},
	},
}
</script>

<style scoped>
.ob-document-actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.ob-document-actions__row {
	display: flex;
	align-items: center;
	gap: 8px;
}
.ob-document-actions__error {
	color: var(--color-error);
}
.ob-document-actions__unavailable {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}
</style>
