// SPDX-License-Identifier: EUPL-1.2

/**
 * What the attachment dialogs of the application detail surface share (the
 * Docudesk document-template and the Procest workflow attachment): the
 * dialog's `open`, the app's schemas and the attachment being edited,
 * whether it edits, closing, and the id a saved entry gets. Each dialog
 * declares its own `update:open` and `save` events.
 *
 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-002
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
 */
export const attachmentDialogMixin = {
	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// The app's schemas as `[{ slug, title, properties }]`.
		schemas: {
			type: Array,
			default: () => [],
		},

		// Existing attachment when editing (null when adding).
		attachment: {
			type: Object,
			default: null,
		},
	},

	computed: {
		editing() {
			return !!this.attachment
		},
	},

	methods: {
		/**
		 * The id of the entry being saved: the edited attachment's, or a new
		 * `<prefix>-<schema>-<time>` one.
		 *
		 * @param {string} prefix - the id prefix of this kind of attachment.
		 * @return {string} the id.
		 */
		entryId(prefix) {
			return (
				(this.attachment && this.attachment.id)
				|| `${prefix}-${this.schemaOption.slug}-${Date.now()}`
			)
		},

		onClose() {
			this.$emit('update:open', false)
		},
	},
}
