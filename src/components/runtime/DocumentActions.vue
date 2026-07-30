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
		<div v-if="docudeskChecked && !docudeskUsable" class="ob-document-actions__unavailable">
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
import { useAppStatus } from '../../composables/useAppStatus.js'
import { objectSchemaKeys, matchesKey } from '../../utils/objectSchemaKeys.js'

export default {
	name: 'DocumentActions',
	components: { NcButton },
	// The live, reactive manifest CnAppRoot provides to every descendant.
	inject: {
		cnManifest: { default: null },
		// CnDetailPage provides the page's own object context. Its `schema` is
		// the manifest's `config.schema` — a SLUG, the same vocabulary
		// `runtime.documents[].schema` uses. `@self.schema` is a numeric id, so
		// this injection is what makes the two sides comparable at all.
		cnObjectContext: { default: null },
	},
	props: {
		// The current OR object being viewed.
		object: {
			type: Object,
			default: () => ({}),
		},
		// All document attachments for the app (manifest `runtime.documents[]`).
		// The widget filters to this object's schema itself.
		//
		// Optional: nothing supplies this at runtime. A registry entry is
		// resolved by CnPageRenderer's slot-override path, which hands the
		// component the DETAIL surface's own props (the object) — it has no way
		// to know a widget wants a slice of the manifest. So the widget reads
		// the manifest itself through the `cnManifest` injection, exactly as the
		// sibling TrackLinkAction does for `runtime.externalForms[]`. The prop
		// stays as an explicit override for tests and for a host that already
		// holds the list.
		attachments: {
			type: Array,
			default: () => ([]),
		},
		// Soft capability flag for Docudesk (graceful absence, REQ-DDT-005).
		// `null` (the default) means "decide for yourself" — see
		// `docudeskUsable`. A boolean is an explicit override.
		docudeskAvailable: {
			type: Boolean,
			default: null,
		},
	},
	/**
	 * Bind the Docudesk generate integration and the soft capability probe.
	 *
	 * @return {object}
	 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
	 */
	setup() {
		const docs = useDocudeskDocument()
		const docudesk = useAppStatus('docudesk')
		return { docs, docudesk }
	},
	computed: {
		/**
		 * Has the Docudesk capability been decided yet?
		 *
		 * An explicit `docudeskAvailable` prop is a decision in itself.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-005
		 */
		docudeskChecked() {
			return this.docudeskAvailable !== null || this.docudesk.checked.value
		},
		/**
		 * May this surface talk to Docudesk?
		 *
		 * @return {boolean}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-005
		 */
		docudeskUsable() {
			return this.docudeskAvailable === null
				? this.docudesk.available.value
				: this.docudeskAvailable
		},
		/**
		 * The app's document attachments: the explicit prop when a host supplies
		 * one, else the built app's own `runtime.documents[]` from the manifest.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		effectiveAttachments() {
			if (Array.isArray(this.attachments) && this.attachments.length) {
				return this.attachments
			}
			const manifest = this.cnManifest
			const list = manifest && manifest.runtime && manifest.runtime.documents
			return Array.isArray(list) ? list : []
		},
		/**
		 * The object's schema slug from its `@self` envelope.
		 *
		 * @return {string}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		objectSchema() {
			return this.schemaKeys[0] || ''
		},
		/**
		 * Every name this object's schema can legitimately be known by.
		 *
		 * `@self.schema` is a NUMERIC id (measured: `{"register":"15","schema":"21"}`)
		 * while `runtime.documents[].schema` is a slug (`hello-message`), so the
		 * old single-field read could never match an attachment and this widget
		 * rendered nothing for every object. See src/utils/objectSchemaKeys.js.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		schemaKeys() {
			return objectSchemaKeys(this.object, this.cnObjectContext)
		},
		/**
		 * Attachments declared for this object's schema, in declared order.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
		 */
		schemaAttachments() {
			const keys = this.schemaKeys
			if (keys.length === 0) {
				return []
			}
			return this.effectiveAttachments.filter((a) => a && matchesKey(a.schema, keys))
		},
	},
	mounted() {
		// Fire the capability probe once. `useAppStatus` short-circuits on
		// `OC.appswebroots` and caches per session, so this is cheap.
		if (this.docudeskAvailable === null) {
			this.docudesk.check()
		}
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
			// REQ-DDT-005: on an instance without Docudesk NO request may reach
			// /apps/docudesk. Resolve the capability BEFORE generating rather
			// than reading a possibly-unresolved flag — `check()` is cached, so
			// the await is free after the first call.
			if (this.docudeskAvailable === null && this.docudesk.checked.value === false) {
				await this.docudesk.check()
			}
			if (!this.docudeskUsable) {
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
