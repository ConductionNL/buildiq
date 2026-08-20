<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AppDeleteDialogSlot — fills CnIndexPage's `#delete-dialog` slot on the
  - applications index (manifest page.slots["delete-dialog"]). It replaces the
  - library's generic CnDeleteDialog so the table's native Delete row action
  - (kept in its default last position, with the trash icon) opens OpenBuild's
  - own DeleteAppDialog: the data checkbox plus deletion through the `destroy`
  - endpoint (versions/registers/routes cleanup honouring the checkbox) instead
  - of a bare object delete that would orphan everything the app owns.
  -
  - The slot binds `item` (the row targeted for deletion, null when closed) and
  - `close` (closes the dialog). Visibility is driven off `item` because
  - CnIndexPage nulls it on close.
  -->
<template>
	<DeleteAppDialog
		:open="!!item"
		:appName="appName"
		:busy="busy"
		@update:open="onOpenChange"
		@confirm="onConfirm" />
</template>

<script>
import { useObjectStore } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import DeleteAppDialog from '../dialogs/DeleteAppDialog.vue'

// The applications index self-fetches under this object-type key
// (`${register}-${schema}`, see CnIndexPage useSelfFetchList); the shared
// library object store keys its collections/objects maps by it.
const APPLICATION_OBJECT_TYPE = 'openbuild-application'

export default {
	name: 'AppDeleteDialogSlot',

	components: { DeleteAppDialog },

	props: {
		/** The row targeted for deletion (CnIndexPage `#delete-dialog` binding); null when closed. */
		item: { type: Object, default: null },
		/** Closes the delete dialog (CnIndexPage `#delete-dialog` binding). */
		close: { type: Function, default: null },
	},

	data() {
		return { busy: false }
	},

	computed: {
		/**
		 * Display name for the targeted app.
		 *
		 * @return {string}
		 */
		appName() {
			const i = this.item || {}
			return String(i.name || i.slug || '')
		},

		/**
		 * OpenRegister object id / UUID of the targeted app.
		 *
		 * @return {string}
		 */
		appUuid() {
			const i = this.item || {}
			return String(i.id || (i['@self'] && i['@self'].id) || i.uuid || '')
		},
	},

	methods: {
		/**
		 * Close the dialog when it requests it, unless a delete is in flight.
		 *
		 * @param {boolean} open The requested open state.
		 * @return {void}
		 */
		onOpenChange(open) {
			if (!open && !this.busy && typeof this.close === 'function') {
				this.close()
			}
		},

		/**
		 * Delete the app through OpenBuild's destroy endpoint (honouring the data
		 * checkbox), then drop it from the table's store collection and close.
		 *
		 * @param {boolean} deleteData Whether to also delete all app data.
		 * @return {Promise<void>}
		 */
		async onConfirm(deleteData) {
			if (!this.appUuid || this.busy) {
				return
			}
			this.busy = true
			try {
				await axios.delete(
					generateUrl(`/apps/openbuild/api/applications/${this.appUuid}`),
					{
						params: { deleteData: deleteData ? 1 : 0 },
					},
				)
				this.evictFromList(this.appUuid)
				if (typeof this.close === 'function') {
					this.close()
				}
			} catch (e) {
				const detail =
					(e.response && e.response.data && e.response.data.detail)
					|| e.message
					|| e
				showError(
					this.t('openbuild', 'Delete failed: {error}', { error: detail }),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Remove a deleted app from the shared library object store so the
		 * self-fetch table (which reads `collections[type]`) updates reactively —
		 * mirrors the store's own post-delete cache eviction.
		 *
		 * @param {string} uuid The deleted application's id.
		 * @return {void}
		 */
		evictFromList(uuid) {
			try {
				const store = useObjectStore()
				const type = APPLICATION_OBJECT_TYPE
				if (store.collections && store.collections[type]) {
					store.collections = {
						...store.collections,
						[type]: store.collections[type].filter((o) => o.id !== uuid),
					}
				}
				if (store.objects && store.objects[type]) {
					const { [uuid]: _removed, ...rest } = store.objects[type]
					store.objects = { ...store.objects, [type]: rest }
				}
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn(
					'[openbuild] could not evict deleted app from list cache',
					e,
				)
			}
		},
	},
}
</script>
