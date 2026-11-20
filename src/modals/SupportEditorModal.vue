<!--
SPDX-FileCopyrightText: 2026 Conduction B.V.
SPDX-License-Identifier: EUPL-1.2

The support and donation note editor on the app detail page, with a save.

The shared CnEditSupportModal saves through the `cnManifestEditor` its host
provides. On buildiq's app detail page the manifest belongs to another app, so
this wrapper provides one, scoped to the dialog, whose save writes the working
manifest to that app with PUT /api/applications/{slug}/manifest. The library
dialog closes after Done whatever the save did; after a failed save this
wrapper keeps it open, so the maker keeps their edits and can try again.

@spec openspec/changes/apps-support-note-editor-saves/specs/support-note/spec.md
-->

<template>
	<CnEditSupportModal :working="working" @close="onClose" />
</template>

<script>
import { CnEditSupportModal } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'SupportEditorModal',

	components: {
		CnEditSupportModal,
	},

	provide() {
		return {
			cnManifestEditor: {
				editing: true,
				save: () => this.save(),
			},
		}
	},

	props: {
		/** The slug of the app whose manifest is edited. */
		slug: {
			type: String,
			required: true,
		},

		/** The working manifest copy the dialog edits in place. */
		working: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'saved', 'failed'],

	data() {
		return {
			// True between a failed save and the close the library dialog emits right after it.
			failedSave: false,
		}
	},

	methods: {
		/**
		 * Write the working manifest, with its support block, to the app.
		 *
		 * @return {Promise<void>} Resolves once saved; rejects with the request's error.
		 * @spec openspec/changes/apps-support-note-editor-saves/specs/support-note/spec.md#requirement-the-support-editor-saves-the-note-to-the-apps-manifest-req-bqsn-001
		 */
		async save() {
			this.failedSave = false
			const url = generateUrl(
				`/apps/buildiq/api/applications/${encodeURIComponent(this.slug)}/manifest`,
			)
			try {
				await axios.put(url, { manifest: this.working })
			} catch (e) {
				this.failedSave = true
				this.$emit('failed', e)
				throw e
			}
			this.$emit('saved', this.working)
		},

		/**
		 * Pass the dialog's close on, except the one that follows a failed save.
		 *
		 * @return {void}
		 * @spec openspec/changes/apps-support-note-editor-saves/specs/support-note/spec.md#requirement-a-failed-save-keeps-the-editor-open-req-bqsn-002
		 */
		onClose() {
			if (this.failedSave) {
				this.failedSave = false
				return
			}
			this.$emit('close')
		},
	},
}
</script>
