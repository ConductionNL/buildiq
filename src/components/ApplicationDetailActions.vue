<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationDetailActions — the actions bar on the VirtualAppDetail
  - (`type: detail`) page (`config.actionsComponent: "ApplicationDetailActions"`).
  - Owner/editor-gated Publish (OR lifecycle transition → ObjectTransitionedEvent
  - → version snapshot + BuiltAppRoute), Manage permissions (PermissionsModal —
  - kept in this component per ADR-004 gate-modal-isolation), Design pages
  - (router-link to PageDesigner), and Open virtual app. Reads/writes the
  - Application via OR's REST API (ADR-022) using the applicationContext mixin.
  -->
<template>
	<div class="ob-detail-actions">
		<NcButton
			v-if="obAppRole === 'owner'"
			type="primary"
			:disabled="!canPublish || publishing"
			@click="publish">
			{{ publishing ? t('openbuild', 'Publishing…') : t('openbuild', 'Publish') }}
		</NcButton>
		<NcButton
			v-if="obAppRole === 'owner'"
			:disabled="!obApp"
			@click="permissionsOpen = true">
			{{ t('openbuild', 'Manage permissions') }}
		</NcButton>
		<NcButton
			v-if="obAppRole === 'owner'"
			:disabled="!obApp"
			@click="historyOpen = true">
			{{ t('openbuild', 'Permission history') }}
		</NcButton>
		<NcButton v-if="obApp && obApp.slug" :to="{ name: 'PageDesigner', params: { slug: obApp.slug } }">
			{{ t('openbuild', 'Design pages') }}
		</NcButton>
		<NcButton v-if="builderUrl" :href="builderUrl">
			{{ t('openbuild', 'Open virtual app') }}
		</NcButton>
		<NcButton
			:disabled="!obApp || !obApp.productionVersion"
			@click="exportOpen = true">
			{{ t('openbuild', 'Export') }}
		</NcButton>
		<span v-if="toast" class="ob-detail-actions__toast">{{ toast }}</span>
		<span v-if="error" class="ob-detail-actions__error">{{ error }}</span>
		<ExportDialog
			v-if="exportOpen && obApp"
			:application-slug="obApp.slug"
			@close="exportOpen = false" />
		<PermissionsModal
			:open="permissionsOpen"
			:application="obApp"
			:available-groups="availableGroups"
			@update:open="permissionsOpen = $event"
			@save="onPermissionsSave" />
		<PermissionHistoryModal
			v-if="obApp"
			:open="historyOpen"
			:application-uuid="obAppUuid"
			@update:open="historyOpen = $event" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import PermissionsModal from '../modals/PermissionsModal.vue'
import PermissionHistoryModal from '../modals/PermissionHistoryModal.vue'
import { getCurrentUserGroups } from '../composables/useRole.js'
import applicationContext from '../mixins/applicationContext.js'

const ExportDialog = () => import('../dialogs/ExportDialog.vue')

export default {
	name: 'ApplicationDetailActions',
	components: { NcButton, PermissionsModal, PermissionHistoryModal, ExportDialog },
	mixins: [applicationContext],
	data() {
		return {
			publishing: false,
			permissionsOpen: false,
			historyOpen: false,
			exportOpen: false,
			toast: '',
			error: '',
		}
	},
	computed: {
		/**
		 * Observed behaviour of `canPublish` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		canPublish() {
			return !!this.obApp && (this.obApp.status === 'draft' || this.obApp.status === 'published')
		},
		/**
		 * Observed behaviour of `builderUrl` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		builderUrl() {
			if (!this.obApp || !(this.obApp.currentVersion || this.obApp.status === 'published')) {
				return ''
			}
			return generateUrl(`/apps/openbuild/builder/${this.obApp.slug}`)
		},
		/**
		 * Observed behaviour of `availableGroups` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		availableGroups() {
			const perms = (this.obApp && this.obApp.permissions) || {}
			const gids = new Set(getCurrentUserGroups())
			;['owners', 'editors', 'viewers'].forEach((b) => {
				if (Array.isArray(perms[b])) {
					perms[b].forEach((g) => gids.add(g))
				}
			})
			return Array.from(gids)
		},
	},
	methods: {
		/**
		 * Observed behaviour of `publish` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		async publish() {
			if (this.obAppRole !== 'owner' || !this.obApp || this.publishing) {
				return
			}
			this.publishing = true
			this.toast = ''
			this.error = ''
			try {
				// OR's lifecycle transition endpoint — fires ObjectTransitionedEvent,
				// which ApplicationVersionSnapshotListener consumes to snapshot the
				// manifest into ApplicationVersion and bump currentVersion + create
				// the BuiltAppRoute.
				const url = generateUrl(`/apps/openregister/api/objects/openbuild/application/${this.obAppUuid}/transition/publish`)
				const { data } = await axios.post(url, {})
				await this.obLoadApp()
				const v = (data && (data.currentVersion || data.uuid)) || (this.obApp && this.obApp.currentVersion) || ''
				this.toast = t('openbuild', 'Published version {uuid}', { uuid: v ? String(v).slice(0, 8) + '…' : '' })
			} catch (e) {
				this.error = `${t('openbuild', 'Publish failed')}: ${e.message || e}`
			} finally {
				this.publishing = false
			}
		},
		/**
		 * Observed behaviour of `onPermissionsSave` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		async onPermissionsSave(permissions) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ permissions })
				this.permissionsOpen = false
			} catch (e) {
				this.error = `${t('openbuild', 'Failed to save permissions')}: ${e.message || e}`
			}
		},
	},
}
</script>

<style scoped>
.ob-detail-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.ob-detail-actions__toast {
	font-size: 13px;
	color: var(--color-success-text, #2d8a3e);
}

.ob-detail-actions__error {
	font-size: 13px;
	color: var(--color-error, #d63f3f);
}
</style>
