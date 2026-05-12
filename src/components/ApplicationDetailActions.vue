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
			{{ publishing ? t('openbuilt', 'Publishing…') : t('openbuilt', 'Publish') }}
		</NcButton>
		<NcButton
			v-if="obAppRole === 'owner'"
			:disabled="!obApp"
			@click="permissionsOpen = true">
			{{ t('openbuilt', 'Manage permissions') }}
		</NcButton>
		<NcButton v-if="obApp && obApp.slug" :to="{ name: 'PageDesigner', params: { slug: obApp.slug } }">
			{{ t('openbuilt', 'Design pages') }}
		</NcButton>
		<NcButton v-if="builderUrl" :href="builderUrl">
			{{ t('openbuilt', 'Open virtual app') }}
		</NcButton>
		<span v-if="toast" class="ob-detail-actions__toast">{{ toast }}</span>
		<span v-if="error" class="ob-detail-actions__error">{{ error }}</span>
		<PermissionsModal
			:open="permissionsOpen"
			:application="obApp"
			:available-groups="availableGroups"
			@update:open="permissionsOpen = $event"
			@save="onPermissionsSave" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import PermissionsModal from '../modals/PermissionsModal.vue'
import { getCurrentUserGroups } from '../composables/useRole.js'
import applicationContext from '../mixins/applicationContext.js'

export default {
	name: 'ApplicationDetailActions',
	components: { NcButton, PermissionsModal },
	mixins: [applicationContext],
	data() {
		return {
			publishing: false,
			permissionsOpen: false,
			toast: '',
			error: '',
		}
	},
	computed: {
		canPublish() {
			return !!this.obApp && (this.obApp.status === 'draft' || this.obApp.status === 'published')
		},
		builderUrl() {
			if (!this.obApp || !(this.obApp.currentVersion || this.obApp.status === 'published')) {
				return ''
			}
			return generateUrl(`/apps/openbuilt/builder/${this.obApp.slug}`)
		},
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
				const url = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${this.obAppUuid}/transition/publish`)
				const { data } = await axios.post(url, {})
				await this.obLoadApp()
				const v = (data && (data.currentVersion || data.uuid)) || (this.obApp && this.obApp.currentVersion) || ''
				this.toast = t('openbuilt', 'Published version {uuid}', { uuid: v ? String(v).slice(0, 8) + '…' : '' })
			} catch (e) {
				this.error = `${t('openbuilt', 'Publish failed')}: ${e.message || e}`
			} finally {
				this.publishing = false
			}
		},
		async onPermissionsSave(permissions) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ permissions })
				this.permissionsOpen = false
			} catch (e) {
				this.error = `${t('openbuilt', 'Failed to save permissions')}: ${e.message || e}`
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
